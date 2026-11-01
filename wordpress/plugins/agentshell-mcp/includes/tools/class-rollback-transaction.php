<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_rollback_transaction — discard all staged changes.
 */
class Rollback_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_rollback_transaction'; }
    public function get_description() { return 'Discard all staged changes of the open transaction. The database was never touched while the transaction was open, so the live site is unaffected.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Transaction_Manager::instance()->rollback();
    }
}