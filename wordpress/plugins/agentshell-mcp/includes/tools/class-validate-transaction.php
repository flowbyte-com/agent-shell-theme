<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_validate_transaction — validate the staged config without committing.
 */
class Validate_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_validate_transaction'; }
    public function get_description() { return 'Run the site doctor against the staged configuration of the open transaction. Returns errors and warnings. Errors must be resolved before commit will succeed.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $tx     = \AgentShell_MCP\Transaction_Manager::instance();
        $issues = $tx->validate(); // throws when no transaction is open
        return array(
            'transaction_id' => $tx->payload()['id'],
            'status'         => empty( $issues['errors'] ) ? 'ok' : 'issues',
            'errors'         => $issues['errors'],
            'warnings'       => $issues['warnings'],
            'tip'            => empty( $issues['errors'] )
                ? 'Clean. agentshell_commit_transaction will succeed.'
                : 'Fix the errors with more mutation calls, then re-validate.',
        );
    }
}