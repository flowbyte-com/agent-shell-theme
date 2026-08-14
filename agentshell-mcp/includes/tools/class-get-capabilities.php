<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_capabilities — capability discovery.
 *
 * Lets MCP clients discover what this installation supports instead of
 * assuming. Mirrors the server's initialize() capabilities.
 */
class Get_Capabilities extends Base_Tool {
    public function get_name() { return 'agentshell_get_capabilities'; }
    public function get_description() { return 'Return the capabilities of this AgentShell installation: design, zones, widgets, content, transactions, revisions, snapshots, profiles, preview, screenshot, audit. Call this before planning work on an unfamiliar installation.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $tx = \AgentShell_MCP\Transaction_Manager::instance();

        return array(
            'server_version'  => defined( 'AGENTSHELL_MCP_VERSION' ) ? AGENTSHELL_MCP_VERSION : 'unknown',
            'protocol_version' => class_exists( '\AgentShell_MCP\Server' ) ? \AgentShell_MCP\Server::PROTOCOL_VERSION : '2025-03-26',
            'theme_active'    => function_exists( 'agentshell_get_config' ),
            'capabilities'    => array(
                'design'       => true,
                'zones'        => true,
                'widgets'      => true,
                'content'      => true,
                'transactions' => true,
                'revisions'    => true,
                'snapshots'    => true,
                'profiles'     => true,
                'preview'      => true,
                'screenshot'   => false,
                'audit'        => true,
            ),
            'state' => array(
                'transaction_open' => $tx->is_active(),
                'transaction_id'   => $tx->is_active() ? $tx->payload()['id'] : null,
            ),
        );
    }
}