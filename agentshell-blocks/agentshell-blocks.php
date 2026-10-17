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
    // The legacy agentshell_widgets option has been consolidated into
    // agentshell_config.widgets. On activation, drain whatever lives in the
    // legacy option into the canonical store so existing installations
    // do not silently lose widgets. The migration helper is provided by
    // the theme (functions.php) and is idempotent.
    if ( function_exists( 'agentshell_migrate_legacy_widgets_option' ) ) {
        agentshell_migrate_legacy_widgets_option();
    } else {
        // Theme not active yet — keep the legacy option in place so the
        // theme's own first-time migration path (in agentshell_get_config)
        // can pick it up on first request.
        add_option( AGENTSHELL_WIDGETS_OPTION, array() );
    }
} );

/**
 * Defensive: also drain the legacy option on every admin request. The
 * migration is idempotent and runs once per install; doing it from a
 * frequent hook guarantees the data is moved before any tool call that
 * reads the canonical store can miss it.
 */
add_action( 'admin_init', function() {
    if ( function_exists( 'agentshell_migrate_legacy_widgets_option' ) ) {
        agentshell_migrate_legacy_widgets_option();
    }
} );

/*
 * Widget CSS and init JS are emitted by the theme, from the canonical
 * registry, in template-parts/widgets.php (head) and footer.php (assets).
 *
 * This plugin used to emit a second copy of both on wp_head and wp_footer.
 * Two copies of the same rules is not a cosmetic problem here: the plugin's
 * copy was built from a different store than the theme's, so on a site where
 * they disagreed the page carried one widget's CSS and another's markup, and a
 * transaction preview — which stages through the theme's reader — would show
 * the staged widgets with the committed CSS. One emitter, one registry.
 */

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
 *
 * Only for the two tools that exist *only* here. `agentshell_register_widget`
 * and `agentshell_list_widgets` are deliberately not intercepted: the main
 * plugin registers implementations of both, and those are transaction-aware —
 * they read the effective config and stage their writes. This plugin's copies
 * wrote straight to the legacy `agentshell_widgets` option, so an agent that
 * registered a widget inside a transaction produced a widget that no other tool
 * could see and that a rollback could not undo.
 *
 * `agentshell_unregister_widget` and `agentshell_get_widget` have no counterpart
 * elsewhere, so they are still delegated here — over a registry that now reads
 * the canonical store.
 */
add_filter( 'agentshell_mcp_execute_tool', function( $response, $tool_name, $arguments ) {
    // Section E fix: the previous closure declared 4 parameters but the
    // dispatcher in class-server.php:104 calls apply_filters() with three
    // arguments (null, $tool_name, $arguments). The fourth parameter,
    // $tool_registry, was never read in the body — and was never passed by
    // the dispatcher either — so the only way to reconcile the two was
    // to drop the parameter. The body is unchanged.
    $widget_tools = array( 'agentshell_unregister_widget', 'agentshell_get_widget' );
    if ( ! in_array( $tool_name, $widget_tools, true ) ) {
        return $response; // Not ours — pass through
    }

    // These tools live in this plugin; delegate to tool objects
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-unregister-widget.php';
    require_once AGENTSHELL_BLOCKS_PLUGIN_DIR . 'includes/tools/class-get-widget.php';

    $tool_map = array(
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
