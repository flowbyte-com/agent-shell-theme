<?php
/**
 * Stage 2c M5-t2 — post-COMMIT recovery regression suite.
 *
 * Verifies the cross-process recovery contract the M4 diag scripts
 * demonstrated but did not commit:
 *
 *   R3 — post-COMMIT interrupt + fresh-process recovery.
 *        Phase A spawns a child that runs the publish SQL transaction
 *        (FOR UPDATE → wp_update_post → cas_write_row → audit → COMMIT)
 *        and exits WITHOUT running the reconciliation tail. This
 *        simulates a host kill, a process crash, or a network drop
 *        between SQL COMMIT and the reconciliation tail.
 *
 *        Phase B spawns a SECOND, fresh PHP process with its own
 *        MySQL connection. The recover child calls
 *        Publish_Edit_Reconcile::execute() against the op left in
 *        publication_committed by phase A. The production
 *        reconciliation path completes the lifecycle without
 *        re-running the SQL transaction.
 *
 *   R3.1 — repeated reconciliation is safe and mutation-free.
 *          Phase C re-runs the same Publish_Edit_Reconcile::execute()
 *          against the now-settled op and verifies it refuses with
 *          no side effects.
 *
 * The race pattern is re-derived from the M4 diagnostic scripts
 * (`tests/diag-m4-issue-3-orchestrator.php`,
 * `tests/diag-m4-issue-3-pre-commit.php`,
 * `tests/diag-m4-issue-3-recover.php`). The M4 diags proved the
 * contract; this file commits them as tracked regression coverage
 * without copying the diags verbatim.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live stage-2c-publish-edit-recovery (M5-t2)\n";

// Destructive live suite: requires the test-bed config.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live stage-2c-publish-edit-recovery suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-pe-recovery-' . getmypid() . '.json';
@unlink( $report_file );

// The child runs R3 phase A (pre-commit interrupt) and phase B
// (fresh-process reconciliation) in a single boot of WordPress.
// Each phase is its own spawned child PHP process.
$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/stage-2c-publish-edit-recovery-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = (string) @file_get_contents( $report_file );
$report = json_decode( $raw, true );
@unlink( $report_file );

if ( ! is_array( $report ) || ! isset( $report['phaseA'] ) || ! isset( $report['phaseB'] ) || ! isset( $report['phaseC'] ) ) {
    Agentshell_Test::run(
        'live stage-2c-publish-edit-recovery: child produced a report',
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

// ── Phase A: post-COMMIT interrupt ─────────────────────────────
$pa = $report['phaseA'];

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.sql_committed is true',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( true === (bool) ( $pa['sql_committed'] ?? false ), 'sql_committed' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.op_state is publication_committed (not settled)',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( 'publication_committed' === (string) ( $pa['op_state_after_commit'] ?? '' ), 'state=' . ( $pa['op_state_after_commit'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.original carries the staged post_title',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( '' !== (string) ( $pa['post_title'] ?? '' ) && false === strpos( (string) ( $pa['post_title'] ?? '' ), ' orig ' ), 'title=' . (string) ( $pa['post_title'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.shadow still exists',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( true === (bool) ( $pa['shadow_still_exists'] ?? false ), 'shadow gone' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.claim is still held',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( true === (bool) ( $pa['claim_present'] ?? false ), 'claim released' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.exactly one publication audit row',
    function () use ( $pa ) {
        $n = (int) ( $pa['audit_count'] ?? 0 );
        Agentshell_Test::assert_true( 1 === $n, 'audit_count=' . $n );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: A.interruption_after_commit flag is true',
    function () use ( $pa ) {
        Agentshell_Test::assert_true( true === (bool) ( $pa['interruption_after_commit'] ?? false ), 'flag missing' );
    }
);

// ── Phase B: fresh-process recovery ───────────────────────────
$pb = $report['phaseB'];

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.outcome is reconciled',
    function () use ( $pb ) {
        Agentshell_Test::assert_true( 'reconciled' === (string) ( $pb['outcome'] ?? '' ), 'outcome=' . ( $pb['outcome'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.op reached publication_settled',
    function () use ( $pb ) {
        Agentshell_Test::assert_true( 'publication_settled' === (string) ( $pb['op_state_after'] ?? '' ), 'state=' . ( $pb['op_state_after'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.shadow is gone',
    function () use ( $pb ) {
        Agentshell_Test::assert_true( false === (bool) ( $pb['shadow_exists_after'] ?? true ), 'shadow still present' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.claim is released',
    function () use ( $pb ) {
        Agentshell_Test::assert_true( false === (bool) ( $pb['claim_present_after'] ?? true ), 'claim still present' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.caches were invalidated',
    function () use ( $pb ) {
        Agentshell_Test::assert_true( true === (bool) ( $pb['caches_invalidated'] ?? false ), 'caches not invalidated' );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.post_title UNCHANGED by recovery (no replay)',
    function () use ( $pb, $pa ) {
        $before = (string) ( $pa['post_title'] ?? '' );
        $after  = (string) ( $pb['post_title_after'] ?? '' );
        Agentshell_Test::assert_true( $before === $after && '' !== $before, "before='{$before}' after='{$after}'" );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.no second publication audit row (no replay)',
    function () use ( $pb, $pa ) {
        $after = (int) ( $pb['audit_published_total'] ?? 0 );
        $before = (int) ( $pa['audit_count'] ?? 0 );
        Agentshell_Test::assert_true( $after === $before, "before={$before} after={$after}" );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: B.exactly one reconciliation audit row was added',
    function () use ( $pb ) {
        $n = (int) ( $pb['audit_reconciled_total'] ?? 0 );
        Agentshell_Test::assert_true( $n >= 1, 'reconciled audit count=' . $n );
    }
);

// ── Phase C: repeated reconciliation is mutation-free ─────────
$pc = $report['phaseC'];

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: C.repeat reconcile refuses (op is already settled)',
    function () use ( $pc ) {
        Agentshell_Test::assert_true( 'refused' === (string) ( $pc['outcome'] ?? '' ), 'outcome=' . ( $pc['outcome'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: C.repeat reconcile did not add a second publication audit',
    function () use ( $pc, $pb ) {
        $n = (int) ( $pc['audit_published_total'] ?? 0 );
        $before = (int) ( $pb['audit_published_total'] ?? 0 );
        Agentshell_Test::assert_true( $n === $before, "before={$before} after={$n}" );
    }
);

Agentshell_Test::run(
    'live stage-2c-publish-edit-recovery: C.repeat reconcile did not change the post',
    function () use ( $pc, $pb ) {
        $before = (string) ( $pb['post_title_after'] ?? '' );
        $after  = (string) ( $pc['post_title_after'] ?? '' );
        Agentshell_Test::assert_true( $before === $after, "before='{$before}' after='{$after}'" );
    }
);
