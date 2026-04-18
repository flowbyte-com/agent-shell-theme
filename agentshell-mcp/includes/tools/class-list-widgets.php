<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Widgets extends Base_Tool {
    public function get_name() { return 'agentshell_list_widgets'; }
    public function get_description() { return 'List all registered widgets — stable (file-based) and agent-defined.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        $widgets = array();

        // Stable widgets from file system
        $stable_index = get_template_directory() . '/widgets/.index.json';
        if ( file_exists( $stable_index ) ) {
            $index = json_decode( file_get_contents( $stable_index ), true );
            foreach ( $index['stable'] ?? array() as $entry ) {
                $widgets[] = array(
                    'id'      => $entry['id'] ?? '',
                    'name'    => $entry['name'] ?? '',
                    'source'  => 'stable',
                    'version' => $entry['version'] ?? '',
                );
            }
        }

        // Agent-defined widgets from config
        $config = $this->get_agentshell_config();
        foreach ( $config['widgets'] ?? array() as $w ) {
            $widgets[] = array(
                'id'     => $w['id'] ?? '',
                'name'   => $w['name'] ?? '',
                'source' => 'agent',
            );
        }

        return array( 'widgets' => $widgets );
    }
}
