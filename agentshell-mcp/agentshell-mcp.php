<?php
/**
 * Plugin Name: AgentShell MCP
 * Description: MCP server for AgentShell — bridges JSON-RPC to WordPress filter-based tool registry.
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AGENTSHELL_MCP_VERSION', '1.3.0' );
define( 'AGENTSHELL_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-activator.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-error-codes.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-json-rpc.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-auth.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-base-tool.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-registry.php';

// Core services: history store, transaction manager (hooks option writes),
// the deterministic site validator, content primitives, and screenshot backend.
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-store.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-transactions.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-doctor.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-content.php';
require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/class-screenshot.php';
\AgentShell_MCP\Transaction_Manager::instance();

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
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-zone-slots.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-layout.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-site-info.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-inject-json-block.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-post-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-widgets.php';

    // Agent operations layer (v1.2)
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-inspect.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-explain.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-validate.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-capabilities.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-audit-log.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-begin-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-preview-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-validate-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-commit-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-rollback-transaction.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-revisions.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-diff-revisions.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-restore-revision.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-create-snapshot.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-snapshots.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-restore-snapshot.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-diff-snapshot.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-design-system.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-palette.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-typography.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-spacing.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-set-shape.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-save-theme-profile.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-list-theme-profiles.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-apply-theme.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-preview-theme.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-export-theme.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-import-theme.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-enable-widget.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-disable-widget.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-remove-widget.php';

    // Content primitives (v1.3)
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-create-page.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-create-post.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-update-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-publish-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-unpublish-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-search-content.php';
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-get-content.php';

    // Screenshot loop (v1.3)
    require_once AGENTSHELL_MCP_PLUGIN_DIR . 'includes/tools/class-screenshot.php';

    $tools[] = new AgentShell_MCP\Tools\Get_Config();
    $tools[] = new AgentShell_MCP\Tools\Set_Css_Var();
    $tools[] = new AgentShell_MCP\Tools\Set_Design();
    $tools[] = new AgentShell_MCP\Tools\List_Zones();
    $tools[] = new AgentShell_MCP\Tools\Update_Zone_Composition();
    $tools[] = new AgentShell_MCP\Tools\Update_Zone_Slots();
    $tools[] = new AgentShell_MCP\Tools\Set_Layout();
    $tools[] = new AgentShell_MCP\Tools\Get_Site_Info();
    $tools[] = new AgentShell_MCP\Tools\Inject_Json_Block();
    $tools[] = new AgentShell_MCP\Tools\Update_Post_Content();

    // — Agent operations layer (v1.2) —
    // Introspection & validation
    $tools[] = new AgentShell_MCP\Tools\Inspect();
    $tools[] = new AgentShell_MCP\Tools\Explain();
    $tools[] = new AgentShell_MCP\Tools\Validate();
    $tools[] = new AgentShell_MCP\Tools\Get_Capabilities();
    $tools[] = new AgentShell_MCP\Tools\Get_Audit_Log();

    // Transactions
    $tools[] = new AgentShell_MCP\Tools\Begin_Transaction();
    $tools[] = new AgentShell_MCP\Tools\Get_Transaction();
    $tools[] = new AgentShell_MCP\Tools\Preview_Transaction();
    $tools[] = new AgentShell_MCP\Tools\Validate_Transaction();
    $tools[] = new AgentShell_MCP\Tools\Commit_Transaction();
    $tools[] = new AgentShell_MCP\Tools\Rollback_Transaction();

    // Revisions & snapshots
    $tools[] = new AgentShell_MCP\Tools\List_Revisions();
    $tools[] = new AgentShell_MCP\Tools\Diff_Revisions();
    $tools[] = new AgentShell_MCP\Tools\Restore_Revision();
    $tools[] = new AgentShell_MCP\Tools\Create_Snapshot();
    $tools[] = new AgentShell_MCP\Tools\List_Snapshots();
    $tools[] = new AgentShell_MCP\Tools\Restore_Snapshot();
    $tools[] = new AgentShell_MCP\Tools\Diff_Snapshot();

    // Design system API
    $tools[] = new AgentShell_MCP\Tools\Get_Design_System();
    $tools[] = new AgentShell_MCP\Tools\Set_Palette();
    $tools[] = new AgentShell_MCP\Tools\Set_Typography();
    $tools[] = new AgentShell_MCP\Tools\Set_Spacing();
    $tools[] = new AgentShell_MCP\Tools\Set_Shape();

    // Theme profiles & packages
    $tools[] = new AgentShell_MCP\Tools\Save_Theme_Profile();
    $tools[] = new AgentShell_MCP\Tools\List_Theme_Profiles();
    $tools[] = new AgentShell_MCP\Tools\Apply_Theme();
    $tools[] = new AgentShell_MCP\Tools\Preview_Theme();
    $tools[] = new AgentShell_MCP\Tools\Export_Theme();
    $tools[] = new AgentShell_MCP\Tools\Import_Theme();

    // Widget lifecycle
    $tools[] = new AgentShell_MCP\Tools\List_Widgets();
    $tools[] = new AgentShell_MCP\Tools\Enable_Widget();
    $tools[] = new AgentShell_MCP\Tools\Disable_Widget();
    $tools[] = new AgentShell_MCP\Tools\Remove_Widget();

    // Content primitives (v1.3)
    $tools[] = new AgentShell_MCP\Tools\Create_Page();
    $tools[] = new AgentShell_MCP\Tools\Create_Post();
    $tools[] = new AgentShell_MCP\Tools\Update_Content();
    $tools[] = new AgentShell_MCP\Tools\Publish_Content();
    $tools[] = new AgentShell_MCP\Tools\Unpublish_Content();
    $tools[] = new AgentShell_MCP\Tools\Search_Content();
    $tools[] = new AgentShell_MCP\Tools\Get_Content();

    // Screenshot loop (v1.3)
    $tools[] = new AgentShell_MCP\Tools\Screenshot();

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
