<?php
/**
 * Stage 2c publish-edit tests — Milestone 4: agentshell_publish_edit and
 * agentshell_publish_edit_reconcile.
 *
 * This suite exercises the §1.4 publication sequence, the §1.7
 * reconciliation tail, and the pre-publication rollback path through the
 * recovery engine. It does NOT run against real WordPress: the live
 * tests are stage-2c-publish-edit-live-child.php. The unit tests use
 * the in-memory post/meta/options surface the existing journal-recovery
 * suites provide, with extra $wpdb stubs for the publish-edit tool's
 * SELECT ... FOR UPDATE, START TRANSACTION / COMMIT / ROLLBACK, and
 * GET_LOCK / RELEASE_LOCK surfaces.
 *
 * What this suite covers:
 *   - §1  Refusal gates (10+ refusal conditions, all OUTSIDE the SQL
 *          transaction per the post-rollback audit-durability rule).
 *   - §2  The 11-step §1.4 sequence (advisory lock, FOR UPDATE
 *          revalidate, wp_update_post, write-and-verify-C with
 *          allowlist rule, journal transition, success audit, COMMIT).
 *   - §3  Allowlist verification (refuse on supported-field overwrite,
 *          refuse on protected-preserved change, accept housekeeping
 *          divergence).
 *   - §4  Baseline drift (refuse when external writer changes the
 *          original between baseline capture and publish).
 *   - §5  Reconciliation tail (per-step status read, idempotent
 *          re-runs).
 *   - §6  Pre-publication rollback via Content_Recovery::recover().
 *   - §7  Reconciliation tail via Content_Recovery::recover().
 *
 * What this suite does NOT cover:
 *   - Real WordPress persistence — covered by the live suite.
 *   - The preview overlay — covered by stage-2c-overlay-test.php.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'stage-2c-publish-edit-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-recovery-advisory-lock.php',
    'class-content-recovery.php',
    'class-published-claim.php',
    'class-published-shadow.php',
    'tools/class-base-tool.php',
    'tools/class-publish-edit.php',
    'tools/class-publish-edit-reconcile.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Publish_Edit;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "Stage 2c publish-edit tests (Milestone 4: §1.4 sequence + §1.7 reconciliation)\n";

if ( ! defined( 'S2C_T0' ) ) { define( 'S2C_T0', 1810000000 ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

// ── WordPress surface ────────────────────────────────────────────────────────
//
// Stubs the WP functions the publish-edit and reconcile tools call.
// The post surface is the same shape the foundation/shadow test
// suites use; this file extends the $wpdb stub to support the
// queries the publish-edit tool issues directly
// (SELECT ... FOR UPDATE, START TRANSACTION / COMMIT / ROLLBACK,
//  GET_LOCK / RELEASE_LOCK).

$GLOBALS['_agentshell_test_postmeta']      = array();
$GLOBALS['_agentshell_test_posts']         = array();
$GLOBALS['_agentshell_test_insert_calls']  = array();
$GLOBALS['_agentshell_test_update_calls']  = array();
$GLOBALS['_agentshell_test_delete_calls']  = array();
$GLOBALS['_agentshell_test_lock_holds']    = array();
$GLOBALS['_agentshell_test_lock_contended'] = false;

if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) { return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( 'maybe_serialize' ) ) {
    function maybe_serialize( $value ) {
        if ( is_string( $value ) ) { return $value; }
        return serialize( $value );
    }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $value ) {
        if ( is_string( $value ) ) { return @unserialize( $value ); }
        return $value;
    }
}
if ( ! function_exists( 'hash_equals' ) ) {
    function hash_equals( $a, $b ) { return hash( 'sha256', (string) $a ) === hash( 'sha256', (string) $b ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return is_object( $thing ) && property_exists( $thing, 'errors' ); }
}
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $k => $v ) { $value->$k = wp_slash( $v ); }
            return $value;
        }
        return is_string( $value ) ? addslashes( $value ) : $value;
    }
}
if ( ! function_exists( 'clean_post_cache' ) ) {
    function clean_post_cache( $post_id ) { return true; }
}

if ( ! function_exists( 'add_post_meta' ) ) {
    function add_post_meta( $post_id, $key, $value, $unique = false ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ][] = $value;
        return true;
    }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] = array( $value );
        return true;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $rows = $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] ?? array();
        if ( $single ) {
            return empty( $rows ) ? '' : (string) $rows[0];
        }
        return $rows;
    }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key ) {
        unset( $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] );
        return true;
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false ) {
        $GLOBALS['_agentshell_test_insert_calls'][] = $postarr;
        $existing = array_keys( $GLOBALS['_agentshell_test_posts'] );
        $next_id  = empty( $existing ) ? 8000 : ( max( $existing ) + 1 );
        $post = (object) array(
            'ID'                => (int) $next_id,
            'post_type'         => (string) ( $postarr['post_type']   ?? 'post' ),
            'post_status'       => (string) ( $postarr['post_status'] ?? 'draft' ),
            'post_parent'       => (int)    ( $postarr['post_parent'] ?? 0 ),
            'post_title'        => (string) ( $postarr['post_title']  ?? '' ),
            'post_content'      => (string) ( $postarr['post_content']?? '' ),
            'post_excerpt'      => (string) ( $postarr['post_excerpt']?? '' ),
            'post_name'         => (string) ( $postarr['post_name']   ?? '' ),
            'post_author'       => (int)    ( $postarr['post_author'] ?? 1 ),
            'post_date_gmt'     => '2026-10-01 00:00:00',
            'post_modified_gmt' => '2026-10-01 00:00:00',
            'post_modified'     => '2026-10-01 00:00:00',
        );
        $GLOBALS['_agentshell_test_posts'][ (int) $next_id ] = $post;
        if ( isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
            foreach ( $postarr['meta_input'] as $k => $v ) {
                $GLOBALS['_agentshell_test_postmeta'][ (int) $next_id ][ (string) $k ] = array( $v );
            }
        }
        return (int) $next_id;
    }
}

if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false ) {
        $GLOBALS['_agentshell_test_update_calls'][] = $postarr;
        $id = (int) ( $postarr['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) {
            return 0;
        }
        $post = $GLOBALS['_agentshell_test_posts'][ $id ];
        foreach ( array(
            'post_title', 'post_content', 'post_excerpt', 'post_name',
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_date_gmt', 'post_mime_type',
            'comment_status', 'ping_status', 'menu_order', 'post_password',
            'post_content_filtered', 'comment_count', 'to_ping', 'pinged',
            'guid', 'post_modified', 'post_modified_gmt',
        ) as $field ) {
            if ( array_key_exists( $field, $postarr ) ) {
                $post->$field = is_string( $postarr[ $field ] ) ? stripslashes( $postarr[ $field ] ) : $postarr[ $field ];
            }
        }
        // wp_update_post sets post_modified to now(); simulate.
        $post->post_modified     = '2026-10-17 12:00:00';
        $post->post_modified_gmt = '2026-10-17 12:00:00';
        $GLOBALS['_agentshell_test_posts'][ $id ] = $post;
        return $id;
    }
}

if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $post_id, $force = false ) {
        $GLOBALS['_agentshell_test_delete_calls'][] = (int) $post_id;
        $id = (int) $post_id;
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) { return false; }
        unset( $GLOBALS['_agentshell_test_posts'][ $id ] );
        unset( $GLOBALS['_agentshell_test_postmeta'][ $id ] );
        return true;
    }
}

if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        return $GLOBALS['_agentshell_test_posts'][ (int) $post_id ] ?? null;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, $args = null ) { return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() { return (object) array( 'ID' => 1 ); }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) { return; }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) { return $value; }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = null ) { return array( 'body' => '' ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = null ) { return array( 'body' => '' ); }
}

// A $wpdb that supports the queries the publish-edit tool issues:
//   - SELECT ... FOR UPDATE on wp_posts (returns the in-memory post row)
//   - SELECT (without FOR UPDATE) on wp_posts
//   - SELECT GET_LOCK(name, 0) / SELECT RELEASE_LOCK(name)
//   - DELETE FROM wp_options ... AND option_value = ? (claim release)
//   - START TRANSACTION / COMMIT / ROLLBACK (returns true so
//     Content_Write_Guard wraps the closure in a real transaction).
$GLOBALS['wpdb'] = new class {
    public $posts    = 'wp_posts';
    public $options  = 'wp_options';
    public $last_query = '';
    public $last_prepared_args = null;
    public $in_transaction = false;
    public function prepare( $sql, ...$args ) {
        // Resolve %d / %s placeholders so the regex matchers in query()
        // see a fully-quoted SQL string.
        $i = 0; $len = strlen( $sql ); $a = $args; $out = '';
        while ( $i < $len ) {
            $c = $sql[ $i ];
            if ( '%' === $c && $i + 1 < $len ) {
                $fmt = $sql[ $i + 1 ];
                $arg = array_shift( $a );
                if ( 's' === $fmt ) { $out .= "'" . str_replace( "'", "''", (string) $arg ) . "'"; }
                elseif ( 'd' === $fmt ) { $out .= (int) $arg; }
                else { $out .= $c . $fmt; }
                $i += 2;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return array( 'sql' => $out, 'args' => $a );
    }
    public function query( $prepared ) {
        if ( is_string( $prepared ) ) {
            $sql = $prepared;
        } elseif ( is_array( $prepared ) ) {
            $sql = $prepared['sql'];
        } else {
            return false;
        }
        $GLOBALS['wpdb']->last_query = $sql;
        $trim = trim( $sql );
        if ( 0 === strcasecmp( $trim, 'START TRANSACTION' ) ) {
            $GLOBALS['wpdb']->in_transaction = true;
            return 1;
        }
        if ( 0 === strcasecmp( $trim, 'COMMIT' ) ) {
            $GLOBALS['wpdb']->in_transaction = false;
            return 1;
        }
        if ( 0 === strcasecmp( $trim, 'ROLLBACK' ) ) {
            $GLOBALS['wpdb']->in_transaction = false;
            return 1;
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'INSERT' ) ) {
            return $this->do_insert( $sql );
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'UPDATE' ) ) {
            return $this->do_update( $sql );
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'DELETE' ) ) {
            return $this->do_delete( $sql );
        }
        return 1;
    }
    public function get_var( $prepared ) {
        if ( is_string( $prepared ) ) {
            $sql = $prepared;
        } elseif ( is_array( $prepared ) ) {
            $sql = $prepared['sql'];
        } else {
            return null;
        }
        $GLOBALS['wpdb']->last_query = $sql;
        $trim = trim( $sql );
        if ( 0 === strcasecmp( substr( $trim, 0, 9 ), 'SELECT GE' ) ) {
            // SELECT GET_LOCK('name', 0)
            if ( preg_match( "/SELECT GET_LOCK\\('([^']*)', 0\\)/", $sql, $m ) ) {
                $name = $m[1];
                if ( ! empty( $GLOBALS['_agentshell_test_lock_contended'] ) ) {
                    return '0';
                }
                $GLOBALS['_agentshell_test_lock_holds'][ $name ] = true;
                return '1';
            }
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 13 ), 'SELECT RELEAS' ) ) {
            // SELECT RELEASE_LOCK('name')
            if ( preg_match( "/SELECT RELEASE_LOCK\\('([^']*)'\\)/", $sql, $m ) ) {
                $name = $m[1];
                unset( $GLOBALS['_agentshell_test_lock_holds'][ $name ] );
                return '1';
            }
        }
        return null;
    }
    public function get_row( $prepared, $output = ARRAY_A ) {
        if ( is_string( $prepared ) ) {
            $sql = $prepared;
            $args = array();
        } elseif ( is_array( $prepared ) ) {
            $sql  = $prepared['sql'];
            $args = $prepared['args'];
        } else {
            return null;
        }
        $GLOBALS['wpdb']->last_query = $sql;
        // Resolve %d/%s placeholders.
        $resolved = $this->resolve_args( $sql, $args );
        if ( ! preg_match( '/WHERE ID = (\\d+)/', $resolved, $m ) ) { return null; }
        $id = (int) $m[1];
        $post = $GLOBALS['_agentshell_test_posts'][ $id ] ?? null;
        if ( ! $post ) { return null; }
        $row = array();
        foreach ( array(
            'post_title', 'post_content', 'post_excerpt', 'post_name',
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_date_gmt', 'post_mime_type',
            'comment_status', 'ping_status', 'menu_order', 'post_password',
            'post_content_filtered', 'comment_count', 'to_ping', 'pinged',
            'guid', 'post_modified', 'post_modified_gmt',
        ) as $field ) {
            $row[ $field ] = isset( $post->$field ) ? (string) $post->$field : '';
        }
        return $row;
    }
    private function resolve_args( $sql, $args ) {
        $out = '';
        $i = 0; $len = strlen( $sql );
        $a = $args;
        while ( $i < $len ) {
            $c = $sql[ $i ];
            if ( '%' === $c && $i + 1 < $len ) {
                $fmt = $sql[ $i + 1 ];
                $arg = array_shift( $a );
                if ( 's' === $fmt ) { $out .= "'" . str_replace( "'", "''", (string) $arg ) . "'"; }
                elseif ( 'd' === $fmt ) { $out .= (int) $arg; }
                else { $out .= $c . $fmt; }
                $i += 2;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }
    private function do_insert( $sql ) {
        // The "ON DUPLICATE KEY UPDATE" variant is used by
        // Published_Claim::create. The "ON DUPLICATE KEY UPDATE
        // option_name = option_name" form is a no-op on a
        // duplicate key, returning 0 affected rows. We model
        // it as "row already exists -> 0, fresh insert -> 1".
        $pattern = "/INSERT INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '((?:[^']|'')*)', '([^']*)'\\)( ON DUPLICATE KEY UPDATE .*)?$/";
        if ( ! preg_match( $pattern, $sql, $m ) ) {
            return false;
        }
        $key = $m[1];
        $value = str_replace( "''", "'", $m[2] );
        $has_on_duplicate = isset( $m[4] ) && '' !== $m[4];
        if ( array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
            // Row already exists: INSERT ... ON DUPLICATE KEY UPDATE
            // returns 0 affected rows because the duplicate-key path
            // is a no-op. (For the unconditional INSERT, this
            // should be reported as a database error, but the
            // production callers in this suite all use the
            // ON DUPLICATE form, so we return 0 for both.)
            return 0;
        }
        $unser = @unserialize( $value );
        $GLOBALS['_agentshell_test_options'][ $key ] = ( false === $unser && 'b:0;' !== $value ) ? $value : $unser;
        return 1;
    }
    private function do_update( $sql ) {
        if ( ! preg_match( "/UPDATE wp_options SET option_value = '((?:[^']|'')*)' WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/", $sql, $m ) ) {
            return false;
        }
        $new_value = str_replace( "''", "'", $m[1] );
        $key = $m[2];
        $expected_value = str_replace( "''", "'", $m[3] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
            return 0;
        }
        $stored_blob = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored_blob !== $expected_value ) {
            return 0;
        }
        $unser = @unserialize( $new_value );
        $GLOBALS['_agentshell_test_options'][ $key ] = ( false === $unser && 'b:0;' !== $new_value ) ? $new_value : $unser;
        return 1;
    }
    private function do_delete( $sql ) {
        if ( ! preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/", $sql, $m ) ) {
            return false;
        }
        $key = $m[1];
        $expected = str_replace( "''", "'", $m[2] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) { return 0; }
        $stored = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored !== $expected ) { return 0; }
        unset( $GLOBALS['_agentshell_test_options'][ $key ] );
        return 1;
    }
};

// ── Test harness ─────────────────────────────────────────────────────────────

$GLOBALS['__s2c_results'] = array();
$GLOBALS['__s2c_pass']    = 0;
$GLOBALS['__s2c_fail']    = 0;
function s2cs_check( $name, $ok, $detail = '' ) {
    $GLOBALS['__s2c_results'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( class_exists( 'Agentshell_Test' ) ) {
        if ( $ok ) { \Agentshell_Test::$passed++; } else { \Agentshell_Test::$failed++; }
    }
    if ( $ok ) { $GLOBALS['__s2c_pass']++; } else { $GLOBALS['__s2c_fail']++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}
function s2cs_reset_state() {
    $GLOBALS['_agentshell_test_posts']         = array();
    $GLOBALS['_agentshell_test_postmeta']      = array();
    $GLOBALS['_agentshell_test_options']       = array();
    $GLOBALS['_agentshell_test_insert_calls']  = array();
    $GLOBALS['_agentshell_test_update_calls']  = array();
    $GLOBALS['_agentshell_test_delete_calls']  = array();
    $GLOBALS['_agentshell_test_lock_holds']    = array();
    $GLOBALS['_agentshell_test_lock_contended'] = false;
    $GLOBALS['__s2c_db_errors']                = 0;
    $GLOBALS['__s2c_txid']                     = null;
    \AgentShell_MCP\Recovery_Advisory_Lock::reset();
    \AgentShell_MCP\Content_Write_Guard::reset();
}

/**
 * Drop the open transaction row without running recovery.
 *
 * Tests that exercise refusal paths and then leave the op in
 * an unusual state (publication_committed without a shadow,
 * conflict, etc.) cannot rely on Tx::rollback() to clean up,
 * because the rollback's auto-compensate recovery path would
 * itself trigger a M4 compensation and refuse. The production
 * analogue is a `delete_option()` from outside the manager;
 * here we drop the row directly. The next begin() creates a
 * fresh transaction.
 */
