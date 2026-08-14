<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_spacing — base spacing unit.
 */
class Set_Spacing extends Base_Tool {
    public function get_name() { return 'agentshell_set_spacing'; }
    public function get_description() { return 'Set the base spacing unit (drives --spacing-base and the theme\'s space scale), e.g. "1rem" or "8px".'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'base' => array( 'type' => 'string', 'description' => 'CSS length, e.g. 1rem, 8px' ),
            ),
            'required' => array( 'base' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'base' ) );
        $base = trim( (string) $arguments['base'] );
        if ( ! preg_match( '/^(\d*\.?\d+)(rem|em|px)$/', $base ) ) {
            throw new \InvalidArgumentException( "base must be a CSS length (e.g. 1rem, 8px): {$base}" );
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['typography'] ) ) { $config['design']['typography'] = array(); }
        $config['design']['typography']['baseSize'] = $base;
        $this->update_agentshell_config( $config );

        return array(
            'updated'  => array( 'baseSize' ),
            'base'     => $base,
            'token'    => '--spacing-base',
            'typography' => $config['design']['typography'],
        );
    }
}