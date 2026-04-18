<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Config extends Base_Tool {
    public function get_name() { return 'agentshell_get_config'; }
    public function get_description() { return 'Get the full AgentShell configuration including zones, design, layout, widgets, and CSS variables.'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( function_exists( 'agentshell_flatten_config' ) ) {
            return agentshell_flatten_config( $config );
        }
        return $config;
    }
}
