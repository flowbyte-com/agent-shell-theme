<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The durable write-ahead journal for a transaction's content operations.
 *
 * ## Why this exists
 *
 * Stage 0 gave the transaction row a lease and a rule: a transaction holding
 * durable work is never reclaimed, because `has_durable_work()` consults
 * `$tx['content']`. That rule has been enforced since the day it was written
 * and it has so far only ever been true vacuously, because nothing ever put
 * anything in `$tx['content']`. This class is what makes it load-bearing — and
 * it is therefore the first thing to get right about journalling, because
 * getting it wrong is not a lost draft but a permanently wedged site.
 *
 * ## Where it lives, and why there
 *
 * In the transaction row itself, alongside `before` and `staged`. Not a table
 * of its own. Two reasons:
 *
 *   - `has_durable_work()` already reads `$tx['content']`, and the invariant
 *     Stage 0 established is that the row *is* the journal's only copy.
 *     A second storage location would mean two things to keep in step and a
 *     window where one exists without the other.
 *   - Every write to the row is already compare-and-swapped against its exact
 *     bytes. That single guard then covers journal writes and lease writes
 *     alike, and recovery's concurrency story is the one Stage 0 has already
 *     tested: exactly one of N racers gets a row.
 *
 * ## The rule this file exists to enforce
 *
 *     The journal must be durable before any operation that depends on it for
 *     recovery.
 *
 * So `record_intent()` writes the operation and reports success only when the
 * guarded swap landed. Nothing in this class ever reports an intention that was
 * not stored.
 *
 * ## The other rule, which is the subtle one
 *
 *     A recorded intention is not proof that the WordPress mutation occurred.
 *
 * An `intended` record says "we were about to create post 301". It does not
 * say post 301 exists, because the process may have died between the swap and
 * `wp_insert_post()`. Equally, a write that *did* happen may have been
 * followed by a crash before `mark_applied()` recorded the id. So `post_id`
 * being 0 is not evidence that nothing was created — it is the absence of
 * evidence either way, and `Content_Recovery` resolves it from durable
 * markers on the post rather than from the journal's own optimism.
 *
 * ## What this does not do
 *
 * It records. It does not compensate, and it is not wired into any content
 * tool. `Content_Recovery` owns compensation; Stage 2 will decide which tools
 * journal. Until then `record_intent()` has no production caller, which is
 * deliberate: journalling half of a workflow would produce journal records
 * with no compensation path behind them, and a record that cannot be resolved
 * is worse than no record at all.
 */
class Content_Journal {

    /**
     * Schema version, stored in the journal itself.
     *
     * Per-operation rather than only per-journal, so a row written by an older
     * build can be read by a newer one without either pretending to
     * understand the other's field set. See read().
     */
    const VERSION = 1;

    /**
     * The one operation type this build can compensate.
     *
     * §3.2.2 rates create-draft `wp_delete_post( id, true )` as Exact — "we
     * created it" — which is the only reason any compensation is safe to ship
     * at all. Updates, navigation, taxonomy and permanent deletion all have
     * before/after contracts that do not exist yet; see SUPPORTED_OPERATIONS
     * and the refusal in Content_Recovery.
     */
    const OP_CREATE_DRAFT = 'create_draft';

    /**
     * Update a draft this transaction already created.
     *
     * Stage 2a: the inverse of an update is "apply the before-image via
     * `wp_update_post`", so a rollback that has compensated a chain of
     * updates must apply them in reverse chronological order before
     * compensating the create. The contract is narrower than update_content's
     * (no status, no parent, no taxonomy; the post is required to be a draft
     * the current transaction owns), and the before-image is the strong
     * after-image's predecessor: `post_title`, `post_content`, `post_name`,
     * `post_content_sha256`, `post_content_length`, `post_modified_gmt`,
     * and `op_meta_value` (the create op's id, which is what the post
     * carries as its `_agentshell_journal_op`).
     */
    const OP_UPDATE_DRAFT = 'update_draft';

    /**
     * Operation types this build is willing to compensate.
     *
     * Deliberately two entries. Every other type the design document discusses
     * is recorded nowhere and compensated by nothing, and `Content_Recovery`
     * refuses an operation that is not in this list rather than guessing at an
     * inverse. Adding a row here is the commit that makes that operation
     * recoverable, and it must bring its own before-image contract.
     */
    const SUPPORTED_OPERATIONS = array( self::OP_CREATE_DRAFT, self::OP_UPDATE_DRAFT );

