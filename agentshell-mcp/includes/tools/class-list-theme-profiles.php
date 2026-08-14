<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_list_theme_profiles — list saved design profiles.
 */
class List_Theme_Profiles extends Base_Tool {
    public function get_name() { return 'agentshell_list_theme_profiles'; }
    public function get_description() { return 'List all saved theme profiles (name, description, actor, timestamp, design summary).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $out = array();
        foreach ( \AgentShell_MCP\Store::profiles() as $profile ) {
            $out[] = array(
                'name'        => $profile['name'],
                'description' => $profile['description'] ?? '',
                'actor'       => $profile['actor'] ?? '',
                'timestamp'   => $profile['timestamp'] ?? 0,
                'design'      => $profile['design'] ?? array(),
            );
        }
        return array( 'profiles' => $out );
    }
}