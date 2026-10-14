<?php
/**
 * Mutation test for the configuration-resolution contract.
 *
 * The defect this guards against is not a crash — it is two readers disagreeing
 * about which configuration they hold. Such a thing does not fail loudly; it
 * fails as a widget that vanishes, a preview that shows the wrong layout, or a
 * transaction that validates one state and commits another. The only honest way
 * to show the tests would catch that is to take each mechanism away and confirm
 * they do.
 *
 * Each mutant removes exactly one mechanism from the contract:
 *
 *   - `agentshell_get_config( 'effective' )` stops consulting staged state
 *   - an unrecognised source silently falls back instead of being refused
 *   - `agentshell_get_widget_registry()` / `agentshell_get_zones()` stop
 *     honouring the configuration they are handed
 *   - the render resolver stops consulting the preview credential gate
 *   - `Base_Tool` reads committed state instead of effective state
 *   - `Doctor` validates the committed registry instead of the staged one
 *   - the legacy-option migration deletes before verifying the write landed
 *   - the blocks plugin reads its own option again / renders disabled widgets
 *   - the shell's zone resolver reads the committed option again
 *
 * Killed means the suite reported at least one failing assertion. It does not
 * mean the mutant crashed — a fatal parse error would be a harness bug, so each
 * mutant is linted before it is judged.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the system
 * temp directory and the suite is run from that copy. Nothing here opens the
 * working tree for writing; the fingerprint at the end asserts that rather than
 * assuming it. See tests/mutation-sandbox.php.
 *
 * Usage:
 *   php tests/config-consistency-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

$THEME_REL = 'functions.php';
$SUITE_REL = 'tests/config-consistency-test.php';

$BASE_REL   = 'agentshell-mcp/includes/tools/class-base-tool.php';
$TX_REL     = 'agentshell-mcp/includes/class-transactions.php';
$DOCTOR_REL = 'agentshell-mcp/includes/class-doctor.php';
$SHELL_REL  = 'template-parts/shell-render.php';
$BLOCKS_REL = 'agentshell-blocks/includes/class-widget-registry.php';

$mutants = array(

    // ── the effective/committed split ──────────────────────────────────────

    "'effective' ignores the staged configuration" => array(
        $THEME_REL,
        "            if ( is_array( \$staged ) ) {\n                return \$staged;\n            }",
        "            if ( false ) {\n                return \$staged;\n            }",
    ),

    "'effective' consults committed state first" => array(
        $THEME_REL,
        "        if ( class_exists( '\\AgentShell_MCP\\Transaction_Manager' ) ) {",
        "        if ( false ) {",
    ),

    "an unrecognised source falls back instead of being refused" => array(
        $THEME_REL,
        "    if ( 'committed' !== \$source ) {",
        "    if ( false ) {",
    ),

    "Base_Tool reads committed state" => array(
        $BASE_REL,
        "            return agentshell_get_config( 'effective' );",
        "            return agentshell_get_config();",
    ),

    // ── cancellation of an out-of-band write during a transaction ───────────

    "a direct config write during a transaction is stored instead of staged" => array(
        $TX_REL,
        "        if ( \$this->stage( \$value ) ) {\n            return \$old_value; // unchanged — WordPress skips the write\n        }",
        "        \$this->stage( \$value );",
    ),

    // ── readers that must take their configuration explicitly ──────────────

    "the widget registry ignores the configuration it is handed" => array(
        $THEME_REL,
        "    // 2. Merge agent-defined widgets from config (these override stable with same ID)\n    \$config = is_array( \$config ) ? \$config : agentshell_get_config();",
        "    // mutant: the argument is ignored\n    \$config = agentshell_get_config();",
    ),

    "the zone resolver ignores the configuration it is handed" => array(
        $THEME_REL,
        "    \$config = is_array( \$config ) ? \$config : agentshell_get_config();\n    \$saved  = \$config['zones'] ?? array();",
        "    \$config = agentshell_get_config();\n    \$saved  = \$config['zones'] ?? array();",
    ),

    // ── the render-side gate ───────────────────────────────────────────────

    "the render resolver skips the preview credential gate" => array(
        $THEME_REL,
        "    if ( ! agentshell_authorize_transaction_preview() ) {\n        return \$config;\n    }",
        "    if ( false ) {\n        return \$config;\n    }",
    ),

    "the shell's own zone resolver reads the committed option" => array(
        $SHELL_REL,
        "    \$config  = agentshell_config_for_render();",
        "    \$config  = agentshell_get_config();",
    ),

    // ── validation sees what would be committed ────────────────────────────

    "Doctor validates the committed registry" => array(
        $DOCTOR_REL,
        "            \$registry = function_exists( 'agentshell_get_widget_registry' ) ? agentshell_get_widget_registry( \$config ) : array();",
        "            \$registry = function_exists( 'agentshell_get_widget_registry' ) ? agentshell_get_widget_registry() : array();",
    ),

    // ── the legacy option ──────────────────────────────────────────────────

    "the legacy migration deletes without checking the write landed" => array(
        $THEME_REL,
        "    foreach ( array_keys( \$migrated ) as \$id ) {\n        if ( ! is_array( \$persisted ) || ! isset( \$persisted['widgets'][ \$id ] ) ) {\n            return; // not written — leave the legacy option alone\n        }\n    }",
        "    // mutant: delete unconditionally",
    ),

    "the blocks plugin reads its own option again" => array(
        $BLOCKS_REL,
        "                \$this->cache = array_values( agentshell_get_widget_registry() );",
        "                \$this->cache = get_option( self::legacy_option(), array() );",
    ),

    "the blocks plugin renders disabled widgets" => array(
        $BLOCKS_REL,
        "        if ( ( \$widget['status'] ?? 'active' ) === 'disabled' ) {\n            return '';\n        }",
        "        // mutant: no disabled check",
    ),

    // ── the preview render path in the blocks plugin ────────────────────────

    "the blocks plugin renderer resolves against committed state again" => array(
        $BLOCKS_REL,
        "        \$widget = \$this->get_widget_for_render( \$id );",
        "        \$widget = \$this->get_widget( \$id );",
    ),

    "the blocks plugin asset paths resolve against committed state again" => array(
        $BLOCKS_REL,
        "        foreach ( \$this->get_widgets_for_render() as \$widget ) {\n            if ( ! empty( \$widget['css'] ) ) {",
        "        foreach ( \$this->get_widgets() as \$widget ) {\n            if ( ! empty( \$widget['css'] ) ) {",
    ),
);

$killed   = 0;
$survived = array();

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_suite = $sandbox . '/' . $SUITE_REL;
if ( ! is_file( $sandbox_suite ) ) {
    echo "SANDBOX FAILED — {$SUITE_REL} is missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox_suite );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
if ( ! preg_match( '/(\d+) passed/', $baseline['output'], $m ) ) {
    echo "NOTE — the suite prints no pass count standalone (it is a *-test.php;\n";
    echo "       run-all.php prints the tally), so only the FAIL count is used.\n\n";
} else {
    echo "baseline: {$m[1]} passed, 0 failed\n\n";
}

foreach ( $mutants as $label => $mutation ) {
    list( $rel, $find, $replace ) = $mutation;

    $sandbox_file = $sandbox . '/' . $rel;
    if ( ! is_file( $sandbox_file ) ) {
        echo "SKIP   $label — {$rel} is missing from the copy\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $original = file_get_contents( $sandbox_file );

    if ( false === strpos( $original, $find ) ) {
        // A mutant that cannot be applied is not evidence about the suite.
        echo "SKIP   $label — the target text is no longer in the source\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $mutated = str_replace( $find, $replace, $original, $count );
    if ( $count < 1 ) {
        echo "SKIP   $label — no replacement made\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $lint = agentshell_mutation_lint( $mutated );
    if ( 0 !== $lint['rc'] ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . $lint['output'] . "\n";
        $survived[] = $label . ' (does not parse)';
        continue;
    }

    // The only write in the whole harness, and it is inside the sandbox.
    file_put_contents( $sandbox_file, $mutated );

    $result = agentshell_mutation_run_suite( $sandbox_suite );

    // Put the pristine copy back so the next mutant starts from the original.
    file_put_contents( $sandbox_file, $original );

    if ( $result['failed'] > 0 ) {
        $killed++;
        echo "killed $label ({$result['failed']} failing assertions)\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// The whole point of the sandbox: prove it, do not assume it.
$diff = agentshell_repo_diff( $repo_before );
if ( $diff['changed'] || $diff['added'] || $diff['removed'] ) {
    echo "\nREPO INTEGRITY FAILURE — the harness modified the checkout:\n";
    foreach ( array( 'changed', 'added', 'removed' ) as $kind ) {
        foreach ( $diff[ $kind ] as $path ) {
            echo "  $kind: $path\n";
        }
    }
    exit( 1 );
}
echo "checkout untouched\n";

exit( $survived ? 1 : 0 );