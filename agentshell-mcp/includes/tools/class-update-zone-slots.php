<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Update the slots object for a tri-slot zone (header or footer).
 * Slots use a { left, center, right } structure for precise horizontal placement.
 *
 * Example:
 * {
 *   "zone_id": "header",
 *   "slots": {
 *     "left":   [ { "type": "wp_core", "id": "site_logo" } ],
 *     "center": [ { "type": "wp_core", "id": "nav_menu" } ],
 *     "right":  [ { "type": "wp_core", "id": "search_form" } ]
 *   }
 * }
 */
class Update_Zone_Slots extends Base_Tool {
    public function get_name() { return 'agentshell_update_zone_slots'; }
    public function get_description() { return 'Set or replace the slots object for a tri-slot zone (header or footer). Slots use a { left, center, right } structure. Each slot is an array of blocks.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id' => array(
                    'type'    => 'string',
                    'enum'    => array( 'header', 'footer' ),
                    'description' => 'Zone ID: header or footer (only tri-slot zones support slots)',
                ),
                'slots' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'left'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
                        'center' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
                        'right'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
                    ),
                    'required' => array( 'left', 'center', 'right' ),
                ),
            ),
            'required' => array( 'zone_id', 'slots' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'slots' ) );

        $zone_id = $arguments['zone_id'];
        $slots   = $arguments['slots'];

        if ( ! in_array( $zone_id, array( 'header', 'footer' ), true ) ) {
            throw new \InvalidArgumentException( "slots are only supported on header and footer zones. For main zone use agentshell_update_zone_composition." );
        }

        $valid_slot_keys = array( 'left', 'center', 'right' );
        foreach ( $slots as $key => $items ) {
            if ( ! in_array( $key, $valid_slot_keys, true ) ) {
                throw new \InvalidArgumentException( "Invalid slot key '$key'. Must be one of: " . implode( ', ', $valid_slot_keys ) );
            }
            if ( ! is_array( $items ) ) {
                throw new \InvalidArgumentException( "slots[$key] must be an array of block objects." );
            }
            // Validate each block in the slot
            $valid_types = array( 'wp_core', 'wp_loop', 'widget', 'json_block', 'wp_widget_area' );
            foreach ( $items as $i => $block ) {
                $type = $block['type'] ?? '';
                if ( ! in_array( $type, $valid_types, true ) ) {
                    throw new \InvalidArgumentException( "slots[$key][$i]: type must be one of: " . implode( ', ', $valid_types ) . ". For wp_core blocks, also include the 'id' field." );
                }
                if ( $type === 'wp_core' && empty( $block['id'] ) ) {
                    throw new \InvalidArgumentException( "slots[$key][$i]: wp_core block requires 'id' field (e.g. site_logo, nav_menu, search_form, site_title, site_tagline)." );
                }
                if ( $type === 'widget' && empty( $block['id'] ) ) {
                    throw new \InvalidArgumentException( "slots[$key][$i]: widget block requires 'id' field." );
                }
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
                $z['slots']      = $slots;
                $z['composition'] = array(); // Clear legacy composition when using slots
                $found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $found ) {
            $config['zones'][] = array(
                'id'     => $zone_id,
                'slots'  => $slots,
            );
        }

        $this->update_agentshell_config( $config );

        return array(
            'zone_id' => $zone_id,
            'slots'   => $slots,
        );
    }
}