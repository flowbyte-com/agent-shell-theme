<?php
/**
 * Regressions for the bounded content-write guard.
 *
 * The defect is not a crash: it is a tool reporting a failed write while the
 * content is still in the database, with no post id to identify it. That is
 * invisible to a unit test that only inspects return values, so these tests
 * pin the *boundary* — which statements were issued, in what order, and what
 * the caller is told about atomicity — using a wpdb stub that records exactly
 * what the guard asked the database to do.
 *
 * The end-to-end behaviour against a real WordPress (a genuine post-insert
 * WP_Error, real rows, a real object cache) is `tests/content-write-live-test.php`.
 * This suite exists because that one skips when no install is present.
 */

require_once __DIR__ . '/bootstrap.php';

// ── WordPress surface the content primitives call ────────────────────────────
// Guarded so that defining them here cannot collide with a future suite.
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $v ) { return $v; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $p = null ) { return $p ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_the_date' ) ) {
    function get_the_date( $f = '', $p = null ) { return $p ? $p->post_date : ''; }
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
    function get_the_modified_date( $f = '', $p = null ) { return $p ? $p->post_modified : ''; }
}
if ( ! function_exists( 'get_the_excerpt' ) ) {
    function get_the_excerpt( $p = null ) { return $p ? $p->post_excerpt : ''; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $p = null ) { return 'https://example.test/?p=' . ( is_object( $p ) ? $p->ID : (int) $p ); }
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
    function wp_cache_flush() { $GLOBALS['cw_cache_flushes'] = ( $GLOBALS['cw_cache_flushes'] ?? 0 ) + 1; return true; }
}

/** Scripted result for the next wp_insert_post()/wp_update_post() call. */
$GLOBALS['cw_write_result'] = 1;

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false ) {
        $GLOBALS['cw_insert_calls'][] = $postarr;
        return $GLOBALS['cw_write_result'];
    }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false ) {
        $GLOBALS['cw_update_calls'][] = $postarr;
        return $GLOBALS['cw_write_result'];
    }
}

/**
 * A wpdb that records the transaction control statements the guard issues and
 * lets a test decide what the database would say about the tables.
 */
class CWG_wpdb extends wpdb_stub_for_tests {
    public $posts   = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $log     = array();
    public $engine  = 'InnoDB';
    public $engine_rows = null;   // null = report the two tables; array = answer with it
    public $start_fails = false;

    public function query( $sql ) {
        $trimmed = trim( (string) $sql );
        if ( preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)$/i', $trimmed ) ) {
            $this->log[] = strtoupper( $trimmed );
            if ( 'START TRANSACTION' === strtoupper( $trimmed ) && $this->start_fails ) {
                return false;
            }
            return 0;
        }
        return parent::query( $sql );
    }

    public function get_results( $sql ) {
        if ( 0 === stripos( ltrim( (string) $sql ), 'SHOW TABLE STATUS' ) ) {
            if ( null !== $this->engine_rows ) {
                return $this->engine_rows;
            }
            return array(
                (object) array( 'Name' => 'wp_posts', 'Engine' => $this->engine ),
                (object) array( 'Name' => 'wp_postmeta', 'Engine' => $this->engine ),
            );
        }
        return array();
    }
}

function cw_reset( array $db_over = array() ) {
    global $wpdb;
    $db = new CWG_wpdb();
    foreach ( $db_over as $k => $v ) {
        $db->$k = $v;
    }
    $wpdb = $db;
    $GLOBALS['wpdb'] = $db;

    $GLOBALS['cw_write_result']  = 1;
    $GLOBALS['cw_insert_calls']  = array();
    $GLOBALS['cw_update_calls']  = array();
    $GLOBALS['cw_cache_flushes'] = 0;
    $GLOBALS['_agentshell_test_posts'] = array();

    \AgentShell_MCP\Content_Write_Guard::reset();
    return $db;
}

function cw_post_object( $id, $title = 'A page', $type = 'page', $status = 'draft' ) {
    return (object) array(
        'ID'            => $id,
        'post_title'    => $title,
        'post_content'  => '<p>body</p>',
        'post_excerpt'  => '',
        'post_name'     => strtolower( $title ),
        'post_type'     => $type,
        'post_status'   => $status,
        'post_date'     => '2026-01-01 00:00:00',
        'post_modified' => '2026-01-01 00:00:00',
    );
}

