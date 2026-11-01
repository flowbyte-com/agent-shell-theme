<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_create_snapshot — named, restorable copy of the full config.
 */
class Create_Snapshot extends Base_Tool {
    public function get_name() { return 'agentshell_create_snapshot'; }
    public function get_description() { return 'Capture the full current configuration as a named snapshot. Snapshots are restorable (agentshell_restore_snapshot), diffable, and exportable as theme packages. Max 20 snapshots (oldest dropped).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'  => array( 'type' => 'string', 'description' => 'Snapshot name, e.g. "launch-day" or "dark-2026"' ),
                'label' => array( 'type' => 'string', 'description' => 'Optional description' ),
            ),
            'required' => array( 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name' ) );
        $name = sanitize_key( $arguments['name'] );
        if ( '' === $name ) {
            throw new \InvalidArgumentException( 'Snapshot name must be alphanumeric (hyphens/underscores allowed).' );
        }
        $snapshot = \AgentShell_MCP\Store::create_snapshot( $name, $arguments['label'] ?? '' );
        return array(
            'snapshot' => array(
                'name'      => $snapshot['name'],
                'label'     => $snapshot['label'],
                'actor'     => $snapshot['actor'],
                'timestamp' => $snapshot['timestamp'],
            ),
            'tip' => 'Restore with agentshell_restore_snapshot, diff with agentshell_diff_snapshot, export with agentshell_export_theme.',
        );
    }
}