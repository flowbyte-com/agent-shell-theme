<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_diff_snapshot — compare a snapshot against the live config.
 */
class Diff_Snapshot extends Base_Tool {
    public function get_name() { return 'agentshell_diff_snapshot'; }
    public function get_description() { return 'Token-level diff between a saved snapshot and the current live configuration.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'description' => 'Snapshot name to diff against the live config' ),
            ),
            'required' => array( 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name' ) );
        $snapshot = \AgentShell_MCP\Store::get_snapshot( sanitize_key( $arguments['name'] ) );
        if ( null === $snapshot ) {
            throw new \InvalidArgumentException( "Snapshot not found: {$arguments['name']}. Use agentshell_list_snapshots." );
        }

        $live = $this->get_agentshell_config();
        $diff = \AgentShell_MCP\Store::diff_config( $snapshot['config'], $live );

        return array(
            'name'    => $snapshot['name'],
            'diff'    => $diff,
            'summary' => array(
                'added'   => count( $diff['added'] ),
                'removed' => count( $diff['removed'] ),
                'changed' => count( $diff['changed'] ),
            ),
        );
    }
}