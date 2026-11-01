<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_transaction — inspect the open transaction.
 */
class Get_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_get_transaction'; }
    public function get_description() { return 'Return the open transaction (id, label, actor, staged state) or null if none is open. Also reports the lease: when the transaction was created, when it was last touched, when it expires, and how many seconds remain. An expired transaction is reported under `stale` rather than as `open`.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $tx      = \AgentShell_MCP\Transaction_Manager::instance();
        $payload = $tx->payload();

        if ( ! is_array( $payload ) ) {
            return array( 'transaction' => null, 'open' => false );
        }

        // An expired transaction is not open, and reporting it as though it
        // were is what made this failure invisible in the first place. Say so
        // plainly, and say whether it is safe to discard or is holding work
        // that has to be recovered — the two demand opposite responses from
        // whoever reads this.
        if ( \AgentShell_MCP\Transaction_Manager::is_expired( $payload ) ) {
            $stale = \AgentShell_MCP\Transaction_Manager::describe( $payload );
            $stale['discarded_staged_changes'] = is_array( $payload['staged'] ?? null );
            $stale['recovery'] = \AgentShell_MCP\Transaction_Manager::has_durable_work( $payload )
                ? 'required — this transaction holds work whose only record is its own journal; it was not discarded'
                : 'automatic — a config-only transaction; the next agentshell_begin_transaction discards it';
            return array(
                'open'        => false,
                'transaction' => null,
                'stale'       => $stale,
                'note'        => 'The lease on this transaction ran out, so it is no longer open and no longer stages anything. '
                    . 'Nothing was written to the database while it was open, so nothing needs undoing.',
            );
        }

        // OP_EDIT_PUBLISHED ops stage into the journal, not into
        // `payload['staged']` (which carries config-token diffs).
        // `has_staged_changes` must therefore reflect either path,
        // otherwise a tx that has a staged edit reports
        // has_staged_changes=false even though the staged shadow
        // and claim are live. See §4 of the final verification
        // pass; this is the contract defect the audit exposed.
        $content = isset( $payload[ \AgentShell_MCP\Content_Journal::CONTENT_KEY ] )
            ? $payload[ \AgentShell_MCP\Content_Journal::CONTENT_KEY ]
            : null;
        $has_journal_unsettled = \AgentShell_MCP\Content_Journal::has_unsettled_work( $content );

        return array(
            'open'        => true,
            'transaction' => array(
                'id'          => $payload['id'],
                'label'       => $payload['label'],
                'actor'       => $payload['actor'],
                'timestamp'   => $payload['timestamp'],
                'lease'       => \AgentShell_MCP\Transaction_Manager::describe( $payload ),
                'has_staged_changes' => is_array( $payload['staged'] ) || $has_journal_unsettled,
                'staged_tokens' => $payload['staged']
                    ? \AgentShell_MCP\Store::diff_token_keys( $payload['before'], $payload['staged'] )
                    : array(),
            ),
        );
    }
}