<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_disable_widget — deactivate a widget.
 */
class Disable_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_disable_widget'; }
    public function get_description() { return 'Disable a widget: set its status to disabled so zones render nothing for it. Registration is preserved, so it can be re-enabled. Prefer this over removal for stable file widgets.'; }
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

        $registry = function_exists( 'agentshell_get_widget_registry' )
            ? agentshell_get_widget_registry()
            : array();
        if ( ! isset( $registry[ $id ] ) ) {
            throw new \InvalidArgumentException( "Unknown widget '{$id}'. Use agentshell_list_widgets." );
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['widget_overrides'] ) ) { $config['widget_overrides'] = array(); }
        $config['widget_overrides'][ $id ] = array( 'status' => 'disabled' );
        $this->update_agentshell_config( $config );

        return array( 'widget' => $id, 'status' => 'disabled' );
    }
}