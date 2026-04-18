<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Base_Tool {
    abstract public function get_name();
    abstract public function get_description();
    abstract public function get_input_schema();
    abstract public function execute( array $arguments );

    public function get_required_capability() {
        return 'manage_options';
    }

    public function get_definition() {
        return array(
            'name'        => $this->get_name(),
            'description' => $this->get_description(),
            'inputSchema' => $this->get_input_schema(),
        );
    }

    protected function validate_required( array $arguments, array $required_keys ) {
        $missing = array();
        foreach ( $required_keys as $key ) {
            if ( ! isset( $arguments[ $key ] ) || '' === $arguments[ $key ] ) {
                $missing[] = $key;
            }
        }
        if ( ! empty( $missing ) ) {
            throw new \InvalidArgumentException( 'Missing required parameters: ' . implode( ', ', $missing ) );
        }
    }

    protected function get_agentshell_config() {
        return get_option( 'agentshell_config', array() );
    }

    protected function update_agentshell_config( array $config ) {
        return update_option( 'agentshell_config', $config );
    }
}
