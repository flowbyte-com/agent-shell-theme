<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Base_Tool {
    abstract public function get_name();
    abstract public function get_description();
    abstract public function get_input_schema();
    abstract public function execute( array $arguments );

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

    /**
     * Current config: staged value while a transaction is open, else stored.
     * This lets a sequence of mutation tools compose within a transaction.
     */
    protected function get_agentshell_config() {
        return \AgentShell_MCP\Transaction_Manager::instance()->effective_config();
    }

    /**
     * Persist (or stage, inside a transaction) a config change.
     * History recording happens automatically via the option update hook.
     */
    protected function update_agentshell_config( array $config ) {
        $tx = \AgentShell_MCP\Transaction_Manager::instance();
        if ( $tx->stage( $config ) ) {
            return true; // transaction open — deferred to staging buffer
        }
        return update_option( 'agentshell_config', $config );
    }
}
