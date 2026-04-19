<?php
/**
 * Plugin Name: AgentShell MCP
 * Description: MCP server for AgentShell — exposes AgentShell zones, CSS vars, widgets, and layout as JSON-RPC tools.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AGENTSHELL_MCP_VERSION', '1.0.0' );
define( 'AGENTSHELL_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-activator.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-error-codes.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-json-rpc.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-auth.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-base-tool.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-registry.php';

register_activation_hook( __FILE__, array( 'AgentShell_MCP\\Activator', 'activate' ) );

add_action( 'rest_api_init', function() {
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-server.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-transport.php';

    $registry = new AgentShell_MCP\Tools\Registry();

    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-config.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-css-var.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-design.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-zones.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-zone-source.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-inject-json-block.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-post-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-widgets.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-register-widget.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-layout.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-site-info.php';

    $registry->register( new AgentShell_MCP\Tools\Get_Config() );
    $registry->register( new AgentShell_MCP\Tools\Set_Css_Var() );
    $registry->register( new AgentShell_MCP\Tools\Set_Design() );
    $registry->register( new AgentShell_MCP\Tools\List_Zones() );
    $registry->register( new AgentShell_MCP\Tools\Set_Zone_Source() );
    $registry->register( new AgentShell_MCP\Tools\Inject_Json_Block() );
    $registry->register( new AgentShell_MCP\Tools\Update_Post_Content() );
    $registry->register( new AgentShell_MCP\Tools\List_Widgets() );
    $registry->register( new AgentShell_MCP\Tools\Register_Widget() );
    $registry->register( new AgentShell_MCP\Tools\Set_Layout() );
    $registry->register( new AgentShell_MCP\Tools\Get_Site_Info() );

    $server    = new AgentShell_MCP\Server( $registry );
    $transport = new AgentShell_MCP\Transport( $server );
    $transport->register_routes();
} );
