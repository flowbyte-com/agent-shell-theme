<?php
/**
 * Child half of the live content-write suite. Runs inside a real WordPress
 * install and reports facts as JSON; the parent asserts them.
 *
 * Every post it creates is disposable and carries a per-run token, and all of
 * them are deleted before the child exits — including any that a failed write
 * leaked, which is the point: the parent asserts the count is zero.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$wp_root}\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
require_once $theme_root . '/agentshell-mcp/includes/class-content.php';

use AgentShell_MCP\Content_Primitives;
use AgentShell_MCP\Content_Write_Guard;

global $wpdb;

$token = 'AScw' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$f     = array();
$mine  = array();

function ids_for( $needle ) {
    global $wpdb;
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", array( '%' . $needle . '%' )
    ) ) ?: array() );
}

function meta_for( $id ) {
    global $wpdb;
    if ( ! $id ) {
        return array();
    }
    return wp_list_pluck( $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key", $id
    ) ), 'meta_key' );
}

function track( &$mine, $id ) {
    if ( $id > 0 ) {
        $mine[] = (int) $id;
    }
}

/**
 * Reproduce the post-insert WP_Error through the write boundary AgentShell
 * actually guards.
 *
 * The branch is `page_template` validation, which runs at core's
 * wp_insert_post():5160-5172 — after the post row, its categories and its
 * meta_input have been written — and returns WP_Error without ever naming the
 * post it wrote.
 *
 * AgentShell's own arguments cannot reach it today: `page_template` is not a
 * field `create_page` accepts, and core builds its INSERT column set from a
 * fixed `compact()` list, so a plugin adding it through the late
 * `wp_insert_post_data` filter gets a database error rather than this branch.
 * That is why the reproduction drives `Content_Write_Guard` directly instead of
 * a tool: the branch is latent for AgentShell, and the guard is exactly what
 * will hold when it stops being latent.
 */
function cw_guarded_insert( $postarr ) {
    return Content_Write_Guard::run( function () use ( $postarr ) {
        $id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( ! $id ) {
            return 0;
        }
        return (int) $id;
    } );
}

// ── 1. A successful create through the public tool still works ───────────────
$title = $token . '-ok';
try {
    $rec = Content_Primitives::create( 'page', $title, '<p>probe body</p>', 'draft', array( 'post_name' => strtolower( $title ) ) );
    track( $mine, $rec['id'] );
    $f['create_ok'] = array(
        'returned_id'  => isset( $rec['id'] ) ? (int) $rec['id'] : 0,
        'title'        => isset( $rec['title'] ) ? $rec['title'] : '',
        'status'       => isset( $rec['status'] ) ? $rec['status'] : '',
        'content'      => isset( $rec['content']['raw'] ) ? $rec['content']['raw'] : '',
        'edit_url'     => isset( $rec['edit_url'] ) ? $rec['edit_url'] : '',
        'atomicity'    => isset( $rec['atomicity'] ) ? $rec['atomicity'] : null,
        'db_title'     => get_post_field( 'post_title', $rec['id'] ),
        'db_status'    => get_post_field( 'post_status', $rec['id'] ),
        'db_slug'      => get_post_field( 'post_name', $rec['id'] ),
        'rows'         => ids_for( $title ),
        'live_url'     => get_permalink( $rec['id'] ),
        'categories'   => wp_get_post_categories( $rec['id'] ),
    );
} catch ( \Throwable $e ) {
    $f['create_ok'] = array( 'exception' => get_class( $e ) . ': ' . $e->getMessage() );
}

// ── 2. F3: the post-insert WP_Error must leave nothing behind ────────────────
$title   = $token . '-f3';
$result  = cw_guarded_insert( array(
    'post_type'     => 'page',
    'post_status'   => 'draft',
    'post_title'    => $title,
    'post_content'  => '<p>probe body</p>',
    'page_template' => 'no-such-template-xyz',
) );
$leaked  = ids_for( $title );
$f['f3'] = array(
    'error'          => is_wp_error( $result ) ? $result->get_error_code() : null,
    'message'        => is_wp_error( $result ) ? $result->get_error_message() : null,
    'rows_after'     => $leaked,
    'query_after'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s", $title ) ),
    // If the row was written and then rolled back, the object cache must not
    // still be serving it: get_post() returning a post that is not in the
    // database is the same lie the tool must not tell.
    'cache_serves_leak'  => $leaked ? (bool) get_post( $leaked[0] ) : null,
    'permalink_after'    => $leaked ? get_permalink( $leaked[0] ) : null,
);
foreach ( $leaked as $id ) {
    track( $mine, $id );
}

// ── 3. Metadata written before the failure must not survive either ───────────
$title  = $token . '-f3meta';
$result = cw_guarded_insert( array(
    'post_type'     => 'page',
    'post_status'   => 'draft',
    'post_title'    => $title,
    'post_content'  => '<p>probe body</p>',
    'meta_input'    => array( 'cw_probe_meta' => 'probe value' ),
    'page_template' => 'no-such-template-xyz',
) );
$leaked = ids_for( $title );
$f['f3meta'] = array(
    'error'          => is_wp_error( $result ) ? $result->get_error_code() : null,
    'rows_after'     => $leaked,
    'meta_after'     => $leaked ? meta_for( $leaked[0] ) : array(),
    'stray_meta_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'cw_probe_meta'" ),
);
foreach ( $leaked as $id ) {
    track( $mine, $id );
}

