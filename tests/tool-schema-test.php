<?php
/**
 * MCP tool inputSchema serialization.
 *
 * Pre-fix state: Base_Tool::get_definition() passed get_input_schema()
 * through untouched. A zero-argument tool declares 'properties' => array(),
 * and an empty PHP array encodes as the JSON array [], while MCP requires
 * properties to be a JSON object. Strict clients validate every entry of
 * tools/list and reject the whole payload, so those tools made the server
 * report "Connected" while exposing zero tools.
 *
 * Post-fix invariants:
 *   1. An empty properties map serializes as a JSON object ({}).
 *   2. Non-empty property maps are passed through unchanged, keys and all.
 *   3. `required` is never coerced — JSON Schema requires it to stay an
 *      array, including the empty case.
 *   4. A schema that omits `properties` entirely does not gain one.
 *
 * Assertions run against the ENCODED JSON, decoded without assoc=true.
 * Decoding with assoc=true would turn {} back into an array and silently
 * mask the original defect — which is exactly how it survived review.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-blocks/includes/tools/class-base-tool.php';

echo "tool inputSchema serialization tests\n";

// ─── helpers ───────────────────────────────────────────────────

/** Encode a definition the way the transport does, then decode as objects. */
function schema_json( array $definition ) {
    return json_decode( json_encode( $definition ) );
}

function schema_prop_types( $schema ) {
    $out = array();
    foreach ( $schema->properties as $key => $value ) {
        $out[ $key ] = $value->type ?? null;
    }
    return $out;
}

/** Build a tool stub bound to a schema, for either base class. */
function make_tool( $base, array $schema ) {
    return new class( $base, $schema ) extends \AgentShell_MCP\Tools\Base_Tool {
        private $base;
        private $schema;
        public function __construct( $base, array $schema ) {
            $this->base   = $base;
            $this->schema = $schema;
        }
        public function get_name()        { return 'stub_tool'; }
        public function get_description() { return 'Schema stub.'; }
        public function get_input_schema() { return $this->schema; }
        public function execute( array $arguments ) { return null; }
    };
}

// Two independent stubs, one per base class, so both namespaces are covered.
// The blocks base class is structurally identical to the plugin's, so the
// subclass cannot extend both; duplicating the contract check is the point.
function make_blocks_tool( array $schema ) {
    return new class( $schema ) extends \AgentShell_Blocks\Tools\Base_Tool {
        private $schema;
        public function __construct( array $schema ) { $this->schema = $schema; }
        public function get_name()        { return 'stub_tool'; }
        public function get_description() { return 'Schema stub.'; }
        public function get_input_schema() { return $this->schema; }
        public function execute( array $arguments ) { return null; }
    };
}

// ─── zero-argument tools: the defect itself ────────────────────

