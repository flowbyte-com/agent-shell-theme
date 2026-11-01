<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_list_snapshots — list saved configuration snapshots.
 */
class List_Snapshots extends Base_Tool {
    public function get_name() { return 'agentshell_list_snapshots'; }
    public function get_description() { return 'List all saved configuration snapshots with name, label, actor, and timestamp.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $out = array();
        foreach ( \AgentShell_MCP\Store::snapshots() as $snapshot ) {
            $out[] = array(
                'name'      => $snapshot['name'],
                'label'     => $snapshot['label'] ?? '',
                'actor'     => $snapshot['actor'] ?? '',
                'timestamp' => $snapshot['timestamp'] ?? 0,
            );
        }
        return array( 'snapshots' => $out );
    }
}