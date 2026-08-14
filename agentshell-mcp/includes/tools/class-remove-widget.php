<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_remove_widget — permanently remove a config-registered widget.
 */
class Remove_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_remove_widget'; }
    public function get_description() { return 'Permanently remove a config-registered (agent-defined) widget from the registry. Stable file widgets cannot be removed — disable them instead. Zones referencing the removed widget will render nothing.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array( 'type' => 'string', 'description' => 'Widget id' ),
            ),
            'required' => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id' ) );
        $id = sanitize_key( $arguments['id'] );

        $config     = $this->get_agentshell_config();
        $config_widgets = $config['widgets'] ?? array();
        if ( ! is_array( $config_widgets ) ) {
            $config_widgets = array();
        }

        $found = false;
        foreach ( $config_widgets as $i => $widget ) {
            if ( ( $widget['id'] ?? '' ) === $id ) {
                unset( $config_widgets[ $i ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            // Not in config — check if it is a stable file widget.
            if ( function_exists( 'agentshell_get_widget_registry' )
                && isset( agentshell_get_widget_registry()[ $id ] )
            ) {
                throw new \InvalidArgumentException( "Widget '{$id}' is a stable file widget and cannot be removed. Use agentshell_disable_widget instead." );
            }
            throw new \InvalidArgumentException( "Unknown widget '{$id}'. Use agentshell_list_widgets." );
        }

        $config['widgets'] = array_values( $config_widgets );
        // Drop any status override for the removed widget.
        if ( isset( $config['widget_overrides'][ $id ] ) ) {
            unset( $config['widget_overrides'][ $id ] );
        }
        $this->update_agentshell_config( $config );

        return array(
            'removed'   => $id,
            'remaining' => count( $config['widgets'] ),
        );
    }
}