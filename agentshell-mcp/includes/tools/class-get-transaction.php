<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_transaction — inspect the open transaction.
 */
class Get_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_get_transaction'; }
    public function get_description() { return 'Return the open transaction (id, label, actor, staged state) or null if none is open.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $tx = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx->is_active() ) {
            return array( 'transaction' => null, 'open' => false );
        }
        $payload = $tx->payload();
        return array(
            'open'        => true,
            'transaction' => array(
                'id'          => $payload['id'],
                'label'       => $payload['label'],
                'actor'       => $payload['actor'],
                'timestamp'   => $payload['timestamp'],
                'has_staged_changes' => is_array( $payload['staged'] ),
                'staged_tokens' => $payload['staged']
                    ? \AgentShell_MCP\Store::diff_token_keys( $payload['before'], $payload['staged'] )
                    : array(),
            ),
        );
    }
}