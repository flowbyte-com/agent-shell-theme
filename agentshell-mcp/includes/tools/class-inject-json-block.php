<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Inject_Json_Block extends Base_Tool {
    public function get_name() { return 'agentshell_inject_json_block'; }
    public function get_description() { return 'Inject raw HTML into a zone via json_block source. Script tags and style attributes are stripped server-side.'; }
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

        // Strip script tags and style attributes (security)
        $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
        $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );
        $html = wp_kses_post( $html );

        $config = $this->get_agentshell_config();
        $zones  = $config['zones'] ?? array();

        $found = false;
        foreach ( $zones as &$zone ) {
            if ( $zone['id'] === $arguments['zone_id'] ) {
                $zone['source']       = 'json_block';
                $zone['json_content'] = $html;
                $found = true;
                break;
            }
        }
        unset( $zone );

        if ( ! $found ) {
            throw new \InvalidArgumentException( "Zone not found: {$arguments['zone_id']}" );
        }

        $config['zones'] = $zones;
        $this->update_agentshell_config( $config );

        return array(
            'zone_id'     => $arguments['zone_id'],
            'source'      => 'json_block',
            'html_length' => strlen( $html ),
        );
    }
}
