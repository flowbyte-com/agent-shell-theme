<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Register_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_register_widget'; }
    public function get_description() { return 'Register or update an agent-defined widget.'; }
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
            'required'   => array( 'id', 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id', 'name' ) );

        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $arguments['id'] ) ) {
            throw new \InvalidArgumentException( 'Widget ID must be alphanumeric with dashes/underscores only' );
        }
        if ( strlen( $arguments['id'] ) > 50 ) {
            throw new \InvalidArgumentException( 'Widget ID must be 50 characters or less' );
        }

        $widget = array(
            'id'   => $arguments['id'],
            'name' => $arguments['name'],
        );
        if ( ! empty( $arguments['init_js'] ) )  { $widget['init_js']  = $arguments['init_js']; }
        if ( ! empty( $arguments['css'] ) )       { $widget['css']       = $arguments['css']; }
        if ( ! empty( $arguments['template'] ) )  { $widget['template']  = $arguments['template']; }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['widgets'] ) ) { $config['widgets'] = array(); }

        $found = false;
        foreach ( $config['widgets'] as &$w ) {
            if ( $w['id'] === $widget['id'] ) {
                $w = array_merge( $w, $widget );
                $found = true;
                break;
            }
        }
        unset( $w );

        if ( ! $found ) {
            $config['widgets'][] = $widget;
        }

        $this->update_agentshell_config( $config );
        return $widget;
    }
}