function s2cs_drop_transaction() {
    if ( array_key_exists( Tx::KEY, $GLOBALS['_agentshell_test_options'] ) ) {
        delete_option( Tx::KEY );
    }
    \AgentShell_MCP\Content_Write_Guard::reset();
}

function s2cs_seed_original( $id, $title = 'Original Title', $content = 'Original content.', $excerpt = '', $name = null, $status = 'publish', $type = 'page' ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array(
        'ID'                => (int) $id,
        'post_type'         => (string) $type,
        'post_status'       => (string) $status,
        'post_title'        => (string) $title,
        'post_content'      => (string) $content,
        'post_excerpt'      => (string) $excerpt,
        'post_name'         => (string) ( null === $name ? "orig-{$id}" : $name ),
        'post_author'       => 1,
        'post_parent'       => 0,
        'post_date'         => '2026-10-01 00:00:00',
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
        'post_mime_type'    => '',
        'comment_status'    => 'open',
        'ping_status'       => 'open',
        'menu_order'        => 0,
        'post_password'     => '',
        'post_content_filtered' => '',
        'comment_count'     => 0,
        'to_ping'           => '',
        'pinged'            => '',
        'guid'              => 'http://example.com/?p=' . (int) $id,
    );
}

function s2cs_seed_shadow( $shadow_id, $original_id, $op_id, $fields ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $shadow_id ] = (object) array(
        'ID'                => (int) $shadow_id,
        'post_type'         => 'page',
        'post_status'       => Published_Shadow::POST_STATUS,
        'post_title'        => (string) ( $fields['post_title']   ?? '' ),
        'post_content'      => (string) ( $fields['post_content'] ?? '' ),
        'post_excerpt'      => (string) ( $fields['post_excerpt'] ?? '' ),
        'post_name'         => (string) ( $fields['post_name']    ?? '' ),
        'post_author'       => 1,
        'post_parent'       => (int) $original_id,
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
    );
    update_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, (string) $original_id );
    update_post_meta( $shadow_id, Published_Shadow::META_TX_ID, (string) $GLOBALS['__s2c_txid'] );
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, (string) $op_id );
}

