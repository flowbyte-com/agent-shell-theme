<?php
namespace AgentShell_Blocks\Tools;

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
}
