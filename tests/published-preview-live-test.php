<?php
/**
 * Parent test for the published-preview capture regression
 * (HAL 9001 remediation, defect D7).
 *
 * Spawns the live child against the disposable test bed and asserts the
 * structured results. Skips cleanly when no test bed is configured.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live published-preview capture (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    echo "  SKIPPED: no test-bed config (destructive suites require tests/config/test-bed.json)\n";
    Agentshell_Test::run(
        'live published-preview capture',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/published-preview-live-child.php';
$report_file = sys_get_temp_dir() . '/as-pp-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    $child,
    array( $config['wp_root'], $report_file )
);

echo $run['output'];

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );
if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live published-preview capture: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['pp_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['pp_f'] = $f;

function pp_f( $key, $default = null ) {
    $f = $GLOBALS['pp_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live published-preview capture',
    function () {
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v1_refused' ),
            'V1.1 published_preview without a post id must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v1_mentions_post_id' ),
            'V1.2 the refusal must name the missing argument'
        );

        Agentshell_Test::assert_true(
            (bool) pp_f( 'v2_refused' ),
            'V2.1 published_preview on an unpublished page must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v2_mentions_published' ),
            'V2.2 the refusal must say the page is not published'
        );

        Agentshell_Test::assert_true(
            (bool) pp_f( 'v3_refused' ),
            'V3.1 published_preview with no open transaction must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v3_mentions_transaction' ),
            'V3.2 the refusal must say a transaction is needed'
        );

        Agentshell_Test::assert_true(
            (bool) pp_f( 'v4_staged' ),
            'V4.0 the edit must have been staged'
        );
        Agentshell_Test::assert_equals(
            '',
            (string) pp_f( 'v4_error', '' ),
            'V4.1 the capture must not fail: ' . (string) pp_f( 'v4_error', '' )
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v4_captured' ),
            'V4.2 the capture must succeed'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v4_names_edit' ),
            'V4.3 the response must name the edit it previewed'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v4_note' ),
            'V4.4 the response must explain what was previewed'
        );

        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_image_captured' ),
            'V5.1 the capture must have produced an image'
        );

        Agentshell_Test::assert_true(
            (bool) pp_f( 'v6_published_unchanged' ),
            'V6.1 the published body must be unchanged after the preview'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v6_title_unchanged' ),
            'V6.2 the published title must be unchanged after the preview'
        );
    }
);
