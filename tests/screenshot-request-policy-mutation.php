<?php
/**
 * Mutation test for the browser request policy.
 *
 * The claim under test is that a capture cannot reach a destination outside
 * the site. The strongest way to check that claim is to remove the
 * mechanism and confirm the suite says so, rather than trusting that the
 * assertions happen to touch the code that matters.
 *
 * Each mutant removes or inverts exactly one mechanism, the suite is run,
 * and the run is judged **killed** if any assertion failed. Killed does
 * not mean "the mutation produced an error" — it means the suite noticed.
 *
 * The suite used to judge them is the fast interception suite, which
 * drives the capture against a fake browser that pauses requests and
 * records what the capture answered. The live suite
 * (`screenshot-request-policy-live-test.php`) proves the same property
 * against a real browser, and it is far too slow to run once per mutant.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the
 * system temp directory, and the suites run from that copy. Nothing here
 * opens the working tree for writing; the fingerprint at the end asserts
 * it rather than assuming it.
 *
 * Usage:
 *   php tests/screenshot-request-policy-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

/** Paths are relative to the repository root, and identical in the sandbox. */
$CDP_REL    = 'agentshell-mcp/includes/class-screenshot-cdp.php';
$POLICY_REL = 'agentshell-mcp/includes/class-browser-request-policy.php';
$SUITE_REL  = 'tests/screenshot-interception-test.php';

$cdp_source    = file_get_contents( agentshell_repo_root() . '/' . $CDP_REL );
$policy_source = file_get_contents( agentshell_repo_root() . '/' . $POLICY_REL );

/**
 * Mutants of the interception.
 *
 * find/replace pairs, applied to a copy of the real source.
 */
$cdp_mutants = array(
    'the interception is never installed' => array(
        "        \$fetch = \$connection->call(\n            'Fetch.enable',",
        "        \$fetch = \$connection->call(\n            'Page.enable',",
    ),
    'every paused request is continued' => array(
        '        if ( $decision[\'allow\'] ) {',
        '        if ( true ) {',
    ),
    'the refusal is not sent to the browser' => array(
        "        \$conn->notify(\n            'Fetch.failRequest',",
        "        \$conn->notify(\n            'Fetch.continueRequest',",
    ),
    'the page asking to leave is not refused' => array(
        "            if ( ! empty( \$violation['is_main_frame'] ) && 'Document' === \$violation['resource_type'] ) {\n                \$out['error'] = 'the page asked to leave this site: ' . \$violation['reason'];\n                \$out['fatal'] = true;\n                return \$out;\n            }",
        "            if ( false ) {\n                return \$out;\n            }",
    ),
    'the refusals are not reported' => array(
        "        \$state['violations'][] = array(",
        "        \$state['violations_not_reported'][] = array(",
    ),
);

/**
 * Mutants of the policy itself.
 */
$policy_mutants = array(
    'the port is not compared' => array(
        '            if ( null !== $origin[\'port\'] && (int) $origin[\'port\'] === $port ) {',
        '            if ( null !== $origin[\'port\'] ) {',
    ),
    'the policy permits everything' => array(
        "        return \$this->decision(\n            false,\n            \$category,",
        "        return \$this->decision(\n            true,\n            \$category,",
    ),
);

$killed   = 0;
$survived = array();

/**
 * Run one mutant in the sandbox and judge it.
 *
 * @param array  $mutation      find/replace pair.
 * @param string $label         Human-readable mutant name.
 * @param string $sandbox       Sandbox root; suites are run from here.
 * @param string $sandbox_file  Path of the mutable file inside the sandbox.
 * @param string $original      Pristine source, restored after each mutant.
 * @param string $suite_rel     Suite to judge with, relative to the sandbox root.
 */function agentshell_judge_mutant( array $mutation, $label, $sandbox, $sandbox_file, $original, $suite_rel ) {
    global $killed, $survived;

    list( $find, $replace ) = $mutation;

    if ( false === strpos( $original, $find ) ) {
        echo "SKIP   $label — the target text is no longer in the source\n";
        $survived[] = $label . ' (not applicable)';
        return;
    }

    $mutated = str_replace( $find, $replace, $original, $count );
    if ( $count < 1 ) {
        echo "SKIP   $label — no replacement made\n";
        $survived[] = $label . ' (not applicable)';
        return;
    }

    $lint = agentshell_mutation_lint( $mutated );
    if ( 0 !== $lint['rc'] ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . $lint['output'] . "\n";
        $survived[] = $label . ' (does not parse)';
        return;
    }

    // The only write in the whole harness, and it is inside the sandbox.
    file_put_contents( $sandbox_file, $mutated );

    $result = agentshell_mutation_run_suite( $sandbox . '/' . $suite_rel );

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

/**
 * Make a file executable inside the sandbox.
 *
 * @param string $path The path.
 */
function sandbox_chmod( $path ) {
    if ( is_file( $path ) ) {
        @chmod( $path, 0755 );
    }
}

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_cdp    = $sandbox . '/' . $CDP_REL;
$sandbox_policy = $sandbox . '/' . $POLICY_REL;
if ( ! is_file( $sandbox_cdp ) || ! is_file( $sandbox_policy ) ) {
    echo "SANDBOX FAILED — the sources are missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// The sandbox copy is made file by file, and a copy does not carry the
// executable bit. The fake browser is a program, so it needs one.
@sandbox_chmod( $sandbox . '/tests/fake-cdp-browser.php' );

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

foreach ( $cdp_mutants as $label => $mutation ) {
    agentshell_judge_mutant( $mutation, $label, $sandbox, $sandbox_cdp, $cdp_source, $SUITE_REL );
}

foreach ( $policy_mutants as $label => $mutation ) {
    agentshell_judge_mutant( $mutation, $label, $sandbox, $sandbox_policy, $policy_source, $SUITE_REL );
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $cdp_mutants ) + count( $policy_mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// The whole point of the sandbox: prove it, do not assume it.
$diff = agentshell_repo_diff(
    $repo_before,
    array(
        agentshell_repo_root() . '/' . $CDP_REL,
        agentshell_repo_root() . '/' . $POLICY_REL,
    )
);
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