    /** The journal's key inside $tx['content']. */
    const CONTENT_KEY = 'content';

    /** Where operations live inside the journal. */
    const OPS_KEY = 'ops';

    // ── Operation states ───────────────────────────────────────────────────
    //
    // One record, five states, and the transitions are the whole contract:
    //
    //   intended ──(recovery claims)──▶ compensating ──▶ compensated
    //       │                                  │
    //       └────────────(state diverged)─────┴──────▶ conflict
    //
    // `applied` is a fourth entry point rather than a fifth state: a crash
    // between the mutation and mark_applied() leaves the record at `intended`
    // with nothing recorded about the result, and recovery has to work from
    // that. It exists for the case where mark_applied() did land.

    const STATE_INTENDED     = 'intended';
    const STATE_APPLIED      = 'applied';
    const STATE_COMPENSATING = 'compensating';
    const STATE_COMPENSATED  = 'compensated';
    const STATE_CONFLICT     = 'conflict';
    /**
     * `mark_applied` returned null: the post and marker are on disk but
     * the journal does not know the post id. Recorded explicitly so the
     * close paths (commit / rollback / recovery) can refuse to discard
     * the evidence and route the operator to recovery.
     */
    const STATE_PARTIAL_APPLIED = 'partial-applied';

    const STATES = array(
        self::STATE_INTENDED,
        self::STATE_APPLIED,
        self::STATE_COMPENSATING,
        self::STATE_COMPENSATED,
        self::STATE_CONFLICT,
        self::STATE_PARTIAL_APPLIED,
    );

    /**
     * Terminal states: nothing further will be attempted automatically.
     *
     * `compensated` is terminal for the operation. `conflict` is terminal for
     * *recovery* — the engine will not try again on its own — but it is not
     * settled, because a conflict is a question put to a human and the row is
     * the only place that question is written down.
     */
    const TERMINAL_STATES = array( self::STATE_COMPENSATED, self::STATE_CONFLICT );

    /**
     * Settled states: the operation is done and owes nobody anything.
     *
     * Narrower than TERMINAL_STATES, and the difference between them is the
     * whole of §2's "an expired journalled transaction must not be silently
     * deleted". A conflicted operation is finished with but not finished *with*:
     * somebody still has to decide what to do about the post that diverged, and
     * discarding the journal to free the transaction slot would destroy the
     * record of that decision being needed. So only `compensated` frees the row.
     */
    const SETTLED_STATES = array( self::STATE_COMPENSATED );

    /**
     * Post meta that ties a post to the journal operation that created it.
     *
     * This is the only durable evidence that survives a crash between the write
     * and the journal update, and it is what makes "which post did this
     * transaction create?" answerable without trusting the journal's own
     * account of itself. See OP_META's docblock for why it is per-operation.
     */
    const OP_META = '_agentshell_journal_op';

    /**
     * Fields every operation record must carry, and their types.
     *
     * Validation is structural and strict on purpose. A journal is the only
     * copy of the intent to delete something, so a record missing the fields
     * that make deletion safe is not a record recovery may act on — it is a
     * reason to refuse. `validate()` returns the problems rather than throwing
     * so the refusal can name them.
     */
    private static function required_fields() {
        return array(
            'op'          => 'string',
            'v'           => 'int',
            'state'       => 'string',
            'actor'       => 'string',
            'recorded_at' => 'int',
            'updated_at'  => 'int',
            'target'      => 'array',
            'after'       => 'array',
            'post_id'     => 'int',
            'before'      => 'null_or_array',
            'claim'       => 'null_or_array',
            'comp'        => 'null_or_array',
            'notes'       => 'array',
        );
    }

    // ── Storage ────────────────────────────────────────────────────────────