function cw_refuse( callable $fn ) {
    try {
        $fn();
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
    return null;
}

require_once __DIR__ . '/../agentshell-mcp/includes/class-content-write-guard.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-content.php';

use AgentShell_MCP\Content_Primitives;
use AgentShell_MCP\Content_Write_Guard;

// ── the failure predicate ────────────────────────────────────────────────────
Agentshell_Test::run(
    'write guard: what counts as a failure',
    function () {
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( new WP_Error( 'x', 'y' ) ), 'a WP_Error is a failure' );
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( false ), 'false is a failure' );
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( null ), 'null is a failure' );
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( 0 ), 'zero is a failure' );
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( -1 ), 'a negative id is a failure' );
        Agentshell_Test::assert_true( Content_Write_Guard::is_failure( '0' ), 'a zero string is a failure' );

        Agentshell_Test::assert_false( Content_Write_Guard::is_failure( 42 ), 'a post id is a success' );
        Agentshell_Test::assert_false( Content_Write_Guard::is_failure( '42' ), 'a numeric id string is a success' );
        Agentshell_Test::assert_false( Content_Write_Guard::is_failure( array( 'id' => 42 ) ), 'a record is a success' );
        Agentshell_Test::assert_false( Content_Write_Guard::is_failure( true ), 'true is a success' );
    }
);

// ── the transaction itself ───────────────────────────────────────────────────
Agentshell_Test::run(
    'write guard: a successful write is committed',
    function () {
        $db = cw_reset();
        $out = Content_Write_Guard::run( function () { return 7; } );
        Agentshell_Test::assert_equals( 7, $out, 'the callable result must be returned unchanged' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'COMMIT' ), $db->log, 'a success must be committed inside a transaction' );
        Agentshell_Test::assert_equals( 0, $GLOBALS['cw_cache_flushes'], 'a commit must not flush the cache' );
    }
);

Agentshell_Test::run(
    'write guard: a reported failure is rolled back, and still reported',
    function () {
        $db    = cw_reset();
        $error = new WP_Error( 'invalid_page_template', 'Invalid page template.' );
        $out   = Content_Write_Guard::run( function () use ( $error ) { return $error; } );

        Agentshell_Test::assert_true( $out instanceof WP_Error, 'the failure must be handed back, not swallowed' );
        Agentshell_Test::assert_equals( 'invalid_page_template', $out->get_error_code(), 'the error code must survive' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'a failure must be rolled back, never committed' );
        Agentshell_Test::assert_equals( 1, $GLOBALS['cw_cache_flushes'], 'a rollback must drop the caches a rolled-back post primed' );
    }
);

Agentshell_Test::run(
    'write guard: a thrown failure is rolled back and rethrown unchanged',
    function () {
        $db  = cw_reset();
        $out = cw_refuse( function () {
            return Content_Write_Guard::run( function () { throw new RuntimeException( 'boom' ); } );
        } );
        Agentshell_Test::assert_equals( 'boom', $out, 'the exception must propagate with its message intact' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'a throw must roll back' );
        Agentshell_Test::assert_equals( 1, $GLOBALS['cw_cache_flushes'], 'a throw must drop the stale caches too' );
    }
);

Agentshell_Test::run(
    'write guard: a throw does not leak the nesting depth',
    function () {
        $db = cw_reset();
        cw_refuse( function () {
            return Content_Write_Guard::run( function () { throw new RuntimeException( 'boom' ); } );
        } );
        // If the depth were left raised, this write would run unguarded forever.
        Content_Write_Guard::run( function () { return 3; } );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ), $db->log, 'the next write must open its own transaction' );
    }
);

// ── nesting ──────────────────────────────────────────────────────────────────
Agentshell_Test::run(
    'write guard: a nested write joins the transaction, it does not open one',
    function () {
        $db = cw_reset();
        Content_Write_Guard::run( function () {
            return Content_Write_Guard::run( function () { return 9; } );
        } );
        Agentshell_Test::assert_equals(
            array( 'START TRANSACTION', 'COMMIT' ),
            $db->log,
            'MySQL has no nested transactions; START inside one would commit the outer write'
        );
    }
);

Agentshell_Test::run(
    'write guard: a nested failure is the outer write\'s decision, not a rollback',
    function () {
        $db = cw_reset();
        $out = cw_refuse( function () {
            return Content_Write_Guard::run( function () {
                $inner = Content_Write_Guard::run( function () { return new WP_Error( 'inner', 'nope' ); } );
                if ( $inner instanceof WP_Error ) {
                    throw new RuntimeException( 'outer saw: ' . $inner->get_error_code() );
                }
                return $inner;
            } );
        } );
        Agentshell_Test::assert_equals( 'outer saw: inner', $out, 'the outer write must be able to see and act on the inner failure' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'exactly one rollback, from the outermost write' );
    }
);

