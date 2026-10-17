<?php
/**
 * Stage 2c M5-s2 — leak-check coverage test. Verifies that the
 * end-of-suite leak check (`tests/agentshell-leak-check-child.php`)
 * reports synthetic `agentshell_published_claim_*` rows and
 * synthetic `_agentshell_shadow_*` postmeta rows in its new
 * `claim_rows_with_fixture_prefix` and `shadow_meta_with_marker`
 * fields. The M5-s2 plan: "synthetic rows are reported".
 *
 * The test inserts two synthetic rows against the test bed, runs
 * the leak check child via the standard `agentshell_wp_live_run`
 * plumbing, asserts both new fields are > 0 and the synthetic
 * sample values are present, then cleans up.
 *
 * Like the other destructive live suites, this skips cleanly when
 * the test-bed config is missing or the fail-closed check fails.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live stage-2c-leak-check (M5-s2 self-test)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live stage-2c-leak-check: no test-bed config',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

// The self-test child is a small script that inserts two
// synthetic rows, runs the leak check child, and reports the
// JSON. The parent here asserts the report contains the new
// fields with the expected values.
$report_file = sys_get_temp_dir() . '/as-leak-selftest-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/stage-2c-leak-check-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = (string) @file_get_contents( $report_file );
$report = json_decode( $raw, true );
@unlink( $report_file );

Agentshell_Test::run(
    'live stage-2c-leak-check: child produced a JSON report',
    function () use ( $report, $run ) {
        Agentshell_Test::assert_true(
            is_array( $report ),
            'child did not report: ' . substr( (string) $run['output'], 0, 400 )
        );
    }
);

if ( ! is_array( $report ) ) {
    return;
}

// The child wraps the leak-check result under a 'leak' key.
$leak = is_array( $report['leak'] ?? null ) ? $report['leak'] : array();

Agentshell_Test::run(
    'live stage-2c-leak-check: synthetic claim row is reported',
    function () use ( $leak ) {
        $n = (int) ( $leak['claim_rows_with_fixture_prefix'] ?? -1 );
        Agentshell_Test::assert_true( $n >= 1, 'expected claim_rows_with_fixture_prefix >= 1, got ' . $n );
    }
);

Agentshell_Test::run(
    'live stage-2c-leak-check: synthetic claim sample names are reported',
    function () use ( $leak ) {
        $samples = (array) ( $leak['sample_claim_option_names'] ?? array() );
        $found = false;
        foreach ( $samples as $name ) {
            if ( 0 === strpos( (string) $name, 'agentshell_published_claim_astb-selftest-' ) ) {
                $found = true;
                break;
            }
        }
        Agentshell_Test::assert_true( $found, 'no ASTB self-test claim name in samples: ' . implode( ',', $samples ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-leak-check: synthetic shadow meta row is reported',
    function () use ( $leak ) {
        $n = (int) ( $leak['shadow_meta_with_marker'] ?? -1 );
        Agentshell_Test::assert_true( $n >= 1, 'expected shadow_meta_with_marker >= 1, got ' . $n );
    }
);

Agentshell_Test::run(
    'live stage-2c-leak-check: synthetic shadow meta sample rows are reported',
    function () use ( $leak ) {
        $rows = (array) ( $leak['sample_shadow_meta_rows'] ?? array() );
        $found = false;
        foreach ( $rows as $r ) {
            if ( '_agentshell_shadow_for' === ( $r['meta_key'] ?? '' )
                && 0 === strpos( (string) ( $r['meta_value'] ?? '' ), 'astb-selftest-' ) ) {
                $found = true;
                break;
            }
        }
        Agentshell_Test::assert_true( $found, 'no ASTB self-test shadow meta in samples: ' . json_encode( $rows ) );
    }
);