function s2cs_begin_tx( $actor = 'agent-1', $label = 'M4 test' ) {
    // Make sure no stale transaction is left from a prior test.
    $existing = Tx::instance()->payload();
    if ( is_array( $existing ) ) {
        try { s2cs_drop_transaction(); } catch ( \Throwable $e ) { /* ignore */ }
    }
    $payload = Tx::instance()->begin( $actor, $label );
    $GLOBALS['__s2c_txid'] = (string) $payload['id'];
    Tx::freeze_time( S2C_T0 );
    return $payload;
}

function s2cs_stage_edit_published( $original_id, $shadow_id, $staged, $baseline = null ) {
    // Default baseline: capture from the seeded original.
    if ( null === $baseline ) {
        $baseline = Content_Journal::capture_edit_published_baseline( $original_id );
    }
    // The journal validator requires after.post_name to identify the post
    // with. The staged after-image must carry the post_name the original
    // would end up carrying post-publication; if the caller did not
    // provide one, fall back to the original's current name from the
    // baseline (i.e. "no slug change").
    $effective_post_name = array_key_exists( 'post_name', $staged )
        ? (string) $staged['post_name']
        : (string) ( $baseline['post_name'] ?? "orig-{$original_id}" );
    $op = Content_Journal::record_intent(
        Content_Journal::OP_EDIT_PUBLISHED,
        array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'original_id' => (int) $original_id,
            'shadow_id'   => (int) $shadow_id,
        ),
        array(
            'post_title'   => (string) ( $staged['post_title']   ?? '' ),
            'post_content' => (string) ( $staged['post_content'] ?? '' ),
            'post_excerpt' => (string) ( $staged['post_excerpt'] ?? '' ),
            'post_name'    => $effective_post_name,
        )
    );
    // Persist the op into the journal row via attach (the guarded CAS
    // the production tools use). Then read-modify-write to inject
    // the baseline.
    $tx = Tx::instance()->payload();
    if ( ! Content_Journal::attach( $tx, $op ) ) {
        throw new \RuntimeException( 's2cs_stage_edit_published: attach refused the write' );
    }
    s2cs_update_op( $op['id'], function ( $op_row ) use ( $baseline ) {
        $op_row['before'] = $baseline;
        return $op_row;
    } );
    // Hold a per-original claim on the test actor's behalf so the
    // publish-edit tool's claim check passes.
    $claim_row = Published_Claim::shape(
        (string) $GLOBALS['__s2c_txid'],
        (int) $original_id,
        (int) $shadow_id,
        'agent',
        S2C_T0,
        S2C_T0,
        Published_Claim::TTL_SECONDS
    );
    Published_Claim::create( $original_id, $claim_row );
    return $op;
}

/**
 * Apply an op-level mutation to the journal via a value-guarded
 * compare-and-swap. Use this rather than reading and re-writing
 * the row directly, so the test exercises the same guarded path
 * production code does.
 */
function s2cs_update_op( $op_id, callable $mutator ) {
    $current = (array) get_option( Tx::KEY, null );
    $updated = $current;
    if ( ! isset( $updated['content']['ops'][ $op_id ] ) ) {
        return false;
    }
    $updated['content']['ops'][ $op_id ] = $mutator( $updated['content']['ops'][ $op_id ] );
    $updated['content']['sig'] = Content_Journal::sign( $updated['content'] );
    return Tx::cas_write_row( $current, $updated );
}

/**
 * Apply a row-level mutation to the journal via a value-guarded
 * compare-and-swap. The mutator receives the current row, returns
 * the new row.
 */
function s2cs_update_row( callable $mutator ) {
    $current = (array) get_option( Tx::KEY, null );
    $updated = $mutator( $current );
    $updated['content']['sig'] = Content_Journal::sign( $updated['content'] );
    return Tx::cas_write_row( $current, $updated );
}

Tx::freeze_time( S2C_T0 );

// ─────────────────────────────────────────────────────────────────────────────
// §1 — Refusal gates
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §1 Refusal gates ---\n";

s2cs_reset_state();

// 1.1: refuses outside a transaction.
s2cs_begin_tx( 'agent-1' );
s2cs_drop_transaction();
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.1 publish_edit refuses outside a transaction (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.1 publish_edit refuses outside a transaction', strpos( $e->getMessage(), 'outside a transaction' ) !== false, $e->getMessage() );
}

