<?php
namespace AgentShell_Blocks\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Unregister_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_unregister_widget'; }
    public function get_description() { return 'Remove an agent-defined widget from the blocks registry.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array( 'type' => 'string', 'description' => 'Widget ID to remove' ),
            ),
            'required' => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id' ) );

        $registry = \AgentShell_Blocks\Widget_Registry::get_instance();
        $deleted  = $registry->delete_widget( $arguments['id'] );

        if ( ! $deleted ) {
            throw new \InvalidArgumentException( 'Widget not found: ' . $arguments['id'] );
        }

        // Also clean up any zone compositions that reference this widget ID
        $config = get_option( 'agentshell_config', array() );
        if ( ! empty( $config['zones'] ) && is_array( $config['zones'] ) ) {
            $changed = false;
            foreach ( $config['zones'] as &$zone ) {
                if ( empty( $zone['composition'] ) || ! is_array( $zone['composition'] ) ) {
                    continue;
                }
                foreach ( $zone['composition'] as $key => $block ) {
                    if ( ( $block['type'] ?? '' ) === 'widget' && ( $block['id'] ?? '' ) === $arguments['id'] ) {
                        unset( $zone['composition'][ $key ] );
                        $changed = true;
                    }
                }
                // Re-index to avoid gaps
                $zone['composition'] = array_values( $zone['composition'] );
            }
            unset( $zone );
            if ( $changed ) {
                update_option( 'agentshell_config', $config, false );
            }
        }

        return array( 'deleted' => true, 'id' => $arguments['id'] );
    }
}
