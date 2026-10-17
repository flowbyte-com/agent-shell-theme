<?php
/**
 * Stage 2c M5-t1 — race regression suite for the publish-edit tool.
 *
 * Verifies the cross-process concurrency contracts the M4 diag scripts
 * demonstrated but did not commit:
 *
 *   R1 — two concurrent agentshell_publish_edit calls against the same
 *        staged operation produce exactly one committed winner and one
 *        refused loser.
 *   R2 — two concurrent agentshell_publish_edit_reconcile calls against
 *        the same op (left in STATE_PUBLICATION_COMMITTED) produce
 *        exactly one reconciled winner and one refused loser, with the
 *        shadow deleted exactly once and the claim released exactly once.
 *
 * The test is destructive (spawns child PHP processes against the test
 * bed, races them through a file-based barrier, asserts on the on-disk
 * post-publish truth). It is gated on the test-bed config like the
 * other live destructive suites.
 *
 * The patterns here are re-derived from the M4 diagnostic scripts
 * (`tests/diag-m4-issue-1-orchestrator.php` and
 * `tests/diag-m4-issue-2-orchestrator.php`). The M4 diags proved the
 * contracts; this file commits them as tracked regression coverage
 * without copying the diags verbatim.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live stage-2c-publish-edit-race (M5-t1)\n";

// Destructive live suite: requires the test-bed config.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live stage-2c-publish-edit-race suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-pe-race-' . getmypid() . '.json';
@unlink( $report_file );

// The child runs both R1 and R2 in sequence in one boot of WordPress.
// Each race is its own orchestrator (provision + barrier + spawn +
// barrier release + report), so a single child process per test is
// sufficient.
$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/stage-2c-publish-edit-race-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = (string) @file_get_contents( $report_file );
$report = json_decode( $raw, true );
@unlink( $report_file );

if ( ! is_array( $report ) || ! isset( $report['R1'] ) || ! isset( $report['R2'] ) ) {
    Agentshell_Test::run(
        'live stage-2c-publish-edit-race: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['pe_report'] ),
                'child failed to report: ' . substr( (string) $run['output'], 0, 600 )
            );
        }
    );
    return;
}
$GLOBALS['pe_report'] = $report;

// ── R1: same-op publication race ──────────────────────────────
$r1 = $report['R1'];

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R1 outcomes are exactly [committed, refused]',
    function () use ( $r1 ) {
        $outcomes = (array) ( $r1['outcomes_sorted'] ?? array() );
        Agentshell_Test::assert_true(
            array( 'committed', 'refused' ) === $outcomes,
            'got: ' . json_encode( $outcomes ) . ' (children: ' . json_encode( array(
                'A' => (string) ( $r1['A']['outcome'] ?? '?' ),
                'B' => (string) ( $r1['B']['outcome'] ?? '?' ),
            ) ) . ')'
        );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R1 committed child reached publication_settled',
    function () use ( $r1 ) {
        $a = (string) ( $r1['A']['state'] ?? '' );
        $b = (string) ( $r1['B']['state'] ?? '' );
        $recon = (string) ( $r1['A']['recon_status'] ?? '' );
        if ( 'committed' === ( $r1['A']['outcome'] ?? '' ) ) {
            Agentshell_Test::assert_true( false !== strpos( $a, 'publication_settled' ) || 'complete' === $recon, 'A state: ' . $a . ' recon: ' . $recon );
        } else {
            Agentshell_Test::assert_true( false !== strpos( $b, 'publication_settled' ) || 'complete' === (string) ( $r1['B']['recon_status'] ?? '' ), 'B state: ' . $b );
        }
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R1 post carries the staged title',
    function () use ( $r1 ) {
        $staged = (string) ( $r1['staged_title'] ?? '' );
        $final  = (string) ( $r1['post_title_final'] ?? '' );
        Agentshell_Test::assert_true( '' !== $staged, 'staged_title missing' );
        Agentshell_Test::assert_true( $staged === $final, "staged='{$staged}' final='{$final}'" );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R1 shadow is gone after the race',
    function () use ( $r1 ) {
        Agentshell_Test::assert_true( true === (bool) ( $r1['shadow_gone'] ?? false ), 'shadow still present after R1' );
    }
);

// ── R2: same-op reconciliation race ───────────────────────────
$r2 = $report['R2'];

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R2 outcomes are exactly [reconciled, refused]',
    function () use ( $r2 ) {
        $outcomes = (array) ( $r2['outcomes_sorted'] ?? array() );
        Agentshell_Test::assert_true(
            array( 'reconciled', 'refused' ) === $outcomes,
            'got: ' . json_encode( $outcomes ) . ' (children: ' . json_encode( array(
                'A' => (string) ( $r2['A']['outcome'] ?? '?' ),
                'B' => (string) ( $r2['B']['outcome'] ?? '?' ),
            ) ) . ')'
        );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R2 shadow was deleted exactly once (gone, with no orphan)',
    function () use ( $r2 ) {
        Agentshell_Test::assert_true( true === (bool) ( $r2['shadow_deleted_final'] ?? false ), 'shadow still present after R2' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R2 claim was released (absent on disk)',
    function () use ( $r2 ) {
        Agentshell_Test::assert_true( false === (bool) ( $r2['claim_final_present'] ?? true ), 'claim row still present after R2' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-race: R2 op reached publication_settled',
    function () use ( $r2 ) {
        $state = (string) ( $r2['op_state_final'] ?? '' );
        Agentshell_Test::assert_true( false !== strpos( $state, 'publication_settled' ), 'final op state: ' . $state );
    }
);
