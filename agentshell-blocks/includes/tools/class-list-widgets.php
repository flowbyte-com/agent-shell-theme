<?php
namespace AgentShell_Blocks\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Widgets extends Base_Tool {
    public function get_name() { return 'agentshell_list_widgets'; }
    public function get_description() { return 'List all registered agent-defined widgets from the blocks registry.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
            'additionalProperties' => false,
        );
    }

    public function execute( array $arguments ) {
        $registry = \AgentShell_Blocks\Widget_Registry::get_instance();
        $widgets  = $registry->get_widgets();

        return array(
            'widgets' => array_map( function( $w ) {
                return array(
                    'id'   => $w['id'] ?? '',
                    'name' => $w['name'] ?? '',
                );
            }, $widgets ),
        );
    }
}
