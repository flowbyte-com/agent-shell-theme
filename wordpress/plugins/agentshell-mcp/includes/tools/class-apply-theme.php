<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_apply_theme — apply a named theme profile to the live site.
 */
class Apply_Theme extends Base_Tool {
    public function get_name() { return 'agentshell_apply_theme'; }
    public function get_description() { return 'Apply a saved theme profile: merge its design (colors, typography, geometry, custom vars) into the live configuration. Recorded as a revision and audit entry.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'description' => 'Profile name to apply' ),
            ),
            'required' => array( 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name' ) );
        $profile = \AgentShell_MCP\Store::get_profile( sanitize_key( $arguments['name'] ) );
        if ( null === $profile ) {
            throw new \InvalidArgumentException( "Theme profile not found: {$arguments['name']}. Use agentshell_list_theme_profiles." );
        }

        $config = $this->get_agentshell_config();
        $before = $config;
        $config['design'] = $this->merge_design( $config['design'] ?? array(), $profile['design'] );

        \AgentShell_MCP\Store::$operation_label = 'apply theme profile: ' . $profile['name'];
        $this->update_agentshell_config( $config );

        return array(
            'applied'  => $profile['name'],
            'diff'     => \AgentShell_MCP\Store::diff_config( $before, $config ),
            'design'   => $config['design'],
        );
    }

    private function merge_design( array $current, array $incoming ) {
        foreach ( array( 'colors', 'typography', 'layout', 'custom_css_vars' ) as $group ) {
            if ( isset( $incoming[ $group ] ) && is_array( $incoming[ $group ] ) ) {
                if ( ! isset( $current[ $group ] ) || ! is_array( $current[ $group ] ) ) {
                    $current[ $group ] = array();
                }
                $current[ $group ] = array_merge( $current[ $group ], $incoming[ $group ] );
            }
        }
        return $current;
    }
}