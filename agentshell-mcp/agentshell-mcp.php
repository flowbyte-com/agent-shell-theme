<?php
/**
 * Plugin Name: AgentShell MCP
 * Description: MCP server for AgentShell — bridges JSON-RPC to WordPress filter-based tool registry.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AGENTSHELL_MCP_VERSION', '1.1.0' );
define( 'AGENTSHELL_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-activator.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-error-codes.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-json-rpc.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-auth.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-base-tool.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-registry.php';

register_activation_hook( __FILE__, array( 'AgentShell_MCP\Activator', 'activate' ) );

/**
 * agentshell_mcp_register_tools
 *
 * Theme and blocks plugin hook here to register their tools.
 * Theme tools register at priority 5, blocks plugin at priority 10.
 *
 * @param array $tools
 * @return array
 */
add_filter( 'agentshell_mcp_register_tools', function( $tools ) {
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-config.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-css-var.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-design.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-zones.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-zone-composition.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-layout.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-site-info.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-inject-json-block.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-post-content.php';

    $tools[] = new AgentShell_MCP\Tools\Get_Config();
    $tools[] = new AgentShell_MCP\Tools\Set_Css_Var();
    $tools[] = new AgentShell_MCP\Tools\Set_Design();
    $tools[] = new AgentShell_MCP\Tools\List_Zones();
    $tools[] = new AgentShell_MCP\Tools\Update_Zone_Composition();
    $tools[] = new AgentShell_MCP\Tools\Set_Layout();
    $tools[] = new AgentShell_MCP\Tools\Get_Site_Info();
    $tools[] = new AgentShell_MCP\Tools\Inject_Json_Block();
    $tools[] = new AgentShell_MCP\Tools\Update_Post_Content();

    return $tools;
}, 5 );

/**
 * agentshell_mcp_execute_tool
 *
 * For delegated tool execution from plugins that own their own tools.
 *
 * @param mixed  $response
 * @param string $tool_name
 * @param array  $arguments
 * @return array
 */
add_filter( 'agentshell_mcp_execute_tool', function( $response, $tool_name, $arguments ) {
    return $response;
}, 10, 3 );

add_action( 'rest_api_init', function() {
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-server.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-transport.php';

    $server    = new AgentShell_MCP\Server();
    $transport = new AgentShell_MCP\Transport( $server );
    $transport->register_routes();
} );
