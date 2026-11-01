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
     * Stage 2a (revised): the journal is a chain of authorised state
     * transitions, not a log of independent actions. The head of the
     * chain — the latest applied after-image — is the expected current
     * state. Compensation does NOT revert individual updates with
     * `wp_update_post()`; it verifies the live post against the head and
     * deletes the post once. Pre-existing-draft updates remain Stage 2b.
     *
     * Each update's `before` is preserved as the historical evidence of
     * what was at that point, and the OCC chain (pre-check before
     * applying, atomic head advancement after applying) catches an
     * external edit at the next authorised update rather than at rollback
     * time. The contract is narrower than update_content's (no status,
     * no parent, no taxonomy; the post is required to be a draft the
     * current transaction owns).
     */
    const OP_UPDATE_DRAFT = 'update_draft';

    /**
     * Edit a draft that existed before the current transaction opened.
     *
     * Stage 2b. The journal gains a way to claim a pre-existing draft so
     * the OCC chain and the recoverable lifecycle can apply to it without
     * the user having to delete and recreate the post. The op is recorded
     * by `agentshell_claim_existing_draft`, which captures the live
     * post's strong after-image as the immutable `before` baseline (the
     * pre-image is what rollback restores). The op's `state` is
     * `claimed`, a new state between `intended` and `applied`: the
     * transaction now owns the post, but no edit has run yet, so the head
     * map is empty for this post and the first edit's head CAS
     * legitimately lands with `expected_prev === null`.
     *
     * Each subsequent edit runs through `agentshell_update_draft` against
     * this op, which the ownership check matches by marker. The op's
     * `state` transitions `claimed` → `applied` on the first edit; the
     * strong after-image is the head pointer for the next op, and
     * rollback restores from the immutable `before` (the supported
     * fields: post_title, post_content, post_excerpt where present,
     * post_name; `post_modified_gmt` is excluded so re-applying it does
     * not falsify the OCC chain for any subsequent op).
     *
     * Live visibility is not an isolation guarantee: pre-existing drafts
     * are visible to authorised WordPress users and plugins while the
     * transaction is open. Every edit's OCC pre-check verifies the live
     * post against the head; compensation re-checks against the head and
     * the baseline; commit revalidation re-checks the head against the
     * live post. External edits, publishes, and deletes are caught and
     * refused or routed to `conflict`.
     */
    const OP_EDIT_EXISTING_DRAFT = 'edit_existing_draft';

    /**
     * Stage 2c. The first op that touches a *published* post.
     *
     * The four supported fields are the only ones this op may
     * change: `post_title`, `post_content`, `post_excerpt`,
     * `post_name`. Everything else (post_status, post_parent,
     * post_author, post_password, post_mime_type, post_date,
     * comment_status, ping_status, menu_order,
     * post_content_filtered, meta_input, taxonomy, featured
     * image, custom fields, navigation menus) is refused by
     * the validator. The narrow scope is deliberate: a wider
     * surface area would have to define inverse operations for
     * each field, and that is the next stage's problem, not
     * this one's.
     *
     * The op's `before` is the **strong immutable baseline** of
     * the *original* post at the moment the claim was taken —
     * the post's type, status, name, title, content, excerpt,
     * `post_date_gmt`, `post_modified_gmt`, and a SHA-256 of
     * `post_content` plus a content length. This is what
     * `agentshell_publish_edit`'s write-and-verify-C check
     * compares against to detect an external editor's
     * intervening update. It is *not* the head of an OCC
     * chain; the original is not under transaction control
     * (the staging happens on a private shadow), so there is
     * no OCC chain. There is one baseline, captured at
     * claim time, and one final compare against it at
     * publish time.
     *
     * The op's `after` carries the staged values the shadow
     * holds at the moment of the last `edit_published` call.
     * Each successive authorised edit re-writes the
     * shadow's four fields and re-records the op's `after`
     * in the same guarded write.
     *
     * The op's state machine is the eight-state model from
     * §1.7: `staged` (entry), `publication_in_progress`
     * (transient in-memory), `publication_committed` (atomic
     * with the wp_update_post in §1.4 step 4),
     * `reconciliation_pending`, `publication_settled`,
     * `compensated`, `conflict`, `abandoned`. The recovery
     * engine distinguishes `publication_committed` (the
     * publication has landed) from any other state (it has
     * not).
     *
     * Distinct from `OP_UPDATE_DRAFT` because (a) the
     * before-image is the *original*'s state, not the
     * *shadow*'s, and conflating them would force
     * `verify_post` to branch on a flag, and (b) the
     * compensation contract is "publish to the database,
     * not delete a draft".
     */
    const OP_EDIT_PUBLISHED = 'edit_published';

    /**
     * The four fields an OP_EDIT_PUBLISHED may stage.
     *
     * Used by the validator to refuse any other field at
     * attach time, and by the publish tool's
     * write-and-verify-C step to know which fields to
     * write via `wp_update_post`. Listed in the order
     * the publish tool passes them to `wp_update_post`,
     * which is the order WP's meta cache and term cache
     * see them.
     */
    const EDIT_PUBLISHED_FIELDS = array(
        'post_title',
        'post_content',
        'post_excerpt',
        'post_name',
    );

    /**
     * Operation types this build is willing to compensate.
     *
     * Deliberately four entries. Every other type the design document
     * discusses is recorded nowhere and compensated by nothing, and
     * `Content_Recovery` refuses an operation that is not in this list
     * rather than guessing at an inverse. Adding a row here is the commit
     * that makes that operation recoverable, and it must bring its own
     * before-image contract.
     */
    const SUPPORTED_OPERATIONS = array(
        self::OP_CREATE_DRAFT,
        self::OP_UPDATE_DRAFT,
        self::OP_EDIT_EXISTING_DRAFT,
        self::OP_EDIT_PUBLISHED,
    );

    /** The journal's key inside $tx['content']. */
    const CONTENT_KEY = 'content';

    /** Where operations live inside the journal. */
    const OPS_KEY = 'ops';

    /**
     * Where the per-post head after-image lives inside the journal.
     *
     * For each post the journal owns (one entry per `post_id` that has
     * been created or updated by an applied op), `$tx['content']['head'][$post_id]`
     * is the after-image of the most recently applied op. It is the
     * expected current state. Compensation verifies the live post
     * against this map; it never reverts via `wp_update_post()`.
     */
    const HEAD_KEY = 'head';

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
    /**
     * Stage 2b. The op is for a pre-existing draft whose marker now
     * matches this transaction, but no edit has run yet. The head map
     * for the post is empty; the first edit's head CAS legitimately
     * lands with `expected_prev === null` (the previous contract's
     * unreachable first-op branch). The state transitions to
     * `applied` on the first successful `mark_applied()`. The op is
     * treated as unsettled for the close gates — `commit` and
     * `rollback` both refuse on a journal that carries only a
     * `claimed` op with no follow-on `applied` op, because
     * abandoning the claim would leave the post carrying a journal
     * marker the transaction no longer owns.
     */
    const STATE_CLAIMED = 'claimed';

    // ── Stage 2c OP_EDIT_PUBLISHED states ─────────────────────────────────
    //
    // The eight-state model from docs/STAGE-2C-DESIGN.md §1.7. The first
    // five are durable states recorded on the journal row; the
    // `publication_in_progress` state is an in-memory marker that lives
    // only for the lifetime of the §1.4 SQL transaction. Recovery never
    // sees `publication_in_progress` — a crash closes the connection,
    // InnoDB ROLLBACKs, the marker disappears, and the recovery engine
    // sees `staged` (unpublished) or `publication_committed` (published).
    //
    // The §1.7 state machine exists so the recovery engine can
    // distinguish a committed publication from unpublished staging
    // without trusting anything but the journal's own state field.

    /** Entry state: shadow written, claim held, original untouched. */
    const STATE_STAGED = 'staged';

    /**
     * Transient in-memory marker. The §1.4 SQL transaction is
     * open. Not a durable state — never read by recovery. Listed
     * here so `validate()` permits a record written mid-publish
     * to be inspected while the transaction is still in flight.
     */
    const STATE_PUBLICATION_IN_PROGRESS = 'publication_in_progress';

    /**
     * The §1.4 SQL transaction COMMITted; the original is
     * published; the shadow still exists; reconciliation is owed.
     * Written by COMMIT, not before; a ROLLBACK rolls it back.
     */
    const STATE_PUBLICATION_COMMITTED = 'publication_committed';

    /**
     * One or more of the three reconciliation steps (shadow
     * delete, cache invalidation, audit) failed. The op is in
     * this state until a retry succeeds.
     */
    const STATE_RECONCILIATION_PENDING = 'reconciliation_pending';

    /** All three reconciliation steps completed; the op is fully done. */
    const STATE_PUBLICATION_SETTLED = 'publication_settled';

    /** The transaction's lease expired without commit or rollback. */
    const STATE_ABANDONED = 'abandoned';

    const STATES = array(
        self::STATE_INTENDED,
        self::STATE_APPLIED,
        self::STATE_COMPENSATING,
        self::STATE_COMPENSATED,
        self::STATE_CONFLICT,
        self::STATE_PARTIAL_APPLIED,
        self::STATE_CLAIMED,
        self::STATE_STAGED,
        self::STATE_PUBLICATION_IN_PROGRESS,
        self::STATE_PUBLICATION_COMMITTED,
        self::STATE_RECONCILIATION_PENDING,
        self::STATE_PUBLICATION_SETTLED,
        self::STATE_ABANDONED,
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
     *
     * For OP_EDIT_PUBLISHED, the terminal state is `publication_settled`
     * (the publication committed and all three reconciliation steps
     * verified), not `compensated` — there is no post to delete, only
     * a shadow that should already be gone. Including it here makes
     * the close-gate logic treat a settled edit as settled, the same
     * way it treats a compensated create op.
     */
    const SETTLED_STATES = array(
        self::STATE_COMPENSATED,
        self::STATE_PUBLICATION_SETTLED,
    );

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

    /**
     * The expected current state of a post this transaction owns.
     *
     * Returns the after-image of the most recently applied op for
     * `$post_id`, or null when the transaction has no head for that
     * post (the post was never created, or its journal is from an older
     * build that did not maintain the head map).
     *
     * This is what compensation verifies the live post against, and
     * what the next update op pre-checks against before applying. The
     * historical `after` fields on each op are preserved for audit and
     * for backwards compatibility; they are not what compensation
     * compares against.
     */
    public static function head_after( $tx, $post_id ) {
        $post_id = (int) $post_id;
        $content = self::of( $tx );
        $head    = is_array( $content[ self::HEAD_KEY ] ?? null ) ? $content[ self::HEAD_KEY ] : array();
        return is_array( $head[ $post_id ] ?? null ) ? $head[ $post_id ] : null;
    }

    /**
     * Atomically advance the head for a post, conditional on the
     * current head matching `$expected_prev`.
     *
     * Used by Update_Draft to refuse a concurrent update that has
     * already advanced the head. The CAS is the value-guarded one
     * `cas_write_row` provides: a row that moved between the read and
     * the write is detected, and the function returns false without
     * overwriting the new head.
     *
     * @param array       $tx             The pre-write transaction row.
     * @param int         $post_id        Post whose head to advance.
     * @param array|null  $expected_prev  The head the caller verified
     *                                    the live post against. Pass
     *                                    null when no head existed
     *                                    (this is the first op for the
     *                                    post).
     * @param array       $new_after      The new after-image.
     * @return array|false The updated row, or false on CAS failure.
     */
    public static function compare_and_set_head( array $tx, $post_id, $expected_prev, array $new_after ) {
        $content = self::of( $tx );
        $head    = is_array( $content[ self::HEAD_KEY ] ?? null ) ? $content[ self::HEAD_KEY ] : array();
        $current = $head[ (int) $post_id ] ?? null;

        if ( null === $expected_prev ) {
            if ( null !== $current ) {
                return false;
            }
        } else {
            if ( ! is_array( $current ) ) {
                return false;
            }
            // Compare by the strong evidence, not by byte-equality: a
            // head written by an older build (no sha256 / length) should
            // still be comparable to a head written by this build.
            $expected_prev = (array) $expected_prev;
            $current       = (array) $current;
            foreach ( array( 'post_type', 'post_status', 'post_name' ) as $field ) {
                $a = isset( $expected_prev[ $field ] ) ? (string) $expected_prev[ $field ] : '';
                $b = isset( $current[ $field ] ) ? (string) $current[ $field ] : '';
                if ( $a !== $b ) {
                    return false;
                }
            }
            $a = (string) ( $expected_prev['post_content_sha256'] ?? '' );
            $b = (string) ( $current['post_content_sha256'] ?? '' );
            if ( '' !== $a && '' !== $b && $a !== $b ) {
                return false;
            }
        }

        $head[ (int) $post_id ] = $new_after;
        $content[ self::HEAD_KEY ] = $head;
        $content['sig']           = self::sign( $content );

        $next = $tx;
        $next[ self::CONTENT_KEY ] = $content;
        return Transaction_Manager::cas_write_row( $tx, $next ) ? $next : false;
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
     * Settled states, plus the explicit non-settled states we count.
     *
     * `claimed` is a non-settled state for the close gates: a journal
     * that holds a `claimed` op with no follow-on `applied` op still
     * owns the post (the post carries the marker), and abandoning the
     * claim would silently leave the post carrying a marker the
     * transaction no longer owns. The same logic that makes
     * `compensated` settled and `conflict` unsettled applies here:
     * the operator must run an update (which transitions the op to
     * `applied`) or run recovery (which rolls the claim back), and
     * the row is the durable evidence either way.
     */
    public static function is_settled_state( $state ) {
        return in_array( (string) $state, self::SETTLED_STATES, true );
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
            self::STATE_CLAIMED,
            // OP_EDIT_PUBLISHED in flight: the shadow holds the
            // staged values, the claim holds the original; the
            // op is "in progress" the way STATE_INTENDED is for
            // a create or update. Closing the transaction
            // without processing it would leave the shadow and
            // the claim with no owner.
            self::STATE_STAGED,
            // OP_EDIT_PUBLISHED with the publication committed
            // but the reconciliation tail unfinished. The
            // original carries the staged fields; the shadow
            // and claim remain. Closing the transaction
            // without processing it would leave the
            // reconciliation owed forever (or until a recovery
            // finds the journal, which is its own race).
            self::STATE_PUBLICATION_COMMITTED,
            self::STATE_RECONCILIATION_PENDING,
            // The transaction's lease has expired. The
            // recovery engine owns the op; closing the
            // transaction row without settling it would lose
            // the reference to the original.
            self::STATE_ABANDONED,
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
            self::STATE_CLAIMED,
            // OP_EDIT_PUBLISHED live states — see
            // has_unsettled_work() for the rationale. The
            // rollback gate's auto-recovery path needs to
            // process these so a transaction that holds an
            // OP_EDIT_PUBLISHED op is not left with the
            // shadow and the claim dangling when the
            // operator rolls back.
            self::STATE_STAGED,
            self::STATE_PUBLICATION_COMMITTED,
            self::STATE_RECONCILIATION_PENDING,
            self::STATE_ABANDONED,
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

        // OP_EDIT_PUBLISHED enters in the `staged` state, not
        // `intended`. The §1.7 state machine gives the op a
        // dedicated entry point: the shadow is written, the claim
        // is held, the original is untouched, and the op is
        // durably recorded. `intended` is the *creation* entry
        // state for create_draft / update_draft /
        // edit_existing_draft, where the post is not yet on disk.
        $entry_state = ( self::OP_EDIT_PUBLISHED === $op )
            ? self::STATE_STAGED
            : self::STATE_INTENDED;

        return array(
            'id'          => $id,
            'op'          => (string) $op,
            'v'           => self::VERSION,
            'state'       => $entry_state,
            'actor'       => null === $actor ? Store::actor() : (string) $actor,
            'recorded_at' => $now,
            'updated_at'  => $now,
            'target'      => $target,
            // A creation has no prior state to record. Stated explicitly rather
            // than left as an empty array so that a reader cannot mistake an
            // absent pre-image for one that was lost. OP_EDIT_PUBLISHED
            // overwrites this with the strong immutable baseline (see
            // capture_edit_published_baseline()).
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
     * Capture the strong immutable baseline (baseline-A) of the original
     * for an OP_EDIT_PUBLISHED op.
     *
     * Read the live original post and record its state as the
     * `before` field of the op. The fields captured are exactly
     * those the publish tool's write-and-verify-C step
     * compares against, plus the identity fields that let
     * recovery locate the post again:
     *
     *   - `post_type` — the original's post type. Recovery
     *     refuses if a successor transaction's post type
     *     differs.
     *   - `post_status` — the original's status at capture
     *     time. A draft the operator later publishes out of
     *     band is detected by the publish revalidation.
     *   - `post_title` — the original's title at capture
     *     time.
     *   - `post_content` — the original's content at
     *     capture time. The publish step re-hashes this
     *     and compares against `post_content_sha256`.
     *   - `post_excerpt` — the original's excerpt at
     *     capture time. The publish step re-reads this.
     *   - `post_name` — the original's slug at capture
     *     time. The publish step re-reads this.
     *   - `post_date_gmt` — the original's post date in
     *     GMT. Acts as the creation-time anchor that an
     *     edit cannot silently change.
     *   - `post_modified_gmt` — the original's modified
     *     time in GMT. Fast pre-check; not the strong
     *     evidence (a same-second edit leaves it
     *     unchanged).
     *   - `post_content_sha256` — SHA-256 of the original's
     *     `post_content` at capture time. The strong
     *     evidence.
     *   - `post_content_length` — sanity check that the
     *     post the publish step is hashing is the one
     *     the journal hashed.
     *   - `op_meta_value` — the op's marker id. The
     *     baseline carries the op id (not a separate
     *     shadow marker) so a record-keeping reader can
     *     match the op to its baseline by equality.
     *
     * Returns the captured baseline, or throws if the original
     * cannot be loaded (e.g. it was deleted between the
     * caller's id check and this capture).
     *
     * @param int $original_id The original post id.
     * @return array The baseline image.
     */
    public static function capture_edit_published_baseline( $original_id, $now = null ) {
        $original_id = (int) $original_id;
        if ( $original_id < 1 ) {
            throw new \InvalidArgumentException(
                'capture_edit_published_baseline requires a positive original id; got ' . $original_id . '.'
            );
        }
        $post = get_post( $original_id );
        if ( ! $post ) {
            throw new \RuntimeException(
                'capture_edit_published_baseline: the original post (id ' . $original_id
                . ') could not be loaded. The post may have been deleted between the caller\'s '
                . 'id check and the baseline capture.'
            );
        }
        $now = ( null === $now ) ? Transaction_Manager::lease_now() : (int) $now;
        $id  = self::new_op_id();
        // The baseline A captures every field the publish-edit
        // tool's post-write allowlist can reclassify as a
        // supported-, protected-preserved-, or housekeeping-field
        // divergence. Capturing the protected-preserved fields
        // here is what makes the post-write check able to refuse
        // a hook-induced change to a field the publish-edit
        // tool did not write to: without the baseline value,
        // expected_c() falls back to the post-write value and
        // the divergence is invisible.
        $baseline = array(
            'post_type'            => (string) $post->post_type,
            'post_status'          => (string) $post->post_status,
            'post_title'           => (string) $post->post_title,
            'post_content'         => (string) $post->post_content,
            'post_excerpt'         => (string) $post->post_excerpt,
            'post_name'            => (string) $post->post_name,
            'post_date'            => (string) $post->post_date,
            'post_date_gmt'        => (string) $post->post_date_gmt,
            'post_modified_gmt'    => (string) $post->post_modified_gmt,
            'post_author'          => (string) ( isset( $post->post_author ) ? (int) $post->post_author : 0 ),
            'post_parent'          => (string) ( isset( $post->post_parent ) ? (int) $post->post_parent : 0 ),
            'post_mime_type'       => (string) ( $post->post_mime_type ?? '' ),
            'comment_status'       => (string) ( $post->comment_status ?? '' ),
            'ping_status'          => (string) ( $post->ping_status ?? '' ),
            'menu_order'           => (string) ( isset( $post->menu_order ) ? (int) $post->menu_order : 0 ),
            'post_password'        => (string) ( $post->post_password ?? '' ),
            'post_content_filtered'=> (string) ( $post->post_content_filtered ?? '' ),
            'comment_count'        => (string) ( isset( $post->comment_count ) ? (int) $post->comment_count : 0 ),
            'to_ping'              => (string) ( $post->to_ping ?? '' ),
            'pinged'               => (string) ( $post->pinged ?? '' ),
            'guid'                 => (string) ( $post->guid ?? '' ),
            'post_content_sha256'  => hash( 'sha256', (string) $post->post_content ),
            'post_content_length'  => strlen( (string) $post->post_content ),
            'op_meta_value'        => $id,
        );
        return $baseline;
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
     * Documented boundary (S-5, Stage 2c M5 audit): the CAS write in this
     * method does NOT consult `self::verify()`. The integrity gate is
     * downstream at the destructive boundary
     * (`class-content-recovery.php::compensate()`'s `verify_post` call),
     * not at every CAS. This is the layered-gating contract: CAS provides
     * atomicity, verify provides integrity at destruction. Verifying on
     * every read would make the journal a single point of contention and
     * would force every tool call to validate the whole `content` blob.
     * The destructive engine reads, verifies, and acts — the read path
     * reads and trusts, because the read path cannot destroy.
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
     * The head map is NOT advanced here. Head advancement is the exclusive
     * responsibility of compare_and_set_head(), which the tool calls BEFORE
     * mark_applied() (sharing one Content_Write_Guard::run() closure with the
     * wp_update_post / wp_insert_post call). That ordering means the head CAS
     * is the load-bearing step that catches a concurrent update winning the
     * race: when compare_and_set_head() returns false, the closure throws
     * and Content_Write_Guard rolls back the post write. mark_applied()
     * only runs on a successful head CAS, so it never advances the head for
     * an op whose post write has been rolled back. It also means the
     * "expected_prev === null" branch of compare_and_set_head() is reachable
     * for the first edit of a pre-existing draft (the Stage 2b path) and the
     * head pointer is updated atomically with the post write, not after.
     *
     * @param array $tx     The post-CAS transaction row returned by
     *                      compare_and_set_head() (or, for a create where
     *                      there is no prior head, the row from attach()).
     *                      The caller is responsible for using the latest
     *                      row bytes; passing an obsolete row will fail
     *                      the CAS and return null.
     * @return array|null   The updated transaction row, or null when the
     *                      guard refused the write. The caller treats null
     *                      as a failure to record — the post exists and
     *                      the journal does not know it, which is the state
     *                      recovery is built for.
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
        // NOTE: the head map is no longer written here. The tool that
        // produced this op is responsible for advancing the head via
        // compare_and_set_head() before calling mark_applied(); the two
        // CAS writes share one Content_Write_Guard::run() SQL transaction
        // with the post mutation. See the class docstring and the
        // mark_applied() rationale above for the full contract.

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
            foreach ( array( 'post_type', 'post_status', 'op_meta_value' ) as $needed ) {
                if ( ! isset( $op['after'][ $needed ] ) || '' === $op['after'][ $needed ] ) {
                    $problems[] = $label . ' has no after.' . $needed . ' to identify its post with';
                }
            }

            // `post_name` is the one field of that set that a WordPress post
            // may legitimately not have: a *draft* has no slug. WordPress
            // names a post when it is published, so every draft in the
            // database has an empty post_name — and a validator that
            // demanded a non-empty one refused the whole journal for a
            // perfectly ordinary draft. The refusal was not a near miss:
            // the recovery engine could not interpret the journal, so a
            // rollback could not compensate the draft, and the draft
            // survived the rollback with the record of the debt discarded.
            //
            // An empty name is therefore allowed only when the operation
            // identifies its post some other way — by its id, and by the
            // marker the post carries. A *missing* field is still a
            // structural fault and is still refused: a record that was never
            // built correctly must not be interpreted, whatever else it
            // carries. And the comparison itself stays exact: the verifier
            // skips an empty recorded field, so a draft that was published
            // in the meantime (and therefore has a name now) is a
            // divergence, and still a conflict.
            if ( ! array_key_exists( 'post_name', (array) ( $op['after'] ?? array() ) ) ) {
                $problems[] = $label . ' has no after.post_name to identify its post with';
            } elseif ( '' === (string) $op['after']['post_name'] ) {
                $identifies_by_id = ! empty( $op['post_id'] )
                    || ! empty( $op['target']['post_id'] );
                if ( ! $identifies_by_id ) {
                    $problems[] = $label . ' has an empty after.post_name and no post id to identify '
                        . 'its post with';
                }
            }

            // Per-op-type structural requirements. create_draft is what it has
            // always been; update_draft carries a `before` and a stronger
            // after-image because compensation applies the before via
            // `wp_update_post` and must verify the live post first;
            // edit_existing_draft carries the *immutable baseline* in
            // `before` and the post's state at first touch in `after`;
            // edit_published carries the *strong immutable baseline* of
            // the original (the post the op is about to publish against)
            // in `before`, and the staged values the shadow holds in
            // `after`.
            $op_type = isset( $op['op'] ) ? (string) $op['op'] : '';
            if ( self::OP_UPDATE_DRAFT === $op_type ) {
                $problems = array_merge( $problems, self::validate_update_draft( $op, $label ) );
            } elseif ( self::OP_EDIT_EXISTING_DRAFT === $op_type ) {
                $problems = array_merge( $problems, self::validate_edit_existing_draft( $op, $label ) );
            } elseif ( self::OP_EDIT_PUBLISHED === $op_type ) {
                $problems = array_merge( $problems, self::validate_op_edit_published( $op, $label ) );
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

    /**
     * Structural checks specific to OP_EDIT_EXISTING_DRAFT.
     *
     * The `before` is the immutable baseline captured at first touch:
     * the post's state the moment the claim was recorded. Rollback
     * restores from it. Every supported field the inverse path
     * (post_title, post_content, post_excerpt where present, post_name)
     * must be present so a `wp_update_post` can re-apply them. The
     * `after` carries the post's identification fields
     * (post_type, post_status, post_name, op_meta_value) at first touch;
     * the OCC chain does not start until the first edit's head CAS, so
     * the strong-evidence after fields are not required here.
     *
     * `target.post_id` is the post the claim owns. The state is `claimed`
     * (the post has not been edited yet) or `applied` (the first edit
     * has run, the op has been promoted to applied by mark_applied's
     * op-state transition); the value-guarded CAS that promotes a claim
     * op from `claimed` to `applied` is the same one `mark_applied()`
     * uses for any other op type.
     *
     * @param array  $op    The op record.
     * @param string $label Human-readable label for problem strings.
     * @return array<int,string> Per-op problems; empty when well-formed.
     */
    private static function validate_edit_existing_draft( array $op, $label ) {
        $problems = array();
        if ( empty( $op['target']['post_id'] ) ) {
            $problems[] = $label . ' has no target.post_id; the claim does not name a post.';
        }
        if ( ! is_array( $op['before'] ?? null ) ) {
            $problems[] = $label . ' has no before-image; a claim without the immutable baseline cannot be rolled back.';
            return $problems;
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
        // The state check is intentionally broader than `claimed` or
        // `applied`. The recovery engine drives the op through
        // `compensating` (a transient state set during settlement) and
        // may encounter the op in `compensated` or `conflict` from a
        // prior settle. Those are all valid rows on disk: the engine's
        // settle_operation() already short-circuits terminal states
        // (`compensated`, `conflict`) and the claim_or_refresh() path
        // handles the in-flight `compensating` case. The validator's
        // job is to refuse structurally-broken records, not to enforce
        // the settlement lifecycle.
        $state = (string) ( $op['state'] ?? '' );
        $lifecycle_states = array(
            self::STATE_INTENDED,
            self::STATE_CLAIMED,
            self::STATE_APPLIED,
            self::STATE_COMPENSATING,
            self::STATE_COMPENSATED,
            self::STATE_CONFLICT,
        );
        if ( '' === $state || ! in_array( $state, $lifecycle_states, true ) ) {
            $problems[] = $label . ' has state "' . $state . '"; edit_existing_draft ops are only valid in the lifecycle states.';
        }
        return $problems;
    }

    /**
     * Structural checks specific to OP_EDIT_PUBLISHED.
     *
     * The op has TWO critical image fields:
     *
     *   - `before` — the *strong immutable baseline* of the
     *     original (the live published post) at the moment the
     *     claim was taken. The publish tool's write-and-verify-C
     *     step compares the live original against this exact
     *     image to detect an external editor's intervening
     *     update. The fields it carries: `post_type`,
     *     `post_status`, `post_title`, `post_content`,
     *     `post_excerpt`, `post_name`, `post_date_gmt`,
     *     `post_modified_gmt`, `post_content_sha256`,
     *     `post_content_length`, `op_meta_value`. The hash is
     *     the strong evidence; the modified time is the fast
     *     pre-check.
     *
     *   - `after` — the four staged values the shadow holds
     *     (post_title, post_content, post_excerpt, post_name).
     *     Each successive authorised edit re-records this image
     *     in the same guarded write. The publish tool reads
     *     these values and passes them to `wp_update_post` as
     *     the staged fields.
     *
     * `target.post_id` is the **original** the op is about to
     * publish against. `target.shadow_id` is the private shadow
     * that holds the staged values. `op_meta_value` is the
     * marker the shadow carries; it is independent of the
     * original's marker (the original is *not* under
     * transaction control — only the shadow is).
     *
     * The op's `state` is the eight-state model from
     * `docs/STAGE-2C-DESIGN.md` §1.7. The validator permits
     * every state in the model so a recovery-driven transition
     * (`staged` → `publication_in_progress` →
     * `publication_committed` → `reconciliation_pending` →
     * `publication_settled`, or `staged` → `compensated`, or
     * `staged` → `conflict`, or any state → `abandoned`) is
     * valid on disk. The validator's job is to refuse
     * structurally-broken records, not to enforce the
     * settlement lifecycle.
     *
     * @param array  $op    The op record.
     * @param string $label Human-readable label for problem strings.
     * @return array<int,string> Per-op problems; empty when well-formed.
     */
    private static function validate_op_edit_published( array $op, $label ) {
        $problems = array();
        if ( empty( $op['target']['original_id'] ) ) {
            $problems[] = $label . ' has no target.original_id; the op does not name the post it is about to publish against.';
        }
        if ( empty( $op['target']['shadow_id'] ) ) {
            $problems[] = $label . ' has no target.shadow_id; the op does not name the shadow that holds the staged values.';
        }
        if ( ! is_array( $op['before'] ?? null ) ) {
            $problems[] = $label . ' has no before-image; publish cannot revalidate baseline-A without the immutable original state.';
            return $problems;
        }
        // The strong immutable baseline. Every field the
        // write-and-verify-C step compares against must be
        // present; an incomplete baseline would make the
        // publish step a guess, not a verification.
        $baseline_required = array(
            'post_type',
            'post_status',
            'post_title',
            'post_content',
            'post_excerpt',
            'post_name',
            'post_date_gmt',
            'post_modified_gmt',
            'post_content_sha256',
            'post_content_length',
            'op_meta_value',
        );
        foreach ( $baseline_required as $needed ) {
            if ( ! array_key_exists( $needed, $op['before'] ) ) {
                $problems[] = $label . ' has no before.' . $needed . '; the publish revalidation would be a guess.';
            }
        }
        // The staged values the shadow holds. Only the four
        // supported fields, and `op_meta_value` (the shadow's
        // own marker, so the publish step can confirm the
        // shadow is still the one the op names).
        $after_required = array(
            'post_title',
            'post_content',
            'post_excerpt',
            'post_name',
            'op_meta_value',
        );
        foreach ( $after_required as $needed ) {
            if ( ! array_key_exists( $needed, $op['after'] ) ) {
                $problems[] = $label . ' has no after.' . $needed . '; the publish step would not know what to write.';
            }
        }
        // `original_id` and `shadow_id` must match the baseline's
        // `op_meta_value` semantics: the baseline is for the
        // original (the post the op publishes *against*); the
        // shadow's marker is on the after.
        if ( isset( $op['before']['op_meta_value'], $op['after']['op_meta_value'] )
            && (string) $op['before']['op_meta_value'] !== ''
            && (string) $op['before']['op_meta_value'] === (string) $op['after']['op_meta_value'] ) {
            $problems[] = $label . ' has the same op_meta_value in before and after; the baseline is for the original, the after is for the shadow. They must be different.';
        }
        $state = (string) ( $op['state'] ?? '' );
        $lifecycle_states = array(
            self::STATE_INTENDED,
            self::STATE_STAGED,
            self::STATE_PUBLICATION_IN_PROGRESS,
            self::STATE_PUBLICATION_COMMITTED,
            self::STATE_RECONCILIATION_PENDING,
            self::STATE_PUBLICATION_SETTLED,
            // STATE_COMPENSATING is set by the claim step and is
            // valid on disk if a prior recovery crashed
            // mid-compensation. The recovery engine reads
            // `claim.pre_state` to resume the correct compensation
            // (M5-audit R-2 fix). The validator permits it so the
            // recovery does not refuse the journal as "unreadable".
            self::STATE_COMPENSATING,
            self::STATE_COMPENSATED,
            self::STATE_CONFLICT,
            self::STATE_ABANDONED,
        );
        if ( '' === $state || ! in_array( $state, $lifecycle_states, true ) ) {
            $problems[] = $label . ' has state "' . $state . '"; edit_published ops are only valid in the Stage 2c lifecycle states.';
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