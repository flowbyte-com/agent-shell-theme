<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_save_theme_profile — persist a named design profile.
 */
class Save_Theme_Profile extends Base_Tool {
    public function get_name() { return 'agentshell_save_theme_profile'; }
    public function get_description() { return 'Save the current design (or an explicit design object) as a named theme profile. Profiles can be applied (agentshell_apply_theme) and previewed (agentshell_preview_theme).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'        => array( 'type' => 'string', 'description' => 'Profile name, e.g. "terminal" or "brutalist"' ),
                'description' => array( 'type' => 'string' ),
                'design'      => array( 'type' => 'object', 'description' => 'Optional design object { colors, typography, layout, custom_css_vars }. Omit to capture the current live design.' ),
            ),
            'required' => array( 'name' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name' ) );
        $name = sanitize_key( $arguments['name'] );
        if ( '' === $name ) {
            throw new \InvalidArgumentException( 'Profile name must be alphanumeric (hyphens/underscores allowed).' );
        }

        if ( isset( $arguments['design'] ) && is_array( $arguments['design'] ) ) {
            $design = $arguments['design'];
        } else {
            $config = $this->get_agentshell_config();
            $design = $config['design'] ?? array();
        }
        if ( ! is_array( $design ) || empty( $design ) ) {
            throw new \InvalidArgumentException( 'design is empty — the site has no design configuration to capture.' );
        }

        $profile = \AgentShell_MCP\Store::save_profile( $name, $design, $arguments['description'] ?? '' );

        return array(
            'profile' => array(
                'name'        => $profile['name'],
                'description' => $profile['description'],
                'actor'       => $profile['actor'],
                'timestamp'   => $profile['timestamp'],
            ),
            'design'  => $design,
            'tip'     => 'Apply with agentshell_apply_theme, preview with agentshell_preview_theme.',
        );
    }
}