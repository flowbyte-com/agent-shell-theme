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
 *    blocks plugin registers plus the number the forms plugin registers
 *    equals the count in `AGENTS.md` §2.
 * 2. The plugin README states the same number.
 * 3. The new tool that closed the "no MCP route to custom CSS" gap is
 *    registered and reachable by name.
 * 4. The twelve forms tools are registered and reachable by name.
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
 *
 * Returns the *tool names*, not the class names. The registry is keyed by
 * tool name and keeps the first registration of each name, so the number
 * an agent sees is the number of distinct names — and two plugins may
 * register the same name. Counting classes instead would count a
 * registration that the registry then discards, which is precisely how the
 * documented count came to disagree with the live surface.
 */
function tool_names_in( $path, $namespace, $class_dir ) {
    $source = file_get_contents( $path );
    if ( ! is_string( $source ) ) {
        throw new RuntimeException( 'cannot read ' . $path );
    }
    $pattern = '/new\s+' . preg_quote( $namespace, '/' ) . '\\\\Tools\\\\([A-Za-z0-9_]+)\s*\(/';
    if ( ! preg_match_all( $pattern, $source, $m ) ) {
        return array();
    }
    $names = array();
    foreach ( array_unique( $m[1] ) as $class ) {
        $file = $class_dir . '/includes/tools/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        if ( ! file_exists( $file ) ) {
            throw new RuntimeException( 'cannot find the class file for ' . $class . ' (expected ' . $file . ')' );
        }
        $class_src = file_get_contents( $file );
        if ( ! preg_match( "/function\s+get_name\s*\(\s*\)\s*\{\s*return\s+'([^']+)'/", $class_src, $nm ) ) {
            throw new RuntimeException( 'cannot read the tool name of ' . $class );
        }
        $names[] = $nm[1];
    }
    return $names;
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
        $mcp    = tool_names_in( dirname( __DIR__ ) . '/agentshell-mcp/agentshell-mcp.php', 'AgentShell_MCP', dirname( __DIR__ ) . '/agentshell-mcp' );
        $blocks = tool_names_in( dirname( __DIR__ ) . '/agentshell-blocks/agentshell-blocks.php', 'AgentShell_Blocks', dirname( __DIR__ ) . '/agentshell-blocks' );
        $forms  = tool_names_in( dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php', 'AgentShell_Forms', dirname( __DIR__ ) . '/agentshell-forms' );
        // The registry is keyed by tool name and keeps the first
        // registration of each name, so the number an agent sees is the
        // number of DISTINCT names — not the number of registrations.
        // Two plugins register `agentshell_list_widgets`, so counting
        // registrations over-counts by one, and that is how the
        // documented number came to disagree with the live surface.
        $registered = count( array_unique( array_merge( $mcp, $blocks, $forms ) ) );
        $documented = tool_count_documented();

        Agentshell_Test::assert_equals(
            $documented,
            $registered,
            sprintf(
                'AGENTS.md §2 says %d tools; the plugins publish %d distinct tool names (%d in agentshell-mcp + %d in agentshell-blocks + %d in agentshell-forms)',
                $documented,
                $registered,
                count( array_unique( $mcp ) ),
                count( array_unique( $blocks ) ),
                count( array_unique( $forms ) )
            )
        );
    }
);

Agentshell_Test::run(
    'no two plugins publish the same tool name',
    function () {
        $mcp    = tool_names_in( dirname( __DIR__ ) . '/agentshell-mcp/agentshell-mcp.php', 'AgentShell_MCP', dirname( __DIR__ ) . '/agentshell-mcp' );
        $blocks = tool_names_in( dirname( __DIR__ ) . '/agentshell-blocks/agentshell-blocks.php', 'AgentShell_Blocks', dirname( __DIR__ ) . '/agentshell-blocks' );
        $forms  = tool_names_in( dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php', 'AgentShell_Forms', dirname( __DIR__ ) . '/agentshell-forms' );
        foreach ( array(
            array( 'agentshell-mcp and agentshell-blocks', $mcp, $blocks ),
            array( 'agentshell-mcp and agentshell-forms', $mcp, $forms ),
            array( 'agentshell-blocks and agentshell-forms', $blocks, $forms ),
        ) as $pair ) {
            $clash = array_intersect( $pair[1], $pair[2] );
            Agentshell_Test::assert_equals(
                array(),
                array_values( $clash ),
                $pair[0] . ' share a tool name — the registry keeps the first registration of a name, so a duplicated name means one implementation is silently unreachable'
            );
        }
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

Agentshell_Test::run(
    'the forms tools are registered and reachable by name',
    function () {
        $expected = array(
            'agentshell_list_forms'           => 'List_Forms',
            'agentshell_get_form'             => 'Get_Form',
            'agentshell_create_form'          => 'Create_Form',
            'agentshell_update_form'          => 'Update_Form',
            'agentshell_validate_form'        => 'Validate_Form',
            'agentshell_publish_form'         => 'Publish_Form',
            'agentshell_discard_form_changes' => 'Discard_Form_Changes',
            'agentshell_list_submissions'     => 'List_Submissions',
            'agentshell_get_submission'       => 'Get_Submission',
            'agentshell_export_submissions'   => 'Export_Submissions',
            'agentshell_trash_submission'     => 'Trash_Submission',
            'agentshell_delete_submission'    => 'Delete_Submission',
        );

        $plugin = file_get_contents( dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php' );
        Agentshell_Test::assert_true( is_string( $plugin ), 'the forms bootstrap must be readable' );

        // Registration is a literal `new` line per tool — the same lines
        // the count above parses. A name that appears only in a comment
        // is not registered.
        foreach ( $expected as $name => $class ) {
            Agentshell_Test::assert_true(
                false !== strpos( $plugin, 'new AgentShell_Forms\\Tools\\' . $class . '()' ),
                $name . ' must be registered by the forms plugin'
            );
        }

        $registered = tool_names_in( dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php', 'AgentShell_Forms', dirname( __DIR__ ) . '/agentshell-forms' );

        $want = array_keys( $expected );
        sort( $want );
        $actual = array_values( array_unique( $registered ) );
        sort( $actual );

        Agentshell_Test::assert_equals(
            $want,
            $actual,
            'the forms plugin must publish exactly the twelve documented tools'
        );

        require_once dirname( __DIR__ ) . '/agentshell-forms/includes/tools/class-base-tool.php';
        require_once dirname( __DIR__ ) . '/agentshell-forms/includes/tools/class-list-forms.php';

        $tool = new \AgentShell_Forms\Tools\List_Forms();
        Agentshell_Test::assert_equals(
            'agentshell_list_forms',
            $tool->get_name(),
            'the tool must answer to the name it is documented under'
        );
    }
);

echo "\n";
echo "tool count: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
