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
        $mgr = \AgentShell_MCP\Transaction_Manager::instance();
        $tx  = $mgr->begin( $arguments['label'] ?? '' );
        // Read after begin(), not before: the reclaim happens inside it, so an
        // earlier read would always report nothing and the note below would
        // never fire.
        $reclaimed = $mgr->reclaimed();
        $result = array(
            'transaction' => $tx,
            'lease'       => \AgentShell_MCP\Transaction_Manager::describe( $tx ),
            'tip'         => 'Now call mutation tools (set_design, update_zone_slots, ...). Then agentshell_preview_transaction / agentshell_validate_transaction, then agentshell_commit_transaction or agentshell_rollback_transaction.',
        );
        // A crashed session leaves a transaction behind. Recovering from that
        // invisibly is how the failure got as far as an indefinite lock in the
        // first place, so the transaction it replaced is named in the result.
        if ( is_array( $reclaimed ) ) {
            $result['reclaimed'] = $reclaimed;
            $result['note']      = 'An expired transaction from ' . $reclaimed['actor'] . ' was discarded: it had been idle for '
                . ( $reclaimed['lease_ttl_seconds'] - $reclaimed['seconds_remaining'] )
                . 's or more and its staged changes were never written to the database. The audit log records it as '
                . 'transaction_reclaimed.';
        }
        return $result;
    }
}