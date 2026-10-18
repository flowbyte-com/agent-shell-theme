<?php
/**
 * Child half of the test-bed preflight drain-failure regression suite.
 *
 * What this pins
 * --------------
 * The preflight at `agentshell_test_bed_preflight()` (tests/agentshell-test-bed.php)
 * applies a baseline via `multi_query()` and then drains the queued result
 * sets in a `while ( $mysqli->more_results() && $mysqli->next_result() )`
 * loop. The first statement of a multi_query may succeed (so `multi_query`
 * returns `true`) and a later statement may fail; the failure is surfaced
 * by `next_result()` throwing `mysqli_sql_exception` for a queued
 * statement that hit, e.g., a duplicate-key error.
 *
 * Pre-fix, the drain loop did not catch the exception. The preflight's
 * `if ( ! $preflight['ok'] )` return path at the call site never ran;
 * the unhandled `mysqli_sql_exception` killed the entire runner. The
 * failure observed in /tmp/run-all-final4.txt (2026-10-18 13:13) was
 * `Duplicate entry '1' for key 'wp_comments.PRIMARY'` at
 * agentshell-test-bed.php:870, raised by `mysqli->next_result()` inside
 * the drain loop.
 *
 * This child constructs an in-process poison baseline — two `INSERT`
 * statements, the first into a fresh synthetic helper table, the second
 * colliding on the primary key — so the drain failure is deterministic.
 * It then pre-inserts a `_agentshell_journal_op` marker in `wp_postmeta`
 * to force the preflight into the restore branch (markers > 0 ⇒ restore).
 * It calls `agentshell_test_bed_preflight()` with the poison baseline path
 * and asserts:
 *
 *   - No `mysqli_sql_exception` propagates out of the call.
 *   - The returned action is `restore_failed`.
 *   - The detail string contains a safe MySQL error and a safe statement
 *     context.
 *   - The pre-inserted marker survives the failed restore (the fix must
 *     guarantee a failed restore does not silently clear the marker — the
 *     next preflight still has work to do).
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: agentshell-test-bed-preflight-live-child.php <wp_root> <report_path>\n" );
    exit( 7 );
}

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

global $wpdb;
wp_set_current_user( 1 );

$report = array();
$poison_path = sys_get_temp_dir() . '/as-preflight-poison-' . getmypid() . '.sql';

// Build a poison baseline. First INSERT into a synthetic helper table
// (succeeds); second INSERT collides on the same primary key (fails
// during drain). The preflight must catch the drain-loop exception and
// return a structured `restore_failed` action — not propagate the
// exception to the caller.
$poison_sql = <<<SQL
CREATE TABLE IF NOT EXISTS `wp_preflight_poison_helper` (
  `id` bigint unsigned NOT NULL,
  `v` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_preflight_poison_helper` (`id`, `v`) VALUES (1, 'first');
INSERT INTO `wp_preflight_poison_helper` (`id`, `v`) VALUES (1, 'second-collides');
SQL;

file_put_contents( $poison_path, $poison_sql );

// Pre-insert a journal marker so the preflight's leak branch fires and
// the restore path runs. The marker is in `wp_postmeta` and is the
// exact key the preflight counts.
$wpdb->insert(
    $wpdb->postmeta,
    array( 'post_id' => 1, 'meta_key' => '_agentshell_journal_op', 'meta_value' => 'op_poison_' . bin2hex( random_bytes( 4 ) ) ),
    array( '%d', '%s', '%s' )
);

// Build a config that points at the poison baseline. We copy the live
// test-bed config and override `baseline_snapshot_path`.
$cfg = agentshell_test_bed_load();
if ( ! is_array( $cfg ) ) {
    fwrite( STDERR, "no test-bed config; abort\n" );
    exit( 7 );
}
$cfg['expect']['baseline_snapshot_path'] = $poison_path;

// Call the preflight. The fix must catch the drain-loop exception; the
// bug propagates the exception and kills this child.
$threw = false;
$preflight = null;
try {
    $preflight = agentshell_test_bed_preflight( $cfg );
} catch ( \Throwable $e ) {
    $threw = true;
    $report['caught_throwable_class'] = get_class( $e );
    $report['caught_throwable_message'] = $e->getMessage();
}

if ( ! $threw ) {
    $report['preflight_ok']           = isset( $preflight['ok'] ) ? (bool) $preflight['ok'] : null;
    $report['preflight_action']       = isset( $preflight['action'] ) ? (string) $preflight['action'] : '';
    $report['preflight_detail']       = isset( $preflight['detail'] ) ? (string) $preflight['detail'] : '';
    $report['preflight_marker_count'] = isset( $preflight['marker_count'] ) ? (int) $preflight['marker_count'] : -1;
}

// Verify the pre-inserted marker is still on disk. A failed restore must
// not clear it. (The fix must return early on the restore-failed path,
// not fall through to the verify step which would also find the marker.)
// Each verification query is wrapped in its own try/catch so a transient
// DB error here cannot prevent the cleanup below from running.
try {
    $marker_still_present = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_agentshell_journal_op'
        )
    );
    $report['marker_count_after'] = $marker_still_present;
} catch ( \Throwable $e ) {
    $report['marker_count_after'] = 'verify-error: ' . $e->getMessage();
}
try {
    $report['helper_table_count'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `wp_preflight_poison_helper`"
    );
} catch ( \Throwable $e ) {
    $report['helper_table_count'] = 'verify-error: ' . $e->getMessage();
}
try {
    // If the first INSERT succeeded, the helper table has row id=1 with v='first'.
    // The second INSERT never landed (the failure was in the drain, after the
    // statement ran). This invariant pins the partial-state behavior: a failed
    // restore leaves a partial state but the test cleans it up below.
    $report['helper_v_first'] = (string) $wpdb->get_var(
        "SELECT `v` FROM `wp_preflight_poison_helper` WHERE `id` = 1"
    );
} catch ( \Throwable $e ) {
    $report['helper_v_first'] = 'verify-error: ' . $e->getMessage();
}

// Clean up. The preflight may have left a partial state (helper table
// exists with row id=1, marker is on disk). Drop both so the next test
// run is not poisoned. The cleanup is unconditional — it runs on the
// happy path, on the caught-Throwable path, and on any verification
// error. None of the cleanup statements throw on their own (DROP
// TABLE IF EXISTS is idempotent; DELETE with a literal meta_key is
// idempotent; @unlink ignores missing files).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_agentshell_journal_op'" );
$wpdb->query( "DROP TABLE IF EXISTS `wp_preflight_poison_helper`" );
@unlink( $poison_path );

file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT ) );
exit( 0 );
