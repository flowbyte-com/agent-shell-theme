<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Registry {
    private $tools = array();

    public function register( Base_Tool $tool ) {
        $this->tools[ $tool->get_name() ] = $tool;
    }

    public function get_tool( $name ) {
        return $this->tools[ $name ] ?? null;
    }

    public function get_all_definitions() {
        return array_values( array_map( function( $tool ) {
            return $tool->get_definition();
        }, $this->tools ) );
    }
}
