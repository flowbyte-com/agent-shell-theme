<?php
namespace AgentShell_Blocks\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Register_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_register_widget'; }
    public function get_description() { return 'Register or update an agent-defined widget in the blocks registry.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'       => array( 'type' => 'string', 'description' => 'Widget ID (alphanumeric + dash/underscore, max 50)', 'pattern' => '^[a-zA-Z0-9_-]+$', 'maxLength' => 50 ),
                'name'     => array( 'type' => 'string', 'maxLength' => 100 ),
                'init_js'  => array( 'type' => 'string', 'maxLength' => 5000 ),
                'css'      => array( 'type' => 'string', 'maxLength' => 5000 ),
                'template' => array( 'type' => 'string', 'maxLength' => 2000 ),
            ),
            'required' => array( 'id', 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id', 'name' ) );

        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $arguments['id'] ) ) {
            throw new \InvalidArgumentException( 'Widget ID must be alphanumeric with dashes/underscores only.' );
        }
        if ( strlen( $arguments['id'] ) > 50 ) {
            throw new \InvalidArgumentException( 'Widget ID must be 50 characters or less.' );
        }

        $widget = array(
            'id'   => $arguments['id'],
            'name' => $arguments['name'],
        );
        if ( ! empty( $arguments['init_js'] ) )  { $widget['init_js']  = $arguments['init_js']; }
        if ( ! empty( $arguments['css'] ) )       { $widget['css']      = $arguments['css']; }
        if ( ! empty( $arguments['template'] ) ) { $widget['template'] = $arguments['template']; }

        $registry = \AgentShell_Blocks\Widget_Registry::get_instance();
        return $registry->save_widget( $widget );
    }
}
