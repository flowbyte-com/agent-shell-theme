<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_screenshot — render the site and capture it as an image.
 *
 * Completes the agent visual feedback loop: inspect → change → render →
 * screenshot → evaluate → iterate. Requires a headless browser on the server
 * (google-chrome / chromium, or AGENTSHELL_CHROME_BIN in wp-config.php).
 */
class Screenshot extends Base_Tool {
    public function get_name() { return 'agentshell_screenshot'; }
    public function get_description() { return 'Capture a screenshot of the site (or a saved theme profile preview) at a given viewport using the headless browser installed on the server. Returns the image URL. Backend: Chrome/Chromium headless; configure AGENTSHELL_CHROME_BIN if not on PATH.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'viewport' => array(
                    'type'        => 'string',
                    'enum'        => array( 'desktop', 'mobile', 'tablet' ),
                    'description' => 'Preset viewport (default desktop = 1280x800, mobile = 390x844, tablet = 768x1024)',
                ),
                'width'    => array( 'type' => 'integer', 'description' => 'Explicit viewport width in px (overrides viewport preset)' ),
                'height'   => array( 'type' => 'integer', 'description' => 'Explicit viewport height in px (overrides viewport preset)' ),
                'url'      => array( 'type' => 'string', 'description' => 'URL to capture (defaults to the site home page)' ),
                'profile'  => array( 'type' => 'string', 'description' => 'Optional theme profile name — the screenshot renders its design tokens as preview overrides (requires AGENTSHELL_REST_TOKEN)' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $backend = \AgentShell_MCP\Screenshot::backend();
        if ( null === $backend ) {
            return array(
                'captured' => false,
                'reason'   => 'No headless browser available on this server. Install google-chrome or chromium, or define AGENTSHELL_CHROME_BIN in wp-config.php.',
                'tip'      => 'Until then, use agentshell_preview_theme to get a preview URL and view it in a browser.',
            );
        }

        // Resolve viewport.
        $viewport = $arguments['viewport'] ?? 'desktop';
        $preset   = \AgentShell_MCP\Screenshot::VIEWPORTS[ $viewport ] ?? \AgentShell_MCP\Screenshot::VIEWPORTS['desktop'];
        $width    = isset( $arguments['width'] ) ? (int) $arguments['width'] : $preset[0];
        $height   = isset( $arguments['height'] ) ? (int) $arguments['height'] : $preset[1];
        if ( $width < 320 || $width > 4096 || $height < 240 || $height > 4096 ) {
            throw new \InvalidArgumentException( 'Viewport must be within 320-4096px wide and 240-4096px tall.' );
        }

        // Resolve URL.
        $url = ! empty( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : home_url( '/' );
        if ( empty( $url ) ) {
            throw new \InvalidArgumentException( 'url is not a valid URL.' );
        }

        // SSRF guard: a trusted agent could otherwise point Chrome at
        // http://169.254.169.254/ (cloud metadata), http://localhost:8080/admin, or any
        // internal host; --no-sandbox means the rendered page leaks into
        // wp-content/uploads/agentshell-screenshots/, which is publicly readable.
        // Reject any URL whose host differs from home_url() — the legitimate use
        // case (previewing theme profiles) is same-site by construction.
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $target_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $target_host || strcasecmp( $target_host, (string) $site_host ) !== 0 ) {
            throw new \InvalidArgumentException(
                "url host '{$target_host}' does not match this site's host ('{$site_host}'); screenshots are scoped to this site to prevent SSRF."
            );
        }

        // Optional profile preview — sign it so the theme can render overrides
        // without a browser session.
        if ( ! empty( $arguments['profile'] ) ) {
            $profile = sanitize_key( $arguments['profile'] );
            if ( null === \AgentShell_MCP\Store::get_profile( $profile ) ) {
                throw new \InvalidArgumentException( "Theme profile not found: {$profile}. Use agentshell_list_theme_profiles." );
            }
            if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) {
                throw new \InvalidArgumentException( 'AGENTSHELL_REST_TOKEN must be defined in wp-config.php to screenshot a profile preview (the preview key is derived from it).' );
            }
            $url = add_query_arg(
                array(
                    'agentshell_preview'     => $profile,
                    'agentshell_preview_key' => hash_hmac( 'sha256', $profile, AGENTSHELL_REST_TOKEN ),
                ),
                $url
            );
        }

        $shot = \AgentShell_MCP\Screenshot::capture( $url, $width, $height );

        return array(
            'captured'  => true,
            'screenshot' => array(
                'url'     => $shot['url'],
                'file'    => $shot['file'],
                'width'   => $shot['width'],
                'height'  => $shot['height'],
                'backend' => $shot['backend'],
            ),
            'captured_url' => $url,
            'tip' => 'Evaluate the screenshot, then iterate: adjust design tokens, re-screenshot. Use agentshell_list_theme_profiles + agentshell_screenshot(profile=...) to compare profiles side by side.',
        );
    }
}