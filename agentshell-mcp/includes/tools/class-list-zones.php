<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Zones extends Base_Tool {
    public function get_name() { return 'agentshell_list_zones'; }
    public function get_description() { return 'List all declared zones with their current source type and configuration.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        if ( function_exists( 'agentshell_get_zones' ) ) {
            return array( 'zones' => agentshell_get_zones() );
        }
        $config = $this->get_agentshell_config();
        return array( 'zones' => $config['zones'] ?? array(
            array( 'id' => 'header',  'label' => 'Header',  'source' => 'wp_loop' ),
            array( 'id' => 'main',    'label' => 'Main',    'source' => 'wp_loop' ),
            array( 'id' => 'sidebar', 'label' => 'Sidebar', 'source' => 'wp_widget_area', 'widget_area_id' => 'primary-sidebar' ),
            array( 'id' => 'footer',  'label' => 'Footer',  'source' => 'wp_loop' ),
        ) );
    }
}
