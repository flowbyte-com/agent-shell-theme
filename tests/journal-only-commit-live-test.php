<?php
/**
 * Live regression for the journal-only commit gate (Gate 3 Section D).
 *
 * The bug this pins
 * -----------------
 * `agentshell_commit_transaction` refused journal-only transactions with
 * "Transaction has no staged changes" even when the journal was clean
 * and `validate_transaction` had reported "tip: commit will succeed".
 * The only path through was a dummy `set_css_var`, which (a) wrote a
 * config change the operator did not ask for, (b) erased that change
 * from the diff, and (c) left an audit row that does not match the
 * real intent. A dummy mutation is not a contract.
 *
 * What is asserted here
 * ---------------------
 * Four things, each through a real WordPress install (a stubbed
 * assertion cannot prove `update_option( Store::CONFIG_KEY, null )`
 * would not erase the config; only a live option can show the
 * pre-state is preserved):
 *
 *   F1. Journal-only commit succeeds. The post is applied, the
 *       tx row is value-guarded deleted, the persisted config
 *       keys are unchanged, and the audit row names
 *       `transaction_committed` (not the compensation variant).
 *   F2. Empty-tx commit is still refused. The original protection
 *       is preserved; the tx row is still there after the refusal.
 *   F3. Staged-only commit still works. The pre-existing happy
 *       path is not regressed.
 *   F4. Journal-only rollback is unchanged. The destructive
 *       inverse still removes the post and the row.
 *
 * The child lives in the test site; the parent (this file) reaches
 * it via the same `agentshell_test_bed` runner the overlay / publish /
 * publish-edit-recovery suites use, and reads structured results back
 * from a JSON file.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live journal-only commit (Gate 3 Section D regression)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    echo "  SKIPPED: no test-bed config (destructive suites require tests/config/test-bed.json)\n";
    Agentshell_Test::run(
        'live journal-only commit',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child  = __DIR__ . '/journal-only-commit-live-child.php';
$report_file = sys_get_temp_dir() . '/as-joc-live-' . getmypid() . '.json';
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
        'live journal-only commit: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['joc_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['joc_f'] = $f;

function joc_f( $key, $default = null ) {
    $f = $GLOBALS['joc_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live journal-only commit',
    function () {
        // ── F0: precondition ────────────────────────────────────────────
        Agentshell_Test::assert_true(
            joc_f( 'f0_clean' ),
            'F0: tx option row must be empty at child start'
        );

        // ── F1: journal-only commit succeeds ────────────────────────────
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f1_committed' ),
            'F1: commit must succeed without a dummy set_css_var; got: '
                . wp_json_encode( array(
                    'committed'     => joc_f( 'f1_committed' ),
                    'message'       => joc_f( 'f1_commit_message' ),
                    'changed_tokens' => joc_f( 'f1_changed_tokens' ),
                ) )
        );
        Agentshell_Test::assert_equals(
            array(),
            joc_f( 'f1_changed_tokens' ),
            'F1: journal-only commit must record an empty diff token list'
        );
        Agentshell_Test::assert_true(
            is_null( joc_f( 'f1_tx_row_after' ) ),
            'F1: tx row must be value-guarded deleted by commit'
        );
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f1_cfg_preserved' ),
            'F1: persisted config keys must equal pre-state (no erasure); got: '
                . wp_json_encode( array(
                    'before' => joc_f( 'f0_cfg_keys_before' ),
                    'after'  => is_array( joc_f( 'f1_cfg_preserved' ) ) ? 'array' : joc_f( 'f1_cfg_preserved' ),
                ) )
        );
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f1_post_exists' ),
            'F1: post must exist after commit'
        );
        Agentshell_Test::assert_equals(
            'draft',
            joc_f( 'f1_post_status' ),
            'F1: post must remain a draft'
        );
        Agentshell_Test::assert_true(
            '' !== (string) joc_f( 'f1_post_marker' ),
            'F1: post must retain the op marker'
        );
        Agentshell_Test::assert_equals(
            'transaction_committed',
            joc_f( 'f1_audit_operation' ),
            'F1: audit row must name the standard committed operation (not compensation)'
        );

        // ── F2: empty-tx commit is still refused ─────────────────────────
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f2_empty_refused' ),
            'F2: commit with no staged and no journal ops must still refuse; got: '
                . (string) joc_f( 'f2_empty_message' )
        );
        Agentshell_Test::assert_true(
            str_contains( (string) joc_f( 'f2_empty_message' ), 'no staged changes' ),
            'F2: the refusal must name the original "no staged changes" reason'
        );
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f2_empty_tx_still_open' ),
            'F2: a refused commit must not close the tx row'
        );
        Agentshell_Test::assert_false(
            (bool) joc_f( 'f2_after_rollback' ),
            'F2: rollback after the refused commit must remove the row'
        );

        // ── F3: staged-only commit still works (no regression) ──────────
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f3_staged_committed' ),
            'F3: a staged-only commit must still succeed'
        );
        Agentshell_Test::assert_equals(
            '#abcdef',
            joc_f( 'f3_staged_var_value' ),
            'F3: the staged var must be applied to the persisted config'
        );

        // ── F4: journal-only rollback still works ────────────────────────
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f4_rb_ok' ),
            'F4: rollback of a journal-only tx must succeed'
        );
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f4_post_gone' ),
            'F4: the applied post must be removed by rollback'
        );
        Agentshell_Test::assert_true(
            (bool) joc_f( 'f4_tx_gone' ),
            'F4: the tx row must be gone after rollback'
        );
    }
);
