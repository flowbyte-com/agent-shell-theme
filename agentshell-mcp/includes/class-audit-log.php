<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded retention for the MCP call audit log.
 *
 * The wp_agentshell_mcp_audit_log table is durable security history.
 * Pre-fix: one INSERT per tools/call, no rotation, no cap, no cleanup
 * path. Long-running sites with active agents filled disk forever.
 *
 * Post-fix: a single static helper prune() applies both an age window
 * (default 30 days) and a row-count ceiling (default 10000 rows).
 * prune() is safe to call from any context: failures are caught and
 * logged so MCP calls and the cron handler never propagate the error.
 *
 * Two callers:
 *   - Daily WP cron event agentshell_audit_log_prune_event.
 *   - Opportunistic after-insert throttle (every Nth call) via
 *     maybe_prune(), so a busy agent does not silently overrun the
 *     cap between cron runs.
 *
 * The class is intentionally tiny: a constant interface, prune(),
 * maybe_prune(), and a hook installer. Constants are discoverable by
 * introspection; behaviour is exercised by tests/audit-log-test.php.
 */
class Agentshell_Audit_Log {
    /** Rows older than this many days are pruned on every run. */
    const DEFAULT_RETENTION_DAYS = 30;

    /** Hard maximum row count; oldest rows are dropped first when over. */
    const MAX_ROW_CAP = 10000;

    /** Cron hook name. Scheduled on activation, cleared on uninstall. */
    const CRON_HOOK = 'agentshell_audit_log_prune_event';

    /** After this many tool calls, maybe_prune() runs the actual prune. */
    const OPPORTUNISTIC_INTERVAL = 100;

    /**
     * Apply the age and count bounds in a single call. Idempotent:
     * a small fresh table produces zero deletes.
     *
     * @param int|null $retention_days Override the default retention window.
     * @param int|null $max_rows      Override the default row cap.
     */
    public static function prune( $retention_days = null, $max_rows = null ) {
        $retention_days = ( $retention_days === null ) ? self::DEFAULT_RETENTION_DAYS : (int) $retention_days;
        $max_rows       = ( $max_rows === null ) ? self::MAX_ROW_CAP : (int) $max_rows;
        if ( $retention_days <= 0 || $max_rows <= 0 ) {
            return;
        }

        try {
            self::prune_age( $retention_days );
            self::prune_count( $max_rows );
        } catch ( \Throwable $e ) {
            // Pruning failure must never break MCP calls or the cron handler.
            // We deliberately do not surface this to the caller; operators
            // see the error_log line and can investigate.
            error_log( 'AgentShell audit log prune failed: ' . $e->getMessage() );
        }
    }

    /**
     * Cheap on-insert throttled prune. Increments an option counter and
     * runs prune() every OPPORTUNISTIC_INTERVAL calls. On a small table
     * prune() is a no-op (the DELETE matches no rows), so the cost per
     * non-pruning call is just an option read/write.
     */
    public static function maybe_prune() {
        $counter = (int) get_option( 'agentshell_audit_log_prune_counter', 0 );
        $counter++;
        if ( $counter < self::OPPORTUNISTIC_INTERVAL ) {
            update_option( 'agentshell_audit_log_prune_counter', $counter, false );
            return;
        }
        update_option( 'agentshell_audit_log_prune_counter', 0, false );
        self::prune();
    }

    /**
     * Wire up the daily cron event and bind the prune handler.
     * Called from the plugin bootstrap (agentshell-mcp.php).
     */
    public static function register_hooks() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );
    }

    /**
     * Schedule the daily prune if not already scheduled. Idempotent.
     * Called from the plugin activator.
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Clear the scheduled event. Called from the uninstall handler.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // ── internals ───────────────────────────────────────────────

    /**
     * Delete rows older than $retention_days. Uses the created_at index.
     */
    private static function prune_age( $retention_days ) {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ) );
    }

    /**
     * Cap row count at $max_rows. If over, delete the oldest (lowest id)
     * excess rows in one bounded DELETE.
     */
    private static function prune_count( $max_rows ) {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count <= $max_rows ) {
            return;
        }
        $excess = $count - $max_rows;
        $wpdb->query( "DELETE FROM {$table} ORDER BY id ASC LIMIT {$excess}" );
    }
}