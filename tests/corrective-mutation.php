<?php
/**
 * Mutation test for the F1–F6 corrective diff.
 *
 * Why this needs its own harness
 * ------------------------------
 * The corrective diff (ec9fd59..b95037c) closed six findings with six
 * regression tests. A regression test that cannot fail is a claim, not a
 * test, and the six findings were all found by an adversarial review —
 * which is exactly the sort of finding that a test written alongside the
 * fix can accidentally fail to notice.
 *
 * So each guarantee the corrective diff adds is removed in turn here, and
 * the suite that covers it has to notice. A mutant that survives means the
 * corresponding test would have passed against the pre-fix code, and the
 * guarantee is not actually covered.
 *
 * Killed means "an assertion failed", not "the mutation produced an error".
 * A parse error would satisfy a naive check and prove nothing, so each
 * mutant is linted before it counts, and reported separately if it does not
 * parse.
 *
 * Equivalent mutants
 * ------------------
 * A mutant that removes one of two *redundant* guards survives by
 * construction: the other guard still refuses what the removed one
 * refused. Such a mutant is declared, with the argument for why it is
 * equivalent, and its survival is reported rather than counted as a
 * failure. A mutant that is *not* declared and survives is a coverage
 * gap, and fails the run.
 *
 * A mutant may equally be unobservable from outside: it defends against
 * something no caller can reach, so no test can see it. That is declared
 * the same way, with the argument.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the
 * system temp directory and the suite runs from that copy. Nothing here
 * opens the working tree for writing.
 *
 * Note on the kill condition: standalone `*-test.php` files do not set an
 * exit status — run-all.php does — so the verdict keys on FAIL lines in
 * the output. Those lines are indented by the reporter, so the match is
 * anchored at line start.
 *
 * Usage:
 *   php tests/corrective-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

/**
 * Each case: the file to mutate, the suite that must notice, and the
 * mutants. The mutant is a (from, to) pair of exact strings; the harness
 * replaces the *first* occurrence and refuses to run if the pattern is not
 * present exactly once.
 */
$cases = array(

    // ── F2: a frame length is validated before it becomes an offset ──────
    array(
        'label'  => 'F2: the CDP frame length is validated before it is used',
        'target'  => 'agentshell-mcp/includes/class-cdp.php',
        'suite'   => 'tests/cdp-test.php',
        'mutants' => array(
            'the safe-integer range check is removed' => array(
                "                if ( \$high > 0x1FFFFF ) {",
                "                if ( false ) {",
                // Equivalent by construction: the maximum-frame check
                // refuses every length above 32 MiB, and every length at
                // or above 2^53 is above 32 MiB. Removing the range check
                // changes which error the parser reports, not whether it
                // refuses — and the test asserts the refusal, not the
                // wording.
                'equivalent: the maximum-frame check refuses every length the range check would refuse',
            ),
            'the maximum-frame check is removed' => array(
                "                if ( \$payload_length > self::MAX_FRAME_BYTES ) {",
                "                if ( false ) {",
            ),
            'the length is read as one signed 64-bit half again' => array(
                "                \$halves = unpack( 'N2', substr( \$buffer, \$cursor, 8 ) );",
                "                \$halves = array( 1 => 0, 2 => (int) unpack( 'J', substr( \$buffer, \$cursor, 8 ) )[1] );",
            ),
        ),
    ),

    // ── F3: the stylesheet's temporary file cannot be hijacked ───────────
    array(
        'label'  => 'F3: the stylesheet is not written through a symlink',
        'target'  => 'functions.php',
        'suite'   => 'tests/custom-css-asset-test.php',
        'mutants' => array(
            'the temporary file is created non-exclusively again' => array(
                "    \$handle = @fopen( \$tmp, 'x' );",
                "    \$handle = @fopen( \$tmp, 'w' );",
                // Unobservable from outside: the temporary file's name is
                // sixteen random bytes, so nothing can be at that path
                // before it is created and 'x' and 'w' behave identically
                // for every reachable input. The mode defends against a
                // local attacker who can watch the directory and create
                // the file between the name being drawn and the file
                // being created — a race no test can reproduce
                // deterministically.
                //
                // The *finding* — a predictable path — is covered: the
                // mutant that restores the predictable name is killed.
                'unobservable: the name is random, so nothing can precede the file',
            ),
            'the temporary file is named predictably again' => array(
                "    \$tmp = \$location['dir'] . '/.agentshell-custom-' . bin2hex( random_bytes( 16 ) ) . '.tmp';",
                "    \$tmp = \$location['dir'] . '/.agentshell-custom-' . \$hash . '.tmp';",
            ),
        ),
    ),

    // ── F5: the doctor asks about the committed stylesheet ───────────────
    array(
        'label'  => 'F5: the doctor asks about the committed stylesheet',
        'target'  => 'agentshell-mcp/includes/class-doctor.php',
        'suite'   => 'tests/custom-css-asset-test.php',
        'mutants' => array(
            'the doctor asks about the staged stylesheet again' => array(
                "            \$committed = get_option( Store::CONFIG_KEY, array() );\n            \$committed = is_array( \$committed ) ? \$committed : array();\n            if ( ! empty( \$committed['custom_css'] ) ) {\n                \$state = \\AgentShell_MCP\\Custom_CSS_Asset::state( \$committed );",
                "            \$committed = \$config;\n            if ( ! empty( \$committed['custom_css'] ) ) {\n                \$state = \\AgentShell_MCP\\Custom_CSS_Asset::state( \$committed );",
            ),
        ),
    ),

    // ── F4: the deployment is a build, and the build is checked ──────────
    array(
        'label'  => 'F4: the release archive carries nothing it must not',
        'target'  => 'tools/release-exclusions.sh',
        'suite'   => 'tests/release-archive-test.php',
        'mutants' => array(
            'the test suite is no longer excluded' => array(
                "    \"tests\"                # the test suite and every fixture it carries",
                "    \"tests-disabled\"      # the test suite and every fixture it carries",
            ),
            'a depth category is no longer excluded' => array(
                "    \"*.zip\"",
                "    \"*.zip-disabled\"",
            ),
        ),
    ),

    // ── F4: the packager refuses a credential-looking file ───────────────
    array(
        'label'  => 'F4: the packager refuses a file that looks like a credential',
        'target'  => 'tools/package-release.sh',
        'suite'   => 'tests/release-archive-test.php',
        'mutants' => array(
            'a credential-looking file is skipped rather than refused' => array(
                "            say \"REFUSED: the archive would contain \$relative\"\n            LEAKS=1",
                "            :",
            ),
        ),
    ),
);

