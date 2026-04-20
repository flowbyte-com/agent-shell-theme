<?php
/**
 * Plugin Name: AgentShell Blocks
 * Description: Interactive component factory for AgentShell — owns the bilateral widget registry, shortcode bridge, and MCP widget tools. Requires AgentShell theme.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AGENTSHELL_BLOCKS_VERSION', '1.0.0' );
define( 'AGENTSHELL_BLOCKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_BLOCKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/class-widget-registry.php';
require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/class-json-rpc.php';
require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-base-tool.php';

/**
 * Option key for agent-defined widget registry.
 * Theme reads from this; plugin writes to it.
 */
define( 'AGENTSHELL_WIDGETS_OPTION', 'agentshell_widgets' );

register_activation_hook( __FILE__, function() {
    add_option( AGENTSHELL_WIDGETS_OPTION, array() );
} );

/**
 * Inject widget CSS in <head>.
 * Consolidated into a single <style> tag — no physical files.
 */
add_action( 'wp_head', function() {
    $registry = AgentShell_Blocks\Widget_Registry::get_instance();
    $assets   = $registry->get_all_css();
    if ( empty( $assets ) ) { return; }
    echo '<style id="agentshell-widgets-css">' . "\n" . $assets . "\n" . '</style>' . "\n";
} );

/**
 * Inject widget JS in footer — window.AgentshellWidgets registry + MutationObserver init.
 * Consolidated into a single <script> tag — no physical files.
 */
add_action( 'wp_footer', function() {
    $registry = AgentShell_Blocks\Widget_Registry::get_instance();
    $js       = $registry->get_init_js();
    if ( empty( $js ) ) { return; }
    echo '<script id="agentshell-widgets-js">' . "\n" . $js . "\n" . '</script>' . "\n";
} );

/**
 * Shortcode: [agent_block id="widget-id"]
 * Allows human content editors to embed widget output in WP post content.
 */
add_shortcode( 'agent_block', function( $atts ) {
    $atts = shortcode_atts( array( 'id' => '' ), $atts, 'agent_block' );
    if ( empty( $atts['id'] ) ) { return ''; }
    $registry = AgentShell_Blocks\Widget_Registry::get_instance();
    return $registry->render_widget( $atts['id'] );
} );

/**
 * Hook into agentshell_mcp_register_tools to expose widget tools.
 */
add_filter( 'agentshell_mcp_register_tools', function( $tools ) {
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-list-widgets.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-register-widget.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-unregister-widget.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-get-widget.php';

    $registry = AgentShell_Blocks\Widget_Registry::get_instance();

    $tools[] = new AgentShell_Blocks\Tools\List_Widgets();
    $tools[] = new AgentShell_Blocks\Tools\Register_Widget();
    $tools[] = new AgentShell_Blocks\Tools\Unregister_Widget();
    $tools[] = new AgentShell_Blocks\Tools\Get_Widget();

    return $tools;
} );

/**
 * Hook into agentshell_mcp_execute_tool to delegate widget tool execution.
 */
add_filter( 'agentshell_mcp_execute_tool', function( $response, $tool_name, $arguments, $tool_registry ) {
    $widget_tools = array( 'agentshell_list_widgets', 'agentshell_register_widget', 'agentshell_unregister_widget', 'agentshell_get_widget' );
    if ( ! in_array( $tool_name, $widget_tools, true ) ) {
        return $response; // Not ours — pass through
    }

    // These tools live in this plugin; delegate to tool objects
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-list-widgets.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-register-widget.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-unregister-widget.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-get-widget.php';

    $tool_map = array(
        'agentshell_list_widgets'      => new AgentShell_Blocks\Tools\List_Widgets(),
        'agentshell_register_widget'   => new AgentShell_Blocks\Tools\Register_Widget(),
        'agentshell_unregister_widget'=> new AgentShell_Blocks\Tools\Unregister_Widget(),
        'agentshell_get_widget'       => new AgentShell_Blocks\Tools\Get_Widget(),
    );

    if ( ! isset( $tool_map[ $tool_name ] ) ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => array(
                'code'    => 'TOOL_NOT_FOUND',
                'message' => 'Tool not found. Ensure the required plugin is active.',
            ),
        );
    }

    try {
        $result = $tool_map[ $tool_name ]->execute( $arguments );
        return array(
            'jsonrpc' => '2.0',
            'id'      => null,
            'result'  => $result,
        );
    } catch ( \InvalidArgumentException $e ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => array(
                'code'    => 'INVALID_PARAMS',
                'message' => $e->getMessage(),
            ),
        );
    } catch ( \Exception $e ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => array(
                'code'    => 'INTERNAL_ERROR',
                'message' => $e->getMessage(),
            ),
        );
    }
}, 10, 3 );
