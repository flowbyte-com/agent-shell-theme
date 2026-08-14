<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_palette — semantic color updates.
 */
class Set_Palette extends Base_Tool {
    public function get_name() { return 'agentshell_set_palette'; }
    public function get_description() { return 'Update palette colors semantically. Keys: background, surface, text, border, accent, primary, secondary. Values must be hex (#rgb/#rgba/#rrggbb/#rrggbbaa). Only provided keys change.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'colors' => array(
                    'type'       => 'object',
                    'description' => 'Color key → hex value',
                    'additionalProperties' => array( 'type' => 'string' ),
                ),
            ),
            'required' => array( 'colors' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'colors' ) );
        $colors = $arguments['colors'];
        if ( ! is_array( $colors ) || empty( $colors ) ) {
            throw new \InvalidArgumentException( 'colors must be a non-empty object.' );
        }

        $allowed = \AgentShell_MCP\Doctor::ALLOWED_COLORS;
        foreach ( $colors as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                throw new \InvalidArgumentException( "Unknown color key '{$key}'. Valid keys: " . implode( ', ', $allowed ) );
            }
            if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
                throw new \InvalidArgumentException( "Invalid hex color for {$key}: " . var_export( $value, true ) );
            }
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['colors'] ) ) { $config['design']['colors'] = array(); }
        foreach ( $colors as $key => $value ) {
            $config['design']['colors'][ $key ] = $value;
        }
        $this->update_agentshell_config( $config );

        return array(
            'updated' => array_keys( $colors ),
            'palette' => $config['design']['colors'],
        );
    }
}