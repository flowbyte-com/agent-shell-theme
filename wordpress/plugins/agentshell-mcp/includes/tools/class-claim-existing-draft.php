<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_claim_existing_draft — record a journal claim on a draft that
 * existed before the current transaction opened.
 *
 * Stage 2b. The first-touch tool that makes the OCC chain and the
 * recoverable lifecycle apply to pre-existing drafts without requiring
 * the user to delete and recreate the post.
 *
 * The tool's only durable effect is a journal op in `claimed` state
 * with the live post's strong after-image recorded as the *immutable
 * baseline* in `op['before']`. The post itself is not modified; the
 * tool does not call `wp_update_post`, does not bump `post_modified_gmt`,
 * does not touch any taxonomy or metadata. The marker
 * `_agentshell_journal_op` IS stamped on the post — that is what makes
 * the post carry the journal's ownership and is what the next
 * `agentshell_update_draft` call's ownership check matches against.
 *
 * Refusal conditions, in evaluation order:
 *
 *   - **Not in a transaction.** A claim is a journal op; a journal op
 *     lives inside a transaction. Outside a transaction the caller
 *     has no journal row to write to.
 *   - **No `idempotency_key`.** Schema-level refusal. The key is
 *     bound to (actor, transaction, post_id, content fingerprint):
 *     a retry with the same key returns the same op with
 *     `resumed: true`; a retry with a different content fingerprint
 *     refuses.
 *   - **The post does not exist.** Schema-level refusal.
 *   - **The post is not a draft.** Status transitions (publish,
 *     pending, etc.) are deliberately out of scope — a status
 *     transition is a different lifecycle, and a claim on a
 *     non-draft post would change what "recoverable" means.
 *   - **Wrong type.** The `type` argument must match the post's
 *     actual post_type.
 *   - **Missing capability.** The actor must currently hold
 *     `edit_post` on the post. A user whose role has been revoked
 *     since the post was created cannot claim it.
 *   - **The post is already transaction-owned.** A different journal
 *     op (in the same journal, or a journal that has since closed)
 *     has already stamped the marker. A second claim for the same
 *     post in the same journal refuses; a claim on a post that
 *     carries a marker from a different journal refuses.
 *   - **The journal already has a head for this post.** A head
 *     means a prior `mark_applied()` already wrote the post's
 *     identity into the head map. For a pre-existing draft there
 *     is no prior head, so this branch is unreachable in
 *     production; the refusal is a defence in depth that catches
 *     a journal that survived a partial claim.
 *
 * After-image (the immutable baseline)
 * ------------------------------------
 *
 * The op records the live post's strong after-image (post_type,
 * post_status, post_title, post_name, post_date_gmt,
 * post_modified_gmt, post_content_sha256, post_content_length,
 * op_meta_value) and the same fields in `before` so the inverse
 * operation is a `wp_update_post` of the supported fields
 * (post_title, post_content, post_excerpt where present, post_name;
 * `post_modified_gmt` is excluded so re-applying it does not
 * falsify the OCC chain for any subsequent op).
 *
 * The marker stamp
 * ----------------
 *
 * The marker `_agentshell_journal_op` is stamped on the post via
 * `update_post_meta` *outside* the guarded SQL transaction. The
 * marker is the durable evidence that the post is owned by the
 * journal — a crash between the journal attach and the marker
 * stamp leaves the journal with a `claimed` op whose marker is
 * not yet on the post, which a subsequent recovery call can
 * detect and re-stamp. The atomicity guarantee is the *op* is
 * recorded before the marker is stamped, not that both share one
 * SQL transaction: the existing op's id is the marker's value,
 * so the journal row is the authoritative source of the marker's
 * identity, and the stamp is the projection of that identity onto
 * the post.
 *
 * Idempotency
 * -----------
 *
 * A retry that supplies the same `idempotency_key` as a previous
 * OP_EDIT_EXISTING_DRAFT op in `claimed` state with matching
 * (actor, transaction, post_id, content fingerprint) returns the
 * same op with `resumed: true`; no second journal op is recorded,
 * and the marker is not re-stamped. A retry that finds an op in
 * `applied` state (the first edit ran between the original call
 * and the retry) refuses with
 * `transaction_claim_existing_draft_already_edited`. A retry with
 * a different content fingerprint refuses with
 * `transaction_claim_existing_draft_idempotency_conflict`.
 */
