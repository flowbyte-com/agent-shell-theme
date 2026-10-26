<?php
/**
 * Live regression for `agentshell_test_bed_preflight()`'s drain-failure
 * path.
 *
 * What this pins
 * --------------
 * The preflight (tests/agentshell-test-bed.php, function
 * `agentshell_test_bed_preflight`) applies a baseline file via
 * `mysqli::multi_query()` and then drains the queued result sets in a
 * `while ( $mysqli->more_results() && $mysqli->next_result() )` loop.
 * The first statement of a multi_query may succeed (so `multi_query`
 * returns `true`) and a later statement may fail; the failure is
 * surfaced by `next_result()` throwing `mysqli_sql_exception` for a
 * queued statement that hit, e.g., a duplicate-key error.
 *
 * Pre-fix, the drain loop did not catch the exception. The preflight's
 * `if ( ! $preflight['ok'] )` return path at the call site never ran;
 * the unhandled `mysqli_sql_exception` killed the entire runner. The
 * failure observed in /tmp/run-all-final4.txt (2026-10-18 13:13) was
 * `Duplicate entry '1' for key 'wp_comments.PRIMARY'` at
 * agentshell-test-bed.php:870, raised by `mysqli->next_result()` inside
 * the drain loop.
 *
 * The fix wraps the drain loop in a try/catch and returns a structured
 * `action = 'restore_failed'` with the MySQL error and a safe statement
 * context. This parent test runs the child (which constructs an
 * in-process poison baseline) and asserts:
 *
 *   - The child exited 0 (no propagated exception; the runner wasn't
 *     killed).
 *   - The preflight returned `ok = false`.
 *   - The preflight returned `action = 'restore_failed'`.
 *   - The preflight's `detail` field carries a MySQL error and a
 *     safe statement context.
 *   - The pre-inserted `_agentshell_journal_op` marker survives the
 *     failed restore (the next preflight still has work to do — the
 *     fix must not silently clear the marker).
 *   - No partial state was leaked beyond the first INSERT (the
 *     failing second INSERT is the failure that triggered the catch).
 *
 * TDD red-green: this parent exists alongside the child. Run BEFORE
 * the fix — the child will exit with a propagated exception (and
 * therefore the runner may record this as a fail / caught throwable
 * in the report). Run AFTER the fix — the child should report all
 * preflight fields cleanly and every assertion in the suite should
 * pass.
 *
 * Boundaries
 * ----------
 *   - The child is destructive: it must call
 *     `agentshell_destructive_child_identity_guard( $wp_root, __FILE__ )`
 *     on entry, so this suite runs only against the verified
 *     disposable test bed (per the b8a12a8 guard).
 *   - The child cleans up after itself: the helper table and the
 *     pre-inserted marker are dropped at end so a failed assertion
 *     here does not pollute the next suite.
 *   - This suite is added to the destructive-live list in run-all.php
 *     so the run-all gate is the same as for the other destructive
 *     live suites.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live test-bed preflight drain-failure regression\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)\\n' );
    Agentshell_Test::run(
        'live test-bed preflight drain-failure',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/agentshell-test-bed-preflight-live-child.php';
$report_file = sys_get_temp_dir() . '/as-preflight-live-' . getmypid() . '.json';
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
        'live test-bed preflight drain-failure: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['pf_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['pf_f'] = $f;

function pf_f( $key, $default = null ) {
    $f = $GLOBALS['pf_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live test-bed preflight drain-failure',
    function () {
        // The child caught a Throwable inside the try around the
        // preflight call. That alone is the RED state — the fix
        // makes the catch unnecessary. After the fix, the child
        // sets `caught_throwable_class` to an empty string (no
        // throw propagated). Before the fix, the field is set to
        // 'mysqli_sql_exception' (or a subclass) — which is the
        // observable bug.
        Agentshell_Test::assert_equals(
            '',
            (string) pf_f( 'caught_throwable_class' ),
            'preflight must NOT propagate a Throwable out of the call; the drain-loop exception must be caught and converted to a structured failure. Got: '
                . wp_json_encode( array(
                    'caught_throwable_class'   => pf_f( 'caught_throwable_class' ),
                    'caught_throwable_message' => pf_f( 'caught_throwable_message' ),
                ) )
        );

        Agentshell_Test::assert_equals(
            false,
            (bool) pf_f( 'preflight_ok' ),
            'preflight must return ok = false on a drain failure'
        );
        Agentshell_Test::assert_equals(
            'restore_failed',
            (string) pf_f( 'preflight_action' ),
            'preflight must return action = "restore_failed" on a drain failure; got: '
                . wp_json_encode( array(
                    'preflight_ok'     => pf_f( 'preflight_ok' ),
                    'preflight_action' => pf_f( 'preflight_action' ),
                    'preflight_detail' => pf_f( 'preflight_detail' ),
                ) )
        );

        // The detail string must reference a MySQL error and the
        // statement context — NOT the verification-step "baseline
        // restored but verification shows N marker(s)" string, which
        // is the symptom of a successful multi_query submission that
        // silently swallowed a queued-statement failure. The fix
        // adds: after the drain loop, check `$mysqli->error` and
        // return `restore_failed` with the error and a safe
        // statement context. The pre-fix detail does NOT mention
        // "Duplicate", "1062", or any error code.
        $detail = (string) pf_f( 'preflight_detail' );
        $mentions_mysql_error = ( false !== stripos( $detail, 'Duplicate' ) )
            || ( false !== stripos( $detail, 'multi_query' ) )
            || ( false !== stripos( $detail, 'next_result' ) )
            || ( false !== stripos( $detail, 'drain' ) )
            || ( false !== stripos( $detail, 'queued' ) )
            || preg_match( '/\b10[0-9]{2}\b/', $detail );
        Agentshell_Test::assert_true(
            $mentions_mysql_error,
            'preflight detail must reference a MySQL error and a safe statement context (the queued-statement failure the drain loop swallowed). Currently the detail is: '
                . $detail
        );

        // The pre-inserted marker must survive the failed restore.
        // The fix must not silently drop it (the next preflight
        // still has work to do).
        Agentshell_Test::assert_true(
            (int) pf_f( 'marker_count_after' ) >= 1,
            'pre-inserted journal marker must survive a failed restore; got: '
                . wp_json_encode( pf_f( 'marker_count_after' ) )
        );
    }
);
