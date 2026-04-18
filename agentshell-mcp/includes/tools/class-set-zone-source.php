<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Zone_Source extends Base_Tool {
    private $valid_sources = array( 'wp_loop', 'wp_widget_area', 'json_block', 'widget' );

    public function get_name() { return 'agentshell_set_zone_source'; }
    public function get_description() { return 'Change a zone content source type (wp_loop, wp_widget_area, json_block, widget).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id'       => array( 'type' => 'string' ),
                'source'        => array( 'type' => 'string', 'enum' => array( 'wp_loop', 'wp_widget_area', 'json_block', 'widget' ) ),
                'widget_area_id' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'zone_id', 'source' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'source' ) );

        if ( ! in_array( $arguments['source'], $this->valid_sources, true ) ) {
            throw new \InvalidArgumentException( 'Invalid source. Must be one of: ' . implode( ', ', $this->valid_sources ) );
        }
        if ( $arguments['source'] === 'wp_widget_area' && empty( $arguments['widget_area_id'] ) ) {
            throw new \InvalidArgumentException( 'widget_area_id required when source is wp_widget_area' );
        }

        $config = $this->get_agentshell_config();

        // Use agentshell_get_zones() for declared zones (has proper defaults)
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
                $z['source'] = $arguments['source'];
                if ( isset( $arguments['widget_area_id'] ) ) {
                    $z['widget_area_id'] = $arguments['widget_area_id'];
                }
                $zone_found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $zone_found ) {
            $config['zones'][] = array(
                'id'   => $arguments['zone_id'],
                'source' => $arguments['source'],
            );
        }

        $this->update_agentshell_config( $config );

        return array( 'zone_id' => $arguments['zone_id'], 'source' => $arguments['source'] );
    }
}