class Claim_Existing_Draft extends Base_Tool {
    public function get_name() { return 'agentshell_claim_existing_draft'; }

    public function get_description() {
        return 'Record a journal claim on a pre-existing draft. The first-touch tool that lets '
            . 'agentshell_update_draft edit a draft this transaction did not create, by stamping '
            . 'the journal\'s `_agentshell_journal_op` marker on the post and recording the post\'s '
            . 'live state as the immutable baseline. The tool may only be called inside an open '
            . 'transaction. The post is not modified: no fields are written, no modified time is '
            . 'bumped. The op\'s `state` is `claimed` until the first edit runs; rollback restores '
            . 'the recorded baseline. An `idempotency_key` is required for retry safety.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'              => array( 'type' => 'integer', 'description' => 'Post ID of the pre-existing draft to claim. Must be a draft the actor has edit_post on and that is not already journal-owned.' ),
                'type'            => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Post type; must match the existing post.' ),
                'idempotency_key' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                    'description' => 'A stable, client-supplied identifier (e.g. a UUID) for retry safety. '
                        . 'Required. A retry with the same key by the same actor on the same '
                        . '(post, content fingerprint) returns the original op with `resumed: true`; '
                        . 'a retry with the same key but different content / actor / transaction / post '
                        . 'is refused. The key is never silently replaced by a server-minted value.',
                ),
            ),
            'required'   => array( 'id', 'type', 'idempotency_key' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id', 'type', 'idempotency_key' ) );

        $id    = (int) $arguments['id'];
        $type  = (string) $arguments['type'];
        $idem  = (string) $arguments['idempotency_key'];

        // The schema constraint already says 1-128 chars; this is defence in
        // depth that also rejects keys that are not printable ASCII.
        if ( '' === $idem || strlen( $idem ) > 128 || ! preg_match( '/^[\x20-\x7E]+$/', $idem ) ) {
            throw new \InvalidArgumentException(
                'idempotency_key must be 1-128 printable ASCII characters; got: ' . bin2hex( $idem )
            );
        }
        if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }

        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx_mgr->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_claim_existing_draft refuses outside a transaction. A claim is a journal '
                . 'op, and a journal op lives inside a transaction. Either open a transaction and '
                . 'call agentshell_claim_existing_draft + agentshell_update_draft, or call '
                . 'agentshell_update_content directly for an immediate-write edit (no journal, no recovery).'
            );
        }

        $tx          = $tx_mgr->payload();
        $tx_id       = (string) $tx['id'];
        $actor       = \AgentShell_MCP\Store::actor();

        // 1. The post must exist, be of the requested type, and be a draft.
        $post = get_post( $id );
        if ( ! $post ) {
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_post_not_found: post #{$id} does not exist; "
                . 'the claim tool can only claim posts that exist.'
            );
        }
        if ( (string) $post->post_type !== $type ) {
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_wrong_type: post #{$id} is of type '"
                . (string) $post->post_type . "', not '{$type}'. The type argument must match the post's actual type."
            );
        }
        if ( 'draft' !== (string) $post->post_status ) {
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_not_a_draft: post #{$id} has status '"
                . (string) $post->post_status . "', not 'draft'. agentshell_claim_existing_draft only "
                . 'claims drafts; a status transition (publish / pending / private / future) is a '
                . 'different lifecycle. Use the corresponding publish / unpublish tool outside a '
                . 'transaction, or commit the transaction first and then publish.'
            );
        }
        if ( ! \current_user_can( 'edit_post', $id ) ) {
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_missing_capability: the current actor lacks "
                . "`edit_post` on post #{$id}. The claim tool re-checks the capability at claim time; "
                . "a user whose role was revoked since the post was created cannot claim it."
            );
        }

        $fingerprint = self::content_fingerprint( $id, $type, $post );

        // 2. Idempotency check FIRST. A retry of a previous claim
        //    returns the same op with `resumed: true` and does not
        //    re-stamp the marker or re-record the op. The key is
        //    bound to (actor, transaction, post, content fingerprint);
        //    a retry with a different content fingerprint (the post
        //    was edited between attempts) is refused explicitly.
        //
        //    The idempotency check must run before the marker stamp
        //    / head / duplicate-claim checks below, because the first
        //    successful call stamps the marker on the post. A retry
        //    is "the same op, with the same key, with the same
        //    post": the op is already in the journal, and the resume
        //    is a read of the existing op, not a fresh claim.
        $content    = \AgentShell_MCP\Content_Journal::of( $tx );
        $existing   = self::find_op_by_idempotency_key( $content, $idem );
        if ( null !== $existing ) {
            return self::resolve_idempotency_hit( $existing, $actor, $tx_id, $id, $fingerprint, $idem );
        }

        // 3. The post must not already carry a journal marker from a
        //    different journal op. (A previous call by this same
        //    journal would have been resumed above, so any marker
        //    here is from a different journal / an old op that was
        //    never resolved.)
        $existing_marker = (string) get_post_meta( $id, \AgentShell_MCP\Content_Journal::OP_META, true );
        if ( '' !== $existing_marker ) {
            // The post already carries a marker. The claim tool refuses to
            // overwrite an existing ownership claim — the existing claim
            // belongs to a different journal, and overwriting it would
            // make recovery for the previous journal unable to find the
            // post.
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_already_owned: post #{$id} already carries the "
                . "journal marker `{$existing_marker}`. A claim is a transfer of ownership; a post "
                . "that is already journal-owned must be released by its current journal (commit / "
                . "rollback / recovery) before a new claim can be made. Editing an already-owned post "
                . "is the normal `agentshell_update_draft` path."
            );
        }

        // 4. The journal must not already have a head for this post.
        //    A head means a previous op (a create or an update) has
        //    already written to this post; the next edit is the
        //    `agentshell_update_draft` path, not a fresh claim.
        $head = \AgentShell_MCP\Content_Journal::head_after( $tx, $id );
        if ( is_array( $head ) && ! empty( $head ) ) {
            // A head already exists for this post in this journal. The
            // claim tool refuses: the post is already transaction-owned
            // through an applied op in this same journal, and a fresh
            // claim would create a divergent ownership chain.
            throw new \InvalidArgumentException(
                "transaction_claim_existing_draft_already_owned: post #{$id} already has a head "
                . "in this journal's head map. A claim is for posts this journal has never "
                . "touched; an already-headed post is owned through an applied op (a create or "
                . "an update), and the next edit is the normal `agentshell_update_draft` path."
            );
        }
        // 5. The journal must not already have a claim op for this
        //    same post. (The idempotency check above caught a retry
        //    with the same key. A second claim with a *different*
        //    key on the same post is also refused: a journal may
        //    carry at most one claim per post. Without this check,
        //    a non-resume retry with a different key would create
        //    a divergent ownership chain.)
        if ( ! empty( $content['ops'] ) ) {
            foreach ( $content['ops'] as $existing_op ) {
                if ( ! is_array( $existing_op ) ) { continue; }
                if ( \AgentShell_MCP\Content_Journal::OP_EDIT_EXISTING_DRAFT !== (string) ( $existing_op['op'] ?? '' ) ) {
                    continue;
                }
                $existing_post_id = (int) ( $existing_op['target']['post_id'] ?? 0 );
                if ( $existing_post_id === $id ) {
                    throw new \InvalidArgumentException(
                        "transaction_claim_existing_draft_already_claimed: post #{$id} is already "
                        . "claimed by op {$existing_op['id']} in this journal. A journal may carry "
                        . "at most one claim per post; further edits are `agentshell_update_draft`."
                    );
                }
            }
        }

        // 3. Capture the strong after-image (immutable baseline). This is
        //    what rollback will restore and what the next op's OCC
        //    pre-check verifies against. Reading the live post here
        //    (rather than in mark_applied) is correct: the claim records
        //    the post's state at first touch, before any edit has run.
        $baseline = array(
            'post_type'           => (string) $post->post_type,
            'post_status'         => (string) $post->post_status,
            'post_title'          => (string) $post->post_title,
            'post_content'        => (string) $post->post_content,
            'post_excerpt'        => (string) $post->post_excerpt,
            'post_name'           => (string) $post->post_name,
            'post_date_gmt'       => (string) $post->post_date_gmt,
            'post_modified_gmt'   => (string) $post->post_modified_gmt,
            'post_content_sha256' => hash( 'sha256', (string) $post->post_content ),
            'post_content_length' => strlen( (string) $post->post_content ),
        );

        // 4. Record the durable intent and stamp the state to `claimed`.
        $target = array(
            'post_id'     => $id,
            'post_type'   => $type,
            'post_status' => 'draft',
        );
        $op = \AgentShell_MCP\Content_Journal::record_intent(
            \AgentShell_MCP\Content_Journal::OP_EDIT_EXISTING_DRAFT,
            $target,
            array(
                'idempotency_key'      => $idem,
                'post_title'           => (string) $post->post_title,
                'post_name'            => (string) $post->post_name,
                'op_meta_value'        => '',  // not yet stamped; the post-stamp value is $op['id']
                'content_fingerprint'  => $fingerprint,
            ),
            $actor
        );
        // The marker is the op's id — the same value the post's
        // _agentshell_journal_op meta will carry. Set it on the op's
        // after so the structural validation accepts the record.
        $op['after']['op_meta_value'] = (string) $op['id'];
        // The immutable baseline is the post's state at first touch.
        // The before-image also carries op_meta_value so the inverse
        // wp_update_post can re-stamp the marker on restore (the
        // marker's value does not change across the edit/rollback
        // cycle).
        $op['before'] = array_merge( $baseline, array( 'op_meta_value' => (string) $op['id'] ) );
        // The op is recorded in `claimed` state: the post is owned by
        // the journal, no edit has run yet, the head map for this
        // post is empty.
        $op['state']  = \AgentShell_MCP\Content_Journal::STATE_CLAIMED;
        // The op's `post_id` is set at intent time (the post exists
        // now); mark_applied's later transition to `applied` keeps
        // this value. This is the post the claim owns.
        $op['post_id'] = $id;

        if ( ! \AgentShell_MCP\Content_Journal::attach( $tx, $op ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' changed while the claim intent was being recorded; '
                . 'nothing was written. Read agentshell_get_transaction and retry.'
            );
        }

        // Re-read the row after attach() so the post-stamp state
        // (which the marker stamp below uses) sees the post-attach
        // bytes. This is the only honest "row is at the version we
        // just attached" baseline.
        $tx_post_attach = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( empty( $tx_post_attach ) || empty( $tx_post_attach['content']['ops'][ $op['id'] ] ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' is no longer present after the claim intent was attached; '
                . 'the row was removed by a competing close. Read agentshell_get_transaction and retry.'
            );
        }

        // 5. Stamp the marker on the post. This is the durable
        //    ownership projection: the post now carries the journal's
        //    _agentshell_journal_op meta with the op's id as the
        //    value. The stamp is not inside a guarded SQL transaction
        //    — see the class docstring for why the journal row is
        //    authoritative and the stamp is the projection.
        update_post_meta( $id, \AgentShell_MCP\Content_Journal::OP_META, (string) $op['id'] );

        return array(
            'id'              => $id,
            'type'            => $type,
            'status'          => 'draft',
            'journaled'       => true,
            'journal_state'   => 'claimed',
            'recoverable'     => true,
            'op_id'           => (string) $op['id'],
            'marker'          => (string) $op['id'],
            'resumed'         => false,
            'idempotency_key' => $idem,
            'transaction'     => $tx_id,
            'baseline'        => array(
                'post_title'          => (string) $baseline['post_title'],
                'post_name'           => (string) $baseline['post_name'],
                'post_content_length' => (int) $baseline['post_content_length'],
                'post_content_sha256' => (string) $baseline['post_content_sha256'],
            ),
        );
    }

    /**
     * Stable SHA-256 of the request fields that, if changed, mean a
     * different live post. Captures post_id, type, and the strong
     * evidence (title, content, slug) at claim time.
     */
    private static function content_fingerprint( $id, $type, $post ) {
        $payload = array(
            'id'      => (int) $id,
            'type'    => (string) $type,
            'title'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'slug'    => (string) $post->post_name,
        );
        return hash( 'sha256', wp_json_encode( $payload ) );
    }

    /**
     * Find an op in the journal whose `after.idempotency_key` matches.
     * Returns the op array, or null.
     */
    private static function find_op_by_idempotency_key( array $content, $key ) {
        if ( empty( $content['ops'] ) ) { return null; }
        foreach ( $content['ops'] as $op_id => $op ) {
            if ( ! is_array( $op ) ) { continue; }
            if ( isset( $op['after']['idempotency_key'] )
                && (string) $op['after']['idempotency_key'] === (string) $key ) {
                return $op;
            }
        }
        return null;
    }

    /**
     * Handle a retry that found an existing op. The rules:
     *   - in-flight (intended): refuse, in progress.
     *   - claimed + matching actor, tx, post, fingerprint: resume (no second op).
     *   - applied: refuse, the original already had its first edit.
     *   - conflict or compensated: refuse, the original is settled.
     *   - any binding mismatch: refuse with the named reason.
     */
    private static function resolve_idempotency_hit( array $op, $actor, $tx_id, $post_id, $fingerprint, $idem ) {
        $state    = (string) ( $op['state'] ?? '' );
        $op_actor = (string) ( $op['actor'] ?? '' );
        $op_tx    = (string) ( $op['op']['tx'] ?? '' );
        $op_post  = (int) ( $op['post_id'] ?? 0 );
        $op_fp    = (string) ( $op['after']['content_fingerprint'] ?? '' );

        if ( \AgentShell_MCP\Content_Journal::STATE_INTENDED === $state ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_in_progress: a previous attempt with this '
                . 'idempotency_key is in flight (state="intended"). Reconcile by waiting or by '
                . 'calling agentshell_recover_transaction.'
            );
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_CLAIMED !== $state
            && \AgentShell_MCP\Content_Journal::STATE_APPLIED !== $state ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_unrecoverable: a previous attempt with this '
                . 'idempotency_key is in state "' . $state . '" and cannot be resumed.'
            );
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_APPLIED === $state ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_already_edited: a previous attempt with this '
                . 'idempotency_key reached `applied` state — the first edit has run — and the '
                . 'post is now owned through an applied op. Use agentshell_update_draft for '
                . 'subsequent edits; a fresh claim would create a divergent ownership chain.'
            );
        }
        if ( $op_actor !== (string) $actor ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_idempotency_actor_mismatch: a previous attempt '
                . 'with this key was made by actor "' . $op_actor . '", but the current actor is "'
                . $actor . '".'
            );
        }
        if ( '' !== $op_tx && $op_tx !== (string) $tx_id ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_idempotency_tx_mismatch: a previous attempt '
                . 'with this key was made in transaction "' . $op_tx . '", but the current '
                . 'transaction is "' . $tx_id . '".'
            );
        }
        if ( $op_post !== (int) $post_id ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_idempotency_post_mismatch: a previous attempt '
                . 'with this key claimed post #' . $op_post . ', but the current call claims '
                . 'post #' . (int) $post_id . '.'
            );
        }
        if ( '' !== $op_fp && $op_fp !== (string) $fingerprint ) {
            throw new \InvalidArgumentException(
                'transaction_claim_existing_draft_idempotency_conflict: a previous attempt with '
                . 'this key captured a different content fingerprint for the post. The original '
                . 'op is preserved.'
            );
        }
        // Resume: return the original op's identity; do not record a
        // second op and do not re-stamp the marker.
        return array(
            'id'              => (int) $post_id,
            'type'            => (string) ( $op['target']['post_type'] ?? '' ),
            'status'          => 'draft',
            'journaled'       => true,
            'journal_state'   => 'claimed',
            'recoverable'     => true,
            'op_id'           => (string) ( $op['id'] ?? '' ),
            'marker'          => (string) ( $op['after']['op_meta_value'] ?? '' ),
            'resumed'         => true,
            'idempotency_key' => $idem,
            'transaction'     => (string) $tx_id,
        );
    }
}
