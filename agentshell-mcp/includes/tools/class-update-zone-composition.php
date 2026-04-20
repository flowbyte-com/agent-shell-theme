<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Update the composition array for a zone.
 * Replaces the legacy agentshell_set_zone_source tool in v2.
 */
class Update_Zone_Composition extends Base_Tool {
    public function get_name() { return 'agentshell_update_zone_composition'; }
    public function get_description() { return 'Set or replace the ordered composition array for a zone. Accepts an array of block objects: { "type": "wp_loop" }, { "type": "widget", "id": "widget-id" }, { "type": "json_block", "content": "..." }, { "type": "wp_widget_area", "id": "widget-area-id" }.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id'     => array( 'type' => 'string', 'description' => 'Zone ID: header, main, or footer' ),
                'composition' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'type'    => array( 'type' => 'string', 'enum' => array( 'wp_loop', 'widget', 'json_block', 'wp_widget_area' ) ),
                            'id'      => array( 'type' => 'string' ),
                            'content' => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'type' ),
                    ),
                ),
            ),
            'required' => array( 'zone_id', 'composition' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'composition' ) );

        $zone_id     = $arguments['zone_id'];
        $composition = $arguments['composition'];

        $valid_zone_ids = array( 'header', 'main', 'footer' );
        if ( ! in_array( $zone_id, $valid_zone_ids, true ) ) {
            throw new \InvalidArgumentException( "Invalid zone_id. Must be one of: " . implode( ', ', $valid_zone_ids ) );
        }

        if ( ! is_array( $composition ) ) {
            throw new \InvalidArgumentException( 'composition must be an array of block objects.' );
        }

        // Validate each block
        $valid_types = array( 'wp_loop', 'widget', 'json_block', 'wp_widget_area' );
        foreach ( $composition as $i => $block ) {
            $type = $block['type'] ?? '';
            if ( ! in_array( $type, $valid_types, true ) ) {
                throw new \InvalidArgumentException( "composition[$i]: type must be one of: " . implode( ', ', $valid_types ) );
            }
            if ( $type === 'widget' && empty( $block['id'] ) ) {
                throw new \InvalidArgumentException( "composition[$i]: widget block requires 'id' field." );
            }
            if ( $type === 'wp_widget_area' && empty( $block['id'] ) ) {
                throw new \InvalidArgumentException( "composition[$i]: wp_widget_area block requires 'id' field." );
            }
        }

        $config = $this->get_agentshell_config();

        if ( ! isset( $config['zones'] ) || ! is_array( $config['zones'] ) ) {
            $config['zones'] = array();
        }

        // Find and replace zone; if not found, append
        $found = false;
        foreach ( $config['zones'] as &$z ) {
            if ( ( $z['id'] ?? '' ) === $zone_id ) {
                $z['composition'] = $composition;
                $found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $found ) {
            $config['zones'][] = array(
                'id'          => $zone_id,
                'composition' => $composition,
            );
        }

        $this->update_agentshell_config( $config );

        return array(
            'zone_id'     => $zone_id,
            'composition' => $composition,
        );
    }
}
