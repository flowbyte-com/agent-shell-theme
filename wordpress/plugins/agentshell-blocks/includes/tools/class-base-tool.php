<?php
namespace AgentShell_Blocks\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Base_Tool {
    abstract public function get_name();
    abstract public function get_description();
    abstract public function get_input_schema();
    abstract public function execute( array $arguments );

    public function get_definition() {
        $schema = $this->get_input_schema();

        // MCP requires inputSchema.properties to be a JSON object. An empty
        // PHP array encodes as [], which strict MCP clients reject — that
        // malformed response voids the whole tools/list payload, hiding every
        // tool, not just this one. Non-empty maps already encode as objects,
        // so only the empty case needs converting. `required` is deliberately
        // left alone: JSON Schema requires it to stay an array, empty or not.
        if ( isset( $schema['properties'] )
            && is_array( $schema['properties'] )
            && array() === $schema['properties'] ) {
            $schema['properties'] = (object) array();
        }

        return array(
            'name'        => $this->get_name(),
            'description' => $this->get_description(),
            'inputSchema' => $schema,
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
