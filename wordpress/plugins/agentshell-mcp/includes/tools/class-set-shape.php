<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_shape — corner radius and border styling.
 */
class Set_Shape extends Base_Tool {
    public function get_name() { return 'agentshell_set_shape'; }
    public function get_description() { return 'Set shape tokens: radius (base corner radius, e.g. 8px or 0px for a sharp look), borderWidth (e.g. 1px, 2px), borderStyle (solid, dashed, none). Only provided keys change.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'radius'      => array( 'type' => 'string', 'description' => 'CSS length, e.g. 8px, 12px, 0px' ),
                'borderWidth' => array( 'type' => 'string', 'description' => 'CSS length, e.g. 1px, 2px' ),
                'borderStyle' => array( 'type' => 'string', 'enum' => array( 'solid', 'dashed', 'dotted', 'none' ) ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['layout'] ) ) { $config['design']['layout'] = array(); }

        $updated = array();

        if ( array_key_exists( 'radius', $arguments ) ) {
            $radius = trim( (string) $arguments['radius'] );
            if ( ! preg_match( '/^\d*\.?\d+(px|rem|em)$/', $radius ) ) {
                throw new \InvalidArgumentException( "radius must be a CSS length (e.g. 8px, 0px): {$radius}" );
            }
            $config['design']['layout']['radius'] = $radius;
            $updated[] = 'radius';
        }
        if ( array_key_exists( 'borderWidth', $arguments ) ) {
            $width = trim( (string) $arguments['borderWidth'] );
            if ( ! preg_match( '/^\d*\.?\d+(px|rem|em)$/', $width ) ) {
                throw new \InvalidArgumentException( "borderWidth must be a CSS length: {$width}" );
            }
            $config['design']['layout']['borderWidth'] = $width;
            $config['design']['custom_css_vars']['--border-width'] = $width;
            $updated[] = 'borderWidth';
        }
        if ( array_key_exists( 'borderStyle', $arguments ) ) {
            $style = (string) $arguments['borderStyle'];
            if ( ! in_array( $style, array( 'solid', 'dashed', 'dotted', 'none' ), true ) ) {
                throw new \InvalidArgumentException( "borderStyle must be one of: solid, dashed, dotted, none" );
            }
            $config['design']['layout']['borderStyle'] = $style;
            $config['design']['custom_css_vars']['--border-style'] = $style;
            $updated[] = 'borderStyle';
        }

        if ( empty( $updated ) ) {
            throw new \InvalidArgumentException( 'Provide at least one of: radius, borderWidth, borderStyle.' );
        }

        $this->update_agentshell_config( $config );

        return array(
            'updated'  => $updated,
            'geometry' => $config['design']['layout'],
        );
    }
}