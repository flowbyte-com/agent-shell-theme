<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Site_Info extends Base_Tool {
    public function get_name() { return 'agentshell_get_site_info'; }
    public function get_description() { return 'Get basic site information for agent context.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        return array(
            'name'               => get_bloginfo( 'name' ),
            'url'                => get_bloginfo( 'url' ),
            'agentshell_version' => AGENTSHELL_MCP_VERSION,
            'theme_name'         => wp_get_theme()->get( 'Name' ),
            'plugin_url'         => AGENTSHELL_MCP_PLUGIN_URL,
        );
    }
}
