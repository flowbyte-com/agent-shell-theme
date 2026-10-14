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
 * Note on the kill condition: standalone `*-test.php` files always exit 0; only
 * run-all.php sets an exit status. Judging on exit code silently reports every
 * mutant as surviving, so this keys on FAIL lines in the output.
 *
 * Usage:
 *   php tests/browser-isolation-mutation.php
 */

$class_file  = dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';
$suite_file  = __DIR__ . '/browser-isolation-test.php';
$original    = file_get_contents( $class_file );

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
        "\$name = 'profile-' . bin2hex( random_bytes( 16 ) );",
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
        "        \$env['HOME']             = \$profile . '/home';",
        '',
    ),
    'path check accepts any name' => array(
        "        if ( ! preg_match( '/\\/profile-[0-9a-f]{32}\$/', \$path ) ) {\n            return;\n        }",
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
 * Run the isolation suite and count its failures.
 *
 * FAIL lines are indented by the reporter, so they are matched with a regex
 * anchored at line start rather than a substring search — a plain "\nFAIL "
 * count silently reads zero and reports every mutant as surviving.
 */
function agentshell_isolation_run( $suite_file ) {
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $suite_file ) . ' 2>&1';
    $output = (string) shell_exec( $cmd );
    return array( 'output' => $output, 'failed' => preg_match_all( '/^\s*FAIL /m', $output ) );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_isolation_run( $suite_file );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

$killed  = 0;
$survived = array();

foreach ( $mutants as $label => $mutation ) {
    list( $find, $replace ) = $mutation;

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

    // Sanity: the mutant must still be valid PHP, or a parse error would be
    // counted as "killed" for the wrong reason.
    //
    // tempnam() already creates the file it returns, so appending '.php' names
    // a *second* path. Both have to be removed, or every mutant leaves an
    // orphaned file in the temp dir.
    $tmp = tempnam( sys_get_temp_dir(), 'as-mutant-' );
    $lint_file = $tmp . '.php';
    file_put_contents( $lint_file, $mutated );
    exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $lint_file ) . ' 2>&1', $lint, $lint_rc );
    @unlink( $lint_file );
    @unlink( $tmp );

    file_put_contents( $class_file, $mutated );
    $result = agentshell_isolation_run( $suite_file );
    file_put_contents( $class_file, $original );

    if ( 0 !== $lint_rc ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . implode( "\n       ", $lint ) . "\n";
        $survived[] = $label . ' (does not parse)';
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

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// Restore unconditionally, so an interrupted run cannot leave the source mutated.
file_put_contents( $class_file, $original );

exit( $survived ? 1 : 0 );