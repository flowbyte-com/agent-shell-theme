<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_restore_snapshot — apply a saved snapshot to the live site.
 */
class Restore_Snapshot extends Base_Tool {
    public function get_name() { return 'agentshell_restore_snapshot'; }
    public function get_description() { return 'Restore the live configuration from a named snapshot. Recorded as a new revision and audit entry, so it is reversible with agentshell_restore_revision.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'description' => 'Snapshot name to restore' ),
            ),
            'required' => array( 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name' ) );

        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException( 'A transaction is open. Commit or roll it back before restoring a snapshot.' );
        }

        $snapshot = \AgentShell_MCP\Store::get_snapshot( sanitize_key( $arguments['name'] ) );
        if ( null === $snapshot ) {
            throw new \InvalidArgumentException( "Snapshot not found: {$arguments['name']}. Use agentshell_list_snapshots." );
        }

        $current = $this->get_agentshell_config();
        \AgentShell_MCP\Store::$operation_label = 'restore snapshot ' . $snapshot['name']
            . ( $snapshot['label'] ? ': ' . $snapshot['label'] : '' );
        $this->update_agentshell_config( $snapshot['config'] );

        return array(
            'restored' => $snapshot['name'],
            'diff'     => \AgentShell_MCP\Store::diff_config( $current, $snapshot['config'] ),
        );
    }
}