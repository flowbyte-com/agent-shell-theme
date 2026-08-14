<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_preview_transaction — diff of staged changes vs live config.
 */
class Preview_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_preview_transaction'; }
    public function get_description() { return 'Return a token-level diff of everything staged in the open transaction against the live config: added, removed, changed tokens. Nothing is persisted.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $tx  = \AgentShell_MCP\Transaction_Manager::instance();
        $diff = $tx->preview();
        return array(
            'transaction_id' => $tx->payload()['id'],
            'diff'           => $diff,
            'summary'        => array(
                'added'   => count( $diff['added'] ),
                'removed' => count( $diff['removed'] ),
                'changed' => count( $diff['changed'] ),
            ),
            'tip'            => 'Review the diff, then agentshell_validate_transaction before agentshell_commit_transaction.',
        );
    }
}