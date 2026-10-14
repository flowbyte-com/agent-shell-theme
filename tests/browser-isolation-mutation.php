<?php
/**
 * Mutation test for browser profile isolation.
 *
 * The claim under test is that a capture cannot reach a persistent browser
 * profile. The strongest way to check that claim is to remove the mechanism and
 * confirm the suite says so, rather than trusting that the assertions happen to
 * touch the code that matters.
 *
 * Each mutant removes or inverts exactly one mechanism, the suite is run, and
 * the run is judged **killed** if any assertion failed. Killed does not mean
 * "the mutation produced an error" — it means the suite noticed.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the system
 * temp directory, and the suites run from that copy. This file used to write
 * them into `agentshell-mcp/includes/class-screenshot.php` itself and restore
 * afterwards, which meant a concurrent test run could read mutated production
 * code and a killed harness could leave the checkout modified. Both happened.
 * Nothing here opens the working tree for writing; the fingerprint at the end
 * asserts it rather than assuming it.
 *
 * Note on the kill condition: standalone `*-test.php` files always exit 0; only
 * run-all.php sets an exit status. Judging on exit code silently reports every
 * mutant as surviving, so this keys on FAIL lines in the output.
 *
 * Usage:
 *   php tests/browser-isolation-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

/** Paths are relative to the repository root, and identical in the sandbox. */
$CLASS_REL = 'agentshell-mcp/includes/class-screenshot.php';
$SUITE_REL = 'tests/browser-isolation-test.php';
$TRUST_REL = 'tests/browser-trust-test.php';

$original = file_get_contents( agentshell_repo_root() . '/' . $CLASS_REL );

/**
 * Mutants, each the minimal edit that removes one guarantee.
 *
 * find/replace pairs, applied to a copy of the real source. The last entry is
 * the one the brief asks for by name.
 */
$mutants = array(
    'no --user-data-dir (the mutation the brief names)' => array(
        "            '--user-data-dir=' . \$profile,\n",
        '',
    ),
    'profile not private (0755)' => array(
        '@chmod( $path, 0700 );',
        '@chmod( $path, 0755 );',
    ),
    'predictable profile name' => array(
        "\$name = 'profile-' . bin2hex( random_bytes( (int) ( self::PROFILE_NAME_HEX / 2 ) ) );",
        "\$name = 'profile-' . getmypid();",
    ),
    'cleanup skipped entirely' => array(
        "        self::destroy_profile_dir( \$profile );",
        '        // mutant: no cleanup',
    ),
    'cleanup outside the lock' => array(
        "                \$result = self::run_browser( \$backend['command'], \$url, \$width, \$height, \$file, \$profile );\n            } finally {\n                self::destroy_profile_dir( \$profile );\n            }",
        "                \$result = self::run_browser( \$backend['command'], \$url, \$width, \$height, \$file, \$profile );\n            } finally {\n                // mutant: cleanup removed\n            }",
    ),
    'rm_tree follows symlinks' => array(
        "        if ( is_link( \$path ) ) {\n            return @unlink( \$path );\n        }",
        '',
    ),
    'stale sweep removed' => array(
        '        self::purge_stale_profiles( $base );',
        '        // mutant: no sweep',
    ),
    'stale sweep drops the marker check' => array(
        "            if ( self::PROFILE_MARKER_VALUE !== \$marker ) {\n                continue;\n            }",
        '',
    ),
    'stale sweep drops the age check' => array(
        'if ( ! is_int( $mtime ) || ( $now - $mtime ) < self::PROFILE_STALE_SECONDS ) {',
        'if ( false ) {',
    ),
    'profile base not per-uid' => array(
        "        return rtrim( sys_get_temp_dir(), '/' ) . '/agentshell-chrome-' . ( null === \$uid ? 'na' : \$uid );",
        "        return rtrim( sys_get_temp_dir(), '/' ) . '/agentshell-chrome-na';",
    ),
    'HOME not redirected' => array(
        "        \$env['HOME']             = self::trust_home_dir( \$profile );",
        '',
    ),
    'path check accepts any name' => array(
        "        if ( ! preg_match( '/\\/profile-[0-9a-f]{16,32}\$/', \$path ) ) {\n            return;\n        }",
        '',
    ),
    'cleanup trusts the name pattern alone' => array(
        "        if ( \$path === \$base || ! str_starts_with( \$path, \$base . '/' ) ) {\n            return;\n        }",
        '',
    ),
    'marker not written into the profile' => array(
        '        if ( @file_put_contents( $path . \'/\' . self::PROFILE_MARKER, self::PROFILE_MARKER_VALUE ) === false ) {',
        '        if ( false ) {',
    ),
);