    /**
     * The journal carried by a transaction row, or an empty journal.
     *
     * Returns the empty journal rather than null for a row with no journal, so
     * callers that only want to read do not each re-derive that shape. Nothing
     * here writes; every mutator returns a *new* row and leaves persisting it
     * to its caller, because the write is the guarded one and the guard is the
     * part that must not be duplicated.
     */
    public static function of( $tx ) {
        if ( ! is_array( $tx ) || ! isset( $tx[ self::CONTENT_KEY ] ) ) {
            return self::empty_journal();
        }
        $content = $tx[ self::CONTENT_KEY ];
        if ( ! is_array( $content ) ) {
            return self::empty_journal();
        }
        // A pre-journal row: something is under `content` but it is not a
        // journal. Reported as present-and-unreadable rather than as empty,
        // so recovery refuses it instead of concluding there is nothing owed.
        if ( ! isset( $content[ self::OPS_KEY ] ) || ! is_array( $content[ self::OPS_KEY ] ) ) {
            return self::empty_journal();
        }
        $content['v']      = isset( $content['v'] ) ? (int) $content['v'] : 0;
        $content['tx']     = isset( $content['tx'] ) ? (string) $content['tx'] : '';
        $content['sig']    = isset( $content['sig'] ) ? (string) $content['sig'] : '';
        $content['ops']    = $content[ self::OPS_KEY ];
        return $content;
    }

    /** An empty, well-formed journal — the shape a transaction starts with. */
    public static function empty_journal( $tx_id = '' ) {
        return array(
            'v'   => self::VERSION,
            'tx'  => (string) $tx_id,
            'ops' => array(),
            'sig' => '',
        );
    }

    public static function has_journal( $tx ) {
        return ! empty( self::of( $tx )[ self::OPS_KEY ] ) || self::is_schema( $tx );
    }

    /** Is this row carrying a journal of our schema? */
    public static function is_schema( $tx ) {
        return is_array( $tx )
            && isset( $tx[ self::CONTENT_KEY ][ self::OPS_KEY ] )
            && is_array( $tx[ self::CONTENT_KEY ][ self::OPS_KEY ] )
            && isset( $tx[ self::CONTENT_KEY ]['v'] );
    }