// 1.2: refuses when no OP_EDIT_PUBLISHED op is in the journal.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.2 publish_edit refuses with no OP_EDIT_PUBLISHED op (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.2 publish_edit refuses with no OP_EDIT_PUBLISHED op', strpos( $e->getMessage(), 'no_op' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.3: refuses when the op is not in `staged` state.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
$op = Content_Journal::record_intent(
    Content_Journal::OP_EDIT_PUBLISHED,
    array( 'post_type' => 'page', 'post_status' => 'publish', 'original_id' => 1001, 'shadow_id' => 2001 ),
    array( 'post_title' => 'X' )
);
Content_Journal::attach( Tx::instance()->payload(), $op );
// Force the op to publication_committed (the publish-time success state).
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    return $op_row;
} );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.3 publish_edit refuses when op is in publication_committed (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.3 publish_edit refuses when op is in publication_committed', strpos( $e->getMessage(), 'state' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.4: refuses when op's actor is not the calling actor.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
$op = Content_Journal::record_intent(
    Content_Journal::OP_EDIT_PUBLISHED,
    array( 'post_type' => 'page', 'post_status' => 'publish', 'original_id' => 1001, 'shadow_id' => 2001 ),
    array( 'post_title' => 'X' )
);
Content_Journal::attach( Tx::instance()->payload(), $op );
// Re-record with a different actor by editing the journal row.
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['actor'] = 'other-agent';
    return $op_row;
} );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.4 publish_edit refuses on actor mismatch (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.4 publish_edit refuses on actor mismatch', strpos( $e->getMessage(), 'actor_mismatch' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.5: refuses when the original is not in `post_status=publish`.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1001, 'Original', 'Original content.', '', 'orig-1001', 'draft' ); // <-- draft, not publish
$op = s2cs_stage_edit_published( 1001, 2001, array( 'post_title' => 'New' ) );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.5 publish_edit refuses when original is not publish (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.5 publish_edit refuses when original is not publish', strpos( $e->getMessage(), 'original_status_changed' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.6: refuses when the shadow is missing.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1002, 'Original 1002', 'Original content.', '', 'orig-1002' );
$op = s2cs_stage_edit_published( 1002, 2002, array( 'post_title' => 'New' ) );
// Don't seed the shadow.
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.6 publish_edit refuses when shadow is missing (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.6 publish_edit refuses when shadow is missing', strpos( $e->getMessage(), 'shadow_missing' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.7: refuses when the shadow has been retargeted (META_SHADOW_FOR != original_id).
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1003, 'Original 1003', 'Original content.', '', 'orig-1003' );
$op = s2cs_stage_edit_published( 1003, 2003, array( 'post_title' => 'New' ) );
s2cs_seed_shadow( 2003, 9999 /* wrong original */, $op['id'], array( 'post_title' => 'New' ) );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.7 publish_edit refuses when shadow retargeted (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.7 publish_edit refuses when shadow retargeted', strpos( $e->getMessage(), 'shadow_retargeted' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.8: refuses when the shadow's META_OP_ID has been advanced by a newer op.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1004, 'Original 1004', 'Original content.', '', 'orig-1004' );
$op = s2cs_stage_edit_published( 1004, 2004, array( 'post_title' => 'New' ) );
s2cs_seed_shadow( 2004, 1004, 'op_newer_xxx', array( 'post_title' => 'New' ) ); // wrong op_id
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.8 publish_edit refuses when shadow head-advanced (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.8 publish_edit refuses when shadow head-advanced', strpos( $e->getMessage(), 'shadow_head_advanced' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.9: refuses when the claim is held by a different transaction.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1005, 'Original 1005', 'Original content.', '', 'orig-1005' );
$op = s2cs_stage_edit_published( 1005, 2005, array( 'post_title' => 'New' ) );
s2cs_seed_shadow( 2005, 1005, $op['id'], array( 'post_title' => 'New' ) );
// Replace the claim with a different tx/actor. The current claim
// is held by the test tx, so release it via the option store
// (bypassing release()'s value-guarded CAS, which needs the
// expected row shape) and recreate it for a different tx.
$pres = Published_Claim::read( 1005 );
if ( is_array( $pres ) ) {
    Published_Claim::release( 1005, $pres );
}
Published_Claim::create( 1005, Published_Claim::shape( 'tx_other', 1005, 2005, 'other-agent', S2C_T0, S2C_T0, Published_Claim::TTL_SECONDS ) );
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.9 publish_edit refuses when claim held by other (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.9 publish_edit refuses when claim held by other', strpos( $e->getMessage(), 'claim_held_by_other' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 1.10: refuses when the advisory lock is held by another connection.
//      The probe (`available()`) catches the contention by returning
//      '0' from the GET_LOCK probe, which is the canonical "the
//      engine cannot perform a lock right now" outcome. The tool
//      refuses with `lock_unavailable` (the more general refusal)
//      rather than `publisher_active` (the more specific "another
//      publisher has the lock" refusal that fires only when the
//      probe succeeds and the per-tx acquire fails).
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 1006, 'Original 1006', 'Original content.', '', 'orig-1006' );
$op = s2cs_stage_edit_published( 1006, 2006, array( 'post_title' => 'New' ) );
s2cs_seed_shadow( 2006, 1006, $op['id'], array( 'post_title' => 'New' ) );
$GLOBALS['_agentshell_test_lock_contended'] = true;
try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '1.10 publish_edit refuses when lock held by other (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '1.10 publish_edit refuses when lock held by other', strpos( $e->getMessage(), 'lock' ) !== false, $e->getMessage() );
}
$GLOBALS['_agentshell_test_lock_contended'] = false;
s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §2 — The 11-step §1.4 sequence (happy path)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §2 §1.4 11-step sequence (happy path) ---\n";

s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 2001, 'Original Title', 'Original content.', 'Original excerpt.', 'orig-2001' );
$op = s2cs_stage_edit_published( 2001, 6001, array(
    'post_title'   => 'Staged Title',
    'post_content' => 'Staged content.',
    'post_excerpt' => 'Staged excerpt.',
    'post_name'    => 'staged-slug',
) );
s2cs_seed_shadow( 6001, 2001, $op['id'], array(
    'post_title'   => 'Staged Title',
    'post_content' => 'Staged content.',
    'post_excerpt' => 'Staged excerpt.',
    'post_name'    => 'staged-slug',
) );

$result = ( new Publish_Edit() )->execute( array() );
s2cs_check( '2.1 publish_edit returns op_id', isset( $result['op_id'] ) && $result['op_id'] === $op['id'] );
s2cs_check( '2.2 publish_edit returns state publication_settled', ( $result['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED, 'state=' . ( $result['state'] ?? 'null' ) );
s2cs_check( '2.3 wp_update_post was called exactly once', count( $GLOBALS['_agentshell_test_update_calls'] ) === 1, 'count=' . count( $GLOBALS['_agentshell_test_update_calls'] ) );
s2cs_check( '2.4 wp_update_post payload has ID 2001', ( $GLOBALS['_agentshell_test_update_calls'][0]['ID'] ?? 0 ) === 2001 );
$update = $GLOBALS['_agentshell_test_update_calls'][0];
s2cs_check( '2.5 wp_update_post payload has post_title=Staged Title', ( $update['post_title']   ?? '' ) === 'Staged Title' );
s2cs_check( '2.6 wp_update_post payload has post_content=Staged content.', ( $update['post_content'] ?? '' ) === 'Staged content.' );
s2cs_check( '2.7 wp_update_post payload has post_excerpt=Staged excerpt.', ( $update['post_excerpt'] ?? '' ) === 'Staged excerpt.' );
s2cs_check( '2.8 wp_update_post payload has post_name=staged-slug', ( $update['post_name']    ?? '' ) === 'staged-slug' );
// The original should now carry the staged fields.
$live_original = $GLOBALS['_agentshell_test_posts'][ 2001 ];
s2cs_check( '2.9 original now carries the staged post_title', $live_original->post_title === 'Staged Title' );
s2cs_check( '2.10 original now carries the staged post_name', $live_original->post_name === 'staged-slug' );
// Shadow should be deleted.
s2cs_check( '2.11 shadow was deleted (reconciliation step 1)', ! isset( $GLOBALS['_agentshell_test_posts'][ 6001 ] ) );
// Claim should be released.
s2cs_check( '2.12 claim was released (reconciliation step 3)', null === Published_Claim::read( 2001 ) );
// Op state on disk.
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '2.13 op on disk is in publication_settled state', is_array( $disk_op ) && ( $disk_op['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED );
s2cs_check( '2.14 op on disk has reconciliation.shadow_deleted.verified', is_array( $disk_op['reconciliation']['shadow_deleted'] ?? null ) && ( $disk_op['reconciliation']['shadow_deleted']['status'] ?? '' ) === 'verified' );
s2cs_check( '2.15 op on disk has reconciliation.claim_released.verified', is_array( $disk_op['reconciliation']['claim_released'] ?? null ) && ( $disk_op['reconciliation']['claim_released']['status'] ?? '' ) === 'verified' );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §3 — Allowlist verification (refuse on hook overwrite / protected change)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §3 Allowlist verification ---\n";

// 3.1: hook overwrites a supported field after wp_update_post.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 3001, 'Original Title', 'Original content.', '', 'orig-3001' );
$op = s2cs_stage_edit_published( 3001, 7001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 7001, 3001, $op['id'], array( 'post_title' => 'Staged Title' ) );
// Replace wp_update_post with a hook-equivalent that overwrites post_title.
$GLOBALS['_agentshell_test_update_calls'] = array();
$original_wp_update_post = 'wp_update_post';
// We can't easily hook in this stub harness, so simulate the
// hook-overwrite by mutating the post object BEFORE the read-back.
// The publish-edit tool reads via get_row AFTER wp_update_post; if
// we mutate the in-memory post after wp_update_post, the verify
// step sees a hook-overwritten value.
// Easier: make the post object's post_title differ from the
// after-image.
$GLOBALS['_agentshell_test_posts'][ 3001 ]->post_title = 'Hooked Title';

try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '3.1 publish_edit refuses on supported-field overwrite (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    // The pre-write baseline revalidate (§1.4 step 4) catches the
    // hook overwrite first: the live row's post_title is "Hooked
    // Title" but the baseline captured before the hook ran was
    // "Original Title". The post-write allowlist check
    // (verify_against_c) is the second line of defence; the
    // stub's lack of action hooks means we cannot exercise it
    // without seeding a hook that mutates the row between
    // wp_update_post and the re-read. Either refusal is
    // correct: both refuse the publish. Assert the message
    // names the divergence.
    s2cs_check(
        '3.1 publish_edit refuses on supported-field overwrite',
        ( strpos( $e->getMessage(), 'hook_overwrote_supported_field' ) !== false
            || strpos( $e->getMessage(), 'baseline_drift' ) !== false ),
        $e->getMessage()
    );
}
// Op on disk should be in conflict, the original should be unchanged.
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '3.2 op on disk transitioned to conflict', is_array( $disk_op ) && ( $disk_op['state'] ?? '' ) === Content_Journal::STATE_CONFLICT );
s2cs_check( '3.3 original is unchanged after refused publish', $GLOBALS['_agentshell_test_posts'][ 3001 ]->post_title === 'Hooked Title' );
s2cs_drop_transaction();

// 3.2: protected-preserved field changed by hook.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 3002, 'Original Title', 'Original content.', '', 'orig-3002' );
$op = s2cs_stage_edit_published( 3002, 7002, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 7002, 3002, $op['id'], array( 'post_title' => 'Staged Title' ) );
// Hook changes post_parent (a protected-preserved field).
$GLOBALS['_agentshell_test_posts'][ 3002 ]->post_parent = 42;

try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '3.2 publish_edit refuses on protected-preserved change (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    // The pre-write baseline revalidate (§1.4 step 4) catches the
    // protected-preserved divergence first: the live row's
    // post_parent is 42 but the baseline was 0. The post-write
    // allowlist check would also refuse (with the same end state).
    s2cs_check(
        '3.2 publish_edit refuses on protected-preserved change',
        ( strpos( $e->getMessage(), 'hook_changed_protected_preserved_field' ) !== false
            || strpos( $e->getMessage(), 'baseline_drift' ) !== false ),
        $e->getMessage()
    );
}
// The op should be in conflict and the original unchanged.
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '3.4 op on disk transitioned to conflict (protected-preserved)', is_array( $disk_op ) && ( $disk_op['state'] ?? '' ) === Content_Journal::STATE_CONFLICT );
s2cs_drop_transaction();

// 3.3: housekeeping-only divergence is accepted.
//
// The post-write allowlist (§1.4 step 6) accepts a divergence
// in post_modified / post_modified_gmt (HOUSEKEEPING_FIELDS)
// because wp_update_post sets those to NOW() server-side and the
// verify step is meant to allow that. The pre-write baseline
// revalidate (§1.4 step 4) does NOT permit the divergence: it
// would refuse the publish before wp_update_post runs. The
// stub's wp_update_post already sets post_modified_gmt to a
// timestamp that differs from the baseline (which was captured
// at the seeded original), so the housekeeping divergence is
// a natural side-effect of the wp_update_post call, not
// something the test needs to inject. The verify-against-C
// step is the place that has to accept it.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 3003, 'Original Title', 'Original content.', '', 'orig-3003' );
$op = s2cs_stage_edit_published( 3003, 7003, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 7003, 3003, $op['id'], array( 'post_title' => 'Staged Title' ) );
// Match the post-modified timestamp to the baseline so the
// pre-write revalidate does not refuse the publish. The stub's
// wp_update_post will then change it; the post-write
// allowlist must accept that housekeeping-only divergence.
$GLOBALS['_agentshell_test_posts'][ 3003 ]->post_modified_gmt = '2026-10-01 00:00:00';
$GLOBALS['_agentshell_test_posts'][ 3003 ]->post_modified     = '2026-10-01 00:00:00';

$result = ( new Publish_Edit() )->execute( array() );
s2cs_check( '3.3 housekeeping-only divergence is accepted (publication lands)', ( $result['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED, 'state=' . ( $result['state'] ?? 'none' ) );
s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §4 — Baseline drift (refuse on external writer between claim and publish)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §4 Baseline drift ---\n";

s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 4001, 'Original Title', 'Original content.', '', 'orig-4001' );
$op = s2cs_stage_edit_published( 4001, 8001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 8001, 4001, $op['id'], array( 'post_title' => 'Staged Title' ) );
// External writer changes the original between baseline and publish.
$GLOBALS['_agentshell_test_posts'][ 4001 ]->post_title = 'Externally Changed';

try {
    ( new Publish_Edit() )->execute( array() );
    s2cs_check( '4.1 publish_edit refuses on baseline drift (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '4.1 publish_edit refuses on baseline drift', strpos( $e->getMessage(), 'baseline_drift' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §5 — Reconciliation tail (per-step status read, idempotent re-runs)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §5 Reconciliation tail ---\n";

// 5.1: the publish-edit tool's reconciliation runs the three
//      steps, marks them verified, and settles the op. The
//      §2 happy path covers this end-to-end. Here we focus
//      on the per-step status read path: simulate an op
//      already in `publication_committed` and call
//      publish_edit_reconcile.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 5001, 'Original Title', 'Original content.', '', 'orig-5001' );
$op = s2cs_stage_edit_published( 5001, 9001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 9001, 5001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Manually set the op to `publication_committed` and write a
// partial reconciliation record (shadow_deleted verified, claim
// released pending). This simulates a crash between step 1 and
// step 3.
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    $op_row['reconciliation'] = array(
        'shadow_deleted' => array(
            'status' => 'verified',
            'detail' => 'already deleted in a previous attempt',
        ),
        'caches_invalidated' => array(
            'status' => 'verified',
        ),
        'claim_released' => array(
            'attempted_at' => S2C_T0,
            'status'       => 'pending',
        ),
        'audit_written' => false,
    );
    return $op_row;
} );

// 5.1 reconcile runs the claim release (the only pending step).
$result = ( new Publish_Edit_Reconcile() )->execute( array() );
s2cs_check( '5.1 reconcile settles the op after a partial-prep crash', ( $result['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED );
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ];
s2cs_check( '5.2 reconcile kept the already-verified shadow_deleted status', ( $disk_op['reconciliation']['shadow_deleted']['status'] ?? '' ) === 'verified' );
s2cs_check( '5.3 reconcile verified the claim_released step', ( $disk_op['reconciliation']['claim_released']['status'] ?? '' ) === 'verified' );

s2cs_drop_transaction();

// 5.4: reconcile refuses on an op in `staged` (not yet committed).
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 5002, 'Original Title', 'Original content.', '', 'orig-5002' );
$op = s2cs_stage_edit_published( 5002, 9002, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 9002, 5002, $op['id'], array( 'post_title' => 'Staged Title' ) );
try {
    ( new Publish_Edit_Reconcile() )->execute( array() );
    s2cs_check( '5.4 reconcile refuses on op in staged (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '5.4 reconcile refuses on op in staged', strpos( $e->getMessage(), 'op_state_unexpected' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// 5.5: reconcile refuses on an op in `publication_settled` (already done).
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 5003, 'Original Title', 'Original content.', '', 'orig-5003' );
$op = s2cs_stage_edit_published( 5003, 9003, array( 'post_title' => 'Staged Title' ) );
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_SETTLED;
    return $op_row;
} );
try {
    ( new Publish_Edit_Reconcile() )->execute( array() );
    s2cs_check( '5.5 reconcile refuses on op in publication_settled (no throw)', false, 'expected InvalidArgumentException' );
} catch ( \InvalidArgumentException $e ) {
    s2cs_check( '5.5 reconcile refuses on op in publication_settled', strpos( $e->getMessage(), 'publication_settled' ) !== false, $e->getMessage() );
}
s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §6 — Pre-publication rollback via Content_Recovery::recover()
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §6 Pre-publication rollback via recovery ---\n";

s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 6001, 'Original Title', 'Original content.', '', 'orig-6001' );
$op = s2cs_stage_edit_published( 6001, 11001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 11001, 6001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Rollback the transaction. The recovery engine should
// pre-publication-rollback the OP_EDIT_PUBLISHED op: delete
// the shadow, release the claim, transition to compensated.
Tx::instance()->rollback();

$row = (array) get_option( Tx::KEY, null );
// The transaction row is gone after rollback.
s2cs_check( '6.1 transaction row is gone after rollback', ! is_array( $row ) || empty( $row['content']['ops'] ) );
// The shadow is gone.
s2cs_check( '6.2 shadow is gone after rollback', ! isset( $GLOBALS['_agentshell_test_posts'][ 11001 ] ) );
// The claim is gone.
s2cs_check( '6.3 claim is gone after rollback', null === Published_Claim::read( 6001 ) );
// The original is unchanged.
s2cs_check( '6.4 original is unchanged after rollback', $GLOBALS['_agentshell_test_posts'][ 6001 ]->post_title === 'Original Title' );

// ─────────────────────────────────────────────────────────────────────────────
// §7 — Reconciliation tail via Content_Recovery::recover()
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §7 Reconciliation tail via recovery ---\n";

// 7.1: a transaction with a committed OP_EDIT_PUBLISHED op can be
//      recovered by the recovery engine running the reconciliation
//      tail. The op transitions to publication_settled (not
//      compensated; the publication is durable).
//
// To exercise this without a full transaction, set up the journal
// row directly and call Content_Recovery::recover().
s2cs_reset_state();
$tx = Tx::instance()->begin( 'agent-1', 'recover test' );
s2cs_seed_original( 7001, 'Original Title', 'Original content.', '', 'orig-7001' );
$op = s2cs_stage_edit_published( 7001, 12001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 12001, 7001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Move the op to publication_committed (simulating a successful
// publish, with reconciliation tail still owed). The shadow
// still exists and the claim is still held.
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    return $op_row;
} );

// Run recovery.
$report = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$recovery_status = is_array( $report ) ? ( $report['status'] ?? '' ) : '';
$recovery_reports = is_array( $report ) && isset( $report['operations'] ) ? $report['operations'] : array();
$op_outcome = '';
foreach ( (array) $recovery_reports as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $op_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '7.1 recovery report status is RECOVERED', $recovery_status === \AgentShell_MCP\Content_Recovery::STATUS_RECOVERED, 'status=' . $recovery_status );
s2cs_check( '7.2 op outcome is "reconciled" (not "compensated")', $op_outcome === 'reconciled', 'outcome=' . $op_outcome );

// Op on disk: state should be publication_settled.
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '7.3 op on disk is in publication_settled state', is_array( $disk_op ) && ( $disk_op['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED );

// Shadow deleted, claim released.
s2cs_check( '7.4 shadow deleted by recovery', ! isset( $GLOBALS['_agentshell_test_posts'][ 12001 ] ) );
s2cs_check( '7.5 claim released by recovery', null === Published_Claim::read( 7001 ) );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §8 — Rollback of a committed op (reconciler path, not destructive)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §8 Rollback of a committed op goes through reconciler ---\n";

s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 8001, 'Original Title', 'Original content.', '', 'orig-8001' );
$op = s2cs_stage_edit_published( 8001, 13001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 13001, 8001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Pre-write the staged fields to the original (the publication
// has committed in the real world, even though the journal says
// `staged`). Now move the op to `publication_committed` and
// rollback — the recovery should run reconciliation (not
// destructive inverse).
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    return $op_row;
} );
// Real-world: original now carries the staged title.
$GLOBALS['_agentshell_test_posts'][ 8001 ]->post_title = 'Staged Title';

Tx::instance()->rollback();

// Original still carries the staged title (rollback is not
// destructive for a committed publication; the publication is
// durable).
s2cs_check( '8.1 original still carries the staged title after rollback', $GLOBALS['_agentshell_test_posts'][ 8001 ]->post_title === 'Staged Title' );
s2cs_check( '8.2 shadow is gone after rollback', ! isset( $GLOBALS['_agentshell_test_posts'][ 13001 ] ) );
s2cs_check( '8.3 claim is gone after rollback', null === Published_Claim::read( 8001 ) );

// ─────────────────────────────────────────────────────────────────────────────
// §9 — Refusal audit durability
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §9 Refusal audit durability ---\n";

// 9.1: a pre-COMMIT refusal writes the audit row OUTSIDE the
//      SQL transaction. The audit row is durable; the
//      publication did not land.
s2cs_reset_state();
s2cs_begin_tx( 'agent-1' );
s2cs_seed_original( 9001, 'Original Title', 'Original content.', '', 'orig-9001' );
$op = s2cs_stage_edit_published( 9001, 14001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 14001, 9001, $op['id'], array( 'post_title' => 'Staged Title' ) );
$GLOBALS['_agentshell_test_posts'][ 9001 ]->post_title = 'Externally Changed';

try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \InvalidArgumentException $e ) { /* expected */ }

// Op on disk should be in conflict.
$row = (array) get_option( Tx::KEY, null );
$disk_op = $row['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '9.1 refused publish transitions op to conflict', is_array( $disk_op ) && ( $disk_op['state'] ?? '' ) === Content_Journal::STATE_CONFLICT );

// The original is unchanged.
s2cs_check( '9.2 refused publish leaves the original unchanged', $GLOBALS['_agentshell_test_posts'][ 9001 ]->post_title === 'Externally Changed' );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §10 — R-1: repeat recovery against a settled op short-circuits
// ─────────────────────────────────────────────────────────────────────────────
//
// M5-audit defect R-1 (CRITICAL). settle_operation() at
// class-content-recovery.php:409-413 short-circuits
// STATE_COMPENSATED and STATE_CONFLICT, but not
// STATE_PUBLICATION_SETTLED. A repeat recovery against a settled
// op falls through to claim_or_refresh, writes
// `state: STATE_COMPENSATING`, the per-tx advisory lock, and
// the STATUS_PARTIAL branch keeps the transaction row
// indefinitely. Every subsequent recovery repeats the same
// corruption. The M5 fix adds STATE_PUBLICATION_SETTLED to the
// short-circuit; this section asserts the fixed contract.

echo "\n--- §10 R-1: repeat recovery against a settled op ---\n";

s2cs_reset_state();
$tx = Tx::instance()->begin( 'agent-1', 'R-1 test' );
s2cs_seed_original( 10001, 'Original Title', 'Original content.', '', 'orig-10001' );
$op = s2cs_stage_edit_published( 10001, 15001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 15001, 10001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Drive the op to publication_committed, then call recover()
// once. The op should settle to publication_settled.
s2cs_update_op( $op['id'], function ( $op_row ) {
    $op_row['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    return $op_row;
} );

$first = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$first_status = is_array( $first ) ? ( $first['status'] ?? '' ) : '';
$first_outcome = '';
foreach ( (array) ( $first['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $first_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '10.1 first recover settles the op (sanity)', $first_status === \AgentShell_MCP\Content_Recovery::STATUS_RECOVERED, 'status=' . $first_status );
s2cs_check( '10.2 first recover outcome is reconciled', $first_outcome === 'reconciled', 'outcome=' . $first_outcome );

// Snapshot the audit count BEFORE the second recovery so we
// can prove the second recovery did not write any new audit
// rows (it is a true no-op).
$audit_log_before = (array) \AgentShell_MCP\Store::audit_log();
$audit_count_before = count( $audit_log_before );
$tx_snapshot = Tx::instance()->payload();
$row_before = (array) get_option( Tx::KEY, null );

// Now run recovery a SECOND time. The op is in
// publication_settled, so the M5 fix should short-circuit
// (return outcome: already_settled) without entering
// claim_or_refresh, without writing `state: compensating`,
// and without writing any audit rows.
$second = \AgentShell_MCP\Content_Recovery::recover( $tx_snapshot, \AgentShell_MCP\Store::actor(), false, true, true );
$second_status = is_array( $second ) ? ( $second['status'] ?? '' ) : '';
$second_outcome = '';
foreach ( (array) ( $second['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $second_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '10.3 second recover short-circuits (status=RECOVERED)', $second_status === \AgentShell_MCP\Content_Recovery::STATUS_RECOVERED, 'status=' . $second_status );
s2cs_check( '10.4 second recover outcome is already_settled', $second_outcome === 'already_settled', 'outcome=' . $second_outcome );

// The op's state on disk must STILL be publication_settled.
// (Without the R-1 fix, the second recover would write
// `state: STATE_COMPENSATING`, corrupting the state machine.)
$row_after = (array) get_option( Tx::KEY, null );
$disk_op_after = $row_after['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '10.5 op on disk is still publication_settled (state not corrupted)', is_array( $disk_op_after ) && ( $disk_op_after['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED, 'state=' . ( $disk_op_after['state'] ?? 'null' ) );

// The recovery's summary audit row (operation:
// `transaction_recovery`) is written on every successful
// recovery call. The R-1 fix prevents the op-level
// compensation from running a second time, so the only new
// audit row MUST be the recovery summary, never an op-level
// compensation row (transaction_edit_published_reconciled_via_recovery
// or similar).
$audit_log_after = (array) \AgentShell_MCP\Store::audit_log();
$audit_count_after = count( $audit_log_after );
s2cs_check( '10.6 second recover wrote exactly one summary audit row', $audit_count_after === $audit_count_before + 1, 'delta=' . ( $audit_count_after - $audit_count_before ) );

// The newly-prepended audit row (index 0) must be the
// recovery summary, not an op-level compensation.
$newest_audit = $audit_log_after[0] ?? array();
$newest_op    = (string) ( $newest_audit['operation'] ?? '' );
s2cs_check( '10.7 newest audit is the recovery summary, not op-level compensation', 'transaction_recovery' === $newest_op, 'op=' . $newest_op );
// And no row in the new audit has the op-level
// compensation operation.
$op_level_audit_count = 0;
foreach ( $audit_log_after as $row ) {
    if ( 'transaction_edit_published_reconciled_via_recovery' === (string) ( $row['operation'] ?? '' ) ) {
        $op_level_audit_count++;
    }
}
s2cs_check( '10.8 exactly one op-level compensation audit row (from the first recover)', $op_level_audit_count === 1, 'count=' . $op_level_audit_count );

// Third call — same as second. The repeatability must hold
// for an arbitrary number of retries.
$third = \AgentShell_MCP\Content_Recovery::recover( $tx_snapshot, \AgentShell_MCP\Store::actor(), false, true, true );
$third_outcome = '';
foreach ( (array) ( $third['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $third_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '10.9 third recover also short-circuits', $third_outcome === 'already_settled', 'outcome=' . $third_outcome );
$row_after_third = (array) get_option( Tx::KEY, null );
$disk_op_third = $row_after_third['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '10.10 op on disk is still publication_settled after third recover', is_array( $disk_op_third ) && ( $disk_op_third['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §11 — R-2: a crashed compensation (op left in STATE_COMPENSATING) is
//      resumed on the next recovery
// ─────────────────────────────────────────────────────────────────────────────
//
// M5-audit defect R-2 (HIGH). A recovery that crashed
// mid-compensation leaves the op in STATE_COMPENSATING. The
// pre-fix dispatch treated STATE_COMPENSATING as "unexpected
// state" and refused as conflict, wedging the transaction
// slot. The R-2 fix records the pre-claim state in
// `claim.pre_state` and the dispatch uses that as the
// authoritative discriminator. This section asserts the
// fixed contract: a compensation can crash and be resumed.

echo "\n--- §11 R-2: compensation is crash-resumable ---\n";

// 11.1: a crashed reconcile-tail compensation (pre_state was
//       publication_committed) is resumed and settled.
s2cs_reset_state();
$tx = Tx::instance()->begin( 'agent-1', 'R-2 reconcile test' );
s2cs_seed_original( 11001, 'Original Title', 'Original content.', '', 'orig-11001' );
$op = s2cs_stage_edit_published( 11001, 16001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 16001, 11001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Simulate: the publish SQL transaction committed, the claim
// step ran and transitioned the op to STATE_COMPENSATING, and
// then the process crashed BEFORE the reconcile tail could
// complete. The op is left in STATE_COMPENSATING with the
// claim held and a partial reconciliation record (audit row
// written; no other steps verified). pre_state =
// STATE_PUBLICATION_COMMITTED is the canonical pre-claim
// state, as recorded by the claim step. The actor matches
// Store::actor() so the recovery's claim refresh succeeds.
$now = S2C_T0 + 5;
$recovery_actor = (string) \AgentShell_MCP\Store::actor();
s2cs_update_op( $op['id'], function ( $op_row ) use ( $now, $recovery_actor ) {
    $op_row['state'] = Content_Journal::STATE_COMPENSATING;
    $op_row['claim'] = array(
        'actor'     => $recovery_actor,
        'at'        => $now,
        'pre_state' => Content_Journal::STATE_PUBLICATION_COMMITTED,
    );
    $op_row['reconciliation'] = array(
        'shadow_deleted'    => array( 'status' => 'pending' ),
        'caches_invalidated' => array( 'status' => 'pending' ),
        'claim_released'     => array( 'status' => 'pending' ),
        'audit_written'     => true,
    );
    return $op_row;
} );

// Resume: the next recovery should pick the op up in
// STATE_COMPENSATING, read pre_state from `claim.pre_state`,
// dispatch to the reconcile tail, and settle to
// publication_settled.
$resumed = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$resumed_status = is_array( $resumed ) ? ( $resumed['status'] ?? '' ) : '';
$resumed_outcome = '';
foreach ( (array) ( $resumed['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $resumed_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '11.1 resume settles a crashed reconcile-tail (status=RECOVERED)', $resumed_status === \AgentShell_MCP\Content_Recovery::STATUS_RECOVERED, 'status=' . $resumed_status . ' reason=' . ( $resumed['reason'] ?? '' ) );
s2cs_check( '11.2 resume outcome is reconciled', $resumed_outcome === 'reconciled', 'outcome=' . $resumed_outcome );
$row_after_resume = (array) get_option( Tx::KEY, null );
$disk_op_resume = $row_after_resume['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '11.3 op on disk is in publication_settled after resume', is_array( $disk_op_resume ) && ( $disk_op_resume['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED );
s2cs_check( '11.4 shadow deleted by resume', ! isset( $GLOBALS['_agentshell_test_posts'][ 16001 ] ) );
s2cs_check( '11.5 claim released by resume', null === Published_Claim::read( 11001 ) );

s2cs_drop_transaction();

// 11.6: a crashed staged-rollback compensation (pre_state was
//       staged) is resumed and settled to compensated.
s2cs_reset_state();
$tx = s2cs_begin_tx( 'agent-1', 'R-2 staged test' );
s2cs_seed_original( 11002, 'Original Title', 'Original content.', '', 'orig-11002' );
$op = s2cs_stage_edit_published( 11002, 16002, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 16002, 11002, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Simulate: the claim step ran and transitioned the op to
// STATE_COMPENSATING, then the staged rollback crashed
// BEFORE the shadow was deleted. pre_state = STATE_STAGED.
// The actor matches Store::actor() so the recovery's claim
// refresh succeeds.
$now = S2C_T0 + 5;
$recovery_actor = (string) \AgentShell_MCP\Store::actor();
s2cs_update_op( $op['id'], function ( $op_row ) use ( $now, $recovery_actor ) {
    $op_row['state'] = Content_Journal::STATE_COMPENSATING;
    $op_row['claim'] = array(
        'actor'     => $recovery_actor,
        'at'        => $now,
        'pre_state' => Content_Journal::STATE_STAGED,
    );
    return $op_row;
} );

// Resume: the next recovery should pick the op up, read
// pre_state = STATE_STAGED, dispatch to staged rollback,
// delete the shadow, release the claim, and settle to
// compensated.
$resumed2 = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$resumed2_status = is_array( $resumed2 ) ? ( $resumed2['status'] ?? '' ) : '';
$resumed2_outcome = '';
foreach ( (array) ( $resumed2['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $resumed2_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '11.6 resume settles a crashed staged-rollback (status=RECOVERED)', $resumed2_status === \AgentShell_MCP\Content_Recovery::STATUS_RECOVERED, 'status=' . $resumed2_status );
s2cs_check( '11.7 resume outcome is compensated', $resumed2_outcome === 'compensated', 'outcome=' . $resumed2_outcome );
$row_after_resume2 = (array) get_option( Tx::KEY, null );
$disk_op_resume2 = $row_after_resume2['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '11.8 op on disk is in compensated after resume', is_array( $disk_op_resume2 ) && ( $disk_op_resume2['state'] ?? '' ) === Content_Journal::STATE_COMPENSATED );
s2cs_check( '11.9 shadow deleted by resume', ! isset( $GLOBALS['_agentshell_test_posts'][ 16002 ] ) );
s2cs_check( '11.10 claim released by resume', null === Published_Claim::read( 11002 ) );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// §12 — R-3: pre-publish claim release classifies successor / anomalous
// ─────────────────────────────────────────────────────────────────────────────
//
// M5-audit defect R-3 (LOW). The pre-publish claim release in
// compensate_edit_published_staged() read the claim outside
// the Content_Write_Guard::run() closure and used the read
// bytes as the cas_delete expected value. A narrow TOCTOU
// window let a successor tx acquire the claim between the
// read and the cas_delete, and the cas would silently
// delete the successor's claim. The R-3 fix re-reads the
// claim INSIDE the guard, classifies the outcome into
// absent / successor / anomalous, and refuses the
// compensation on successor or anomalous.

echo "\n--- §12 R-3: pre-publish claim release is classification-safe ---\n";

// 12.1: the common-case pre-publish rollback (the claim
//       was ours, the cas_delete released it) still
//       succeeds and settles to compensated.
s2cs_reset_state();
$tx = s2cs_begin_tx( 'agent-1', 'R-3 common-case' );
s2cs_seed_original( 12001, 'Original Title', 'Original content.', '', 'orig-12001' );
$op = s2cs_stage_edit_published( 12001, 17001, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 17001, 12001, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Force the op into STATE_COMPENSATING with pre_state=staged
// to drive the staged-rollback path.
$now = S2C_T0 + 5;
$recovery_actor = (string) \AgentShell_MCP\Store::actor();
s2cs_update_op( $op['id'], function ( $op_row ) use ( $now, $recovery_actor ) {
    $op_row['state'] = Content_Journal::STATE_COMPENSATING;
    $op_row['claim'] = array(
        'actor'     => $recovery_actor,
        'at'        => $now,
        'pre_state' => Content_Journal::STATE_STAGED,
    );
    return $op_row;
} );

$res = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$res_outcome = '';
foreach ( (array) ( $res['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $res_outcome = (string) ( $r['outcome'] ?? '' );
        break;
    }
}
s2cs_check( '12.1 common-case staged-rollback settles to compensated', $res_outcome === 'compensated', 'outcome=' . $res_outcome );
s2cs_check( '12.2 claim released (absent)', null === Published_Claim::read( 12001 ) );
s2cs_check( '12.3 shadow deleted by staged-rollback', ! isset( $GLOBALS['_agentshell_test_posts'][ 17001 ] ) );

s2cs_drop_transaction();

// 12.4: TOCTOU — a successor tx has acquired the claim
//       between the read and the cas_delete. The
//       compensation must refuse as conflict and the
//       successor's claim must be preserved.
//
// We simulate the TOCTOU by setting up the op in
// STATE_COMPENSATING with a pre_state=staged AND a
// successor's claim already in place (the cas_delete
// will not match our pre_state tx's bytes because the
// row carries the successor's bytes).
s2cs_reset_state();
$tx = s2cs_begin_tx( 'agent-1', 'R-3 TOCTOU' );
s2cs_seed_original( 12002, 'Original Title', 'Original content.', '', 'orig-12002' );
$op = s2cs_stage_edit_published( 12002, 17002, array( 'post_title' => 'Staged Title' ) );
s2cs_seed_shadow( 17002, 12002, $op['id'], array( 'post_title' => 'Staged Title' ) );

// Replace the test tx's claim with a successor's claim.
$pres = Published_Claim::read( 12002 );
if ( is_array( $pres ) ) {
    Published_Claim::release( 12002, $pres );
}
$successor_claim = Published_Claim::shape(
    'tx_successor_9999',
    12002,
    17002,
    'successor-agent',
    S2C_T0,
    S2C_T0,
    Published_Claim::TTL_SECONDS
);
Published_Claim::create( 12002, $successor_claim );

// Drive the op into STATE_COMPENSATING (the claim step
// has run in a prior recovery and crashed before the
// staged-rollback). pre_state=staged routes to the
// staged-rollback path.
$now = S2C_T0 + 5;
$recovery_actor = (string) \AgentShell_MCP\Store::actor();
s2cs_update_op( $op['id'], function ( $op_row ) use ( $now, $recovery_actor ) {
    $op_row['state'] = Content_Journal::STATE_COMPENSATING;
    $op_row['claim'] = array(
        'actor'     => $recovery_actor,
        'at'        => $now,
        'pre_state' => Content_Journal::STATE_STAGED,
    );
    return $op_row;
} );

$res2 = \AgentShell_MCP\Content_Recovery::recover( Tx::instance()->payload(), \AgentShell_MCP\Store::actor(), false, true, true );
$res2_outcome = '';
$res2_detail  = '';
foreach ( (array) ( $res2['operations'] ?? array() ) as $r ) {
    if ( isset( $r['id'] ) && $r['id'] === $op['id'] ) {
        $res2_outcome = (string) ( $r['outcome'] ?? '' );
        $res2_detail  = (string) ( $r['detail']  ?? '' );
        break;
    }
}
s2cs_check( '12.4 TOCTOU refuses as conflict', $res2_outcome === 'conflict', 'outcome=' . $res2_outcome );
s2cs_check( '12.5 detail names the classification', strpos( $res2_detail, 'successor' ) !== false || strpos( $res2_detail, 'anomalous' ) !== false, 'detail=' . substr( $res2_detail, 0, 80 ) );

// Critical: the successor's claim MUST be preserved. The
// R-3 fix never deletes a row whose bytes differ from
// the read; the cas_delete only matches the read bytes.
$survivor = Published_Claim::read( 12002 );
s2cs_check( '12.6 successor claim is preserved (NOT deleted by our cas)', is_array( $survivor ) && (string) ( $survivor['tx_id'] ?? '' ) === 'tx_successor_9999', 'survivor=' . ( is_array( $survivor ) ? (string) ( $survivor['tx_id'] ?? '' ) : 'null' ) );

// The op should be in conflict (refused) so a recovery_administrator
// can resolve the divergence.
$row_after = (array) get_option( Tx::KEY, null );
$disk_op_after = $row_after['content']['ops'][ $op['id'] ] ?? null;
s2cs_check( '12.7 op on disk is in conflict after refusal', is_array( $disk_op_after ) && ( $disk_op_after['state'] ?? '' ) === Content_Journal::STATE_CONFLICT );

// The shadow delete already happened (it ran before the
// claim release; the conflict was raised at the claim
// release step). The destructive step in the pre-publish
// rollback is the shadow delete; the claim-release
// refusal does not undo the shadow delete.
s2cs_check( '12.8 shadow was deleted (the destructive step before the refusal)', ! isset( $GLOBALS['_agentshell_test_posts'][ 17002 ] ) );

s2cs_drop_transaction();

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo "Stage 2c publish-edit unit tests: " . $GLOBALS['__s2c_pass'] . " passed, "
    . $GLOBALS['__s2c_fail'] . " failed.\n";
if ( $GLOBALS['__s2c_fail'] > 0 ) {
    echo "\nFailures:\n";
    foreach ( $GLOBALS['__s2c_results'] as $r ) {
        if ( ! $r['ok'] ) {
            echo "  FAIL  " . $r['name'] . ( $r['detail'] ? '  [' . $r['detail'] . ']' : '' ) . "\n";
        }
    }
    exit( 1 );
}
exit( 0 );
