<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_typography — semantic typography updates.
 */
class Set_Typography extends Base_Tool {
    public function get_name() { return 'agentshell_set_typography'; }
    public function get_description() { return 'Update typography: fontFamily (CSS font stack), mono (monospace font), baseSize (base spacing/font unit, e.g. 1rem), scale (type scale multiplier). Only provided keys change.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'fontFamily' => array( 'type' => 'string', 'description' => 'CSS font stack, e.g. "Inter, system-ui, sans-serif"' ),
                'mono'       => array( 'type' => 'string' ),
                'baseSize'   => array( 'type' => 'string', 'description' => 'CSS length, e.g. 1rem, 16px' ),
                'scale'      => array( 'type' => 'string', 'description' => 'Type scale multiplier, e.g. 1.25' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $allowed = \AgentShell_MCP\Doctor::ALLOWED_TYPO;
        foreach ( $arguments as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                throw new \InvalidArgumentException( "Unknown typography key '{$key}'. Valid keys: " . implode( ', ', $allowed ) );
            }
            if ( ! is_string( $value ) || '' === trim( $value ) ) {
                throw new \InvalidArgumentException( "typography.{$key} must be a non-empty string." );
            }
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['typography'] ) ) { $config['design']['typography'] = array(); }
        foreach ( $arguments as $key => $value ) {
            $config['design']['typography'][ $key ] = $value;
        }
        $this->update_agentshell_config( $config );

        return array(
            'updated'    => array_keys( $arguments ),
            'typography' => $config['design']['typography'],
        );
    }
}