<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_remove_widget — permanently remove a config-registered widget.
 *
 * The canonical removal path delegates to the pure helper
 * agentshell_remove_widget_from_config() so the rules (file widgets are
 * non-removable; dangling zone references are stripped) live in one
 * place. This class is now a thin shim around that helper plus the
 * persistence + history-recording call.
 */
class Remove_Widget extends Base_Tool {
    public function get_name() { return 'agentshell_remove_widget'; }
    public function get_description() { return 'Permanently remove a config-registered (agent-defined) widget from the registry. Stable file widgets cannot be removed — disable them instead. Zone composition and slot entries that referenced the removed widget are stripped in the same operation.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array( 'type' => 'string', 'description' => 'Widget id' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id' ) );
        $id = sanitize_key( $arguments['id'] );

        $config = $this->get_agentshell_config();

        // agentshell_remove_widget_from_config throws if the widget is not
        // a config-registered one (covers the file-widget case and the
        // unknown-id case with two distinct messages).
        $updated = \agentshell_remove_widget_from_config( $config, $id );

        $this->update_agentshell_config( $updated );

        return array(
            'removed'   => $id,
            'remaining' => is_array( $updated['widgets'] ?? null ) ? count( $updated['widgets'] ) : 0,
        );
    }
}