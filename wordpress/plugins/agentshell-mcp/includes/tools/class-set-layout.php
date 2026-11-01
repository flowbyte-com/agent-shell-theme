<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Layout extends Base_Tool {
    public function get_name() { return 'agentshell_set_layout'; }
    public function get_description() { return 'Update layout grid areas per breakpoint, gap, and padding.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'breakpoints'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'mobile'  => array( 'type' => 'string' ),
                        'tablet'  => array( 'type' => 'string' ),
                        'desktop' => array( 'type' => 'string' ),
                    ),
                ),
                'grid_areas'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'mobile'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                        'tablet'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                        'desktop' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    ),
                ),
                'grid_gap'    => array( 'type' => 'string' ),
                'grid_padding'=> array( 'type' => 'string' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( ! isset( $config['layout'] ) ) { $config['layout'] = array(); }

        if ( ! empty( $arguments['breakpoints'] ) ) {
            $config['layout']['breakpoints'] = $arguments['breakpoints'];
        }
        if ( ! empty( $arguments['grid_areas'] ) ) {
            $config['layout']['grid_areas'] = $arguments['grid_areas'];
        }
        if ( isset( $arguments['grid_gap'] ) ) {
            $config['layout']['grid_gap'] = $arguments['grid_gap'];
        }
        if ( isset( $arguments['grid_padding'] ) ) {
            $config['layout']['grid_padding'] = $arguments['grid_padding'];
        }

        $this->update_agentshell_config( $config );
        return $config['layout'];
    }
}
