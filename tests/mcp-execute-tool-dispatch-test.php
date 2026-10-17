<?php
/**
 * Section E regression — MCP unknown-tool HTTP 500.
 *
 * The bug
 * -------
 * `agentshell-blocks.php:111` registered an `agentshell_mcp_execute_tool`
 * filter whose callback takes 4 parameters (`$response, $tool_name,
 * $arguments, $tool_registry`) but told WordPress `accepted_args = 3`.
 *
 * The dispatcher in `class-server.php:104` calls
 *   apply_filters( 'agentshell_mcp_execute_tool', null, $tool_name, $arguments )
 * — three arguments. WordPress therefore passes three arguments to the
 * callback, and PHP raises ArgumentCountError ("Too few arguments to
 * function ... 3 passed ... 4 expected"). The exception is uncaught at the
 * REST boundary, so an unknown tool name — anything not in the main
 * gather_tools() registry — surfaces to the client as an HTTP 500 instead
 * of the JSON-RPC `METHOD_NOT_FOUND` it should.
 *
 * The narrow contract under test
 * -----------------------------
 * 1. The filter's `accepted_args` must be ≥ the number of arguments the
 *    dispatcher actually passes. Anything lower and PHP raises
 *    ArgumentCountError before the callback can return a response.
 * 2. For an unknown tool name, the callback must return its `$response`
 *    argument unchanged so the dispatcher's null-check at
 *    `class-server.php:105` falls through to the standard
 *    `METHOD_NOT_FOUND` JSON-RPC error. Anything else and the dispatcher
 *    treats the result as a tool response and emits it to the client.
 *
 * Why this lives in the bootstrap and not a live harness
 * ------------------------------------------------------
 * A live test would require the test bed's WP install (currently drifted
 * to `integration` mode per the Section D work) and a Basic Auth session.
 * The defect is a pure PHP/WordPress-filter contract — the same way
 * `tests/tool-schema-test.php` exercises the JSON Schema normalisation
 * without going through HTTP — so the test is fully deterministic in
 * the unit-test bootstrap.
 */

require_once __DIR__ . '/bootstrap.php';

echo "MCP execute-tool dispatch regression (Section E)\n";

// ── helper: extract the closure registered for the filter ────────────────
//
// The blocks plugin file ships the callback as an inline closure in
// add_filter(). We re-register that exact closure here so the test runs
// against the production code shape, not a hand-rolled approximation.
// Reading the source rather than loading the file keeps the test free of
// the plugin's heavier bootstrap (register_activation_hook, add_shortcode,
// plugin_dir_path — none of which are stubbed in the unit-test bootstrap).
function agentshell_section_e_load_blocks_filter() {
    $path = dirname( __DIR__ ) . '/agentshell-blocks/agentshell-blocks.php';
    $src  = file_get_contents( $path );
    if ( ! is_string( $src ) ) {
        throw new RuntimeException( 'cannot read ' . $path );
    }

    // Find the add_filter call for agentshell_mcp_execute_tool. We capture
    // three groups: the parameter list as written, the body, and the
    // accepted_args integer. The pattern matches any closure shape — the
    // test asserts the contract that accepted_args == parameter_count, so
    // the parameter list is taken verbatim from the source.
    if ( ! preg_match(
        '/add_filter\(\s*[\'"]agentshell_mcp_execute_tool[\'"]\s*,\s*function\s*\(\s*(\$response\s*,?\s*(?:\$\w+\s*,?\s*)*)\)\s*\{(.*)\}\s*,\s*10\s*,\s*(\d+)\s*\)\s*;/s',
        $src,
        $m
    ) ) {
        throw new RuntimeException( 'cannot locate the agentshell_mcp_execute_tool filter in the source' );
    }
    $param_list = trim( $m[1], " \t\n," );
    $body       = $m[2];
    $accepted   = (int) $m[3];

    // Reconstruct the closure with the EXACT parameter list the source
    // declares. eval() inside a function emits a Closure; the parameter
    // list is interpolated from the source, so a future change to add or
    // remove parameters is reflected here automatically.
    $closure = eval( 'return function ( ' . $param_list . ' ) {' . $body . '};' );
    if ( ! $closure instanceof Closure ) {
        throw new RuntimeException( 'eval did not produce a closure' );
    }
    return array(
        'closure'       => $closure,
        'accepted_args' => $accepted,
        'param_list'    => $param_list,
    );
}

function agentshell_section_e_register() {
    agentshell_test_reset();
    $cb = agentshell_section_e_load_blocks_filter();
    add_filter(
        'agentshell_mcp_execute_tool',
        $cb['closure'],
        10,
        $cb['accepted_args']
    );
    return $cb;
}

// ── 1. The accepted_args must match the callback's parameter count.
//      WordPress passes min(accepted_args, count($args)) to the callback,
//      so accepted_args < parameter_count → ArgumentCountError.
Agentshell_Test::run(
    'agentshell_mcp_execute_tool accepted_args matches the callback parameter count',
    function () {
        $cb = agentshell_section_e_load_blocks_filter();
        $reflection = new ReflectionFunction( $cb['closure'] );
        $param_count = $reflection->getNumberOfParameters();
        Agentshell_Test::assert_equals(
            $param_count,
            $cb['accepted_args'],
            'accepted_args must equal the callback parameter count; got accepted_args='
                . $cb['accepted_args'] . ' but callback takes ' . $param_count
                . ' parameters — a mismatch means ArgumentCountError on every dispatch'
        );
    }
);

// ── 2. Dispatching the filter with the same shape the server uses must not
//      throw. This is the behavioural symptom: any unknown tool name
//      reaching the dispatcher becomes an uncaught fatal without the fix.
Agentshell_Test::run(
    'unknown tool dispatch returns the pass-through response without throwing',
    function () {
        agentshell_section_e_register();
        $response = null;
        $threw    = null;
        try {
            $response = apply_filters(
                'agentshell_mcp_execute_tool',
                null,
                'agentshell_does_not_exist',
                array()
            );
        } catch ( \Throwable $e ) {
            $threw = $e;
        }
        Agentshell_Test::assert_true(
            null === $threw,
            'dispatching an unknown tool must not throw; got: '
                . ( $threw ? get_class( $threw ) . ': ' . $threw->getMessage() : '' )
        );
        // The pass-through path returns the unmodified response (null), so
        // the dispatcher's null-check on class-server.php:105 falls through
        // to the standard METHOD_NOT_FOUND JSON-RPC error. Anything else
        // would be a synthetic tool response with no body — a real client
        // would see "Connected" with no tools listed.
        Agentshell_Test::assert_true(
            null === $response,
            'pass-through must return the original response (null); got: '
                . var_export( $response, true )
        );
    }
);

// ── 3. A known tool that lives in this plugin (unregister_widget /
//      get_widget) reaches the require_once body of the callback. The
//      fixture in this test does not define the tools' classes — so the
//      callback should not crash on a non-widget tool name (which is what
//      the production path almost always sees, given the main register_tools
//      filter already covers every tool that has a class file). Confirm
//      the early-return branch (in_array check at agentshell-blocks.php:113)
//      is reached and exercised: the response comes back null.
Agentshell_Test::run(
    'non-widget unknown tool returns pass-through',
    function () {
        agentshell_section_e_register();
        $response = apply_filters(
            'agentshell_mcp_execute_tool',
            null,
            'agentshell_list_widgets',
            array( 'name' => 'whatever' )
        );
        Agentshell_Test::assert_true(
            null === $response,
            'a tool name that is not in the widget-tools list must pass through; got: '
                . var_export( $response, true )
        );
    }
);
