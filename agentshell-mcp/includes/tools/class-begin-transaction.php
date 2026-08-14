<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_begin_transaction — open a transactional change session.
 *
 * While open, all config mutations are staged instead of written to the
 * database. Commit applies them atomically; rollback discards them.
 */
class Begin_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_begin_transaction'; }
    public function get_description() { return 'Open a transactional change session. Until committed or rolled back, every config mutation is staged in a buffer instead of the database — the live site stays untouched. Make multiple changes, validate, preview the diff, then commit or roll back.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'label' => array( 'type' => 'string', 'description' => 'Short human-readable description, e.g. "switch to dark palette"' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $tx = \AgentShell_MCP\Transaction_Manager::instance()->begin( $arguments['label'] ?? '' );
        return array(
            'transaction' => $tx,
            'tip'         => 'Now call mutation tools (set_design, update_zone_slots, ...). Then agentshell_preview_transaction / agentshell_validate_transaction, then agentshell_commit_transaction or agentshell_rollback_transaction.',
        );
    }
}