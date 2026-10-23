<?php
/**
 * The documented tool count must equal the registry's.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The tool count was written down in five places and went stale in all of
 * them. A dogfooding session measured the live surface and found the
 * documentation claiming 45+ and 59 where the registry registered 64 — and
 * the finding was not "the number is wrong" but "the number should be
 * generated from the registry rather than written down".
 *
 * A number that is written down can only be kept right by remembering to
 * change it. This test counts the tools the plugins actually register and
 * fails when the documented number differs, so a count that drifts fails
 * the build instead of misleading an agent.
 *
 * What is asserted here
 * ---------------------
 * 1. The number of tools the MCP plugin registers plus the number the
 *    blocks plugin registers equals the count in `AGENTS.md` §2.
 * 2. The plugin README states the same number.
 * 3. The new tool that closed the "no MCP route to custom CSS" gap is
 *    registered and reachable by name.
 */

require_once __DIR__ . '/bootstrap.php';

echo "tool count regression tests\n";

/**
 * The tools a plugin file registers.
 *
 * Counted from the source rather than by booting WordPress, so the check
 * runs in the unit bootstrap and does not depend on a live site. The
 * pattern is the plugin's own: a `new AgentShell_MCP\Tools\…` line
 * inside the registration filter.
 */
function tool_classes_in( $path, $namespace ) {
    $source = file_get_contents( $path );
    if ( ! is_string( $source ) ) {
        throw new RuntimeException( 'cannot read ' . $path );
    }
    // The class names, as distinct names: a plugin may instantiate the
    // same class twice — the blocks plugin does, once for the registry
    // and once for a name-to-instance map — and the registry is keyed by
    // tool name, so a repeated class is one tool.
    $pattern = '/new\s+' . preg_quote( $namespace, '/' ) . '\\\\Tools\\\\([A-Za-z0-9_]+)\s*\(/';
    if ( ! preg_match_all( $pattern, $source, $m ) ) {
        return array();
    }
    return array_values( array_unique( $m[1] ) );
}

/** The number the documentation states. */
function tool_count_documented() {
    $agents = file_get_contents( dirname( __DIR__ ) . '/AGENTS.md' );
    if ( ! is_string( $agents ) ) {
        throw new RuntimeException( 'cannot read AGENTS.md' );
    }
    if ( ! preg_match( '/##\s*2\.\s*MCP tools \(authoritative count: \*\*(\d+)\*\*/', $agents, $m ) ) {
        throw new RuntimeException( 'AGENTS.md §2 does not state an authoritative tool count' );
    }
    return (int) $m[1];
}

Agentshell_Test::run(
    'the documented tool count equals the number the plugins register',
    function () {
        $mcp    = tool_classes_in( dirname( __DIR__ ) . '/agentshell-mcp/agentshell-mcp.php', 'AgentShell_MCP' );
        $blocks = tool_classes_in( dirname( __DIR__ ) . '/agentshell-blocks/agentshell-blocks.php', 'AgentShell_Blocks' );
        $registered = count( $mcp ) + count( $blocks );
        $documented = tool_count_documented();

        Agentshell_Test::assert_equals(
            $documented,
            $registered,
            sprintf(
                'AGENTS.md §2 says %d tools; the plugins register %d (%d in agentshell-mcp + %d in agentshell-blocks)',
                $documented,
                $registered,
                count( $mcp ),
                count( $blocks )
            )
        );
    }
);

Agentshell_Test::run(
    'the plugin README states the same count',
    function () {
        $readme = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/README.md' );
        Agentshell_Test::assert_true( is_string( $readme ), 'the README must be readable' );
        Agentshell_Test::assert_true(
            false !== strpos( $readme, '**' . tool_count_documented() . ' `agentshell_*` MCP tools**' ),
            'the README must state the same count as AGENTS.md §2'
        );
    }
);

Agentshell_Test::run(
    'the custom CSS tool is registered and reachable by name',
    function () {
        $plugin = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/agentshell-mcp.php' );
        Agentshell_Test::assert_true(
            false !== strpos( $plugin, 'new AgentShell_MCP\\Tools\\Set_Custom_Css()' ),
            'the tool must be registered'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $plugin, 'includes/tools/class-set-custom-css.php' ),
            'the tool class must be loaded'
        );

        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-set-custom-css.php';

        $tool = new \AgentShell_MCP\Tools\Set_Custom_Css();
        Agentshell_Test::assert_equals(
            'agentshell_set_custom_css',
            $tool->get_name(),
            'the tool must answer to the name it is documented under'
        );
        $schema = $tool->get_input_schema();
        Agentshell_Test::assert_true(
            in_array( 'css', (array) ( $schema['required'] ?? array() ), true ),
            'the stylesheet must be a required argument'
        );
    }
);

echo "\n";
echo "tool count: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
