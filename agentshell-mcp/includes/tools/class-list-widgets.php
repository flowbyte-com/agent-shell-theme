<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_list_widgets — widget registry with lifecycle status.
 */
class List_Widgets extends Base_Tool {
    public function get_name() { return 'agentshell_list_widgets'; }
    public function get_description() { return 'List all registered widgets — stable (file-based) and agent-defined — with lifecycle status (active/disabled), required libraries, and source.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        if ( function_exists( 'agentshell_get_widget_registry' ) ) {
            $registry = agentshell_get_widget_registry();
            $widgets  = array();
            foreach ( $registry as $id => $widget ) {
                $widgets[] = array(
                    'id'     => $id,
                    'name'   => $widget['name'] ?? $id,
                    'status' => $widget['status'] ?? 'active',
                    'libs'   => $widget['libs'] ?? array(),
                    'source' => ( $widget['source'] ?? 'config' ) === 'file' ? 'stable' : 'agent',
                );
            }
            return array( 'widgets' => $widgets );
        }

        // Fallback: stable index + config widgets.
        $widgets = array();
        $stable_index = get_template_directory() . '/widgets/.index.json';
        if ( file_exists( $stable_index ) ) {
            $index = json_decode( file_get_contents( $stable_index ), true );
            foreach ( $index['stable'] ?? array() as $entry ) {
                $widgets[] = array(
                    'id'      => $entry['id'] ?? '',
                    'name'    => $entry['name'] ?? '',
                    'status'  => 'active',
                    'source'  => 'stable',
                    'version' => $entry['version'] ?? '',
                );
            }
        }
        $config = $this->get_agentshell_config();
        foreach ( $config['widgets'] ?? array() as $w ) {
            $widgets[] = array(
                'id'     => $w['id'] ?? '',
                'name'   => $w['name'] ?? '',
                'status' => $w['status'] ?? 'active',
                'source' => 'agent',
            );
        }
        return array( 'widgets' => $widgets );
    }
}