// ── when the guarantee cannot be kept ────────────────────────────────────────
Agentshell_Test::run(
    'write guard: a non-transactional engine is reported, not pretended away',
    function () {
        $db = cw_reset( array( 'engine' => 'MyISAM' ) );
        $out = Content_Write_Guard::run( function () { return 5; } );
        Agentshell_Test::assert_equals( 5, $out, 'content must still be creatable on a non-transactional engine' );
        Agentshell_Test::assert_equals( array(), $db->log, 'ROLLBACK is a no-op on MyISAM, so no transaction may be claimed' );

        $report = Content_Write_Guard::report();
        Agentshell_Test::assert_false( $report['database_transaction'], 'the report must not claim a transaction it did not have' );
        Agentshell_Test::assert_equals( null, $report['engine'], 'no engine may be named when the tables are not transactional' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['on_failure'], 'not rolled back' ),
            'the report must say plainly that a partial write is possible'
        );
    }
);

Agentshell_Test::run(
    'write guard: an unanswerable engine lookup is treated as no protection',
    function () {
        // A restricted user or a replica can fail the SHOW; answering nothing
        // must not be read as "InnoDB, probably fine".
        $db = cw_reset( array( 'engine_rows' => array() ) );
        Content_Write_Guard::run( function () { return 5; } );
        Agentshell_Test::assert_equals( array(), $db->log, 'an unknown engine must not be assumed transactional' );
        Agentshell_Test::assert_false( Content_Write_Guard::report()['database_transaction'], 'an unknown engine must not be reported as protected' );
    }
);

Agentshell_Test::run(
    'write guard: a refused START degrades honestly',
    function () {
        $db = cw_reset( array( 'start_fails' => true ) );
        $out = Content_Write_Guard::run( function () { return 5; } );
        Agentshell_Test::assert_equals( 5, $out, 'refusing to create content would be a worse failure than an unprotected one' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION' ), $db->log, 'nothing may be committed that was never opened' );
        Agentshell_Test::assert_false(
            Content_Write_Guard::report()['database_transaction'],
            'a database that would not open a transaction must not be reported as protected'
        );
    }
);

Agentshell_Test::run(
    'write guard: the report names what a rollback cannot undo',
    function () {
        cw_reset();
        Content_Write_Guard::run( function () { return 5; } );
        $report = Content_Write_Guard::report();
        Agentshell_Test::assert_true( $report['database_transaction'], 'InnoDB must be reported as protected' );
        Agentshell_Test::assert_equals( 'InnoDB', $report['engine'], 'the engine must be named' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['not_covered'], 'save_post' ),
            'a plugin\'s own writes are inside this transaction and must be disclosed'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $report['not_covered'], 'email' ),
            'side effects outside the database must be disclosed'
        );
    }
);

// ── the tools, not just the guard ────────────────────────────────────────────
Agentshell_Test::run(
    'content primitives: a successful create is committed and describes itself',
    function () {
        $db = cw_reset();
        $GLOBALS['cw_write_result']    = 12;
        $GLOBALS['_agentshell_test_posts'][12] = cw_post_object( 12, 'Real page' );

        $rec = Content_Primitives::create( 'page', 'Real page', '<p>body</p>', 'draft' );
        Agentshell_Test::assert_equals( 12, (int) $rec['id'], 'the created id must be returned' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'COMMIT' ), $db->log, 'a successful create must be committed' );
        Agentshell_Test::assert_true( $rec['atomicity']['database_transaction'], 'the record must state that it was protected' );
        Agentshell_Test::assert_equals( 'draft', $rec['status'], 'WordPress\'s own record must be returned unchanged' );
    }
);

Agentshell_Test::run(
    'content primitives: a WordPress failure leaves nothing behind',
    function () {
        $db = cw_reset();
        $GLOBALS['cw_write_result'] = new WP_Error( 'invalid_page_template', 'Invalid page template.' );

        $msg = cw_refuse( function () {
            return Content_Primitives::create( 'page', 'Doomed', '<p>body</p>', 'draft' );
        } );

        Agentshell_Test::assert_true(
            false !== strpos( (string) $msg, 'page template' ),
            'the real WordPress error must reach the caller: ' . var_export( $msg, true )
        );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'the row must not be left behind by a reported failure' );
        Agentshell_Test::assert_equals( 1, $GLOBALS['cw_cache_flushes'], 'a rolled-back post must not stay in the object cache' );
    }
);

Agentshell_Test::run(
    'content primitives: a failure *after* the insert still rolls back',
    function () {
        // WordPress says the write succeeded; reading the post back is what
        // fails. A tool reporting failure over a post that exists is the same
        // defect one step later, so it has to roll back too.
        $db = cw_reset();
        $GLOBALS['cw_write_result'] = 13;
        // Deliberately no row 13 in the test post table: require_post() fails.

        $msg = cw_refuse( function () {
            return Content_Primitives::create( 'page', 'Unreadable', '<p>body</p>', 'draft' );
        } );

        Agentshell_Test::assert_true( null !== $msg, 'an unreadable post must be reported as a failure' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'the post must not survive a reported failure' );
    }
);