$sandbox     = agentshell_mutation_sandbox_create();
$repo_before = agentshell_repo_snapshot();

$killed   = 0;
$total    = 0;
$survived = array();
$declared_survivors = array();

foreach ( $cases as $case ) {
    echo "\n=== {$case['label']} ===\n";

    $target_rel = $case['target'];
    $target     = $sandbox . '/' . $target_rel;
    $suite      = $sandbox . '/' . $case['suite'];

    if ( ! is_file( $target ) ) {
        echo "SKIPPED — no such file in the sandbox: $target_rel\n";
        continue;
    }
    if ( ! is_file( $suite ) ) {
        echo "SKIPPED — no such suite in the sandbox: {$case['suite']}\n";
        continue;
    }

    $original = file_get_contents( $target );

    foreach ( $case['mutants'] as $label => $pair ) {
        $total++;

        list( $from, $to ) = $pair;
        $declared = isset( $pair[2] ) ? (string) $pair[2] : '';
        $occurrences = substr_count( $original, $from );
        if ( 1 !== $occurrences ) {
            echo "SKIPPED $label — the pattern occurs $occurrences times, not once\n";
            $survived[] = $label . ' (the mutant could not be applied unambiguously)';
            $total--;
            continue;
        }

        $mutated = str_replace( $from, $to, $original );

        $lint = agentshell_mutation_lint( $mutated );
        if ( 0 !== $lint['rc'] ) {
            echo "SURVIVED $label — the mutant does not parse:\n";
            echo '  ' . str_replace( "\n", "\n  ", $lint['output'] ) . "\n";
            $survived[] = $label . ' (does not parse)';
            continue;
        }

        // The only write in the whole harness, and it is inside the sandbox.
        file_put_contents( $target, $mutated );
        $result = agentshell_mutation_run_suite( $suite );
        file_put_contents( $target, $original );

        if ( '' !== $declared ) {
            // The mutant was declared: its survival is the expected
            // outcome, and the argument is printed so a reader can
            // disagree with it. Dying instead would mean the suite is
            // stronger than the argument claimed, which is not a failure.
            if ( $result['failed'] > 0 ) {
                $killed++;
                echo "killed $label ({$result['failed']} failing assertions) — stronger than the declaration claimed\n";
            } else {
                echo "survived as declared: $label\n";
                echo "  $declared\n";
                $declared_survivors[] = $label;
            }
            continue;
        }

        if ( $result['failed'] > 0 ) {
            $killed++;
            echo "killed $label ({$result['failed']} failing assertions)\n";
        } else {
            echo "SURVIVED $label — the suite did not notice\n";
            $survived[] = $label;
        }
    }
}

agentshell_mutation_sandbox_destroy( $sandbox );

echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $declared_survivors ) {
    echo "\nDeclared survivors (equivalent or unobservable by construction):\n";
    foreach ( $declared_survivors as $label ) {
        echo "  - $label\n";
    }
    echo "These are not coverage gaps. Each carries the argument for why it\n";
    echo "cannot be observed, and the argument is printed above.\n";
}

if ( $survived ) {
    echo "\nUndeclared survivors — these are coverage gaps:\n";
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