Agentshell_Test::run(
    'zero-argument tool serializes empty properties as a JSON object',
    function () {
        $tool = make_tool( null, array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true(
            is_object( $json->inputSchema->properties ),
            'properties must serialize as an object, got ' . gettype( $json->inputSchema->properties )
        );
        Agentshell_Test::assert_equals( 0, count( get_object_vars( $json->inputSchema->properties ) ) );
    }
);

Agentshell_Test::run(
    'zero-argument tool keeps type and additionalProperties intact',
    function () {
        $tool = make_tool( null, array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_equals( 'object', $json->inputSchema->type );
        Agentshell_Test::assert_false( $json->inputSchema->additionalProperties );
    }
);

Agentshell_Test::run(
    'blocks base class applies the same empty-properties normalization',
    function () {
        $tool = make_blocks_tool( array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true(
            is_object( $json->inputSchema->properties ),
            'blocks properties must serialize as an object'
        );
    }
);

// ─── parameterized tools must be untouched ─────────────────────

Agentshell_Test::run(
    'parameterized tool keeps its property names and types',
    function () {
        $tool = make_tool( null, array(
            'type'       => 'object',
            'properties' => array(
                'name'  => array( 'type' => 'string', 'description' => 'CSS variable name' ),
                'value' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'name' ),
        ) );
        $json  = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true( is_object( $json->inputSchema->properties ) );
        Agentshell_Test::assert_equals(
            array( 'name' => 'string', 'value' => 'string' ),
            schema_prop_types( $json->inputSchema )
        );
        Agentshell_Test::assert_equals( 'CSS variable name', $json->inputSchema->properties->name->description );
    }
);

Agentshell_Test::run(
    'non-empty properties are passed through, not rebuilt',
    function () {
        $schema = array(
            'type'       => 'object',
            'properties' => array( 'label' => array( 'type' => 'string' ) ),
        );
        $tool = make_tool( null, $schema );
        Agentshell_Test::assert_equals( $schema, $tool->get_definition()['inputSchema'] );
    }
);

Agentshell_Test::run(
    'nested property constraints survive serialization',
    function () {
        $tool = make_tool( null, array(
            'type'       => 'object',
            'properties' => array(
                'slots' => array(
                    'type'       => 'object',
                    'properties' => array( 'left' => array( 'type' => 'array' ) ),
                ),
            ),
        ) );
        $json = schema_json( $tool->get_definition() );
        $slots = $json->inputSchema->properties->slots;
        Agentshell_Test::assert_equals( 'object', $slots->type );
        Agentshell_Test::assert_true( is_object( $slots->properties ), 'nested properties must be an object' );
        Agentshell_Test::assert_equals( 'array', $slots->properties->left->type );
    }
);

// ─── `required` must stay an array ──────────────────────────────

Agentshell_Test::run(
    'empty required stays a JSON array',
    function () {
        $tool = make_tool( null, array(
            'type'       => 'object',
            'properties' => array( 'name' => array( 'type' => 'string' ) ),
            'required'   => array(),
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true(
            is_array( $json->inputSchema->required ),
            'required must remain an array, got ' . gettype( $json->inputSchema->required )
        );
        Agentshell_Test::assert_equals( 0, count( $json->inputSchema->required ) );
    }
);

Agentshell_Test::run(
    'non-empty required keeps its members and order',
    function () {
        $tool = make_tool( null, array(
            'type'       => 'object',
            'properties' => array(
                'name'  => array( 'type' => 'string' ),
                'value' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'name', 'value' ),
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true( is_array( $json->inputSchema->required ) );
        Agentshell_Test::assert_equals( array( 'name', 'value' ), $json->inputSchema->required );
    }
);

Agentshell_Test::run(
    'a zero-argument tool with an empty required keeps both contracts',
    function () {
        $tool = make_tool( null, array(
            'type'       => 'object',
            'properties' => array(),
            'required'   => array(),
        ) );
        $json = schema_json( $tool->get_definition() );
        Agentshell_Test::assert_true( is_object( $json->inputSchema->properties ), 'properties must be an object' );
        Agentshell_Test::assert_true( is_array( $json->inputSchema->required ), 'required must be an array' );
    }
);

// ─── schemas that omit properties are not given one ────────────

Agentshell_Test::run(
    'a schema without a properties key does not gain one',
    function () {
        $schema = array( 'type' => 'object' );
        $tool   = make_tool( null, $schema );
        $def    = $tool->get_definition();
        Agentshell_Test::assert_false( array_key_exists( 'properties', $def['inputSchema'] ) );
        Agentshell_Test::assert_equals( $schema, $def['inputSchema'] );
    }
);

Agentshell_Test::run(
    'definition carries name and description alongside the schema',
    function () {
        $tool = make_tool( null, array( 'type' => 'object', 'properties' => array() ) );
        $def  = $tool->get_definition();
        Agentshell_Test::assert_equals( 'stub_tool', $def['name'] );
        Agentshell_Test::assert_equals( 'Schema stub.', $def['description'] );
        Agentshell_Test::assert_true( isset( $def['inputSchema'] ) );
    }
);