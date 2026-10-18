<?php
/**
 * Live regression for the create_draft CAS refusal properties
 * preserved from the integration worktree's
 * `create-draft-cas-persistent-live-{child,test}.php`.
 *
 * The development version of that suite replaced the integration
 * worktree's F7 (corrupted journal) and F8 (stuck intended op)
 * scenarios with a 5-create stress test (F7) and an idempotency-
 * resume test (F8). The development version's own docblock notes
 * the trade-off: "The fail-closed property is left for a separate
 * pass." This file is that separate pass.
 *
 * What is asserted here
 * ---------------------
 * Three scenarios, each through a real WordPress install with the
 * journal and the option cache. A stubbed assertion cannot prove
 * `Tx::begin()` would refuse on durable work; only a live option
 * can show the refusal path is reachable.
 *
 *   F0. The tx option row is empty at child start (clean ledger).
 *   F1. Tampered-signature journal — the existing row has a
 *       well-formed structure but a wrong `sig`. `Tx::begin()`
 *       refuses on durable work; the new create is never written;
 *       the row is dropped by the child's try/finally block.
 *   F2. Stuck intended op — the existing row has a valid
 *       signature and a stuck `intended` op. `Tx::begin()`
 *       refuses on durable work; the new create is never written;
 *       the stuck op is preserved (state still `intended`); the
 *       row is dropped at end.
 *   F3. No leaked state — the tx option row is gone, no tracked
 *       posts survive, and the audit log has no spurious
 *       compensation rows from this run.
 *
 * Boundaries
 * ----------
 *   - Each refusal scenario runs inside a try/finally so the row
 *     is dropped even on a partial failure.
 *   - The destructive child identity guard (b8a12a8) authorises
 *     this work for the disposable test bed only.
 *   - The per-run preflight in `run-all.php` restores the test
 *     site from its verified baseline if a prior crashed run left
 *     a tampered or stuck row behind.
 *   - An uncaught PHP exception, missing extension, bootstrap
 *     crash or arbitrary process failure is NOT counted as
 *     successful fail-closed behaviour. The test records the
 *     throw class and message so a non-structured refusal
 *     (e.g. a fatal) is visible in the report.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live create-draft CAS refusal regression (separate from create-draft-cas-persistent-live)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    echo "  SKIPPED: no test-bed config (destructive suites require tests/config/test-bed.json)\n";
    Agentshell_Test::run(
        'live create-draft CAS refusal',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/create-draft-cas-refusal-live-child.php';
$report_file = sys_get_temp_dir() . '/as-cdr-live-' . getmypid() . '.json';
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
        'live create-draft CAS refusal: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['cdr_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['cdr_f'] = $f;

function cdr_f( $key, $default = null ) {
    $f = $GLOBALS['cdr_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live create-draft CAS refusal',
    function () {
        // ── F0: precondition — clean ledger ───────────────────────────
        Agentshell_Test::assert_true(
            (bool) cdr_f( 'f0_clean' ),
            'F0: tx option row must be empty at child start'
        );

        // ── F1: tampered-signature journal ───────────────────────────
        // The structured refusal must be a named exception, not a fatal
        // or a silent success. We accept any subclass of \Throwable
        // because the contract is "the create was refused with a
        // structured failure", not a specific class.
        Agentshell_Test::assert_equals(
            'refused',
            (string) cdr_f( 'f1_outcome' ),
            'F1: a tampered-signature journal must refuse the create; got: '
                . wp_json_encode( array(
                    'outcome'    => cdr_f( 'f1_outcome' ),
                    'message'    => cdr_f( 'f1_message' ),
                    'throw_class'=> cdr_f( 'f1_throw_class' ),
                    'post_id'    => cdr_f( 'f1_post_id' ),
                    'post_exists'=> cdr_f( 'f1_post_exists' ),
                ) )
        );
        Agentshell_Test::assert_equals(
            0,
            (int) cdr_f( 'f1_post_id' ),
            'F1: a refused create must not produce a post id; got: '
                . wp_json_encode( cdr_f( 'f1_post_id' ) )
        );
        Agentshell_Test::assert_false(
            (bool) cdr_f( 'f1_post_exists' ),
            'F1: a refused create must not write a post to the database'
        );
        Agentshell_Test::assert_true(
            (bool) cdr_f( 'f1_row_post_dropped' ),
            'F1: the tampered row must be dropped by the try/finally block'
        );

        // ── F2: stuck intended op ────────────────────────────────────
        Agentshell_Test::assert_equals(
            'refused',
            (string) cdr_f( 'f2_outcome' ),
            'F2: a tx with a stuck intended op must refuse the new create; got: '
                . wp_json_encode( array(
                    'outcome'    => cdr_f( 'f2_outcome' ),
                    'message'    => cdr_f( 'f2_message' ),
                    'throw_class'=> cdr_f( 'f2_throw_class' ),
                    'post_id'    => cdr_f( 'f2_post_id' ),
                ) )
        );
        Agentshell_Test::assert_equals(
            0,
            (int) cdr_f( 'f2_post_id' ),
            'F2: a refused create must not produce a post id; got: '
                . wp_json_encode( cdr_f( 'f2_post_id' ) )
        );
        Agentshell_Test::assert_equals(
            'intended',
            (string) cdr_f( 'f2_stuck_op_after' ),
            'F2: the stuck intended op must be preserved (not erased, not advanced); got: '
                . wp_json_encode( cdr_f( 'f2_stuck_op_after' ) )
        );

        // ── F3: no leaked state ──────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) cdr_f( 'f3_tx_row_absent' ),
            'F3: tx option row must be gone at end of suite'
        );
        Agentshell_Test::assert_false(
            (bool) cdr_f( 'f3_orphan_posts' ),
            'F3: every tracked post must have been deleted by teardown; got: '
                . wp_json_encode( array(
                    'tracked'   => cdr_f( 'f3_tracked_post_count' ),
                    'existing'  => cdr_f( 'f3_existing_post_count' ),
                ) )
        );
        Agentshell_Test::assert_equals(
            0,
            (int) cdr_f( 'f3_bad_audit' ),
            'F3: audit log must not record spurious compensation rows from this run'
        );
    }
);
