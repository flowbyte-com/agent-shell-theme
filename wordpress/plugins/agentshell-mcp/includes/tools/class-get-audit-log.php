<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_audit_log — history of configuration mutations.
 *
 * Every change to the AgentShell config is recorded with actor, operation,
 * affected tokens, and revision id. Entries are newest-first.
 *
 * The optional filters are what make the log usable for one question: what
 * happened to *that* transaction? A finished transaction leaves no row
 * behind — the row is deleted by the commit or the rollback — so the
 * evidence that it was closed, and how, lives here. `operation` finds the
 * rows of one kind (`transaction_rolled_back`, `transaction_committed`,
 * `transaction_reclaimed`, `content_trashed`, …), `transaction_id` finds
 * one transaction's rows, and `since` bounds the window.
 *
 * The log is bounded by construction: the configuration history keeps the
 * most recent hundred entries, and the per-call history in the
 * `wp_agentshell_mcp_audit_log` table is pruned to thirty days and ten
 * thousand rows. Nothing here is retained beyond that, and no credential
 * and no unpublished content is written into it: the entries carry ids,
 * statuses, token names and operation names.
 */
class Get_Audit_Log extends Base_Tool {
    public function get_name() { return 'agentshell_get_audit_log'; }
    public function get_description() { return 'Return the audit history of configuration changes: timestamp, actor, operation, affected CSS tokens, revision id. Newest first, max 100 entries. The optional filters (operation, transaction_id, since) narrow the history to one kind of event or to one transaction — a finished transaction leaves no row behind, so this is where its outcome is still inspectable.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'limit'          => array( 'type' => 'integer', 'description' => 'Maximum entries to return (default 20, max 100)' ),
                'operation'      => array( 'type' => 'string', 'description' => 'Return only entries with this operation, e.g. transaction_rolled_back, transaction_committed, transaction_reclaimed, content_trashed.' ),
                'transaction_id' => array( 'type' => 'string', 'description' => 'Return only entries that name this transaction id.' ),
                'since'          => array( 'type' => 'integer', 'description' => 'Return only entries at or after this Unix timestamp.' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $limit = isset( $arguments['limit'] ) ? (int) $arguments['limit'] : 20;
        $limit = max( 1, min( 100, $limit ) );

        $log = \AgentShell_MCP\Store::audit_log();

        $operation      = isset( $arguments['operation'] ) && '' !== $arguments['operation']
            ? (string) $arguments['operation']
            : '';
        $transaction_id = isset( $arguments['transaction_id'] ) && '' !== $arguments['transaction_id']
            ? (string) $arguments['transaction_id']
            : '';
        $since          = isset( $arguments['since'] ) ? (int) $arguments['since'] : 0;

        $filtered = array();
        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( '' !== $operation && (string) ( $entry['operation'] ?? '' ) !== $operation ) {
                continue;
            }
            if ( $since > 0 && (int) ( $entry['timestamp'] ?? 0 ) < $since ) {
                continue;
            }
            if ( '' !== $transaction_id ) {
                $changed = isset( $entry['changed'] ) && is_array( $entry['changed'] ) ? $entry['changed'] : array();
                if ( (string) ( $changed['transaction_id'] ?? '' ) !== $transaction_id ) {
                    continue;
                }
            }
            $filtered[] = $entry;
        }

        return array(
            'entries' => array_slice( $filtered, 0, $limit ),
            'total'   => count( $filtered ),
            'stored'  => count( $log ),
            'filters' => array(
                'operation'      => $operation,
                'transaction_id' => $transaction_id,
                'since'          => $since,
            ),
        );
    }
}
