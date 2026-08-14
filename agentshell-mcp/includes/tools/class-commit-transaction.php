<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_commit_transaction — apply staged changes atomically.
 */
class Commit_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_commit_transaction'; }
    public function get_description() { return 'Validate and apply all staged changes of the open transaction in one atomic write. Records a revision and audit entry. Fails (with no changes applied) if validation errors exist.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $result = \AgentShell_MCP\Transaction_Manager::instance()->commit();
        return array(
            'transaction' => $result,
            'tip'         => 'Changes are live. Use agentshell_list_revisions / agentshell_get_audit_log to confirm, agentshell_restore_revision to undo.',
        );
    }
}