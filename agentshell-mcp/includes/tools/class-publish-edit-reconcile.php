<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_publish_edit_reconcile — idempotent retry of the
 * three reconciliation steps for an OP_EDIT_PUBLISHED op that has
 * committed but not yet settled.
 *
 * Stage 2c Milestone 4. The `agentshell_publish_edit` tool runs the
 * three reconciliation steps (shadow delete, cache invalidation,
 * claim release + journal settlement) immediately after the
 * publication COMMITs. A crash, a refused pre-COMMIT step, or a
 * failure between COMMIT and the third step leaves the op in
 * `publication_committed` or `reconciliation_pending` with the
 * per-step `reconciliation` record showing which steps are still
 * owed. This tool re-runs the owed steps, observing the existing
 * per-step status to keep the work idempotent.
 *
 * ## Why a separate tool
 *
 * Three of the eleven §1.4 steps are durable per-step rather than
 * atomic with the publication, by design: a WordPress hook chain
 * (e.g. `wp_delete_post`'s `before_delete_post` / `after_delete_post`)
 * runs outside any single SQL transaction, and forcing them inside
 * the §1.4 transaction would expose the publication to
 * non-atomic WordPress side effects. So the per-step model is
 * correct, and a separate tool is the natural surface for "the
 * publication committed; now finish the cleanup."
 *
 * ## Refusal conditions
 *
 *   - Not in a transaction. The reconcile tool's state lives in
 *     the journal, which lives inside a transaction.
 *   - No `OP_EDIT_PUBLISHED` op in the journal, or the op is not
 *     in `publication_committed` or `reconciliation_pending` (any
 *     other state is refused by name with a hint about the actual
 *     state and what to do about it).
 *   - Op's actor is not the calling actor.
 *   - The per-tx advisory lock is unavailable or held by another
 *     connection.
 *   - The claim has been held by a successor transaction. A
 *     successor's claim is never deleted by this tool; the
 *     classify_claim_release() helper detects the successor and
 *     the op is settled in `publication_settled` because the
 *     reconciliation work is done (the claim has moved to a
 *     successor, who is now responsible for it).
 *
 * Every refusal writes a refusal audit row OUTSIDE any SQL
 * transaction, per the post-rollback audit-durability rule.
 *
 * ## Idempotency
 *
 * The reconciliation record on the op carries the per-step
 * `status:verified` markers. Each helper in `Publish_Edit`
 * checks its step's marker and skips work that is already
 * verified. The crash-safe ordering of the claim release (3a/3b/3c/3d)
 * is preserved across retries: a retry that observes a
 * `status:pending` marker on `claim_released` knows the cas_delete
 * was attempted but the SQL COMMIT or the post-COMMIT audit may
 * not have landed, and re-attempts the deletion with the same
 * value-guarded CAS.
 */
class Publish_Edit_Reconcile extends Base_Tool {
    public function get_name() { return 'agentshell_publish_edit_reconcile'; }

    public function get_description() {
        return 'Idempotent retry of the three reconciliation steps (shadow delete, cache '
            . 'invalidation, claim release) for an OP_EDIT_PUBLISHED op that has committed but '
            . 'not yet settled. Refuses if the op is in any state other than '
            . '`publication_committed` or `reconciliation_pending`. Each step is durable '
            . 'per-step; a retry observes the per-step status and re-runs only the owed steps. '
            . 'Settles the op to `publication_settled` once all three steps are verified.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'op_id' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 64,
                    'description' => 'Optional. The journal op id to reconcile. Defaults to the '
                        . 'only OP_EDIT_PUBLISHED op in the journal; a journal with more than one '
                        . 'such op is a configuration error and is refused by name.',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $op_id_arg = isset( $arguments['op_id'] ) ? (string) $arguments['op_id'] : '';

        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx_mgr->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_publish_edit_reconcile refuses outside a transaction. The '
                . 'reconciliation record is a field on the journal op, and the journal lives '
                . 'inside a transaction. Open a transaction and call this tool again.'
            );
        }

        $tx    = $tx_mgr->payload();
        $tx_id = (string) $tx['id'];
        $actor = \AgentShell_MCP\Store::actor();

        $content = \AgentShell_MCP\Content_Journal::of( $tx );
        $op      = self::locate_reconcile_op( $content, $op_id_arg, $actor );

        $original_id = (int) ( $op['target']['original_id'] ?? 0 );
        $shadow_id   = (int) ( $op['target']['shadow_id']   ?? 0 );
        $op_id_str   = (string) $op['id'];

        // Acquire the per-tx advisory lock. Same locking discipline
        // as the publish tool: the lock is per-tx, not per-original,
        // and is held through the end of reconciliation.
        $lock_held = self::acquire_reconcile_lock( $tx_id, $actor, $op_id_str, $original_id );

        try {
            // The pre-reconcile check is narrower than the publish
            // tool's pre-SQL check: the publication has already
            // landed, so the original is not deleted (it carries
            // the staged fields), and the shadow identity question
            // becomes "is the shadow still where step 1 expects
            // it?" — which is exactly the identity check inside
            // reconcile_shadow_delete().
            //
            // What we do check up-front: the op is in
            // publication_committed / reconciliation_pending (we
            // did that in locate_reconcile_op), and the lease
            // has not expired (the operator is calling the tool
            // inside a fresh enough transaction to be
            // exercising the lease).
            $lease = \AgentShell_MCP\Transaction_Manager::lease_now();
            $expires_at = (int) ( $tx['lease_expires_at'] ?? 0 );
            if ( $expires_at > 0 && $lease >= $expires_at ) {
                \AgentShell_MCP\Store::record_event(
                    $actor, 'transaction_reconcile_refused_lease_expired',
                    array(
                        'transaction_id' => (string) $tx['id'],
                        'op_id'          => (string) $op_id_str,
                        'original_id'    => (int) $original_id,
                        'lease_now'      => (int) $lease,
                        'lease_expires'  => (int) $expires_at,
                    )
                );
                throw new \InvalidArgumentException(
                    'transaction_reconcile_refused_lease_expired: the transaction\'s lease has '
                    . 'expired; recovery owns this op, not a fresh reconcile. Run '
                    . 'agentshell_recover_transaction to settle or roll back the op.'
                );
            }

            // Re-read the op from the journal row, not the
            // in-process cache. The op's `state` and
            // `reconciliation` record are the source of truth
            // for the per-step status.
            $row = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id_str ] ) ) {
                throw new \RuntimeException(
                    'reconcile_refused_journal_row_lost: the journal row no longer holds the '
                    . 'OP_EDIT_PUBLISHED op; the op has been settled or rolled back. The '
                    . 'reconcile is a no-op.'
                );
            }
            $committed_op = $row['content']['ops'][ $op_id_str ];

            // Delegate to the publish tool's reconciliation
            // implementation. The same per-step status reading
            // and the same crash-safe ordering apply.
            $recon = \AgentShell_MCP\Tools\Publish_Edit::run_reconciliation(
                $tx, $committed_op, $actor, $original_id, $shadow_id
            );

            return self::build_result( $committed_op, $recon );
        } finally {
            if ( $lock_held ) {
                \AgentShell_MCP\Recovery_Advisory_Lock::release( 'tx_' . $tx_id );
            }
        }
    }

    /**
     * Find the OP_EDIT_PUBLISHED op to reconcile. Throws on every
     * refusal; returns the op array on success. The accepted state
     * set is `publication_committed` and `reconciliation_pending`
     * (publication has landed; only the cleanup is owed).
     */
    private static function locate_reconcile_op( array $content, $op_id_arg, $actor ) {
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
                'transaction_reconcile_refused_no_op: this transaction\'s journal has no '
                . 'OP_EDIT_PUBLISHED op. agentshell_publish_edit_reconcile is the retry tool '
                . 'for a published op that has not yet settled. Run agentshell_publish_edit '
                . 'first (the op commits before reconcile is owed) and then call this tool.'
            );
        }
        if ( '' !== $op_id_arg && ! isset( $edits[ $op_id_arg ] ) ) {
            throw new \InvalidArgumentException(
                'transaction_reconcile_refused_op_id_not_found: the supplied op_id "' . $op_id_arg
                . '" does not name an OP_EDIT_PUBLISHED op in this transaction\'s journal.'
            );
        }
        if ( 1 < count( $edits ) && '' === $op_id_arg ) {
            $ids = implode( ', ', array_keys( $edits ) );
            throw new \InvalidArgumentException(
                'transaction_reconcile_refused_multiple_ops: this transaction\'s journal carries '
                . count( $edits ) . ' OP_EDIT_PUBLISHED ops (' . $ids . '). agentshell_publish_edit_reconcile '
                . 'refuses to guess; supply op_id to name the one to reconcile.'
            );
        }
        $op      = ( '' === $op_id_arg ) ? reset( $edits ) : $edits[ $op_id_arg ];
        $state   = (string) ( $op['state'] ?? '' );
        $op_actor = (string) ( $op['actor'] ?? '' );

        if ( $op_actor !== (string) $actor ) {
            throw new \InvalidArgumentException(
                'transaction_reconcile_refused_actor_mismatch: the OP_EDIT_PUBLISHED op '
                . (string) $op['id'] . ' was recorded by actor "' . $op_actor . '", but the '
                . 'current actor is "' . (string) $actor . '". Only the op\'s owner may '
                . 'reconcile it.'
            );
        }
        if ( \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_COMMITTED !== $state
            && \AgentShell_MCP\Content_Journal::STATE_RECONCILIATION_PENDING !== $state ) {
            $hint = self::state_hint( $state );
            throw new \InvalidArgumentException(
                'transaction_reconcile_refused_op_state_unexpected: OP_EDIT_PUBLISHED op '
                . (string) $op['id'] . ' is in state "' . $state . '"; agentshell_publish_edit_reconcile '
                . 'only reconciles ops in `publication_committed` or `reconciliation_pending` state.'
                . $hint
            );
        }
        return $op;
    }

    private static function state_hint( $state ) {
        switch ( $state ) {
            case \AgentShell_MCP\Content_Journal::STATE_STAGED:
                return ' The publication has not yet committed; run agentshell_publish_edit first.';
            case \AgentShell_MCP\Content_Journal::STATE_PUBLICATION_SETTLED:
                return ' The publication is fully settled; nothing to reconcile.';
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

    /**
     * Acquire the per-tx advisory lock. Same shape as the publish
     * tool's lock acquisition. Refuses with refusal audit if the
     * lock is unavailable or held by another connection.
     */
    private static function acquire_reconcile_lock( $tx_id, $actor, $op_id, $original_id ) {
        if ( ! \AgentShell_MCP\Recovery_Advisory_Lock::available() ) {
            \AgentShell_MCP\Store::record_event(
                $actor,
                'transaction_reconcile_refused_lock_unavailable',
                array(
                    'transaction_id' => (string) $tx_id,
                    'op_id'          => (string) $op_id,
                    'original_id'    => (int) $original_id,
                )
            );
            throw new \InvalidArgumentException(
                'transaction_reconcile_refused_lock_unavailable: agentshell_publish_edit_reconcile '
                . 'requires MySQL/MariaDB named advisory locks, and the engine probe says they '
                . 'are unavailable on this database. Refusing to reconcile.'
            );
        }
        $got = \AgentShell_MCP\Recovery_Advisory_Lock::acquire( 'tx_' . $tx_id );
        if ( $got ) {
            return true;
        }
        \AgentShell_MCP\Store::record_event(
            $actor,
            'transaction_reconcile_refused_publisher_active',
            array(
                'transaction_id' => (string) $tx_id,
                'op_id'          => (string) $op_id,
                'original_id'    => (int) $original_id,
                'reason'         => 'another connection holds the per-tx reconcile lock',
            )
        );
        throw new \InvalidArgumentException(
            'transaction_reconcile_refused_publisher_active: another connection holds the '
            . 'per-tx reconcile lock for transaction "' . (string) $tx_id . '". A publish or '
            . 'reconcile is already in flight, or recovery is running. Wait for it to finish '
            . 'and retry; the lock is released on connection close.'
        );
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
                'op_summary' => 'reconciled',
            ),
        );
    }
}
