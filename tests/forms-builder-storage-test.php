<?php
/**
 * The builder's optimistic-concurrency guarantees against a real WordPress.
 *
 * The builder's JavaScript can only be as correct as the storage layer it
 * talks to. This suite pins the storage half: a discard carries the revision
 * the editor read and is refused when somebody else has moved it; a current
 * discard reports the revision it produced; a rename is guarded the same way;
 * and a duplicate is a distinct, unpublished form.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms builder storage (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms builder storage suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-builder-storage-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-builder-storage-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms builder storage: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
    Agentshell_Test::run(
        'forms builder storage: the child reached its subjects',
        function () use ( $p ) {
            Agentshell_Test::assert_true( false, $p['fatal']['where'] . ' — ' . $p['fatal']['code'] . ': ' . $p['fatal']['message'] );
        }
    );
    Agentshell_Test::summary();
    return;
}

/**
 * One probe's field.
 *
 * @param array  $probes Probes.
 * @param string $probe  Probe.
 * @param string $key    Key.
 * @return mixed
 */
function asb4r( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report \"{$probe}\"" );
    }
    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }
    return $probes[ $probe ][ $key ];
}

Agentshell_Test::run(
    'a stale discard is refused and does not delete the newer work',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asb4r( $p, 'stale_discard', 'refused' ), 'the stale discard must be refused' );
        Agentshell_Test::assert_equals(
            'form_revision_mismatch',
            asb4r( $p, 'stale_discard', 'code' ),
            'the refusal names the conflict'
        );
        Agentshell_Test::assert_true(
            in_array( 'email', (array) asb4r( $p, 'stale_discard', 'kept_fields' ), true ),
            'the other editor\'s saved field survives the refused discard'
        );
    }
);

Agentshell_Test::run(
    'a current discard succeeds and reports the revision it produced',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asb4r( $p, 'discard', 'ok' ), 'the discard must succeed' );
        Agentshell_Test::assert_true(
            (int) asb4r( $p, 'discard', 'revision' ) > (int) asb4r( $p, 'discard', 'was' ),
            'the discard advances the revision'
        );
        Agentshell_Test::assert_equals( 1, (int) asb4r( $p, 'discard', 'fields' ), 'the draft is the published definition again' );
    }
);

Agentshell_Test::run(
    'a rename is guarded by the same revision',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asb4r( $p, 'rename', 'ok' ), 'the current rename succeeds' );
        Agentshell_Test::assert_true( false !== strpos( (string) asb4r( $p, 'rename', 'name' ), 'Renamed' ), 'the definition carries the new name' );
        Agentshell_Test::assert_true( false !== strpos( (string) asb4r( $p, 'rename', 'title' ), 'Renamed' ), 'the post title carries the new name too' );
        Agentshell_Test::assert_true( asb4r( $p, 'rename', 'stale_refused' ), 'a stale rename is refused' );
        Agentshell_Test::assert_equals( 'form_revision_mismatch', asb4r( $p, 'rename', 'stale_code' ), 'the refusal names the conflict' );
    }
);

Agentshell_Test::run(
    'a duplicate is a distinct, unpublished form',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asb4r( $p, 'duplicate', 'ok' ), 'the duplicate succeeds' );
        Agentshell_Test::assert_true( asb4r( $p, 'duplicate', 'different' ), 'the copy has its own identifier' );
        Agentshell_Test::assert_true( asb4r( $p, 'duplicate', 'slug_differs' ), 'the copy has its own slug' );
        Agentshell_Test::assert_equals( 0, (int) asb4r( $p, 'duplicate', 'version' ), 'the copy has never been published' );
        Agentshell_Test::assert_equals( 1, (int) asb4r( $p, 'duplicate', 'field_count' ), 'the copy carries the working definition' );
    }
);

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, (int) asb4r( $p, 'cleanup', 'remaining' ), 'no form may outlive the suite' );
    }
);

Agentshell_Test::summary();