// ── 4. A failed write must not damage content that already existed ───────────
$keep_title = $token . '-keep';
$keep       = Content_Primitives::create( 'page', $keep_title, '<p>original body</p>', 'publish', array( 'post_name' => strtolower( $keep_title ) ) );
track( $mine, $keep['id'] );
$before   = get_post( $keep['id'] );
$before_s = array( $before->post_title, $before->post_content, $before->post_status, $before->post_name, $before->post_modified, $before->post_date );

$result = Content_Write_Guard::run( function () use ( $keep ) {
    $out = wp_update_post( wp_slash( array(
        'ID'            => $keep['id'],
        'post_title'    => get_post_field( 'post_title', $keep['id'] ) . '-mutated',
        'post_content'  => '<p>mutated body</p>',
        'post_status'   => 'draft',
        'page_template' => 'no-such-template-xyz',
    ) ), true );
    return is_wp_error( $out ) ? $out : (int) $out;
} );

clean_post_cache( $keep['id'] );
$after = get_post( $keep['id'] );
$f['update_failure'] = array(
    'error'    => is_wp_error( $result ) ? $result->get_error_code() : null,
    'unchanged' => $after ? ( array( $after->post_title, $after->post_content, $after->post_status, $after->post_name, $after->post_modified, $after->post_date ) === $before_s ) : false,
    'still_there' => (bool) $after,
    'rows_after'  => ids_for( $keep_title ),
);

// ── 5. A successful update through the public tool still works ───────────────
$upd = Content_Primitives::update( $keep['id'], 'page', array(
    'title'   => $keep_title . '-edited',
    'content' => '<p>edited body</p>',
    'status'  => 'draft',
) );
track( $mine, $upd['id'] );
$f['update_ok'] = array(
    'returned_id' => (int) $upd['id'],
    'db_title'    => get_post_field( 'post_title', $keep['id'] ),
    'db_content'  => get_post_field( 'post_content', $keep['id'] ),
    'db_status'   => get_post_field( 'post_status', $keep['id'] ),
    'atomicity'   => $upd['atomicity'] ?? null,
);

// ── 6. A write that cannot produce a real page is refused ────────────────────
$untitled_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = ''" );
try {
    $r = Content_Primitives::create( 'page', '', '', 'draft', array() );
    track( $mine, $r['id'] );
    $f['empty_refused'] = null;
} catch ( \Throwable $e ) {
    $f['empty_refused'] = $e->getMessage();
}
$f['empty_delta'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = ''" ) - $untitled_before;

// ── 7. An unsupported field is still refused before anything is written ──────
try {
    Content_Primitives::update( $keep['id'], 'page', array( 'author' => 4 ) );
    $f['unknown_field'] = null;
} catch ( \Throwable $e ) {
    $f['unknown_field'] = $e->getMessage();
}

// ── 8. The MCP tool itself, not just the primitive under it ────────────────
// The primitives are what the tools call, but "the primitive works" is not
// "the tool an agent actually invokes works": a tool that bypassed the guard,
// or dropped the atomicity block, would still pass everything above.
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-create-page.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-update-content.php';

$title = $token . '-tool';
try {
    $tool  = new \AgentShell_MCP\Tools\Create_Page();
    $rec   = $tool->execute( array( 'title' => $title, 'content' => '<p>tool body</p>', 'status' => 'draft' ) );
    track( $mine, $rec['id'] );
    $f['tool'] = array(
        'id'         => (int) $rec['id'],
        'db_title'   => get_post_field( 'post_title', $rec['id'] ),
        'db_status'  => get_post_field( 'post_status', $rec['id'] ),
        'db_content' => get_post_field( 'post_content', $rec['id'] ),
        'atomicity'  => $rec['atomicity'] ?? null,
        'schema'     => $tool->get_name(),
    );

    $upd_tool = new \AgentShell_MCP\Tools\Update_Content();
    $upd      = $upd_tool->execute( array( 'id' => $rec['id'], 'title' => $title . '-tool-edited', 'status' => 'publish' ) );
    $f['tool']['after_update_db_title']  = get_post_field( 'post_title', $rec['id'] );
    $f['tool']['after_update_db_status'] = get_post_field( 'post_status', $rec['id'] );
    $f['tool']['after_update_atomicity'] = $upd['atomicity'] ?? null;
} catch ( \Throwable $e ) {
    $f['tool'] = array( 'exception' => get_class( $e ) . ': ' . $e->getMessage() );
}

// ── cleanup, then report ────────────────────────────────────────────────────
foreach ( array_unique( $mine ) as $id ) {
    if ( $id > 0 ) {
        wp_delete_post( $id, true );
    }
}
foreach ( ids_for( $token ) as $id ) {
    wp_delete_post( $id, true );
}

$f['cleanup'] = array(
    'rows_remaining' => count( ids_for( $token ) ),
    'stray_meta'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'cw_probe_meta'" ),
    'untitled_delta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = ''" ) - $untitled_before,
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";