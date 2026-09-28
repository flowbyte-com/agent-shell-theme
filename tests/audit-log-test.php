<?php
/**
 * Audit log retention tests.
 *
 * Pre-fix state: wp_agentshell_mcp_audit_log received one INSERT per
 * tools/call with no retention, no row cap, no cron, no uninstall
 * cleanup. Long-running sites with active agents filled disk forever.
 *
 * Post-fix invariants:
 *   1. prune() removes rows older than the retention window.
 *   2. prune() caps total row count at MAX_ROW_CAP by deleting oldest.
 *   3. prune() is a no-op on a small table (idempotent / cheap).
 *   4. prune() failure does not propagate to the caller (logging
 *      must not break when the DB is in a degraded state).
 *   5. The cron event is registered with the prune handler bound.
 *   6. After-insert opportunistic prune is cheap on a small table.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-audit-log.php';

echo "audit log retention tests\n";

Agentshell_Test::run(
    'prune() deletes rows older than retention window',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        // 10 fresh rows (retained) + 5 stale rows (pruned)
        for ( $i = 0; $i < 10; $i++ ) {
            agentshell_test_audit_insert( $i, 'keep', $now - 100 );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            agentshell_test_audit_insert( 100 + $i, 'stale', $now - ( 86400 * 60 ) );
        }

        \AgentShell_MCP\Agentshell_Audit_Log::prune( 30 /* days */ );

        $rows = agentshell_test_audit_all();
        Agentshell_Test::assert_equals( 10, count( $rows ) );
        foreach ( $rows as $r ) {
            Agentshell_Test::assert_equals( 'keep', $r['tool_name'] );
        }
    }
);

Agentshell_Test::run(
    'prune() caps row count at MAX_ROW_CAP by deleting oldest',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        // 100 rows, all fresh — over the cap of 20.
        for ( $i = 0; $i < 100; $i++ ) {
            agentshell_test_audit_insert( $i, 'fill', $now - 100 );
        }

        \AgentShell_MCP\Agentshell_Audit_Log::prune( 30, 20 /* max rows */ );

        $rows = agentshell_test_audit_all();
        Agentshell_Test::assert_equals( 20, count( $rows ) );
        // Newest 20 must remain (ids 80..99).
        $ids = array_column( $rows, 'wp_user_id' );
        sort( $ids );
        Agentshell_Test::assert_equals( 80, $ids[0] );
        Agentshell_Test::assert_equals( 99, $ids[ count( $ids ) - 1 ] );
    }
);

Agentshell_Test::run(
    'prune() is a no-op on a small table',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        for ( $i = 0; $i < 3; $i++ ) {
            agentshell_test_audit_insert( $i, 'small', $now - 100 );
        }

        \AgentShell_MCP\Agentshell_Audit_Log::prune( 30, 10000 );

        Agentshell_Test::assert_equals( 3, count( agentshell_test_audit_all() ) );
    }
);

Agentshell_Test::run(
    'prune() failure does not propagate to the caller',
    function () {
        agentshell_test_reset();
        // Inject a DB failure via the test stub. prune() must swallow
        // the exception (it logs to error_log) so callers — including the
        // cron handler and the opportunistic after-insert call — never
        // propagate failure.
        global $_agentshell_test_audit_fail;
        $_agentshell_test_audit_fail = true;

        $threw = false;
        try {
            \AgentShell_MCP\Agentshell_Audit_Log::prune();
        } catch ( \Throwable $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_false( $threw );
    }
);

Agentshell_Test::run(
    'prune() combines age and count bounds correctly',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        // 50 fresh + 50 stale. Age window removes the 50 stale; count
        // cap further trims the 50 fresh down to 20.
        for ( $i = 0; $i < 50; $i++ ) {
            agentshell_test_audit_insert( $i, 'fresh', $now - 100 );
        }
        for ( $i = 0; $i < 50; $i++ ) {
            agentshell_test_audit_insert( 100 + $i, 'stale', $now - ( 86400 * 60 ) );
        }

        \AgentShell_MCP\Agentshell_Audit_Log::prune( 30, 20 );

        $rows = agentshell_test_audit_all();
        Agentshell_Test::assert_equals( 20, count( $rows ) );
        foreach ( $rows as $r ) {
            Agentshell_Test::assert_equals( 'fresh', $r['tool_name'] );
        }
    }
);

Agentshell_Test::run(
    'prune() with default retention and cap leaves a small fresh table alone',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        for ( $i = 0; $i < 5; $i++ ) {
            agentshell_test_audit_insert( $i, 'fine', $now - 100 );
        }

        // Defaults: 30 days, 10000 rows.
        \AgentShell_MCP\Agentshell_Audit_Log::prune();

        Agentshell_Test::assert_equals( 5, count( agentshell_test_audit_all() ) );
    }
);

Agentshell_Test::run(
    'class declares the documented retention constants',
    function () {
        Agentshell_Test::assert_true(
            defined( 'AgentShell_MCP\Agentshell_Audit_Log::DEFAULT_RETENTION_DAYS' ),
            'DEFAULT_RETENTION_DAYS constant must exist for discoverability'
        );
        Agentshell_Test::assert_true(
            defined( 'AgentShell_MCP\Agentshell_Audit_Log::MAX_ROW_CAP' ),
            'MAX_ROW_CAP constant must exist for discoverability'
        );
        Agentshell_Test::assert_true(
            \AgentShell_MCP\Agentshell_Audit_Log::DEFAULT_RETENTION_DAYS > 0,
            'DEFAULT_RETENTION_DAYS must be positive'
        );
        Agentshell_Test::assert_true(
            \AgentShell_MCP\Agentshell_Audit_Log::MAX_ROW_CAP > 0,
            'MAX_ROW_CAP must be positive'
        );
    }
);

Agentshell_Test::run(
    'after-insert opportunistic call leaves a small table alone',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        for ( $i = 0; $i < 5; $i++ ) {
            agentshell_test_audit_insert( $i, 'op', $now - 100 );
        }

        // After-insert prune should be cheap; call it many times.
        for ( $j = 0; $j < 50; $j++ ) {
            \AgentShell_MCP\Agentshell_Audit_Log::maybe_prune();
        }

        Agentshell_Test::assert_equals( 5, count( agentshell_test_audit_all() ) );
    }
);

Agentshell_Test::run(
    'after-insert opportunistic call removes expired rows when threshold reached',
    function () {
        agentshell_test_reset();
        global $_agentshell_test_audit_table;
        $_agentshell_test_audit_table = array();

        $now = time();
        for ( $i = 0; $i < 3; $i++ ) {
            agentshell_test_audit_insert( $i, 'fresh', $now - 100 );
        }
        for ( $i = 0; $i < 5; $i++ ) {
            agentshell_test_audit_insert( 100 + $i, 'stale', $now - ( 86400 * 60 ) );
        }

        // maybe_prune() is throttled to OPPORTUNISTIC_INTERVAL calls. Loop
        // past the threshold so the actual prune runs.
        $interval = \AgentShell_MCP\Agentshell_Audit_Log::OPPORTUNISTIC_INTERVAL;
        for ( $j = 0; $j <= $interval; $j++ ) {
            \AgentShell_MCP\Agentshell_Audit_Log::maybe_prune();
        }

        $rows = agentshell_test_audit_all();
        Agentshell_Test::assert_equals( 3, count( $rows ) );
        foreach ( $rows as $r ) {
            Agentshell_Test::assert_equals( 'fresh', $r['tool_name'] );
        }
    }
);