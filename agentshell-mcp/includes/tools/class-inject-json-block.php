<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Inject_Json_Block extends Base_Tool {
    public function get_name() { return 'agentshell_inject_json_block'; }
    public function get_description() { return 'Inject raw HTML into a zone via json_block source. Script tags and inline styles are always stripped. Admin users preserve custom Web Components (e.g. mpm-*) intact.'; }
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

        // Always strip scripts and inline styles (AgentShell Shadow DOM rules)
        $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
        $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );

        // Only run KSES if the user lacks unfiltered_html capability.
        // Admin users with manage_options have unfiltered_html by default,
        // which preserves custom Web Components like <mpm-*> intact.
        if ( ! current_user_can( 'unfiltered_html' ) ) {
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
                $z['source']       = 'json_block';
                $z['json_content'] = $html;
                $zone_found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $zone_found ) {
            $config['zones'][] = array(
                'id'            => $arguments['zone_id'],
                'source'        => 'json_block',
                'json_content'  => $html,
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