Agentshell_Test::run(
    'content primitives: a successful update is committed and annotated',
    function () {
        $db = cw_reset();
        $GLOBALS['_agentshell_test_posts'][21] = cw_post_object( 21, 'Editable' );
        $GLOBALS['cw_write_result'] = 21;

        $rec = Content_Primitives::update( 21, 'page', array( 'title' => 'Edited', 'content' => '<p>new</p>' ) );
        Agentshell_Test::assert_equals( 21, (int) $rec['id'], 'the updated id must be returned' );
        Agentshell_Test::assert_equals( array( 'START TRANSACTION', 'COMMIT' ), $db->log, 'a successful update must be committed' );
        Agentshell_Test::assert_equals( 'Edited', $GLOBALS['cw_update_calls'][0]['post_title'], 'the sanitised title must be passed through to WordPress' );
        Agentshell_Test::assert_true( $rec['atomicity']['database_transaction'], 'the record must state that it was protected' );
    }
);

Agentshell_Test::run(
    'content primitives: bad input is refused before any write is attempted',
    function () {
        $db = cw_reset();

        $cases = array(
            'empty title and content' => function () {
                return Content_Primitives::create( 'page', '', '', 'draft' );
            },
            'whitespace-only title and content' => function () {
                return Content_Primitives::create( 'page', "  \n ", '   ', 'draft' );
            },
            'unsupported type' => function () {
                return Content_Primitives::create( 'widget', 'A', 'b', 'draft' );
            },
            'unsupported status' => function () {
                return Content_Primitives::create( 'page', 'A', 'b', 'archived' );
            },
            'unknown update field' => function () {
                $GLOBALS['_agentshell_test_posts'][31] = cw_post_object( 31 );
                return Content_Primitives::update( 31, 'page', array( 'author' => 4 ) );
            },
            'parent on a post' => function () {
                $GLOBALS['_agentshell_test_posts'][32] = cw_post_object( 32, 'P', 'post' );
                return Content_Primitives::update( 32, 'post', array( 'parent' => 1 ) );
            },
        );

        foreach ( $cases as $label => $case ) {
            $msg = cw_refuse( $case );
            Agentshell_Test::assert_true( null !== $msg, "{$label} must be refused" );
        }

        Agentshell_Test::assert_equals( array(), $db->log, 'refusal happens before any write, so no transaction may be opened' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['cw_insert_calls'], 'no insert may be attempted for a refused create' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['cw_update_calls'], 'no update may be attempted for a refused update' );
    }
);

Agentshell_Test::run(
    'content primitives: the record reports the engine that is actually there',
    function () {
        // The atomicity block is only worth anything if it is measured. A
        // create on a MyISAM table, and a create whose transaction the
        // database refused, must both say so in the tool result rather than
        // repeating what a successful InnoDB create said.
        $db = cw_reset( array( 'engine' => 'MyISAM' ) );
        $GLOBALS['cw_write_result'] = 51;
        $GLOBALS['_agentshell_test_posts'][51] = cw_post_object( 51, 'Unprotected' );

        $rec = Content_Primitives::create( 'page', 'Unprotected', '<p>body</p>', 'draft' );
        Agentshell_Test::assert_equals( 51, (int) $rec['id'], 'the create itself must still succeed' );
        Agentshell_Test::assert_false(
            $rec['atomicity']['database_transaction'],
            'the record must not claim a transaction the tables cannot honour'
        );
        Agentshell_Test::assert_equals( null, $rec['atomicity']['engine'], 'no engine may be named when there is none to name' );
        Agentshell_Test::assert_true(
            false !== strpos( $rec['atomicity']['on_failure'], 'not rolled back' ),
            'the record must say plainly that a partial write is possible here'
        );

        $db = cw_reset( array( 'start_fails' => true ) );
        $GLOBALS['cw_write_result'] = 52;
        $GLOBALS['_agentshell_test_posts'][52] = cw_post_object( 52, 'Refused' );

        $rec = Content_Primitives::create( 'page', 'Refused', '<p>body</p>', 'draft' );
        Agentshell_Test::assert_equals( 52, (int) $rec['id'], 'a refused START must not stop the content being created' );
        Agentshell_Test::assert_false(
            $rec['atomicity']['database_transaction'],
            'a database that would not open a transaction must not be reported as protected'
        );
    }
);

Agentshell_Test::run(
    'content primitives: a title with no content is still allowed',
    function () {
        // The refusal is aimed at "neither field says anything", not at
        // short content — WordPress stores a titled, empty-bodied post happily.
        cw_reset();
        $GLOBALS['cw_write_result'] = 41;
        $GLOBALS['_agentshell_test_posts'][41] = cw_post_object( 41, 'Just a title' );

        $rec = Content_Primitives::create( 'page', 'Just a title', '', 'draft' );
        Agentshell_Test::assert_equals( 41, (int) $rec['id'], 'a titled page with no body is a legitimate page' );
    }
);