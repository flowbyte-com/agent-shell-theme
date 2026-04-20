<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Inject_Json_Block extends Base_Tool {
    public function get_name() { return 'agentshell_inject_json_block'; }
    public function get_description() { return 'Inject raw HTML into a zone via json_block source. Style tags and inline style attributes are stripped for untrusted users (Unbreakable Grid). Admin users with unfiltered_html/manage_options preserve all tags including <style> and <script> for custom Web Components.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id' => array( 'type' => 'string', 'description' => 'Target zone ID' ),
                'html'    => array( 'type' => 'string', 'description' => 'HTML content (max 10000 chars)', 'maxLength' => 10000 ),
            ),
            'required'   => array( 'zone_id', 'html' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'html' ) );

        $html = $arguments['html'];
        if ( strlen( $html ) > 10000 ) {
            throw new \InvalidArgumentException( 'html must be 10000 characters or less' );
        }

        // Check capability before any stripping. Trusted users (unfiltered_html or manage_options)
        // get full passthrough — no style stripping, no script stripping, no KSES.
        // This preserves custom Web Components that use customElements.define.
        $trusted = current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );

        if ( ! $trusted ) {
            // Security: Unbreakable Grid — strip <style> tags and inline style="" attributes.
            // These can break the fixed CSS Grid structure regardless of user capability.
            $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
            $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );

            // Strip scripts and run KSES sanitization for untrusted users.
            $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
            $html = wp_kses_post( $html );
        }

        $config = $this->get_agentshell_config();

        // Use agentshell_get_zones() for declared zones (has proper defaults)
        // even if they're not yet stored in wp_options config
        $declared_zones = function_exists( 'agentshell_get_zones' )
            ? agentshell_get_zones()
            : ( $config['zones'] ?? array() );

        $found = false;
        foreach ( $declared_zones as $zone ) {
            if ( $zone['id'] === $arguments['zone_id'] ) {
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            throw new \InvalidArgumentException( "Zone not found: {$arguments['zone_id']}" );
        }

        // Initialize zones array in config if needed, then update the zone
        if ( ! isset( $config['zones'] ) || ! is_array( $config['zones'] ) ) {
            $config['zones'] = array();
        }

        $zone_found = false;
        foreach ( $config['zones'] as &$z ) {
            if ( $z['id'] === $arguments['zone_id'] ) {
                $z['source'] = 'json_block';
                $z['html']   = $html;
                $zone_found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $zone_found ) {
            $config['zones'][] = array(
                'id'     => $arguments['zone_id'],
                'source' => 'json_block',
                'html'   => $html,
            );
        }

        $this->update_agentshell_config( $config );

        return array(
            'zone_id'     => $arguments['zone_id'],
            'source'      => 'json_block',
            'html_length' => strlen( $html ),
        );
    }
}
