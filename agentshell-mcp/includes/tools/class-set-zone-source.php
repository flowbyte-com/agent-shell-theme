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
        $zones  = $config['zones'] ?? array();

        $found = false;
        foreach ( $zones as &$zone ) {
            if ( $zone['id'] === $arguments['zone_id'] ) {
                $zone['source'] = $arguments['source'];
                if ( isset( $arguments['widget_area_id'] ) ) {
                    $zone['widget_area_id'] = $arguments['widget_area_id'];
                }
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

        return array( 'zone_id' => $arguments['zone_id'], 'source' => $arguments['source'] );
    }
}
