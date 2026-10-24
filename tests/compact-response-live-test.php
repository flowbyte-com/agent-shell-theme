<?php
/**
 * Live compact-response regressions, run against a real WordPress install.
 *
 * The point of the compact form is measured here, not asserted by eye: a
 * response that carries the body is orders of magnitude larger than the
 * same response without it, and the difference is what an agent's context
 * window pays for.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is
 * safe to run anywhere.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live compact responses (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live compact-response suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-cr-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/compact-response-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live compact-response child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['cr_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['cr_f'] = $f;

function cr_f( $key, $default = null ) {
    $f = $GLOBALS['cr_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'the default response still carries the body',
    function () {
        // The existing contract is unchanged: a caller that reads
        // content.raw keeps working without passing anything new.
        Agentshell_Test::assert_true( (bool) cr_f( 'default_has_raw' ), 'the default response must still carry the body' );
        Agentshell_Test::assert_true(
            (int) cr_f( 'default_raw_length' ) > 10000,
            'the body must be the one that was written: ' . (int) cr_f( 'default_raw_length' )
        );
    }
);

Agentshell_Test::run(
    'include_content=false returns the length and not the body',
    function () {
        Agentshell_Test::assert_false( (bool) cr_f( 'compact_has_raw' ), 'the compact response must not carry the body' );
        Agentshell_Test::assert_true( (bool) cr_f( 'compact_omitted' ), 'the compact response must say the body was omitted' );
        Agentshell_Test::assert_true(
            (int) cr_f( 'compact_length' ) > 10000,
            'the compact response must still report the length: ' . (int) cr_f( 'compact_length' )
        );
    }
);

Agentshell_Test::run(
    'the compact response is actually smaller',
    function () {
        $full    = (int) cr_f( 'default_response_bytes' );
        $compact = (int) cr_f( 'compact_response_bytes' );
        Agentshell_Test::assert_true(
            $compact < $full / 10,
            "the compact response must be far smaller: $compact bytes against $full"
        );
    }
);

Agentshell_Test::run(
    'the update path honours it too',
    function () {
        Agentshell_Test::assert_false( (bool) cr_f( 'update_has_raw' ), 'the compact update response must not carry the body' );
        Agentshell_Test::assert_true( (bool) cr_f( 'update_omitted' ) );
    }
);

Agentshell_Test::run(
    'the full body is still reachable on request',
    function () {
        Agentshell_Test::assert_true( (bool) cr_f( 'read_has_raw' ), 'the read must still return the body by default' );
        Agentshell_Test::assert_true(
            (int) cr_f( 'read_raw_length' ) > 10000,
            'the body must be the one that was written'
        );
        Agentshell_Test::assert_false( (bool) cr_f( 'read_compact_has_raw' ), 'the compact read must not carry the body' );
        Agentshell_Test::assert_true(
            (int) cr_f( 'read_compact_length' ) > 10000,
            'the compact read must report the length'
        );
    }
);

Agentshell_Test::run(
    'the suite cleans up after itself',
    function () {
        Agentshell_Test::assert_true( (bool) cr_f( 'cleanup_done' ) );
    }
);
