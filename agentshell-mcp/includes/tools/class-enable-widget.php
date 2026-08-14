<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_enable_widget — activate a widget.
 */
class Enable_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_enable_widget'; }
    public function get_description() { return 'Enable a widget: set its status to active so zones render it again. Works for both config-registered and stable file widgets.'; }
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
        $config['widget_overrides'][ $id ] = array( 'status' => 'active' );
        $this->update_agentshell_config( $config );

        return array( 'widget' => $id, 'status' => 'active' );
    }
}