/**
 * Mutants for the trust store and the browser temp directory.
 *
 * Separate from the isolation set because they are judged by a different
 * suite. Real Chrome is skipped here on purpose: the two mutants below are
 * each detected by the fake-browser cases, and standing up real TLS per mutant
 * would turn a fast check into a slow one.
 */
$trust_mutants = array(
    'TMPDIR not redirected into the profile' => array(
        "\$env['TMPDIR']           = self::profile_tmp_dir( \$profile );",
        '',
    ),
    'trust store never installed' => array(
        '            self::install_trust_store( $path );',
        '            // mutant: no trust store',
    ),
    'trust store built somewhere Chromium will not look' => array(
        "        return self::trust_home_dir( \$profile ) . '/.pki/nssdb';",
        "        return self::trust_home_dir( \$profile ) . '/nssdb';",
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
 * @param string $sandbox_class Path of the mutable file inside the sandbox.
 * @param string $original      Pristine source, restored after each mutant.
 * @param string $suite_rel     Suite to judge with, relative to the sandbox root.
 * @param array  $env           Environment for the judging suite.
 */
function agentshell_judge_mutant( array $mutation, $label, $sandbox, $sandbox_class, $original, $suite_rel, array $env ) {
    global $killed, $survived;

    list( $find, $replace ) = $mutation;

    if ( false === strpos( $original, $find ) ) {
        // A mutant that cannot be applied is not evidence about the suite.
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
    file_put_contents( $sandbox_class, $mutated );

    $result = agentshell_mutation_run_suite( $sandbox . '/' . $suite_rel, $env );

    // Put the pristine copy back so the next mutant starts from the original.
    file_put_contents( $sandbox_class, $original );

    if ( $result['failed'] > 0 ) {
        $killed++;
        echo "killed $label ({$result['failed']} failing assertions)\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_class = $sandbox . '/' . $CLASS_REL;
if ( ! is_file( $sandbox_class ) ) {
    echo "SANDBOX FAILED — {$CLASS_REL} is missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

foreach ( $mutants as $label => $mutation ) {
    agentshell_judge_mutant( $mutation, $label, $sandbox, $sandbox_class, $original, $SUITE_REL, array() );
}

// ── trust and temp mechanisms, judged by the trust suite ────────────────────
echo "\n";
$trust_env = array( 'AGENTSHELL_TRUST_SKIP_CHROME' => '1' );

$trust_baseline = agentshell_mutation_run_suite( $sandbox . '/' . $TRUST_REL, $trust_env );
if ( $trust_baseline['failed'] > 0 ) {
    echo "TRUST BASELINE FAILED — the suite is red before any mutation.\n";
    echo $trust_baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
echo "trust baseline: 0 failures\n\n";

foreach ( $trust_mutants as $label => $mutation ) {
    agentshell_judge_mutant( $mutation, $label, $sandbox, $sandbox_class, $original, $TRUST_REL, $trust_env );
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $mutants ) + count( $trust_mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// The whole point of the sandbox: prove it, do not assume it.
$diff = agentshell_repo_diff( $repo_before, array( agentshell_repo_root() . '/' . $CLASS_REL ) );
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