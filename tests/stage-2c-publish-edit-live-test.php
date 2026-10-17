<?php
/**
 * Stage 2c Milestone 4 — live acceptance suite for the publish-edit
 * tool. Runs against a real WordPress install and exercises the
 * publication path the unit tests cannot: a real wp_update_post
 * against a real wp_posts row, a real advisory lock, a real
 * per-original claim swap, and a real anonymous HTTP request that
 * walks the actual permalink redirect chain to read the page back.
 *
 * The unit suite (`stage-2c-publish-edit-test.php`) covers the
 * value-guarded CAS and the state machine with a recording wpdb.
 * This suite covers everything that depends on the real database
 * and the real request lifecycle — the things the unit suite
 * would have agreed with for the wrong reason if they had been
 * wrong.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so
 * it is safe to run anywhere; `tests/wp-live.php` finds the install
 * and picks a PHP that can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live publish-edit (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live publish-edit suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-pe-live-' . getmypid() . '.json';
@unlink( $report_file );

// The child boots a real WordPress (wp-load.php) and exercises
// Publish_Edit / Publish_Edit_Reconcile against a real database.
// The parent then asserts the structured results.
$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/stage-2c-publish-edit-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = (string) @file_get_contents( $report_file );
$report = json_decode( $raw, true );
@unlink( $report_file );

if ( ! is_array( $report ) || ! isset( $report['results'] ) ) {
    Agentshell_Test::run(
        'live publish-edit child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['pe_report'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['pe_report'] = $report;

Agentshell_Test::run(
    'live publish-edit: T1 successful publication of all four fields',
    function () use ( $report ) {
        $r = $report['results'];
        // T1.1 and T1.8 carry the state string inside a "state=..."
        // detail, not as a bare value; substring match is the right
        // shape.
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[0]['detail'] ?? '' ), 'publication_settled' ), 'T1.1 result state' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[1]['detail'] ?? '' ), 'Staged Title T1' ), 'T1.2 post_title landed' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[2]['detail'] ?? '' ), 'Staged content T1' ), 'T1.3 post_content landed' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[3]['detail'] ?? '' ), 'Staged excerpt T1' ), 'T1.4 post_excerpt landed' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[4]['detail'] ?? '' ), 'staged-slug-t1' ), 'T1.5 post_name landed' );
        Agentshell_Test::assert_true( (bool) ( $r[5]['ok'] ?? false ), 'T1.6 shadow is gone' );
        Agentshell_Test::assert_true( (bool) ( $r[6]['ok'] ?? false ), 'T1.7 claim is released' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[7]['detail'] ?? '' ), 'publication_settled' ), 'T1.8 op is settled on disk' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T2 protected fields preserved',
    function () use ( $report ) {
        $r = $report['results'];
        // T1 covers indices 0-7, T2 covers 8-12.
        Agentshell_Test::assert_true( (bool) ( $r[8]['ok']  ?? false ), 'T2.1 post_type preserved' );
        Agentshell_Test::assert_true( (bool) ( $r[9]['ok']  ?? false ), 'T2.2 post_status preserved' );
        Agentshell_Test::assert_true( (bool) ( $r[10]['ok'] ?? false ), 'T2.3 post_author preserved' );
        Agentshell_Test::assert_true( (bool) ( $r[11]['ok'] ?? false ), 'T2.4 post_parent preserved' );
        Agentshell_Test::assert_true( (bool) ( $r[12]['ok'] ?? false ), 'T2.5 comment_status preserved' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T3 baseline-A drift refusal',
    function () use ( $report ) {
        $r = $report['results'];
        // T3 covers indices 13-17.
        Agentshell_Test::assert_true( (bool) ( $r[13]['ok'] ?? false ), 'T3.1 baseline_drift throw' );
        Agentshell_Test::assert_true( (bool) ( $r[14]['ok'] ?? false ), 'T3.2 refusal names baseline_drift' );
        Agentshell_Test::assert_true( (bool) ( $r[15]['ok'] ?? false ), 'T3.3 original unchanged after refused publish' );
        Agentshell_Test::assert_true( false !== strpos( (string) ( $r[16]['detail'] ?? '' ), 'conflict' ), 'T3.4 op is in conflict state on disk' );
        Agentshell_Test::assert_true( (bool) ( $r[17]['ok'] ?? false ), 'T3.5 raw option_row state matches expected' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T4 actor mismatch refusal',
    function () use ( $report ) {
        $r = $report['results'];
        // T4 covers 18-20.
        Agentshell_Test::assert_true( (bool) ( $r[18]['ok'] ?? false ), 'T4.1 actor mismatch throws' );
        Agentshell_Test::assert_true( (bool) ( $r[19]['ok'] ?? false ), 'T4.2 refusal names actor_mismatch' );
        Agentshell_Test::assert_true( (bool) ( $r[20]['ok'] ?? false ), 'T4.3 original unchanged after refused publish' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T5 shadow head-advanced refusal',
    function () use ( $report ) {
        $r = $report['results'];
        // T5 covers 21-23.
        Agentshell_Test::assert_true( (bool) ( $r[21]['ok'] ?? false ), 'T5.1 head_advanced throws' );
        Agentshell_Test::assert_true( (bool) ( $r[22]['ok'] ?? false ), 'T5.2 refusal names head_advanced' );
        Agentshell_Test::assert_true( (bool) ( $r[23]['ok'] ?? false ), 'T5.3 original unchanged after refused publish' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T6 claim held by another tx refusal',
    function () use ( $report ) {
        $r = $report['results'];
        // T6 covers 24-27.
        Agentshell_Test::assert_true( (bool) ( $r[24]['ok'] ?? false ), 'T6.1 claim_held_by_other throws' );
        Agentshell_Test::assert_true( (bool) ( $r[25]['ok'] ?? false ), 'T6.2 refusal names claim_held_by_other' );
        Agentshell_Test::assert_true( (bool) ( $r[26]['ok'] ?? false ), 'T6.3 original unchanged after refused publish' );
        Agentshell_Test::assert_true( (bool) ( $r[27]['ok'] ?? false ), 'T6.4 other-actor claim still on the original' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T7 reconcile on a settled op is a refusal',
    function () use ( $report ) {
        $r = $report['results'];
        // T7 covers 28-29.
        Agentshell_Test::assert_true( (bool) ( $r[28]['ok'] ?? false ), 'T7.1 reconcile on a settled op throws op_state_unexpected' );
        Agentshell_Test::assert_true( (bool) ( $r[29]['ok'] ?? false ), 'T7.2 refusal names publication_settled as the offending state' );
    }
);

Agentshell_Test::run(
    'live publish-edit: T8 anonymous HTTP request reads the staged page',
    function () use ( $report ) {
        $r = $report['results'];
        // T8 covers 30-32.
        Agentshell_Test::assert_true( (bool) ( $r[30]['ok'] ?? false ), 'T8.1 anonymous request returns 200' );
        Agentshell_Test::assert_true( (bool) ( $r[31]['ok'] ?? false ), 'T8.2 body contains the staged title' );
        Agentshell_Test::assert_true( (bool) ( $r[32]['ok'] ?? false ), 'T8.3 body contains the staged content' );
    }
);
