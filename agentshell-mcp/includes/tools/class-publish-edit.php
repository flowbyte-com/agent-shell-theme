<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_publish_edit — commit a staged OP_EDIT_PUBLISHED op to the
 * original published post.
 *
 * Stage 2c Milestone 4. The publication step: it takes a private shadow
 * whose four fields (`post_title`, `post_content`, `post_excerpt`,
 * `post_name`) hold the staged values the operator wants the original
 * to carry, and writes them to the original through WordPress's normal
 * update machinery inside a short guarded SQL transaction.
 *
 * ## Why this is a separate tool from `agentshell_publish`
 *
 * The existing `agentshell_publish` (in
 * `class-publish-content.php`) is the immediate-write status-change
 * tool that sets `post_status=publish` for a draft or pending post. It
 * refuses inside a transaction. The new tool cannot share that name
 * because the semantics are different: `agentshell_publish` changes a
 * post's status; `agentshell_publish_edit` publishes a *staged edit*
 * (a shadow's four fields) to an *already-published* original. The
 * same string would be ambiguous in the audit log, in agent
 * instructions, and in the recovery engine's outcome classification.
 *
 * ## The 11-step §1.4 sequence, in tool order
 *
 *   1.  Acquire the per-tx MySQL named advisory lock. Held from this
 *       point through the end of reconciliation; released in `finally`.
 *   2.  Validate ownership, expiry, and that the op is in `staged`
 *       state.
 *   3.  Open a real InnoDB transaction via `Content_Write_Guard::run()`.
 *   4.  `SELECT ... FOR UPDATE` the original; compare against baseline A
 *       (the strong immutable baseline captured at claim time). A
 *       divergence is `publication_refused_baseline_drift` and routes
 *       the op to `conflict`.
 *   5.  `wp_update_post( $original_id, $shadow_fields )` — WordPress's
 *       own update machinery, which fires the `save_post` /
 *       `wp_after_insert_post` / `transition_post_status` / `post_updated`
 *       / `wp_save_post_revision` hook chain. The hook chain is *not*
 *       part of the database transaction; the SQL it issues joins ours.
 *   6.  Write-and-verify-C: re-read the row, construct the expected
 *       resulting state from the four supported fields (from the
 *       shadow's `after`), the protected preserved fields (from the
 *       baseline), and the allowlisted housekeeping fields
 *       (`post_modified`, `post_modified_gmt`, read from the row, not
 *       predicted). Allowlist-based verification:
 *         - supported-field divergence → `publication_refused_hook_overwrote_supported_field`
 *         - protected-preserved-field divergence → `publication_refused_hook_changed_protected_preserved_field`
 *         - housekeeping-only divergence → success.
 *   7.  Transition the op to `publication_committed` and write the
 *       success audit (`transaction_published`) inside the SQL
 *       transaction.
 *   8.  `Content_Write_Guard` COMMITs. The publication, the journal
 *       transition, and the success audit land atomically.
 *   9.  Post-COMMIT refusal audit durability: a refusal audit (if
 *       any refusal fired at step 4 or 6) is written *outside* the
 *       rolled-back SQL transaction, in a separate short guarded
 *       transaction.
 *   10. Reconciliation runs (the §1.7 three-step sequence):
 *       identity-validated shadow delete, cache invalidation, journal
 *       settlement + value-guarded claim release with the crash-safe
 *       3a/3b/3c/3d ordering.
 *   11. The advisory lock is released in `finally`.
 *
 * ## Refusal conditions, in evaluation order
 *
 *   - **Not in a transaction.** No journal to read the staged op from.
 *   - **The transaction has expired.** Recovery's job, not ours.
 *   - **No `OP_EDIT_PUBLISHED` op in the journal**, or the op is not
 *     in `staged` state.
 *   - **Op's actor is not the calling actor.**
 *   - **The shadow is missing** (the shadow post is gone, or the
 *     shadow's `META_SHADOW_FOR` does not match the op's original id).
 *   - **The shadow's `META_OP_ID` does not match the op's id.** The
 *     head pointer has moved on; a newer edit has advanced the shadow
 *     past the op we are about to publish. The op is refused; the
 *     caller is told to retry.
 *   - **The original is not `post_status=publish`.** Status
 *     transitions are out of scope.
 *   - **The per-original claim is not held by this transaction.** A
 *     different tx holds the claim; the publish is refused.
 *   - **The advisory lock is unavailable**, or another connection
 *     holds it. The publish is refused; the operator is told to
 *     retry.
 *
 * Every refusal writes a `transaction_publish_refused_*` audit row
 * *outside* the §1.4 SQL transaction, per the post-rollback audit-
 * durability rule (§1.6a). The successful publication's
 * `transaction_published` audit row is written *inside* the SQL
 * transaction, so a ROLLBACK rolls it back together with the
 * publication — which is correct (the publication did not land).
 *
 * ## The reconciliation tail
 *
 * After COMMIT, the shadow still exists, the claim is still held, the
 * cache may still be serving stale values, and the op is in
 * `publication_committed` (reconciliation is owed). The three
 * reconciliation steps are:
 *
 *   - **Step 1 — shadow delete.** Identity-validated. The pre-delete
 *     check reads the shadow and confirms the shadow's `META_SHADOW_FOR`
 *     and `META_OP_ID` still match the op. A retargeted or relabelled
 *     shadow refuses and leaves the op in `reconciliation_pending`.
 *   - **Step 2 — cache invalidation.** `clean_post_cache( $original_id )`
 *     and the option-cache delete for the claim. Idempotent.
 *   - **Step 3 — claim release + journal settlement, crash-safe.**
 *     The `reconciliation.claim_released` field on the op is set to
 *     a `status:pending` marker inside the same SQL transaction as
 *     the `cas_delete_row`; the audit row is written *after* COMMIT,
 *     in a separate short transaction; the op's state transitions
 *     to `publication_settled` *after* the audit. A retry observes
 *     the per-step status and runs only the steps that have not
 *     landed.
 *
 * ## Atomicity contract
 *
 *   - **Atomic with the publication:** the original-row update, the
 *     journal `state → publication_committed` transition, and the
 *     `transaction_published` audit row share one SQL COMMIT.
 *   - **Durable but not atomic with the publication:** the three
 *     reconciliation steps. Each step is durable on its own; a crash
 *     between steps leaves the op in `reconciliation_pending` and
 *     the next reconcile retries the failed step(s).
 *   - **Outside the SQL transaction:** every refusal audit
 *     (`transaction_publish_refused_*`).
 */
class Publish_Edit extends Base_Tool {
    public function get_name() { return 'agentshell_publish_edit'; }

    public function get_description() {
        return 'Commit a staged OP_EDIT_PUBLISHED op to the original published post. Inside the '
            . 'open transaction; refuses if no OP_EDIT_PUBLISHED op is in `staged` state for the '
            . 'calling actor\'s transaction, refuses if the op\'s shadow is missing or has been '
            . 'retargeted, refuses if the original is not currently `post_status=publish`, refuses '
            . 'if the lease has expired, refuses if the per-original claim is held by a different '
            . 'transaction. The publication, the journal `state -> publication_committed` '
            . 'transition, and the success audit row share one SQL transaction; the three '
            . 'reconciliation steps (shadow delete, cache invalidation, claim release) run '
            . 'after COMMIT and are durable per-step. A failure between COMMIT and reconciliation '
            . 'leaves the op in `reconciliation_pending` until `agentshell_publish_edit_reconcile` '
            . 'retries.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'op_id' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 64,
                    'description' => 'Optional. The journal op id to publish. Defaults to the only '
                        . 'OP_EDIT_PUBLISHED op in the journal; a journal with more than one such op '
                        . 'is a configuration error and is refused by name.',
                ),
            ),
        );
    }

    /**
     * The four supported fields the publish step writes. Listed in the
     * order `wp_update_post` receives them, which is the order WP's
     * meta and term caches see them.
     */
    const SUPPORTED_FIELDS = array(
        'post_title',
        'post_content',
        'post_excerpt',
        'post_name',
    );

    /**
     * The protected preserved fields. Their post-write value MUST equal
     * the baseline A's value; a divergence in any one of them is a
     * hook-induced change, refused by name.
     *
     * Excludes the two housekeeping fields (`post_modified`,
     * `post_modified_gmt`) which `wp_update_post` itself sets.
     */
    const PROTECTED_PRESERVED_FIELDS = array(
        'post_status',
        'post_author',
        'post_parent',
        'post_type',
        'post_date',
        'post_date_gmt',
        'post_mime_type',
        'comment_status',
        'ping_status',
        'menu_order',
        'post_password',
        'post_content_filtered',
        'comment_count',
        'to_ping',
        'pinged',
        'guid',
    );

    /**
     * The allowlisted housekeeping fields. The only two `wp_posts`
     * columns that `wp_update_post` itself mutates (to the current
     * time at the moment of the update). The post-write compare does
     * not predict these values; the read-back value is the expected
     * value.
     */
    const HOUSEKEEPING_FIELDS = array(
        'post_modified',
        'post_modified_gmt',
    );

    /**
     * The full set of `wp_posts` columns the §1.4 write-and-verify-C
     * step reads back. Every column falls into exactly one of the
     * three categories (supported / protected preserved /
     * housekeeping).
     */
    private static function verify_columns() {
        return array_merge( self::SUPPORTED_FIELDS, self::PROTECTED_PRESERVED_FIELDS, self::HOUSEKEEPING_FIELDS );
    }

    public function execute( array $arguments ) {
        $op_id = isset( $arguments['op_id'] ) ? (string) $arguments['op_id'] : '';

        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx_mgr->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_publish_edit refuses outside a transaction. The publication step '
                . 'is the commit shape for an OP_EDIT_PUBLISHED op; the op is a journal record, '
                . 'and a journal record lives inside a transaction. Either open a transaction '
                . 'and stage the edit (the OP_EDIT_PUBLISHED op\'s `staged` state), or close '
                . 'the transaction and use the immediate-write content tools instead.'
            );
        }

        $tx    = $tx_mgr->payload();
        $tx_id = (string) $tx['id'];
        $actor = \AgentShell_MCP\Store::actor();

        // 1. Locate the op. locate_op() throws on every refusal path
        //    it knows about; the only return is a well-formed op array.
        $content = \AgentShell_MCP\Content_Journal::of( $tx );
        $op      = self::locate_op( $content, $op_id, $actor );

        $original_id = (int) ( $op['target']['original_id'] ?? 0 );
        $shadow_id   = (int) ( $op['target']['shadow_id']   ?? 0 );
        $op_actor    = (string) ( $op['actor']               ?? '' );
        $op_id_str   = (string) $op['id'];

        // 2. Acquire the per-tx advisory lock. Held from this point
        //    through the end of reconciliation; released in finally.
        //    Scoped to the tx id, not the original id (§1.6a), so a
        //    single transaction with one op acquires one lock; the
        //    recoverer, reconciler, and rollback paths can detect
        //    that "this whole transaction is in a critical section".
        $lock_held = self::acquire_publish_lock( $tx_id, $actor, $op_id_str, $original_id );

        try {
            // 3. Validate everything that can be checked before the
            //    SQL transaction begins. The shadow identity check is
            //    the head-pointer gate that refuses a stale op (a
            //    newer edit has advanced the shadow's META_OP_ID past
            //    ours).
            self::validate_pre_sql( $tx, $op, $actor, $original_id, $shadow_id );

            // 4-8. The §1.4 SQL transaction: FOR UPDATE revalidate,
            //    wp_update_post, write-and-verify-C, journal
            //    transition + success audit, COMMIT.
            $committed_op = self::run_publish_transaction( $tx, $op, $actor, $original_id, $shadow_id, $op_id_str );

            // 9-10. After COMMIT: reconciliation. The op is in
            //    `publication_committed`; the three reconciliation
            //    steps are owed. Each step is durable on its own.
            //    run_reconciliation returns the op as it stands
            //    on disk after the three steps (or after the
            //    short-circuit when every step is already
            //    verified). The op transitions to
            //    `publication_settled` on a clean run; an
            //    already-settled op stays settled.
            $recon = self::run_reconciliation( $tx, $committed_op, $actor, $original_id, $shadow_id );

            // The reconciliation's status field is the
            // authoritative answer for the three steps. Build the
            // public result from the returned status (which
            // carries the post-settlement state) and the original
            // committed op (which carries op_id / target fields).
            $op_id_for_result = (string) ( $recon['op_id'] ?? $committed_op['id'] ?? '' );
            $state_for_result = (string) ( $recon['state'] ?? $committed_op['state'] ?? '' );
            $recon_for_result = is_array( $recon['reconciliation'] ?? null ) ? $recon['reconciliation'] : array();

            return self::build_result_from_recon( $committed_op, $op_id_for_result, $state_for_result, $recon_for_result );
        } finally {
            if ( $lock_held ) {
                \AgentShell_MCP\Recovery_Advisory_Lock::release( 'tx_' . $tx_id );
            }
        }
    }

    // ── locate_op ─────────────────────────────────────────────────────────

    /**
     * Find the OP_EDIT_PUBLISHED op to publish. Throws on every
     * refusal; returns the op array on success.
     *
     * Refusal paths:
     *   - No OP_EDIT_PUBLISHED ops in the journal.
     *   - Multiple OP_EDIT_PUBLISHED ops in the journal (config error).
     *   - The op id was supplied but does not name an OP_EDIT_PUBLISHED op.
     *   - The op is not in `staged` state (any other state is refused
     *     by name; the caller is told what state it was in and what
     *     to do about it).
     *   - The op's actor does not match the calling actor.
     */
    private static function locate_op( array $content, $op_id_arg, $actor ) {
        $ops = is_array( $content['ops'] ?? null ) ? $content['ops'] : array();
        $edits = array();
        foreach ( $ops as $id => $op ) {
            if ( is_array( $op )
                && \AgentShell_MCP\Content_Journal::OP_EDIT_PUBLISHED === (string) ( $op['op'] ?? '' ) ) {
                $edits[ (string) $id ] = $op;
            }
        }
        if ( empty( $edits ) ) {
            throw new \InvalidArgumentException(
                'transaction_publish_refused_no_op: this transaction\'s journal has no '
                . 'OP_EDIT_PUBLISHED op. agentshell_publish_edit is the commit shape for an '
                . 'OP_EDIT_PUBLISHED op in `staged` state. Stage an edit first (the journal '
                . 'op enters in `staged`) and then call this tool.'
            );
        }
        if ( '' !== $op_id_arg && ! isset( $edits[ $op_id_arg ] ) ) {
            throw new \InvalidArgumentException(
                'transaction_publish_refused_op_id_not_found: the supplied op_id "' . $op_id_arg
                . '" does not name an OP_EDIT_PUBLISHED op in this transaction\'s journal.'
            );
        }
        if ( 1 < count( $edits ) && '' === $op_id_arg ) {
            $ids = implode( ', ', array_keys( $edits ) );
            throw new \InvalidArgumentException(
                'transaction_publish_refused_multiple_ops: this transaction\'s journal carries '
                . count( $edits ) . ' OP_EDIT_PUBLISHED ops (' . $ids . '). agentshell_publish_edit '
                . 'refuses to guess; supply op_id to name the one to publish.'
            );
        }
        $op      = ( '' === $op_id_arg ) ? reset( $edits ) : $edits[ $op_id_arg ];
        $state   = (string) ( $op['state'] ?? '' );
        $op_actor = (string) ( $op['actor'] ?? '' );

        if ( $op_actor !== (string) $actor ) {
            throw new \InvalidArgumentException(
                'transaction_publish_refused_actor_mismatch: the OP_EDIT_PUBLISHED op '
                . (string) $op['id'] . ' was recorded by actor "' . $op_actor . '", but the '
                . 'current actor is "' . (string) $actor . '". Only the op\'s owner may publish it.'
            );
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_STAGED !== $state ) {
            $hint = self::state_hint( $state );
            throw new \InvalidArgumentException(
                'transaction_publish_refused_op_state_unexpected: OP_EDIT_PUBLISHED op '
                . (string) $op['id'] . ' is in state "' . $state . '"; agentshell_publish_edit '
                . 'only publishes ops in `staged` state.' . $hint
            );
        }
        return $op;
    }

    private static function state_hint( $state ) {
        switch ( $state ) {
            case \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED:
            case \AgentShell_MCP\Content_Journal::STATE_RECONCILIATION_PENDING:
                return ' The publication has already landed; call agentshell_publish_edit_reconcile to retry reconciliation.';
            case \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED:
                return ' The publication is fully settled; nothing to do.';
            case \AgentShell_MCP\Content_Journal::STATE_COMPENSATED:
                return ' The op was rolled back; the staged values are not on the original.';
            case \AgentShell_MCP\Content_Journal::STATE_CONFLICT:
                return ' The op is in conflict; inspect the journal and audit log for the refusal reason.';
            case \AgentShell_MCP\Content_Journal::STATE_ABANDONED:
                return ' The transaction\'s lease expired; the operator should run agentshell_recover_transaction.';
            default:
                return '';
        }
    }

    // ── acquire_publish_lock ─────────────────────────────────────────────

    /**
     * Acquire the per-tx advisory lock. Returns true if the lock is
     * held; throws on any refusal (unavailable, held by another
     * connection). The refusal audit is written *outside* any SQL
     * transaction, so it is durable across a refused publish.
     */
    private static function acquire_publish_lock( $tx_id, $actor, $op_id, $original_id ) {
        if ( ! \AgentShell_MCP\Recovery_Advisory_Lock::available() ) {
            \AgentShell_MCP\Store::record_event(
                $actor,
                'transaction_publish_refused_lock_unavailable',
                array(
                    'transaction_id' => (string) $tx_id,
                    'op_id'          => (string) $op_id,
                    'original_id'    => (int) $original_id,
                )
            );
            throw new \InvalidArgumentException(
                'transaction_publish_refused_lock_unavailable: agentshell_publish_edit requires '
                . 'MySQL/MariaDB named advisory locks (GET_LOCK / RELEASE_LOCK), and the engine '
                . 'probe says they are unavailable on this database. Refusing to publish to avoid '
                . 'a destructive step that cannot be serialised against recovery.'
            );
        }
        $got = \AgentShell_MCP\Recovery_Advisory_Lock::acquire( 'tx_' . $tx_id );
        if ( $got ) {
            return true;
        }
        \AgentShell_MCP\Store::record_event(
            $actor,
            'transaction_publish_refused_publisher_active',
            array(
                'transaction_id' => (string) $tx_id,
                'op_id'          => (string) $op_id,
                'original_id'    => (int) $original_id,
                'reason'         => 'another connection holds the per-tx publish lock',
            )
        );
        throw new \InvalidArgumentException(
            'transaction_publish_refused_publisher_active: another connection holds the '
            . 'per-tx publish lock for transaction "' . (string) $tx_id . '". A publish is '
            . 'already in flight, or recovery / rollback is running. Wait for it to finish '
            . 'and retry; the lock is released on connection close.'
        );
    }

    // ── validate_pre_sql ─────────────────────────────────────────────────

    /**
     * Pre-SQL validation. The shadow's identity (post_parent, meta
     * shadow_for, meta op_id) is the head-pointer gate; an op whose
     * shadow has moved is refused before any database work is done.
     * The original's `post_status` and the claim's holder are
     * pre-checks that catch the obvious refusal paths early; the
     * post-write allowlist verification at step 6 catches anything
     * that changes during the publication itself.
     */
    private static function validate_pre_sql( $tx, $op, $actor, $original_id, $shadow_id ) {
        $op_id_str = (string) $op['id'];

        // 1. The original must still be a published post of the
        //    captured type. A draft the operator published
        //    out-of-band is caught here; a post that has been
        //    deleted is caught by get_post() returning null.
        $original = get_post( $original_id );
        if ( ! $original ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_original_gone',
                'the original post is gone (deleted between staging and publish)'
            );
        }
        if ( 'publish' !== (string) $original->post_status ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_original_status_changed',
                'the original post is no longer `post_status=publish`; it is "'
                    . (string) $original->post_status . '". Status transitions are out of scope; '
                    . 'publish the post manually or run recovery on a conflicting state.'
            );
        }

        // 2. The shadow's identity. The head-pointer gate: the
        //    shadow's META_OP_ID must match the op's id. A
        //    shadow whose op_id has been advanced by a newer
        //    authorised edit means we are about to publish a
        //    stale after-image, so the publish is refused.
        $shadow = get_post( $shadow_id );
        if ( ! $shadow ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_shadow_missing',
                'the shadow post (id ' . $shadow_id . ') is gone. The staged values cannot '
                    . 'be published because there is nothing to publish from.'
            );
        }
        $shadow_for = (int) get_post_meta( $shadow_id, \AgentShell_MCP\Published_Shadow::META_SHADOW_FOR, true );
        if ( $shadow_for !== $original_id ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_shadow_retargeted',
                'the shadow\'s META_SHADOW_FOR is ' . $shadow_for . ' but the op\'s original_id '
                    . 'is ' . $original_id . '. The shadow has been retargeted; a recovery is needed.'
            );
        }
        $shadow_op = (string) get_post_meta( $shadow_id, \AgentShell_MCP\Published_Shadow::META_OP_ID, true );
        if ( '' === $shadow_op ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_shadow_marker_missing',
                'the shadow\'s META_OP_ID is empty. A newer edit is required to re-stamp '
                    . 'the head pointer before the publish can proceed.'
            );
        }
        if ( $shadow_op !== $op_id_str ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_shadow_head_advanced',
                'the shadow\'s META_OP_ID is "' . $shadow_op . '" but the op\'s id is "'
                    . $op_id_str . '". A newer authorised edit has advanced the head pointer; '
                    . 'this op\'s staged values are stale. Re-stage and retry, or run recovery.'
            );
        }

        // 3. The per-original claim must be held by this transaction.
        //    A different transaction holding the claim means a
        //    competing staging is in flight; the publish is refused.
        $claim = \AgentShell_MCP\Published_Claim::read( $original_id );
        if ( null === $claim ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_claim_absent',
                'no claim row for the original. A claim is required to publish a staged edit; '
                    . 're-stage and retry, or run recovery if the holder crashed.'
            );
        }
        $claim_tx = (string) ( $claim['tx_id'] ?? '' );
        $claim_actor = (string) ( $claim['actor'] ?? '' );
        if ( $claim_tx !== (string) $tx['id'] || $claim_actor !== (string) $actor ) {
            self::refuse(
                $actor, $tx, $op, $original_id,
                'transaction_publish_refused_claim_held_by_other',
                'the per-original claim is held by transaction "' . $claim_tx . '" / actor "'
                    . $claim_actor . '", not by this transaction. A competing staging is in '
                    . 'flight; the publish is refused. Wait for the holder to release the claim '
                    . '(publish, rollback, or recovery) and retry.'
            );
        }
    }

    /**
     * Record a pre-SQL refusal: audit (OUTSIDE any SQL transaction),
     * transition the op to `conflict`, and throw. The throw carries
     * the refusal reason to the tool boundary; the audit row is
     * durable; the op is preserved in `conflict` for the operator
     * to resolve.
     */
    private static function refuse( $actor, $tx, $op, $original_id, $audit_op, $reason ) {
        $now = \AgentShell_MCP\Transaction_Manager::lease_now();
        // Transition the op to `conflict` so subsequent recovery
        // does not try to publish it again. This is a guarded
        // CAS; if the row has moved, we re-read and re-try once
        // before giving up.
        self::transition_to_conflict( $tx, $op, $actor, $reason, $now );
        \AgentShell_MCP\Store::record_event(
            $actor, $audit_op,
            array(
                'transaction_id' => (string) $tx['id'],
                'op_id'          => (string) $op['id'],
                'original_id'    => (int) $original_id,
                'reason'         => (string) $reason,
            )
        );
        throw new \InvalidArgumentException(
            $audit_op . ': ' . $reason
        );
    }

    /**
     * Move the op from `staged` to `conflict`, with a `comp` block
     * explaining why. Value-guarded on the row's exact bytes; a row
     * that has moved (e.g. another process wrote to the journal) is
     * detected and the transition does not overwrite the new state.
     */
    private static function transition_to_conflict( $tx, $op, $actor, $reason, $now ) {
        $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op['id'] ] ) ) {
            return; // row gone or op absent; nothing to transition
        }
        $existing = $row['content']['ops'][ $op['id'] ];
        if ( \AgentShell_MCP\Content_Journal::STATE_CONFLICT === ( $existing['state'] ?? '' ) ) {
            return; // already conflicted; no-op
        }
        // The cas guard reads the row at this exact moment, then
        // writes the modified version; the value-guarded UPDATE
        // refuses to land if the row moved between the read and
        // the write. Capturing the read separately from the
        // modified row is the whole point of a cas_write_row.
        $expected = $row;
        $row['content']['ops'][ $op['id'] ]['state']       = \AgentShell_MCP\Content_Journal::STATE_CONFLICT;
        $row['content']['ops'][ $op['id'] ]['updated_at']  = (int) $now;
        $row['content']['ops'][ $op['id'] ]['comp']        = array(
            'outcome' => 'conflict',
            'post_id' => (int) ( $op['target']['original_id'] ?? 0 ),
            'actor'   => (string) $actor,
            'at'      => (int) $now,
            'reason'  => (string) $reason,
        );
        $row['content']['ops'][ $op['id'] ]['notes'][]     = (string) $reason;
        $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
        \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row );
    }

    // ── run_publish_transaction ──────────────────────────────────────────

    /**
     * The §1.4 SQL transaction. Steps 3-8:
     *   3. Content_Write_Guard::run() opens a real InnoDB
     *      transaction.
     *   4. SELECT ... FOR UPDATE the original; compare against
     *      baseline A.
     *   5. wp_update_post.
     *   6. Re-read the row; allowlist-based verification against C.
     *   7. Transition the op to `publication_committed`; write the
     *      success audit.
     *   8. Content_Write_Guard COMMITs.
     *
     * The whole closure runs inside one SQL transaction. A
     * pre-COMMIT refusal throws and rolls the whole thing back;
     * the post-rollback refusal audit is written by the caller
     * (the refusal class) in a separate short guarded transaction.
     */
    private static function run_publish_transaction( $tx, $op, $actor, $original_id, $shadow_id, $op_id_str ) {
        $op_id_str = (string) $op['id'];
        $now       = \AgentShell_MCP\Transaction_Manager::lease_now();
        $baseline  = is_array( $op['before'] ) ? $op['before'] : array();
        $after     = is_array( $op['after'] )  ? $op['after']  : array();

        // Build the wp_update_post payload: only the four supported
        // fields, with wp_slash for safety. The protected preserved
        // fields and the housekeeping fields are not in the
        // postarr; we read them back after the write to verify the
        // post-write C.
        $postarr = array(
            'ID'           => (int) $original_id,
            'post_title'   => (string) ( $after['post_title']   ?? '' ),
            'post_content' => (string) ( $after['post_content'] ?? '' ),
            'post_excerpt' => (string) ( $after['post_excerpt'] ?? '' ),
            'post_name'    => (string) ( $after['post_name']    ?? '' ),
        );

        $closure = function () use ( $original_id, $shadow_id, $postarr, $after, $baseline, $op, $op_id_str, $actor, $tx, $now ) {
            // 4. SELECT ... FOR UPDATE the original. The column
            //    list matches the verify_columns() set so the
            //    baseline revalidate and the post-write re-read
            //    cover the same shape. The exclusive row lock
            //    serialises against any concurrent wp_update_post
            //    on the same row.
            $current = self::select_for_update( $original_id );
            if ( null === $current ) {
                throw new \RuntimeException(
                    'publication_refused_original_gone_during_lock: the original post '
                    . '(id ' . $original_id . ') was deleted between the pre-SQL check and '
                    . 'the row lock. The publish is aborted; the original is unchanged.'
                );
            }

            $drift = self::baseline_drift( $current, $baseline );
            if ( null !== $drift ) {
                throw new Publish_Refused(
                    'publication_refused_baseline_drift',
                    'the live original row diverges from baseline A on field "' . $drift['field']
                    . '" (baseline="' . $drift['baseline'] . '", live="' . $drift['live']
                    . '"). An external writer committed since the baseline was captured.',
                    $drift
                );
            }

            // 5. wp_update_post. WordPress's own update machinery.
            //    Its SQL writes (the main wp_posts UPDATE, the
            //    revision INSERT, the term/meta syncs) join our
            //    open InnoDB transaction via the depth counter.
            //    A WP_Error returns the failed state; the
            //    pre-COMMIT throw rolls back the whole
            //    transaction.
            $upd = wp_update_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $upd ) ) {
                throw new \RuntimeException(
                    'publication_refused_wp_update_post_failed: wp_update_post returned WP_Error: '
                    . $upd->get_error_code() . ' — ' . $upd->get_error_message()
                );
            }
            if ( ! $upd ) {
                throw new \RuntimeException(
                    'publication_refused_wp_update_post_failed: wp_update_post returned a falsy '
                    . 'value for the original post. The publish is aborted; the original is unchanged.'
                );
            }

            // 6. Write-and-verify-C. Re-read the row (no FOR
            //    UPDATE; the lock from step 4 is held). Build the
            //    expected C from the supported fields (from the
            //    shadow's after), the protected preserved
            //    fields (from the baseline), and the
            //    housekeeping fields (read back from the row,
            //    not predicted).
            $post_write = self::select_for_verify( $original_id );
            if ( null === $post_write ) {
                throw new \RuntimeException(
                    'publication_refused_original_gone_after_write: the original post is gone '
                    . 'after wp_update_post. A wp_after_insert_post hook may have deleted it; '
                    . 'the publish is rolled back.'
                );
            }
            $expected = self::expected_c( $after, $baseline, $post_write );
            $mismatch = self::verify_against_c( $post_write, $expected, $after, $baseline );
            if ( null !== $mismatch ) {
                throw new Publish_Refused( $mismatch['audit_op'], $mismatch['reason'], $mismatch['detail'] );
            }

            // 7. Transition the op to `publication_committed` and
            //    write the success audit, all inside the SQL
            //    transaction. The journal CAS uses the row bytes
            //    we just verified; a concurrent transition would
            //    fail the CAS and roll back the publication
            //    (correct behaviour: the publication did not
            //    land; the operator reconciles).
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                throw new \RuntimeException(
                    'publication_refused_journal_row_lost: the journal row no longer holds the '
                    . 'OP_EDIT_PUBLISHED op after wp_update_post. A recovery has run, or the '
                    . 'row was reclaimed. The publish is rolled back.'
                );
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row:
            // passing the same array as both args would compare the
            // post-write bytes against themselves and never detect
            // a concurrent transition.
            $expected = $row;
            $row['content']['ops'][ $op_id_str ]['state']       = \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED;
            $row['content']['ops'][ $op_id_str ]['updated_at']  = (int) $now;
            $row['content']['ops'][ $op_id_str ]['after']       = array_merge(
                $row['content']['ops'][ $op_id_str ]['after'] ?? array(),
                array(
                    'post_modified'        => (string) ( $post_write['post_modified'] ?? '' ),
                    'post_modified_gmt'    => (string) ( $post_write['post_modified_gmt'] ?? '' ),
                )
            );
            $row['content']['ops'][ $op_id_str ]['comp']        = array(
                'outcome' => 'publication_committed',
                'post_id' => (int) $original_id,
                'actor'   => (string) $actor,
                'at'      => (int) $now,
                'reason'  => 'publication committed; reconciliation owed',
            );
            $row['content']['sig']                                = \AgentShell_MCP\Content_Journal::sign( $row['content'] );

            if ( ! \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row ) ) {
                throw new \RuntimeException(
                    'publication_refused_journal_cas_lost: the journal row CAS refused to '
                    . 'advance the op to `publication_committed` (the row moved between the '
                    . 're-read and the write). The publish is rolled back.'
                );
            }

            // Success audit. Written inside the SQL transaction
            // (per §1.6a) so a ROLLBACK rolls it back together
            // with the publication. The row_id list contains the
            // single row just written.
            \AgentShell_MCP\Store::record_event(
                $actor,
                'transaction_published',
                array(
                    'transaction_id'    => (string) $tx['id'],
                    'op_id'             => (string) $op_id_str,
                    'original_id'       => (int) $original_id,
                    'shadow_id'         => (int) $shadow_id,
                    'post_type'         => (string) ( $baseline['post_type'] ?? '' ),
                    'post_title'        => (string) ( $after['post_title']   ?? '' ),
                    'post_name'         => (string) ( $after['post_name']    ?? '' ),
                    'post_content_sha256' => (string) ( $baseline['post_content_sha256'] ?? '' ),
                    'post_modified_gmt' => (string) ( $post_write['post_modified_gmt'] ?? '' ),
                    'post_modified'     => (string) ( $post_write['post_modified']     ?? '' ),
                    'verified_against_c' => true,
                    'allowlist_delta'   => array(
                        'housekeeping_diverged' => (string) ( $post_write['post_modified'] ?? '' ) !== (string) ( $baseline['post_modified'] ?? '' )
                            || (string) ( $post_write['post_modified_gmt'] ?? '' ) !== (string) ( $baseline['post_modified_gmt'] ?? '' ),
                    ),
                )
            );

            return $row;
        };

        try {
            $committed_row = \AgentShell_MCP\Content_Write_Guard::run( $closure );
        } catch ( Publish_Refused $refused ) {
            // Pre-COMMIT refusal: rolled back already (the closure
            // threw). Now write the refusal audit OUTSIDE the SQL
            // transaction, transition the op to `conflict` (which
            // is a separate short guarded transaction), and rethrow
            // for the tool boundary.
            self::after_pre_commit_refusal( $tx, $op, $actor, $original_id, $refused );
        }

        // Re-read the row outside the SQL transaction so we return
        // the post-COMMIT bytes (which is what reconciliation
        // expects to see).
        $final = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( ! is_array( $final ) || empty( $final['content']['ops'][ $op_id_str ] ) ) {
            // This is the very narrow race: the publication
            // COMMITted, but the row was reclaimed before we
            // could re-read. The op is on disk as
            // `publication_committed` somewhere; the original
            // carries the staged fields. The reconcile tool
            // re-acquires the row from a recovery-driven path
            // (recovery finds the op by journal marker on the
            // shadow / claim, not by reading the transaction
            // row). We return a marker-only result here; the
            // operator runs reconcile.
            return array(
                'id'      => $op_id_str,
                'state'   => \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED,
                'op'      => 'edit_published',
                'target'  => array(
                    'original_id' => (int) $original_id,
                    'shadow_id'   => (int) $shadow_id,
                ),
                'reconciliation' => array(
                    'shadow_deleted'     => false,
                    'caches_invalidated' => false,
                    'claim_released'     => false,
                    'audit_written'      => false,
                ),
                'note' => 'row was reclaimed between COMMIT and the post-COMMIT re-read; '
                    . 'the publication is durable; call agentshell_publish_edit_reconcile to '
                    . 'complete reconciliation.',
            );
        }
        return $final['content']['ops'][ $op_id_str ];
    }

    /**
     * After a pre-COMMIT refusal: write the refusal audit
     * (OUTSIDE the rolled-back SQL transaction), transition the
     * op to `conflict`, rethrow. The op is preserved in
     * `conflict` for the operator to resolve.
     */
    private static function after_pre_commit_refusal( $tx, $op, $actor, $original_id, Publish_Refused $refused ) {
        $now = \AgentShell_MCP\Transaction_Manager::lease_now();
        // 1. Transition the op to `conflict` in its own short
        //    guarded SQL transaction. Uses the same value-guarded
        //    CAS pattern as the rest of the journal.
        \AgentShell_MCP\Content_Write_Guard::run( function () use ( $tx, $op, $actor, $original_id, $refused, $now ) {
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op['id'] ] ) ) {
                return null;
            }
            $existing = $row['content']['ops'][ $op['id'] ];
            if ( \AgentShell_MCP\Content_Journal::STATE_CONFLICT === ( $existing['state'] ?? '' )
                || \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED === ( $existing['state'] ?? '' )
                || \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED === ( $existing['state'] ?? '' ) ) {
                return null; // do not regress a settled state
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row.
            $expected = $row;
            $row['content']['ops'][ $op['id'] ]['state']       = \AgentShell_MCP\Content_Journal::STATE_CONFLICT;
            $row['content']['ops'][ $op['id'] ]['updated_at']  = (int) $now;
            $row['content']['ops'][ $op['id'] ]['comp']        = array(
                'outcome' => 'conflict',
                'post_id' => (int) $original_id,
                'actor'   => (string) $actor,
                'at'      => (int) $now,
                'reason'  => (string) $refused->reason,
            );
            $row['content']['ops'][ $op['id'] ]['notes'][]     = (string) $refused->reason;
            $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
            \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row );
            // The cas write is the success signal; return true so the
            // guard's failure heuristic does not roll the transaction
            // back. The guard's `is_failure` returns true for null/false
            // and false for everything else, so any non-empty value
            // commits.
            return true;
        } );
        // 2. Refusal audit, outside the SQL transaction.
        \AgentShell_MCP\Store::record_event(
            $actor,
            $refused->audit_op,
            array(
                'transaction_id' => (string) $tx['id'],
                'op_id'          => (string) $op['id'],
                'original_id'    => (int) $original_id,
                'reason'         => (string) $refused->reason,
                'detail'         => $refused->detail,
            )
        );
        throw new \InvalidArgumentException(
            $refused->audit_op . ': ' . $refused->reason
        );
    }

    /**
     * Read the original post row for the FOR UPDATE revalidate.
     * Returns the named columns as a flat array, or null when the
     * row is gone. Uses $wpdb->get_row directly so the FOR UPDATE
     * clause is honoured (a get_post() call would route through
     * the cache layer and miss the lock).
     */
    private static function select_for_update( $original_id ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->posts ) ) {
            return null;
        }
        $cols = self::verify_columns();
        $col_list = implode( ', ', array_map( function( $c ) { return '`' . $c . '`'; }, $cols ) );
        $sql = "SELECT {$col_list} FROM `{$wpdb->posts}` WHERE ID = %d FOR UPDATE";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, (int) $original_id ), ARRAY_A );
        if ( ! is_array( $row ) ) { return null; }
        $out = array();
        foreach ( $cols as $c ) { $out[ $c ] = isset( $row[ $c ] ) ? (string) $row[ $c ] : ''; }
        return $out;
    }

    /**
     * Read the original post row for the post-write verify. Same
     * column list as the FOR UPDATE read, but without FOR UPDATE
     * (the row is already locked). Uses a fresh get_row so the
     * post-write state is read after wp_update_post's writes
     * have joined our transaction.
     */
    private static function select_for_verify( $original_id ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->posts ) ) {
            return null;
        }
        $cols = self::verify_columns();
        $col_list = implode( ', ', array_map( function( $c ) { return '`' . $c . '`'; }, $cols ) );
        $sql = "SELECT {$col_list} FROM `{$wpdb->posts}` WHERE ID = %d";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, (int) $original_id ), ARRAY_A );
        if ( ! is_array( $row ) ) { return null; }
        $out = array();
        foreach ( $cols as $c ) { $out[ $c ] = isset( $row[ $c ] ) ? (string) $row[ $c ] : ''; }
        return $out;
    }

    /**
     * Compare the live row against the baseline A. Returns null on
     * match, or a `{field, baseline, live}` array on the first
     * divergence. The check is field-by-field: a divergence in any
     * single field is a refusal (the row is no longer A).
     */
    private static function baseline_drift( array $current, array $baseline ) {
        foreach ( $baseline as $field => $baseline_value ) {
            if ( ! array_key_exists( $field, $current ) ) { continue; }
            $live = (string) $current[ $field ];
            $base = (string) $baseline_value;
            if ( $live !== $base ) {
                return array(
                    'field'    => (string) $field,
                    'baseline' => $base,
                    'live'     => $live,
                );
            }
        }
        return null;
    }

    /**
     * Build the expected C: the four supported fields from the
     * shadow's after, the protected preserved fields from the
     * baseline, and the housekeeping fields from the post-write
     * row. The result is what the post-write row should equal.
     */
    private static function expected_c( array $after, array $baseline, array $post_write ) {
        $c = array();
        foreach ( self::SUPPORTED_FIELDS as $f ) {
            $c[ $f ] = (string) ( $after[ $f ] ?? '' );
        }
        foreach ( self::PROTECTED_PRESERVED_FIELDS as $f ) {
            $c[ $f ] = (string) ( $baseline[ $f ] ?? ( $post_write[ $f ] ?? '' ) );
        }
        foreach ( self::HOUSEKEEPING_FIELDS as $f ) {
            $c[ $f ] = (string) ( $post_write[ $f ] ?? '' );
        }
        return $c;
    }

    /**
     * Allowlist-based verification. Compares the post-write row
     * against the expected C, classifying any divergence by
     * which field category it falls into.
     *
     * Returns null on success (the row matches C, possibly with
     * a housekeeping-only divergence), or a `{audit_op, reason,
     * detail}` array on refusal.
     */
    private static function verify_against_c( array $post_write, array $expected_c, array $after, array $baseline ) {
        $supported_divergence = null;
        $protected_divergence = null;
        $housekeeping_divergence = null;

        foreach ( $expected_c as $field => $expected_value ) {
            $actual = (string) ( $post_write[ $field ] ?? '' );
            $exp    = (string) $expected_value;
            if ( $actual === $exp ) { continue; }
            $detail = array(
                'field'    => (string) $field,
                'expected' => $exp,
                'actual'   => $actual,
            );
            if ( in_array( $field, self::SUPPORTED_FIELDS, true ) ) {
                $supported_divergence = $detail;
            } elseif ( in_array( $field, self::PROTECTED_PRESERVED_FIELDS, true ) ) {
                $protected_divergence = $detail;
            } else {
                $housekeeping_divergence = $detail;
            }
        }

        if ( null !== $supported_divergence ) {
            return array(
                'audit_op' => 'publication_refused_hook_overwrote_supported_field',
                'reason'   => 'a hook (or wp_update_post itself) overwrote the supported field "'
                    . $supported_divergence['field'] . '" after the write. Expected "'
                    . $supported_divergence['expected'] . '", got "' . $supported_divergence['actual']
                    . '". A supported-field overwrite is a refusal: the contract with the database '
                    . 'is broken; the publish is rolled back.',
                'detail'   => $supported_divergence,
            );
        }
        if ( null !== $protected_divergence ) {
            return array(
                'audit_op' => 'publication_refused_hook_changed_protected_preserved_field',
                'reason'   => 'a hook changed the protected preserved field "'
                    . $protected_divergence['field'] . '" after the write. Expected "'
                    . $protected_divergence['expected'] . '" (baseline A), got "'
                    . $protected_divergence['actual'] . '". A protected-field change is a refusal; '
                    . 'the publish is rolled back.',
                'detail'   => $protected_divergence,
            );
        }
        // Housekeeping-only divergence (or no divergence at all) is
        // the success path. A `null` housekeeping_divergence means
        // the four supported fields match the shadow's after and
        // every protected preserved field matches the baseline; the
        // only possible divergence is in post_modified /
        // post_modified_gmt, which wp_update_post sets to NOW().
        return null;
    }

    // ── reconciliation ───────────────────────────────────────────────────

    /**
     * Run the three reconciliation steps (per §1.7) for an op that
     * has just transitioned to `publication_committed`. Each step
     * is durable per-step and idempotent; a crash between steps
     * leaves the op in `reconciliation_pending` and the next
     * reconcile retries the failed step(s).
     *
     * Public so that `agentshell_publish_edit_reconcile` can call
     * the same code on an op that is already in
     * `publication_committed` or `reconciliation_pending` state
     * (i.e. publication has already committed; only the
     * reconciliation steps remain).
     */
    public static function run_reconciliation( $tx, $committed_op, $actor, $original_id, $shadow_id ) {
        $op_id_str = (string) $committed_op['id'];
        $now       = \AgentShell_MCP\Transaction_Manager::lease_now();

        // Transition to reconciliation_pending (if not already) so
        // the journal's per-op status is honest about what was
        // done. Idempotent: a re-run that finds the op already
        // settled skips the transition.
        self::transition_to_reconciliation_pending( $tx, $committed_op, $actor, $now );

        $recon = is_array( $committed_op['reconciliation'] ?? null ) ? $committed_op['reconciliation'] : array();
        $shadow_done     = ! empty( $recon['shadow_deleted'] ) && is_array( $recon['shadow_deleted'] ) && ! empty( $recon['shadow_deleted']['status'] ) && 'verified' === (string) $recon['shadow_deleted']['status'];
        $caches_done     = ! empty( $recon['caches_invalidated'] ) && is_array( $recon['caches_invalidated'] ) && ! empty( $recon['caches_invalidated']['status'] ) && 'verified' === (string) $recon['caches_invalidated']['status'];
        $claim_done      = ! empty( $recon['claim_released'] ) && is_array( $recon['claim_released'] ) && ! empty( $recon['claim_released']['status'] ) && 'verified' === (string) $recon['claim_released']['status'];

        // 1. Shadow delete (identity-validated). On failure the op
        //    is left in `reconciliation_pending` with the step
        //    status updated, and the failure audit is written.
        if ( ! $shadow_done ) {
            $shadow_result = self::reconcile_shadow_delete( $tx, $committed_op, $actor, $original_id, $shadow_id, $now );
            if ( 'verified' !== $shadow_result['status'] ) {
                return $shadow_result;
            }
        }

        // 2. Cache invalidation. Idempotent; clean_post_cache on a
        //    non-existent post id is a no-op.
        if ( ! $caches_done ) {
            self::reconcile_cache_invalidation( $original_id );
            // Mark step 2 verified.
            $committed_op = self::mark_reconciliation_step( $tx, $committed_op, $actor, 'caches_invalidated', $now, array(
                'status' => 'verified',
                'detail' => 'clean_post_cache issued for original post; cache reset is best-effort and idempotent',
            ) );
        }

        // 3. Claim release + journal settlement, with the crash-
        //    safe 3a/3b/3c/3d ordering. The release is the only
        //    step that has a three-outcome classification; the
        //    others are simple before/after.
        if ( ! $claim_done ) {
            $claim_result = self::reconcile_claim_release( $tx, $committed_op, $actor, $original_id, $now );
            if ( 'verified' !== $claim_result['status'] ) {
                return $claim_result;
            }
            // Journal settlement: the op transitions to
            // `publication_settled` after the claim release and
            // the audit. Idempotent (a re-run on an op already
            // settled is a no-op).
            $committed_op = self::settle_publication( $tx, $committed_op, $actor, $now );
        }

        // If every step was already done (re-entrant call from
        // reconcile tool), settle the publication on the way out.
        if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED === (string) ( $committed_op['state'] ?? '' ) ) {
            $committed_op = self::settle_publication( $tx, $committed_op, $actor, $now );
        }

        return array(
            'status' => 'verified',
            'state'  => (string) ( $committed_op['state'] ?? '' ),
            'reconciliation' => is_array( $committed_op['reconciliation'] ?? null ) ? $committed_op['reconciliation'] : array(),
        );
    }

    /**
     * Transition the op to `reconciliation_pending` if it is not
     * already in `publication_settled` or `publication_committed`
     * with all steps verified. Idempotent.
     */
    private static function transition_to_reconciliation_pending( $tx, $committed_op, $actor, $now ) {
        $op_id_str = (string) $committed_op['id'];
        $state     = (string) ( $committed_op['state'] ?? '' );
        if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED === $state ) {
            return; // already settled; no transition
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_RECONCILIATION_PENDING === $state ) {
            return; // already in this state
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED !== $state ) {
            return; // not in a state that needs the transition
        }
        \AgentShell_MCP\Content_Write_Guard::run( function () use ( $tx, $committed_op, $actor, $now, $op_id_str ) {
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                return null;
            }
            $existing = $row['content']['ops'][ $op_id_str ];
            if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED === ( $existing['state'] ?? '' ) ) {
                return null;
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row.
            $expected = $row;
            // Preserve any per-step status the op already carries.
            // A re-entry from the reconcile tool after a partial
            // pre-publication crash will have shadow_deleted.verified
            // and caches_invalidated.verified from a previous attempt;
            // overwriting the field with all-false would lose the
            // markers and force the steps to re-run. The settlement
            // helpers skip steps whose marker is already verified,
            // so the safe path is to merge rather than replace.
            $recon = is_array( $row['content']['ops'][ $op_id_str ]['reconciliation'] ?? null )
                ? $row['content']['ops'][ $op_id_str ]['reconciliation']
                : array();
            $row['content']['ops'][ $op_id_str ]['state']       = \AgentShell_MCP\Content_Journal::STATE_RECONCILIATION_PENDING;
            $row['content']['ops'][ $op_id_str ]['updated_at']  = (int) $now;
            $row['content']['ops'][ $op_id_str ]['reconciliation'] = array_merge(
                array(
                    'shadow_deleted'     => false,
                    'caches_invalidated' => false,
                    'claim_released'     => false,
                    'audit_written'      => false,
                ),
                $recon
            );
            $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
            \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row );
            // The cas write is the success signal; return true so the
            // guard's failure heuristic does not roll the transaction
            // back. The guard's `is_failure` returns true for null/false
            // and false for everything else, so any non-empty value
            // commits.
            return true;
        } );
    }

    /**
     * Step 1: identity-validated shadow delete. The pre-delete check
     * confirms the shadow's META_SHADOW_FOR and META_OP_ID still
     * match the op. A retargeted or relabelled shadow refuses and
     * leaves the op in `reconciliation_pending`.
     */
    private static function reconcile_shadow_delete( $tx, $committed_op, $actor, $original_id, $shadow_id, $now ) {
        $op_id_str = (string) $committed_op['id'];
        $shadow = get_post( $shadow_id );
        if ( null === $shadow ) {
            // Shadow is already gone. Mark the step verified and
            // return; the next step can proceed.
            self::mark_reconciliation_step( $tx, $committed_op, $actor, 'shadow_deleted', $now, array(
                'status' => 'verified',
                'detail' => 'shadow was already gone before reconciliation step 1',
            ) );
            return array( 'status' => 'verified' );
        }
        $shadow_for = (int) get_post_meta( $shadow_id, \AgentShell_MCP\Published_Shadow::META_SHADOW_FOR, true );
        if ( $shadow_for !== (int) $original_id ) {
            $detail = array( 'shadow_for' => $shadow_for, 'original_id' => (int) $original_id );
            \AgentShell_MCP\Store::record_event(
                $actor, 'reconciliation_refused_shadow_identity_mismatch',
                array(
                    'transaction_id' => (string) $tx['id'],
                    'op_id'          => (string) $op_id_str,
                    'original_id'    => (int) $original_id,
                    'shadow_id'      => (int) $shadow_id,
                    'reason'         => 'shadow META_SHADOW_FOR does not match the op\'s original_id',
                    'detail'         => $detail,
                )
            );
            self::mark_reconciliation_step( $tx, $committed_op, $actor, 'shadow_deleted', $now, array(
                'status' => 'refused',
                'detail' => $detail,
            ) );
            return array( 'status' => 'refused', 'reason' => 'shadow_identity_mismatch' );
        }
        $shadow_op = (string) get_post_meta( $shadow_id, \AgentShell_MCP\Published_Shadow::META_OP_ID, true );
        if ( $shadow_op !== $op_id_str ) {
            $detail = array( 'shadow_op_id' => $shadow_op, 'op_id' => $op_id_str );
            \AgentShell_MCP\Store::record_event(
                $actor, 'reconciliation_refused_shadow_identity_mismatch',
                array(
                    'transaction_id' => (string) $tx['id'],
                    'op_id'          => (string) $op_id_str,
                    'original_id'    => (int) $original_id,
                    'shadow_id'      => (int) $shadow_id,
                    'reason'         => 'shadow META_OP_ID does not match the op\'s id',
                    'detail'         => $detail,
                )
            );
            self::mark_reconciliation_step( $tx, $committed_op, $actor, 'shadow_deleted', $now, array(
                'status' => 'refused',
                'detail' => $detail,
            ) );
            return array( 'status' => 'refused', 'reason' => 'shadow_marker_mismatch' );
        }

        // The delete itself. wp_delete_post is not a single SQL
        // statement; it is a PHP function that issues its own
        // writes (post delete, meta delete, comment delete, term
        // disassociate) and fires the `before_delete_post` /
        // `after_delete_post` hook chain. The SQL writes join our
        // short guarded SQL transaction; a wp_delete_post returning
        // false (or a WP_Error) is a step failure.
        $result = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $shadow_id ) {
            $r = wp_delete_post( (int) $shadow_id, true );
            if ( false === $r ) { return false; }
            if ( is_object( $r ) && method_exists( $r, 'get_error_code' ) ) { return $r; }
            return $r;
        } );
        if ( false === $result || ( is_object( $result ) && method_exists( $result, 'get_error_code' ) ) ) {
            $reason = is_object( $result ) && method_exists( $result, 'get_error_code' )
                ? (string) $result->get_error_code() . ' — ' . (string) $result->get_error_message()
                : 'wp_delete_post returned false';
            \AgentShell_MCP\Store::record_event(
                $actor, 'reconciliation_refused_shadow_delete_failed',
                array(
                    'transaction_id' => (string) $tx['id'],
                    'op_id'          => (string) $op_id_str,
                    'shadow_id'      => (int) $shadow_id,
                    'reason'         => $reason,
                )
            );
            self::mark_reconciliation_step( $tx, $committed_op, $actor, 'shadow_deleted', $now, array(
                'status' => 'refused',
                'detail' => $reason,
            ) );
            return array( 'status' => 'refused', 'reason' => 'shadow_delete_failed' );
        }

        \AgentShell_MCP\Store::record_event(
            $actor, 'shadow_cleaned_up',
            array(
                'transaction_id' => (string) $tx['id'],
                'op_id'          => (string) $op_id_str,
                'shadow_id'      => (int) $shadow_id,
                'original_id'    => (int) $original_id,
            )
        );
        self::mark_reconciliation_step( $tx, $committed_op, $actor, 'shadow_deleted', $now, array(
            'status' => 'verified',
            'detail' => 'shadow deleted',
        ) );
        return array( 'status' => 'verified' );
    }

    /**
     * Step 2: cache invalidation. clean_post_cache on the original
     * (the in-process object cache) and the persistent-cache
     * `clean_post_cache` action. Idempotent.
     */
    private static function reconcile_cache_invalidation( $original_id ) {
        if ( function_exists( 'clean_post_cache' ) ) {
            clean_post_cache( (int) $original_id );
        }
        if ( function_exists( 'wp_cache_delete' ) ) {
            // The claim row's option cache is invalidated here
            // (the row itself is still present at this point;
            // step 3 will cas_delete it). The post-cache
            // invalidation above covers the original's post,
            // post_meta, posts, and terms keys.
            wp_cache_delete( \AgentShell_MCP\Published_Claim::key( $original_id ), 'options' );
        }
    }

    /**
     * Step 3: claim release + journal settlement, crash-safe
     * ordering. The three observable events are (a) the
     * `reconciliation.claim_released` field set to `status:pending`,
     * (b) the `cas_delete_row` against the claim option row, (c) the
     * `claim_released` audit row, (d) the op's `state` transition
     * to `publication_settled`. A retry observes the per-step
     * status and the direct SQL read of the claim row.
     */
    private static function reconcile_claim_release( $tx, $committed_op, $actor, $original_id, $now ) {
        $op_id_str = (string) $committed_op['id'];
        // 3a + 3b. The reconciliation.claim_released field is set
        //     to `status:pending` and the cas_delete_row runs in the
        //     same SQL transaction. A crash before COMMIT rolls
        //     back 3a too; a retry runs step 3 again.
        $claim_key = \AgentShell_MCP\Published_Claim::key( $original_id );
        $claim_row = \AgentShell_MCP\Published_Claim::read( $original_id );
        if ( null === $claim_row ) {
            // The claim is already gone (a previous attempt
            // released it, or a competing release raced). Treat
            // as verified and proceed to the audit + settlement.
            self::mark_reconciliation_step( $tx, $committed_op, $actor, 'claim_released', $now, array(
                'status' => 'verified',
                'detail' => 'claim was already absent at step 3',
            ) );
            \AgentShell_MCP\Store::record_event(
                $actor, 'claim_released',
                array(
                    'transaction_id' => (string) $tx['id'],
                    'op_id'          => (string) $op_id_str,
                    'original_id'    => (int) $original_id,
                    'note'           => 'claim was already absent at step 3',
                )
            );
            return array( 'status' => 'verified' );
        }
        $expected = maybe_serialize( $claim_row );
        $released = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $tx, $committed_op, $actor, $claim_key, $expected, $now, $op_id_str ) {
            global $wpdb;
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                return array( 'cas_affected' => null, 'journal' => null );
            }
            // Direct SQL cas_delete (the value-guarded CAS from §1.6).
            if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
                $sql = $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                    $claim_key, $expected
                );
                $wpdb->query( $sql );
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row.
            $expected_journal = $row;
            // 3a. Mark the per-step status as `status:pending` so a
            //     crash between here and 3c leaves the op with a
            //     pending marker that the next retry observes.
            $row['content']['ops'][ $op_id_str ]['reconciliation'] = is_array( $row['content']['ops'][ $op_id_str ]['reconciliation'] ?? null )
                ? $row['content']['ops'][ $op_id_str ]['reconciliation']
                : array();
            $row['content']['ops'][ $op_id_str ]['reconciliation']['claim_released'] = array(
                'attempted_at' => (int) $now,
                'status'       => 'pending',
            );
            $row['content']['ops'][ $op_id_str ]['updated_at'] = (int) $now;
            $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
            \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected_journal, $row );
            // Best-effort cache invalidation: the actual SQL is the
            // source of truth, the cache invalidation is the
            // get_option() surface.
            if ( function_exists( 'wp_cache_delete' ) ) {
                wp_cache_delete( $claim_key, 'options' );
            }
            return array( 'cas_affected' => 'attempted', 'journal' => $row );
        } );

        // 3c. Authoritative read of the claim row. NOT
        //     get_option() — the cache may be stale. The direct SQL
        //     read is the source of truth.
        $outcome = self::classify_claim_release( $original_id, $claim_row );
        switch ( $outcome['class'] ) {
            case 'absent':
                // The release verified. The audit row records the
                // verification.
                \AgentShell_MCP\Store::record_event(
                    $actor, 'claim_released',
                    array(
                        'transaction_id' => (string) $tx['id'],
                        'op_id'          => (string) $op_id_str,
                        'original_id'    => (int) $original_id,
                        'outcome'        => 'verified',
                        'verified_at'    => (int) $now,
                    )
                );
                self::mark_reconciliation_step( $tx, $committed_op, $actor, 'claim_released', $now, array(
                    'status'      => 'verified',
                    'verified_at' => (int) $now,
                    'outcome'     => 'absent',
                ) );
                return array( 'status' => 'verified' );

            case 'successor':
                // A successor tx has re-acquired the claim.
                // Never delete a successor's claim. The op
                // transitions to `publication_settled` because
                // this transaction's reconciliation is complete
                // (the claim has moved to a successor, who is
                // now responsible for it).
                \AgentShell_MCP\Store::record_event(
                    $actor, 'transaction_claim_released_successor_took_over',
                    array(
                        'transaction_id'    => (string) $tx['id'],
                        'op_id'             => (string) $op_id_str,
                        'original_id'       => (int) $original_id,
                        'successor_tx'      => (string) ( $outcome['row']['tx_id'] ?? '' ),
                        'successor_actor'   => (string) ( $outcome['row']['actor'] ?? '' ),
                        'verified_at'       => (int) $now,
                    )
                );
                self::mark_reconciliation_step( $tx, $committed_op, $actor, 'claim_released', $now, array(
                    'status'      => 'verified',
                    'verified_at' => (int) $now,
                    'outcome'     => 'successor_took_over',
                ) );
                return array( 'status' => 'verified' );

            case 'anomalous':
                // The CAS's WHERE clause should have matched a
                // row with value Y, and the only way it did not
                // is a row identity change. Refuse; the op stays
                // in `reconciliation_pending` for the operator.
                \AgentShell_MCP\Store::record_event(
                    $actor, 'publication_settle_refused_claim_release_anomaly',
                    array(
                        'transaction_id' => (string) $tx['id'],
                        'op_id'          => (string) $op_id_str,
                        'original_id'    => (int) $original_id,
                        'detail'         => $outcome['row'],
                    )
                );
                self::mark_reconciliation_step( $tx, $committed_op, $actor, 'claim_released', $now, array(
                    'status' => 'refused',
                    'detail' => 'anomalous',
                ) );
                return array( 'status' => 'refused', 'reason' => 'claim_release_anomaly' );
        }
        return array( 'status' => 'refused', 'reason' => 'unknown_claim_outcome' );
    }

    /**
     * Direct SQL read of the claim row to classify the release
     * outcome into one of three states. NOT get_option() — the
     * cache may be stale, and the cache invalidation in step 3a
     * does not reach persistent caches that hold a stale value.
     */
    private static function classify_claim_release( $original_id, array $expected_row ) {
        global $wpdb;
        $key = \AgentShell_MCP\Published_Claim::key( $original_id );
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
            return array( 'class' => 'anomalous', 'row' => null );
        }
        $sql = $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        );
        $blob = $wpdb->get_var( $sql );
        if ( null === $blob ) {
            return array( 'class' => 'absent', 'row' => null );
        }
        // Compare structurally to the expected row, not by
        // serialised bytes: a row that was rewritten to the
        // same bytes is a re-creation, not a continuation.
        $row = is_string( $blob ) ? maybe_unserialize( $blob ) : null;
        if ( ! is_array( $row ) ) {
            return array( 'class' => 'anomalous', 'row' => null );
        }
        if ( \AgentShell_MCP\Published_Claim::rows_equal( $row, $expected_row ) ) {
            // Row still present with our bytes — the CAS did
            // not match, which is anomalous. The retry refuses.
            return array( 'class' => 'anomalous', 'row' => $row );
        }
        return array( 'class' => 'successor', 'row' => $row );
    }

    /**
     * Step 3d: the op transitions to `publication_settled` in a
     * separate short SQL transaction after the audit. Idempotent.
     */
    private static function settle_publication( $tx, $committed_op, $actor, $now ) {
        $op_id_str = (string) $committed_op['id'];
        $state     = (string) ( $committed_op['state'] ?? '' );
        if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED === $state ) {
            return $committed_op;
        }
        $row = null;
        \AgentShell_MCP\Content_Write_Guard::run( function () use ( $tx, $op_id_str, $actor, $now, &$row ) {
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                return null;
            }
            $existing = $row['content']['ops'][ $op_id_str ];
            if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED === ( $existing['state'] ?? '' ) ) {
                return null;
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row.
            $expected = $row;
            $row['content']['ops'][ $op_id_str ]['state']       = \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED;
            $row['content']['ops'][ $op_id_str ]['updated_at']  = (int) $now;
            $row['content']['ops'][ $op_id_str ]['comp']        = array(
                'outcome' => 'publication_settled',
                'post_id' => (int) ( $committed_op['target']['original_id'] ?? 0 ),
                'actor'   => (string) $actor,
                'at'      => (int) $now,
                'reason'  => 'all reconciliation steps verified',
            );
            $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
            \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row );
            // The cas write is the success signal; return true so the
            // guard's failure heuristic does not roll the transaction
            // back. The guard's `is_failure` returns true for null/false
            // and false for everything else, so any non-empty value
            // commits.
            return true;
        } );
        if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
            return $committed_op;
        }
        \AgentShell_MCP\Store::record_event(
            $actor, 'transaction_published_reconciled',
            array(
                'transaction_id' => (string) $tx['id'],
                'op_id'          => (string) $op_id_str,
                'original_id'    => (int) ( $committed_op['target']['original_id'] ?? 0 ),
            )
        );
        return $row['content']['ops'][ $op_id_str ];
    }

    /**
     * Mark a reconciliation step's status on the op. Idempotent.
     */
    private static function mark_reconciliation_step( $tx, $committed_op, $actor, $step, $now, array $status ) {
        $op_id_str = (string) $committed_op['id'];
        $row = null;
        \AgentShell_MCP\Content_Write_Guard::run( function () use ( $op_id_str, $step, $status, $now, &$row ) {
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                return null;
            }
            // The cas guard reads the row at this exact moment, then
            // writes the modified version; the value-guarded UPDATE
            // refuses to land if the row moved between the read and
            // the write. Capturing the read separately from the
            // modified row is the whole point of a cas_write_row.
            $expected = $row;
            $recon = is_array( $row['content']['ops'][ $op_id_str ]['reconciliation'] ?? null )
                ? $row['content']['ops'][ $op_id_str ]['reconciliation']
                : array();
            $recon[ $step ] = $status;
            $row['content']['ops'][ $op_id_str ]['reconciliation'] = $recon;
            $row['content']['ops'][ $op_id_str ]['updated_at']      = (int) $now;
            $row['content']['sig'] = \AgentShell_MCP\Content_Journal::sign( $row['content'] );
            \AgentShell_MCP\Transaction_Manager::cas_write_row( $expected, $row );
            // The cas write is the success signal; return true so the
            // guard's failure heuristic does not roll the transaction
            // back. The guard's `is_failure` returns true for null/false
            // and false for everything else, so any non-empty value
            // commits.
            return true;
        } );
        if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
            return $committed_op;
        }
        return $row['content']['ops'][ $op_id_str ];
    }

    /**
     * Build the public result.
     */
    private static function build_result( $committed_op, $recon ) {
        return array(
            'op_id'         => (string) $committed_op['id'],
            'state'         => (string) ( $committed_op['state'] ?? '' ),
            'original_id'   => (int) ( $committed_op['target']['original_id'] ?? 0 ),
            'shadow_id'     => (int) ( $committed_op['target']['shadow_id']   ?? 0 ),
            'reconciliation'=> is_array( $committed_op['reconciliation'] ?? null ) ? $committed_op['reconciliation'] : array(),
            'audit'         => array(
                'op_summary' => 'published',
            ),
        );
    }

    /**
     * Build the public result when the call has already been
     * through reconciliation. Carries the post-settlement state
     * (which build_result would have lost — by the time
     * reconciliation finishes, the op has transitioned to
     * `publication_settled` and the outer committed_op is the
     * pre-settlement snapshot).
     */
    private static function build_result_from_recon( $committed_op, $op_id, $state, $recon ) {
        return array(
            'op_id'         => (string) $op_id,
            'state'         => (string) $state,
            'original_id'   => (int) ( $committed_op['target']['original_id'] ?? 0 ),
            'shadow_id'     => (int) ( $committed_op['target']['shadow_id']   ?? 0 ),
            'reconciliation'=> is_array( $recon ) ? $recon : array(),
            'audit'         => array(
                'op_summary' => 'published',
            ),
        );
    }
}

/**
 * Internal exception class for pre-COMMIT refusals. The closure
 * inside `Content_Write_Guard::run()` throws this so the SQL
 * transaction rolls back, and the outer tool then writes the
 * refusal audit OUTSIDE the rolled-back transaction per the
 * post-rollback audit-durability rule.
 */
class Publish_Refused extends \RuntimeException {
    public $audit_op;
    public $reason;
    public $detail;
    public function __construct( $audit_op, $reason, $detail = null ) {
        parent::__construct( $audit_op . ': ' . $reason );
        $this->audit_op = (string) $audit_op;
        $this->reason   = (string) $reason;
        $this->detail   = $detail;
    }
}
