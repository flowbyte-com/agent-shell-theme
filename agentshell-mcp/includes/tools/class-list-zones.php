<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class List_Zones extends Base_Tool {
    public function get_name() { return 'agentshell_list_zones'; }
    public function get_description() { return 'List all declared zones with their current source type and configuration.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        // Use agentshell_get_zones() which now merges saved config with declared defaults
        if ( function_exists( 'agentshell_get_zones' ) ) {
            return array( 'zones' => agentshell_get_zones() );
        }
        // Fallback to raw config zones
        $config = $this->get_agentshell_config();
        return array( 'zones' => $config['zones'] ?? array() );
    }
}