    /**
     * Is anything in this journal still owed?
     *
     * Consulted by `has_durable_work()`, so it decides whether the lease may
     * discard the row. A compensated operation is not outstanding: the post it
     * created is gone and the row's only remaining job is to be deleted. A
     * conflicted one still is — the engine has given up on it, and the record
     * of having given up is the only thing telling a human which post needs a
     * decision. Freeing the slot on the strength of a conflict would be
     * discarding a journal to make room, which is the one thing §2 forbids.
     *
     * Anything this function cannot account for counts as outstanding. An
     * operation of an unknown type, or one missing its state, is work nobody
     * can prove is finished — and the safe reading of "unprovable" is "do not
     * delete the only copy".
     */
    public static function has_outstanding_work( $content ) {
        if ( ! is_array( $content ) || ! isset( $content[ self::OPS_KEY ] ) || ! is_array( $content[ self::OPS_KEY ] ) ) {
            return true; // unreadable: treat as work rather than as nothing
        }
        foreach ( $content[ self::OPS_KEY ] as $op ) {
            if ( ! is_array( $op ) || ! isset( $op['state'] ) ) {
                return true;
            }
            if ( ! in_array( $op['state'], self::SETTLED_STATES, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is any operation in a non-`applied`, non-`compensated` state?
     *
     * "Unsettled" means the operation still needs operator or recovery
     * action before the transaction may be closed. `intended` says the
     * work has not been done; `compensating` says recovery is in flight;
     * `conflict` says a divergence has been preserved for a human; and
     * `partial-applied` says the post and marker landed but the journal
     * CAS did not, so the post id is unknown. Any of these blocks both
     * `commit_transaction` and `rollback_transaction`.
     */
    public static function has_unsettled_work( $content ) {
        if ( ! is_array( $content ) || ! isset( $content[ self::OPS_KEY ] ) || ! is_array( $content[ self::OPS_KEY ] ) ) {
            return true;
        }
        $unsettled = array(
            self::STATE_INTENDED,
            self::STATE_COMPENSATING,
            self::STATE_CONFLICT,
            self::STATE_PARTIAL_APPLIED,
        );
        foreach ( $content[ self::OPS_KEY ] as $op ) {
            if ( ! is_array( $op ) || ! isset( $op['state'] ) ) {
                return true;
            }
            if ( in_array( $op['state'], $unsettled, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is any operation `applied` (work done, post retained) or in an
     * unsettled state?
     *
     * The rollback gate uses this predicate, not `has_unsettled_work`,
     * so a transaction whose only ops are `applied` is not mistaken for
     * an empty journal — and the post those `applied` ops describe is
     * not abandoned. A transaction with no journal at all (no ops)
     * returns false; the rollback proceeds straight to finalisation.
     */
    public static function has_applied_or_unsettled_work( $content ) {
        if ( ! is_array( $content ) || ! isset( $content[ self::OPS_KEY ] ) || ! is_array( $content[ self::OPS_KEY ] ) ) {
            return false; // no journal: nothing to apply, nothing unsettled
        }
        $live = array(
            self::STATE_APPLIED,
            self::STATE_INTENDED,
            self::STATE_COMPENSATING,
            self::STATE_CONFLICT,
            self::STATE_PARTIAL_APPLIED,
        );
        foreach ( $content[ self::OPS_KEY ] as $op ) {
            if ( ! is_array( $op ) || ! isset( $op['state'] ) ) {
                return true;
            }
            if ( in_array( $op['state'], $live, true ) ) {
                return true;
            }
        }
        return false;
    }

    // ── Recording ──────────────────────────────────────────────────────────

    /**
     * Build an operation record. Does not touch storage.
     *
     * Returns a record in STATE_INTENDED, which is the *only* state a
     * caller-supplied value may set. Letting the caller declare the state
     * would let a bug skip the write the record exists to protect.
     *
     * @param string $op     Operation type. Must be in SUPPORTED_OPERATIONS.
     * @param array  $target What the operation is about: post_type, post_status,
     *                       post_title, post_name. Required — recovery compares
     *                       against it and cannot verify anything without it.
     * @param array  $after  The after-image the caller expects to exist. Same
     *                       keys, plus anything else it needs to recognise its
     *                       own work.
     * @throws \InvalidArgumentException on an unsupported type or a missing
     *         target, because a record that cannot be verified later must not
     *         be written now.
     */
    public static function record_intent( $op, array $target, array $after, $actor = null, $now = null ) {
        if ( ! in_array( $op, self::SUPPORTED_OPERATIONS, true ) ) {
            throw new \InvalidArgumentException(
                'Operation type "' . (string) $op . '" has no compensation contract in this build. '
                . 'Supported: ' . implode( ', ', self::SUPPORTED_OPERATIONS )
                . '. Recording it would leave a journal entry nothing knows how to resolve.'
            );
        }
        foreach ( array( 'post_type', 'post_status' ) as $required ) {
            if ( ! isset( $target[ $required ] ) || '' === $target[ $required ] ) {
                throw new \InvalidArgumentException(
                    'A journal record for ' . $op . ' needs target.' . $required
                    . ' — recovery compares the live post against it and cannot identify anything without it.'
                );
            }
        }

        $now = ( null === $now ) ? Transaction_Manager::lease_now() : (int) $now;
        $id  = self::new_op_id();

        $after = array_merge( $target, $after );
        // The after-image is stored as given, plus the one derived value that
        // makes the marker useful: the marker value the post must carry for
        // recovery to accept it as ours.
        $after['op_meta_value'] = $id;

        return array(
            'id'          => $id,
            'op'          => (string) $op,
            'v'           => self::VERSION,
            'state'       => self::STATE_INTENDED,
            'actor'       => null === $actor ? Store::actor() : (string) $actor,
            'recorded_at' => $now,
            'updated_at'  => $now,
            'target'      => $target,
            // A creation has no prior state to record. Stated explicitly rather
            // than left as an empty array so that a reader cannot mistake an
            // absent pre-image for one that was lost.
            'before'      => null,
            'after'       => $after,
            // 0 means "no id was ever learned", which is a real and common
            // state: a crash between the intent swap and wp_insert_post()
            // returning. It is never read as "nothing was created".
            'post_id'     => 0,
            'claim'       => null,
            'comp'        => null,
            'notes'       => array(),
        );
    }

    /**
     * A unique operation id, which is also the marker value on the post.
     *
     * One value for both, deliberately. If the journal named the post by a
     * different token than the post carries, recovery would be matching two
     * independently-generated identifiers and would have to trust that they
     * were minted together. They are the same string, so the post's own
     * metadata is the evidence and the journal is only the claimant.
     *
     * `random_bytes` rather than a counter or a hash of the transaction: an id
     * an attacker could predict or choose is an id they could plant on a post
     * to get that post deleted by a later recovery.
     */
    public static function new_op_id() {
        return 'op_' . bin2hex( random_bytes( 8 ) );
    }

    /**
     * Add an operation to a transaction row and write it, guarded.
     *
     * The write is what makes the journal durable, so this is the function the
     * "durable before it depends on it" rule is enforced in: it returns true
     * only when the row on disk is the row it just built.
     *
     * @return bool False when the row moved underneath the caller, or the
     *         write failed. The caller must treat that as "nothing was
     *         recorded" and not perform the operation it was journalling for.
     */
    public static function attach( array $tx, array $op ) {
        $content           = self::of( $tx );
        $content['v']      = self::VERSION;
        $content['tx']     = isset( $tx['id'] ) ? (string) $tx['id'] : $content['tx'];
        $content['ops'][ $op['id'] ] = $op;
        ksort( $content['ops'] ); // stable order: recovery and inspection agree
        $content['sig'] = self::sign( $content );

        $next              = $tx;
        $next[ self::CONTENT_KEY ] = $content;
        return Transaction_Manager::cas_write_row( $tx, $next );
    }

    /**
     * Record that the mutation for $op_id is known to have happened.
     *
     * Separate from attach() because the id does not exist until
     * wp_insert_post() returns, and a crash in between leaves the record at
     * `intended` with nothing to go on. Recovery handles that state; this is
     * the fast path that avoids it.
     *
     * @return array The updated transaction row, so the caller can keep
     *         building on it. Null when the guard refused the write, which the
     *         caller must treat as a failure to record — the post exists and
     *         the journal does not know it, which is the exact state recovery
     *         is built for.
     */
    public static function mark_applied( array $tx, $op_id, $post_id, array $after = array(), $now = null ) {
        $content = self::of( $tx );
        if ( ! isset( $content['ops'][ $op_id ] ) ) {
            return null;
        }
        $now = ( null === $now ) ? Transaction_Manager::lease_now() : (int) $now;

        $content['ops'][ $op_id ]['state']      = self::STATE_APPLIED;
        $content['ops'][ $op_id ]['post_id']    = (int) $post_id;
        $content['ops'][ $op_id ]['updated_at'] = $now;
        if ( ! empty( $after ) ) {
            $content['ops'][ $op_id ]['after'] = array_merge(
                $content['ops'][ $op_id ]['after'],
                $after
            );
        }
        $content['sig'] = self::sign( $content );

        $next                     = $tx;
        $next[ self::CONTENT_KEY ] = $content;
        return Transaction_Manager::cas_write_row( $tx, $next ) ? $next : null;
    }

    // ── Integrity ──────────────────────────────────────────────────────────

    /**
     * HMAC over the journal's contents, or '' when there is no secret.
     *
     * Same secret and same failure mode as the preview credential: '' means
     * "cannot verify", and verify() reports that as a separate state rather
     * than collapsing it into either trust or distrust.
     */
    public static function sign( array $content ) {
        $secret = Transaction_Manager::preview_secret();
        if ( '' === $secret ) {
            return '';
        }
        $payload = $content;
        unset( $payload['sig'] );
        return hash_hmac( 'sha256', 'agentshell-journal|' . wp_json_encode( $payload ), $secret );
    }

    /**
     * Can this journal be trusted, and how much?
     *
     * Three states, not two, and the middle one matters:
     *
     *   - `ok`         a secret exists and the signature matches.
     *   - `unverified` no secret exists, so no signature could be computed.
     *                 Refusing outright here would make recovery permanently
     *                 unavailable on an install with neither
     *                 AGENTSHELL_REST_TOKEN nor AUTH_SALT, converting a
     *                 recoverable incident into a permanent outage. Every other
     *                 guard in Content_Recovery — the marker on the post, the
     *                 type/status/slug comparison, the modified-time check —
     *                 is independent of this signature, so compensation still
     *                 cannot delete anything it cannot identify. What is lost
     *                 is the ability to detect a row edited by hand.
     *   - `tampered`   a secret exists and the signature does not match. Hard
     *                 refusal: this is the only state that means "someone
     *                 changed this", and it is evidence.
     */
    public static function verify( array $content ) {
        $stored = isset( $content['sig'] ) ? (string) $content['sig'] : '';
        if ( '' === $stored ) {
            return array(
                'status' => 'unverified',
                'reason' => 'This journal carries no signature. It was written on an install with neither '
                    . 'AGENTSHELL_REST_TOKEN nor AUTH_SALT defined, so there was no secret to sign it with. '
                    . 'Recovery will not claim it is intact; it will verify each post individually instead.',
            );
        }
        $expected = self::sign( $content );
        if ( '' === $expected ) {
            return array(
                'status' => 'unverified',
                'reason' => 'No signing secret is defined on this install, so the stored signature cannot be checked.',
            );
        }
        if ( ! hash_equals( $expected, $stored ) ) {
            return array(
                'status' => 'tampered',
                'reason' => 'The journal\'s signature does not match its contents, so it has been changed outside '
                    . 'AgentShell. Nothing has been deleted and nothing will be.',
            );
        }
        return array( 'status' => 'ok', 'reason' => '' );
    }

    // ── Validation ─────────────────────────────────────────────────────────

    /**
     * Structural problems with a journal, as a list of strings.
     *
     * Empty means well-formed. Any entry means recovery refuses the whole
     * journal: a partial journal is not a partial permission. If record three
     * of five is unreadable, the answer to "may I delete these five things"
     * is no.
     *
     * Checks that matter for safety, and the ones that would otherwise be
     * silently assumed:
     *   - the operation type is one this build knows how to compensate;
     *   - the state is a real state;
     *   - the fields that make a post identifiable are present and of the
     *     right type;
     *   - the row's transaction id matches the journal's, so a journal cannot
     *     be lifted from one transaction into another.
     */
    public static function validate( array $content, $tx = null ) {
        $problems = array();

        if ( ! isset( $content['ops'] ) || ! is_array( $content['ops'] ) ) {
            $problems[] = 'the journal has no operations array';
            return $problems;
        }
        if ( (int) ( $content['v'] ?? 0 ) > self::VERSION ) {
            $problems[] = 'the journal is schema version ' . (int) $content['v']
                . ' and this build understands at most ' . self::VERSION . '. Refusing to interpret it.';
        }
        if ( is_array( $tx ) && isset( $tx['id'] ) && ! empty( $content['tx'] ) && (string) $tx['id'] !== (string) $content['tx'] ) {
            $problems[] = 'the journal claims transaction ' . $content['tx'] . ' but sits in row ' . $tx['id']
                . '. A journal is only evidence for the transaction that wrote it.';
        }

        foreach ( $content['ops'] as $id => $op ) {
            $label = 'operation ' . ( is_scalar( $id ) ? (string) $id : '(unkeyed)' );

            if ( ! is_array( $op ) ) {
                $problems[] = $label . ' is not a record';
                continue;
            }
            $label = 'operation ' . (string) ( $op['id'] ?? $id );

            foreach ( self::required_fields() as $field => $type ) {
                if ( ! array_key_exists( $field, $op ) ) {
                    $problems[] = $label . ' is missing ' . $field;
                    continue;
                }
                if ( ! self::field_is( $op[ $field ], $type ) ) {
                    $problems[] = $label . ' has a ' . $field . ' of the wrong type (expected ' . $type . ')';
                }
            }
            if ( isset( $op['id'] ) && (string) $op['id'] !== (string) $id ) {
                $problems[] = $label . ' is filed under key ' . $id;
            }
            if ( ! isset( $op['op'] ) || ! in_array( $op['op'], self::SUPPORTED_OPERATIONS, true ) ) {
                $problems[] = $label . ' has operation type "' . (string) ( $op['op'] ?? '(none)' )
                    . '", which this build has no compensation contract for. Supported: '
                    . implode( ', ', self::SUPPORTED_OPERATIONS ) . '.';
            }
            if ( isset( $op['state'] ) && ! in_array( $op['state'], self::STATES, true ) ) {
                $problems[] = $label . ' has state "' . (string) $op['state'] . '", which is not a state';
            }
            // Common target requirements: every op names the post it is about.
            foreach ( array( 'post_type', 'post_status' ) as $needed ) {
                if ( ! isset( $op['target'][ $needed ] ) || '' === $op['target'][ $needed ] ) {
                    $problems[] = $label . ' has no target.' . $needed . ' to identify its post with';
                }
            }

            // The expected after-image is not optional decoration. It is what
            // compensation checks the real post against before acting on it,
            // and post_name + op_meta_value are what let an operation whose id
            // was never recorded be found at all. A record missing them cannot
            // be safely resolved, and an unresolvable record must be refused —
            // never guessed at.
            foreach ( array( 'post_type', 'post_status', 'post_name', 'op_meta_value' ) as $needed ) {
                if ( ! isset( $op['after'][ $needed ] ) || '' === $op['after'][ $needed ] ) {
                    $problems[] = $label . ' has no after.' . $needed . ' to identify its post with';
                }
            }

            // Per-op-type structural requirements. create_draft is what it has
            // always been; update_draft carries a `before` and a stronger
            // after-image because compensation applies the before via
            // `wp_update_post` and must verify the live post first.
            $op_type = isset( $op['op'] ) ? (string) $op['op'] : '';
            if ( self::OP_UPDATE_DRAFT === $op_type ) {
                $problems = array_merge( $problems, self::validate_update_draft( $op, $label ) );
            }
        }

        return $problems;
    }

    /**
     * Structural checks specific to OP_UPDATE_DRAFT.
     *
     * The before-image is the load-bearing piece: it is what makes the
     * inverse operation a `wp_update_post` rather than a guess. The
     * after-image must carry the strong evidence `mark_applied` records for
     * create (content hash, length, modified time), because OCC compares the
     * live post against it before applying the before-image. target.post_id
     * is what the new tool wrote at intent time and is what
     * `verify_post()` will check.
     *
     * @param array  $op    The op record.
     * @param string $label Human-readable label for problem strings.
     * @return array<int,string> Per-op problems; empty when well-formed.
     */
    private static function validate_update_draft( array $op, $label ) {
        $problems = array();
        if ( ! is_array( $op['before'] ?? null ) ) {
            $problems[] = $label . ' has no before-image; an update without a prior state is not compensable.';
            return $problems;
        }
        if ( empty( $op['target']['post_id'] ) ) {
            $problems[] = $label . ' has no target.post_id; compensation cannot find the post to restore.';
        }
        $before_required = array(
            'post_title',
            'post_content',
            'post_name',
            'post_content_sha256',
            'post_content_length',
            'post_modified_gmt',
            'op_meta_value',
        );
        foreach ( $before_required as $needed ) {
            if ( ! array_key_exists( $needed, $op['before'] ) ) {
                $problems[] = $label . ' has no before.' . $needed . '; the inverse operation would be a guess.';
            }
        }
        $after_required = array(
            'post_content_sha256',
            'post_content_length',
            'post_modified_gmt',
        );
        foreach ( $after_required as $needed ) {
            if ( ! array_key_exists( $needed, $op['after'] ) ) {
                $problems[] = $label . ' has no after.' . $needed . '; the OCC check cannot be performed.';
            }
        }
        return $problems;
    }

    private static function field_is( $value, $type ) {
        switch ( $type ) {
            case 'int':
                return is_int( $value ) || ( is_numeric( $value ) && (string) (int) $value === (string) $value );
            case 'null_or_array':
                return null === $value || is_array( $value );
            default:
                // 'string', 'array' — and strictly so. A scalar arriving where a
                // string was declared is a record something else wrote.
                return gettype( $value ) === $type;
        }
    }

    // ── Inspection ─────────────────────────────────────────────────────────

    /**
     * A read-only description of a journal.
     *
     * Read-only in the load-bearing sense: it takes the row it is given and
     * returns a description. It does not settle the lease, does not reclaim,
     * does not renew, and does not write. Inspection is available to an actor
     * who could not resolve anything, because the whole point of being able to
     * look is that looking is free of consequences.
     *
     * Authority is checked by the caller (the MCP tool), and the result
     * passed in via `$allowed_to_see`. The function itself does not know
     * who is asking: that decoupling lets the audit log record one row per
     * successful inspection (a write the journal does not make) and lets
     * an unauthorised inspection return the same shape a missing row would
     * — the existence of somebody else's transaction is not disclosed.
     *
     * @param array|null $tx             Null to read the stored row.
     * @param bool       $allowed_to_see False returns the no-row shape,
     *                                   regardless of whether a row exists.
     */
    public static function inspect( $tx = null, $allowed_to_see = true ) {
        if ( ! $allowed_to_see ) {
            return array(
                'transaction'    => null,
                'journal'        => null,
                'recoverable'    => false,
                'reason'         => 'No transaction is open.',
            );
        }
        if ( null === $tx ) {
            $tx = Transaction_Manager::instance()->payload();
        }
        if ( ! is_array( $tx ) ) {
            return array(
                'transaction'    => null,
                'journal'        => null,
                'recoverable'    => false,
                'reason'         => 'No transaction is open.',
            );
        }

        $content   = self::of( $tx );
        $integrity = self::verify( $content );
        $problems  = self::validate( $content, $tx );
        $ops       = array();

        foreach ( $content['ops'] as $id => $op ) {
            $ops[] = array(
                'id'          => (string) $id,
                'operation'   => (string) ( $op['op'] ?? '' ),
                'state'       => (string) ( $op['state'] ?? '' ),
                'supported'   => in_array( (string) ( $op['op'] ?? '' ), self::SUPPORTED_OPERATIONS, true ),
                'actor'       => (string) ( $op['actor'] ?? '' ),
                'recorded_at' => (int) ( $op['recorded_at'] ?? 0 ),
                'updated_at'  => (int) ( $op['updated_at'] ?? 0 ),
                'target'      => $op['target'] ?? null,
                'post_id'     => (int) ( $op['post_id'] ?? 0 ),
                'claim'       => $op['claim'] ?? null,
                'compensation'=> $op['comp'] ?? null,
                'notes'       => array_values( (array) ( $op['notes'] ?? array() ) ),
            );
        }

        $outstanding = self::has_outstanding_work( $content );

        return array(
            'transaction'    => Transaction_Manager::describe( $tx ),
            'schema_version' => (int) $content['v'],
            'operation_count'=> count( $content['ops'] ),
            'operations'     => $ops,
            'outstanding'    => $outstanding,
            'integrity'      => $integrity['status'],
            'integrity_note' => $integrity['reason'],
            'problems'       => $problems,
            'read_only'      => true,
            'supported_operations' => self::SUPPORTED_OPERATIONS,
            'recoverable'    => ( $outstanding && empty( $problems ) && 'tampered' !== $integrity['status'] ),
            'reason'         => self::recoverability_reason( $tx, $content, $problems, $integrity, $outstanding ),
        );
    }

    /**
     * May this actor inspect this transaction's journal? Returns a role, or ''.
     *
     * Two ways to be allowed, and they are not the same:
     *
     *   - `owner` — the transaction row's own `actor`, *and* a current
     *     `manage_options` capability. The capability is what re-checks
     *     `brief B`: an actor whose role was revoked since the transaction
     *     opened can no longer read the row, even though the actor field
     *     still matches. A read is a write to the audit log, and the audit
     *     log names who read what.
     *   - `recovery_administrator` — somebody else, with `manage_options`
     *     *and* an explicit opt-in argument on the call. The opt-in is what
     *     makes the act deliberate rather than a side effect of a session
     *     that happened to hold the right cookie.
     *
     * A refusal returns '' and is indistinguishable from "no row" in the
     * tool's response: the existence of another actor's row is not disclosed.
     */
    public static function authorize_inspection( $tx, $actor, $explicitly_authorized = false ) {
        if ( ! is_array( $tx ) ) {
            // No row: nothing to authorise. Returning '' rather than a role
            // tells the caller to short-circuit to the no-row shape.
            return '';
        }
        $owner = isset( $tx['actor'] ) ? (string) $tx['actor'] : '';
        if ( '' !== $owner
            && $owner === (string) $actor
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' ) ) {
            return 'owner';
        }
        if ( $explicitly_authorized
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' ) ) {
            return 'recovery_administrator';
        }
        return '';
    }

    private static function recoverability_reason( array $tx, array $content, array $problems, array $integrity, $outstanding ) {
        if ( empty( $content['ops'] ) ) {
            return 'This transaction has no content journal.';
        }
        if ( ! Transaction_Manager::is_expired( $tx ) ) {
            return 'This transaction has not expired. Its owner should commit or roll it back — recovery is for '
                . 'transactions whose lease has run out.';
        }
        if ( $problems ) {
            return 'The journal is not readable, so nothing can be safely done with it: ' . implode( ' ', $problems );
        }
        if ( 'tampered' === $integrity['status'] ) {
            return $integrity['reason'];
        }
        if ( ! $outstanding ) {
            return 'Every journalled operation is already settled. This row is only holding the slot; '
                . 'agentshell_recover_transaction will delete it and nothing else.';
        }
        return '';
    }
}