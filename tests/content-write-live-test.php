<?php
/**
 * Live content-write regressions, run against a real WordPress install.
 *
 * The defect these pin is not a crash. It is a tool that reports a failed
 * write while the content is sitting in the database — and because the caller
 * never receives a post id, nobody can say which content. The only honest way
 * to show this suite would catch that is to provoke the real failure inside
 * real WordPress and then read the tables back, which is what this does.
 *
 * The failure is the one `wp_insert_post()` branch that returns WP_Error after
 * it has written: `invalid_page_template`, reached by passing a page template
 * WordPress does not have. It writes the post row, its categories and its
 * `meta_input`, then returns an error that never names the post.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is safe
 * to run anywhere; `tests/wp-live.php` finds the install and picks a PHP that
 * can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live content write (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live content-write suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-cw-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/content-write-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live content-write child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['cw_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['cw_f'] = $f;

function cw_f( $key, $default = null ) {
    $f = $GLOBALS['cw_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live content write',
    function () {
        // ── the failure really is the post-insert one ──────────────────────
        // If this ever stops being true the rest of the suite is proving
        // nothing, so it is checked first and in the most literal way.
        $f3 = cw_f( 'f3', array() );
        Agentshell_Test::assert_equals(
            'invalid_page_template',
            $f3['error'] ?? null,
            'the reproduction must reach the post-insert WP_Error branch'
        );

        // ── F3: nothing survives the failure the tool reported ─────────────
        Agentshell_Test::assert_equals(
            array(),
            $f3['rows_after'] ?? null,
            'F3: no post row may survive the failure'
        );
        Agentshell_Test::assert_equals( 0, (int) ( $f3['query_after'] ?? -1 ), 'F3: a direct query must find nothing' );

        // ── metadata written before the failure goes with it ───────────────
        $fm = cw_f( 'f3meta', array() );
        Agentshell_Test::assert_equals( 'invalid_page_template', $fm['error'] ?? null, 'the meta variant must fail the same way' );
        Agentshell_Test::assert_equals( array(), $fm['rows_after'] ?? null, 'F3: no row survives when meta_input was also written' );
        Agentshell_Test::assert_equals( 0, (int) ( $fm['stray_meta_rows'] ?? -1 ), 'F3: no orphaned metadata may remain' );

        // ── the object cache must not still serve a rolled-back post ───────
        Agentshell_Test::assert_false(
            true === ( $f3['cache_serves_leak'] ?? false ),
            'the object cache must not keep serving a post that was rolled back'
        );
        Agentshell_Test::assert_true(
            false === strpos( (string) ( $f3['permalink_after'] ?? '' ), 'page_id=' ),
            'no permalink may resolve for a rolled-back post'
        );
    }
);

Agentshell_Test::run(
    'live content write: successful writes are unchanged',
    function () {
        $ok = cw_f( 'create_ok', array() );
        Agentshell_Test::assert_true( ! isset( $ok['exception'] ), 'a valid create must not throw: ' . ( $ok['exception'] ?? '' ) );
        Agentshell_Test::assert_true( ! empty( $ok['returned_id'] ), 'a valid create must return a post id' );
        Agentshell_Test::assert_true( ! empty( $ok['db_title'] ), 'the row must exist in the database' );
        Agentshell_Test::assert_equals( 'draft', $ok['db_status'] ?? '', 'the status must be the one asked for' );
        Agentshell_Test::assert_equals( strtolower( $ok['db_title'] ?? 'x' ), $ok['db_slug'] ?? '', 'the slug must be the one asked for' );
        Agentshell_Test::assert_equals( '<p>probe body</p>', $ok['content'] ?? '', 'raw HTML must survive verbatim' );
        Agentshell_Test::assert_true( is_array( $ok['categories'] ?? null ), 'core default-category assignment must still happen' );

        // The result must state what it can guarantee rather than imply more.
        Agentshell_Test::assert_true( is_array( $ok['atomicity'] ?? null ), 'the record must state its atomicity' );
        Agentshell_Test::assert_true(
            true === ( $ok['atomicity']['database_transaction'] ?? null ),
            'on InnoDB the write must report database-transaction protection'
        );
        Agentshell_Test::assert_equals(
            'InnoDB',
            $ok['atomicity']['engine'] ?? null,
            'the reported engine must be the one actually backing the tables'
        );

        $upd = cw_f( 'update_ok', array() );
        Agentshell_Test::assert_true( ( $upd['returned_id'] ?? 0 ) > 0, 'a valid update must return the post id' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $upd['db_content'] ?? '' ), 'edited body' ),
            'a valid update must be written'
        );
        Agentshell_Test::assert_equals( 'draft', $upd['db_status'] ?? '', 'a valid update must apply the status it was given' );
    }
);

Agentshell_Test::run(
    'live content write: existing content survives a failed write',
    function () {
        $u = cw_f( 'update_failure', array() );
        Agentshell_Test::assert_equals( 'invalid_page_template', $u['error'] ?? null, 'the failed update must report the real error' );
        Agentshell_Test::assert_true( $u['still_there'] ?? false, 'a failed update must not delete existing content' );
        Agentshell_Test::assert_true(
            $u['unchanged'] ?? false,
            'a failed update must leave every field of existing content as it was'
        );
    }
);

Agentshell_Test::run(
    'live content write: bad input is refused before anything is written',
    function () {
        Agentshell_Test::assert_true(
            is_string( cw_f( 'empty_refused' ) ),
            'an empty title and empty content must be refused, not written as an untitled draft'
        );
        Agentshell_Test::assert_equals( 0, (int) cw_f( 'empty_delta', -1 ), 'a refused write must leave no untitled page behind' );
        Agentshell_Test::assert_true(
            is_string( cw_f( 'unknown_field' ) ),
            'an unsupported field must still be refused'
        );
    }
);

Agentshell_Test::run(
    'live content write: the MCP tool behaves the same as the primitive',
    function () {
        $t = cw_f( 'tool', array() );
        Agentshell_Test::assert_true( ! isset( $t['exception'] ), 'the tool must not throw on a valid request: ' . ( $t['exception'] ?? '' ) );
        Agentshell_Test::assert_true( ( $t['id'] ?? 0 ) > 0, 'the tool must return a post id' );
        Agentshell_Test::assert_true( ( $t['db_title'] ?? '' ) !== '', 'the tool must write through to the database' );
        Agentshell_Test::assert_equals( '<p>tool body</p>', $t['db_content'] ?? '', 'the tool must preserve raw HTML' );
        Agentshell_Test::assert_true(
            true === ( $t['atomicity']['database_transaction'] ?? null ),
            'the tool result must carry the same measured atomicity the primitive does'
        );

        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $t['after_update_db_title'] ?? '' ), '-tool-edited' ),
            'agentshell_update_content must write through'
        );
        Agentshell_Test::assert_equals( 'publish', $t['after_update_db_status'] ?? '', 'agentshell_update_content must apply the status it was given' );
        Agentshell_Test::assert_true(
            true === ( $t['after_update_atomicity']['database_transaction'] ?? null ),
            'the update tool result must carry the same measured atomicity'
        );
    }
);

Agentshell_Test::run(
    'live content write: the site is left exactly as it was found',
    function () {
        $cl = cw_f( 'cleanup', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $cl['rows_remaining'] ?? -1 ), 'every disposable post must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $cl['stray_meta'] ?? -1 ), 'every disposable metadata row must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $cl['untitled_delta'] ?? -1 ), 'no untitled page may be left behind' );
    }
);