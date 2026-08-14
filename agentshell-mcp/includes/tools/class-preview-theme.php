<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_preview_theme — preview a profile without applying it.
 *
 * Returns a URL that renders the profile's design tokens as :root overrides.
 * The preview is only rendered for logged-in users (it is a safe, read-only
 * view) — agents can open it to verify before applying.
 */
class Preview_Theme extends Base_Tool {
    public function get_name() { return 'agentshell_preview_theme'; }
    public function get_description() { return 'Get a preview URL for a saved theme profile. The profile\'s design tokens are rendered as CSS overrides (no config change). Requires a logged-in browser session to view.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'description' => 'Profile name to preview' ),
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

        $url = add_query_arg( 'agentshell_preview', urlencode( $profile['name'] ), home_url( '/' ) );

        return array(
            'preview_url' => $url,
            'name'        => $profile['name'],
            'description' => $profile['description'] ?? '',
            'overrides'   => \AgentShell_MCP\Store::flatten( array( 'design' => $profile['design'] ) ),
            'note'        => 'The preview renders only for logged-in users. If it looks right, apply with agentshell_apply_theme.',
        );
    }
}