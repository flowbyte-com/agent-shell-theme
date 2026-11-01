<?php
namespace AgentShell_Blocks\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_get_widget'; }
    public function get_description() { return 'Get a single widget definition by ID.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array( 'type' => 'string', 'description' => 'Widget ID to retrieve' ),
            ),
            'required' => array( 'id' ),
        );
    }

public function execute( array $arguments ) {
        $registry = \AgentShell_Blocks\Widget_Registry::get_instance();
        $widget   = $registry->get_widget( $arguments['id'] );

        if ( ! $widget ) {
            throw new \InvalidArgumentException( 'Widget not found: ' . $arguments['id'] );
        }
        $widget['version'] = $registry->get_version();
        return $widget;
    }
}
