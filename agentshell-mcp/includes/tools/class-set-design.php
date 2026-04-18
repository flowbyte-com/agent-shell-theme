<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Design extends Base_Tool {
    public function get_name() { return 'agentshell_set_design'; }
    public function get_description() { return 'Update design system values (colors, typography). All fields optional — only provided fields are updated.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'colors'     => array(
                    'type'       => 'object',
                    'properties' => array(
                        'background' => array( 'type' => 'string' ),
                        'surface'    => array( 'type' => 'string' ),
                        'text'       => array( 'type' => 'string' ),
                        'accent'     => array( 'type' => 'string' ),
                        'border'     => array( 'type' => 'string' ),
                    ),
                ),
                'typography' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'fontFamily' => array( 'type' => 'string' ),
                        'baseSize'   => array( 'type' => 'string' ),
                    ),
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }

        if ( ! empty( $arguments['colors'] ) ) {
            if ( ! isset( $config['design']['colors'] ) ) { $config['design']['colors'] = array(); }
            foreach ( $arguments['colors'] as $k => $v ) {
                $config['design']['colors'][ $k ] = $v;
            }
        }

        if ( ! empty( $arguments['typography'] ) ) {
            if ( ! isset( $config['design']['typography'] ) ) { $config['design']['typography'] = array(); }
            foreach ( $arguments['typography'] as $k => $v ) {
                $config['design']['typography'][ $k ] = $v;
            }
        }

        $this->update_agentshell_config( $config );
        return $config['design'];
    }
}
