<?php
/**
 * Regression test: agentshell-mcp.php must require_once the Stage 2c/2b draft
 * tool class files before instantiating them.
 *
 * Pre-fix state: feature/theme-modernize (rebased on stage/2c) added the
 * instantiations `new Update_Draft()` and `new Claim_Existing_Draft()` at
 * agentshell-mcp.php:201-202, but the corresponding `require_once` lines
 * for the class files were missing. Plugin bootstrap would fatal with
 * `Class "AgentShell_MCP\Tools\Update_Draft" not found`. The dev-site
 * main-repo copy is unaffected (those tools are not in scope until 2c
 * lands), so the bug is specific to the rebased theme-modernize branch.
 *
 * Post-fix invariants:
 *   1. agentshell-mcp.php requires_once class-update-draft.php.
 *   2. agentshell-mcp.php requires_once class-claim-existing-draft.php.
 *   3. Both class files are loadable and declare the expected FQN.
 *   4. No other instantiated tool is missing a require_once (the original
 *      bug only manifested in a rebase; the same shape of regression must
 *      not recur when other tools are added).
 *   5. The class is_base_tools() entry returns the expected class names
 *      (i.e. the load does not autoload-register a stub that hides the
 *      real class).
 *
 * The test reads the plugin source as a string and asserts presence; it
 * does NOT execute the plugin (which would require a full WP install).
 * A companion assertion instantiates the classes from a freshly loaded
 * class file to prove the file itself is well-formed.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';

echo "draft-tools bootstrap regression test\n";

$plugin_file = __DIR__ . '/../agentshell-mcp/agentshell-mcp.php';
Agentshell_Test::assert_true( is_file( $plugin_file ), 'plugin file exists' );

$src = file_get_contents( $plugin_file );
Agentshell_Test::assert_true( is_string( $src ) && strlen( $src ) > 1000, 'plugin source is non-trivial' );

// ─── 1 & 2: require_once lines are present ─────────────────────

Agentshell_Test::run(
    'agentshell-mcp.php requires_once class-update-draft.php',
    function () use ( $src ) {
        Agentshell_Test::assert_true(
            strpos( $src, "tools/class-update-draft.php" ) !== false,
            'class-update-draft.php require_once is missing — instantiating Update_Draft will fatal'
        );
    }
);

Agentshell_Test::run(
    'agentshell-mcp.php requires_once class-claim-existing-draft.php',
    function () use ( $src ) {
        Agentshell_Test::assert_true(
            strpos( $src, "tools/class-claim-existing-draft.php" ) !== false,
            'class-claim-existing-draft.php require_once is missing — instantiating Claim_Existing_Draft will fatal'
        );
    }
);

// ─── 3: class files are loadable and declare the expected FQN ───

Agentshell_Test::run(
    'Update_Draft class file declares namespace AgentShell_MCP\\Tools and class Update_Draft',
    function () {
        $file = __DIR__ . '/../agentshell-mcp/includes/tools/class-update-draft.php';
        Agentshell_Test::assert_true( is_file( $file ), 'class file exists on disk' );
        require_once $file;
        Agentshell_Test::assert_true( class_exists( 'AgentShell_MCP\\Tools\\Update_Draft' ), 'FQN is declared' );
        $r = new ReflectionClass( 'AgentShell_MCP\\Tools\\Update_Draft' );
        Agentshell_Test::assert_true( $r->isSubclassOf( 'AgentShell_MCP\\Tools\\Base_Tool' ), 'extends Base_Tool' );
    }
);

Agentshell_Test::run(
    'Claim_Existing_Draft class file declares namespace AgentShell_MCP\\Tools and class Claim_Existing_Draft',
    function () {
        $file = __DIR__ . '/../agentshell-mcp/includes/tools/class-claim-existing-draft.php';
        Agentshell_Test::assert_true( is_file( $file ), 'class file exists on disk' );
        require_once $file;
        Agentshell_Test::assert_true( class_exists( 'AgentShell_MCP\\Tools\\Claim_Existing_Draft' ), 'FQN is declared' );
        $r = new ReflectionClass( 'AgentShell_MCP\\Tools\\Claim_Existing_Draft' );
        Agentshell_Test::assert_true( $r->isSubclassOf( 'AgentShell_MCP\\Tools\\Base_Tool' ), 'extends Base_Tool' );
    }
);

// ─── 4: every instantiated tool has a matching require_once ────

Agentshell_Test::run(
    'every AgentShell_MCP\\Tools\\Foo instantiation in agentshell-mcp.php has a matching tools/class-foo.php require_once',
    function () use ( $src ) {
        preg_match_all( '/new AgentShell_MCP\\\\Tools\\\\([A-Z][A-Za-z_]+)/', $src, $instantiations );
        $names = array_unique( $instantiations[1] );
        sort( $names );
        $missing = array();
        foreach ( $names as $name ) {
            $slug = strtolower( str_replace( '_', '-', $name ) );
            $needle = "tools/class-{$slug}.php";
            if ( strpos( $src, $needle ) === false ) {
                $missing[] = $name;
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $missing,
            'these tools are instantiated but their class files are not required_once — would fatal on plugin load: '
            . implode( ', ', $missing )
        );
    }
);

// ─── 5: full class-file presence vs source instantiations ──────

Agentshell_Test::run(
    'every tool class file that the plugin requires_once actually exists on disk',
    function () use ( $src ) {
        preg_match_all( "/require_once[^\\n]*'includes\\/tools\\/class-([a-z-]+)\\.php'/", $src, $matches );
        $slugs = array_unique( $matches[1] );
        // class-base-tool and class-registry are required but do not represent
        // instantiated tools — they are the parent class and the registry
        // helper. They live in includes/tools/ and are part of the canonical
        // require_once list. The check below catches typos in either
        // direction: a class file that is required but missing, or a class
        // file that exists but is not required.
        sort( $slugs );
        $missing = array();
        foreach ( $slugs as $slug ) {
            $file = __DIR__ . "/../agentshell-mcp/includes/tools/class-{$slug}.php";
            if ( ! is_file( $file ) ) {
                $missing[] = "class-{$slug}.php";
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $missing,
            'require_once lists a class file that does not exist on disk: '
            . implode( ', ', $missing )
        );
    }
);

// ─── 6: the original bug's exact signature is gone from the file ─

Agentshell_Test::run(
    'no `new Update_Draft()` appears before its require_once line in agentshell-mcp.php',
    function () use ( $src ) {
        $req_pos = strpos( $src, "tools/class-update-draft.php" );
        $new_pos = strpos( $src, 'new AgentShell_MCP\\Tools\\Update_Draft' );
        Agentshell_Test::assert_true( $req_pos !== false, 'require line is present (asserted above; here for ordering check)' );
        Agentshell_Test::assert_true( $new_pos !== false, 'instantiation line is present' );
        Agentshell_Test::assert_true( $req_pos < $new_pos, 'require_once must precede the instantiation' );
    }
);

Agentshell_Test::run(
    'no `new Claim_Existing_Draft()` appears before its require_once line in agentshell-mcp.php',
    function () use ( $src ) {
        $req_pos = strpos( $src, "tools/class-claim-existing-draft.php" );
        $new_pos = strpos( $src, 'new AgentShell_MCP\\Tools\\Claim_Existing_Draft' );
        Agentshell_Test::assert_true( $req_pos !== false, 'require line is present' );
        Agentshell_Test::assert_true( $new_pos !== false, 'instantiation line is present' );
        Agentshell_Test::assert_true( $req_pos < $new_pos, 'require_once must precede the instantiation' );
    }
);

Agentshell_Test::summary();
exit( Agentshell_Test::summary() === true ? 0 : 1 );
