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
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)\\n' );
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

        // V5.2-V5.5: the capture must show the STAGED body, and the
        // credential must not be in the rendered page.
        //
        // "An image exists" was the whole of V5 for a long time, and it
        // was true in every run of a build whose credential was
        // corrupted in transport: the overlay refused it, the capture
        // rendered the PUBLISHED page, and the tool reported success.
        // These are the assertions that would have caught it.
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_published_image_captured' ),
            'V5.2 the published page must be capturable, so the two captures can be compared'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_preview_image_captured' ),
            'V5.3 the preview capture must exist'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_images_differ' ),
            'V5.4 the preview capture must DIFFER from the published capture — identical images mean the credential was refused and the published page was captured'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_staged_body_rendered' ),
            'V5.5 the preview must render the STAGED body, not the published one'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_published_body_absent' ),
            'V5.6 the preview must not render the PUBLISHED body'
        );
        Agentshell_Test::assert_false(
            (bool) pp_f( 'v5_credential_in_body' ),
            'V5.7 the credential must not appear in the rendered page'
        );
        Agentshell_Test::assert_false(
            (bool) pp_f( 'v5_cred_arg_in_body' ),
            'V5.8 neither the credential nor its argument name may appear in the rendered page'
        );
        Agentshell_Test::assert_true(
            (bool) pp_f( 'v5_construction_preserves_credential' ),
            'V5.9 the tool\'s URL construction must preserve the credential for every post id, not only the one this fixture got (broken: ' . (string) pp_f( 'v5_construction_broken_ids', '' ) . ')'
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
