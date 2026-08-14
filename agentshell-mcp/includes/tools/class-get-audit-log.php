<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_audit_log — history of config mutations.
 *
 * Every change to the AgentShell config is recorded with actor, operation,
 * affected tokens, and revision id. Entries are newest-first.
 */
class Get_Audit_Log extends Base_Tool {
    public function get_name() { return 'agentshell_get_audit_log'; }
    public function get_description() { return 'Return the audit history of configuration changes: timestamp, actor, operation, affected CSS tokens, revision id. Newest first, max 100 entries.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'limit' => array( 'type' => 'integer', 'description' => 'Maximum entries to return (default 20, max 100)' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $limit = isset( $arguments['limit'] ) ? (int) $arguments['limit'] : 20;
        $limit = max( 1, min( 100, $limit ) );

        return array(
            'entries' => array_slice( \AgentShell_MCP\Store::audit_log(), 0, $limit ),
            'total'   => count( \AgentShell_MCP\Store::audit_log() ),
        );
    }
}