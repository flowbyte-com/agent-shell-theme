<?php
/**
 * Stage 2c preview feasibility spike — live test runner.
 *
 * Disposability: this file is part of the Stage 2c preview feasibility spike
 * and exists only while the spike is running. It runs against the disposable
 * WordPress test bed at agentshell-test.local.
 *
 * What it proves:
 *   (a) get_queried_object_id() returns the original's id
 *   (b) $post->ID is the original's id
 *   (c) the template loader selects the original's page-{ID}.php
 *   (d) get_ancestors($post->ID) returns the original's ancestors
 *   (e) get_post_meta($post->ID, '_wp_page_template', true) returns the original's
 *       page-template selection
 *   (f) a direct get_post($post->ID) call inside the same request reads the
 *       original's row (not the substituted overlay object)
 *   (g) the four supported fields (post_title, post_content, post_excerpt,
 *       post_name) read from the rendered response body are the shadow's
 *       staged values
 *   (h) the persistent wp_object_cache holds the original's row, not a
 *       poisoned version
 *   (i) anonymous request (no credential) returns the original unchanged with
 *       HTTP 200
 *   (j) unauthorised request (wrong credential) returns the original unchanged
 *       with HTTP 200
 *
 * Usage: php run-preview-spike.php
 *   Will be invoked by the parent with the right LD_LIBRARY_PATH.
 */

$WP_ROOT = '/home/v/local/agentshell-test/app/public';
$SITE    = 'http://agentshell-test.local';
$DB_SOCK = '/home/v/.config/Local/run/JcloZdmY7/mysql/mysqld.sock';
$DB_USER = 'root';
$DB_PASS = 'root';
$DB_NAME = 'agentshell_test_bed';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( ! is_file( $WP_ROOT . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$WP_ROOT}\n" );
    exit( 2 );
}

// Read AUTH_SALT from wp-config.php for HMAC signing
$wp_config = file_get_contents( $WP_ROOT . '/wp-config.php' );
$secret    = '';
if ( preg_match( "/define\\(\\s*'AUTH_SALT'\\s*,\\s*'([^']+)'/", $wp_config, $m ) ) {
    $secret = $m[1];
}
if ( '' === $secret ) {
    fwrite( STDERR, "AUTH_SALT not found in wp-config.php\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $WP_ROOT, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'agentshell-test.local';
$_SERVER['REQUEST_URI']  = '/';
// LocalWP runs MySQL on a non-default socket; the test runner must point the
// mysqli extension at it before wp-load.php opens its connection.
ini_set( 'mysqli.default_socket', $DB_SOCK );
require_once ABSPATH . 'wp-load.php';

// Connect directly to DB for fixture setup
$mysqli = new mysqli( 'localhost', $DB_USER, $DB_PASS, $DB_NAME, 0, $DB_SOCK );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "db connect fail: " . $mysqli->connect_errno . "\n" );
    exit( 2 );
}
$db = function( $sql ) use ( $mysqli ) {
    $res = $mysqli->query( $sql );
    if ( ! $res ) { throw new RuntimeException( "db fail: " . $mysqli->error ); }
    return $res;
};

$results = array();
$pass    = 0;
$fail    = 0;
$report  = function( $name, $ok, $detail = '' ) use ( &$results, &$pass, &$fail ) {
    $results[] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( $ok ) { $pass++; } else { $fail++; }
    echo ( $ok ? "PASS" : "FAIL" ) . "  {$name}" . ( $detail ? "  ({$detail})" : '' ) . "\n";
};

// --- Step 1: clean up any prior spike fixture and install fresh ---
$db( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_name LIKE 'spike-%' OR post_name LIKE 'spike-orig-slug' OR post_name LIKE 'spike-shadow-slug' OR post_name LIKE 'spike-parent-slug')" );
$db( "DELETE FROM wp_posts WHERE post_name LIKE 'spike-%' OR post_name LIKE 'spike-orig-slug' OR post_name LIKE 'spike-shadow-slug' OR post_name LIKE 'spike-parent-slug'" );

// --- Step 2: create the parent page ---
$parent_row = $db( "SELECT ID FROM wp_posts WHERE post_name='spike-parent-slug' AND post_type='page'" )->fetch_assoc();
$parent_id  = $parent_row ? (int) $parent_row['ID'] : 0;
if ( ! $parent_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'parent content', 'Spike Parent', '', 'spike-parent-slug', 'publish', 'page', 0, NOW(), NOW(), 'http://agentshell-test.local/?page_id=10', '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $parent_id = (int) $mysqli->insert_id;
}

// --- Step 3: create the child page (the ORIGINAL) with a custom page template ---
$orig_row = $db( "SELECT ID FROM wp_posts WHERE post_name='spike-orig-slug' AND post_type='page'" )->fetch_assoc();
$orig_id  = $orig_row ? (int) $orig_row['ID'] : 0;
if ( ! $orig_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'original content body', 'Original Title', 'original excerpt', 'spike-orig-slug', 'publish', 'page', {$parent_id}, NOW(), NOW(), CONCAT('http://agentshell-test.local/?page_id=', LAST_INSERT_ID()+1), '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $orig_id = (int) $mysqli->insert_id;
    $db( "UPDATE wp_posts SET guid='http://agentshell-test.local/?page_id={$orig_id}' WHERE ID={$orig_id}" );
}
// Set a unique page template on the original so we can verify it's preserved.
// We use _wp_page_template to point at a template that will be installed with
// the original's id baked in (e.g. page-{orig_id}.php). The template marker
// is the original's id, so a test asserting the marker is present is asserting
// the original's template was selected.
$orig_template = "page-{$orig_id}.php";
$db( "DELETE FROM wp_postmeta WHERE post_id={$orig_id} AND meta_key='_wp_page_template'" );
$db( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$orig_id}, '_wp_page_template', '{$orig_template}')" );

// --- Step 4: create the shadow draft (different four fields) ---
$shadow_row = $db( "SELECT ID FROM wp_posts WHERE post_name='spike-shadow-slug' AND post_type='page'" )->fetch_assoc();
$shadow_id  = $shadow_row ? (int) $shadow_row['ID'] : 0;
if ( ! $shadow_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'SHADOW content body -- version 2 of the content', 'SHADOW Title (v2)', 'SHADOW excerpt', 'spike-shadow-slug', 'draft', 'page', 0, NOW(), NOW(), 'http://agentshell-test.local/?page_id=12', '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $shadow_id = (int) $mysqli->insert_id;
}

// --- Step 5: install the spike mu-plugin into the test site ---
$mu_dir = $WP_ROOT . '/wp-content/mu-plugins';
$mu_src = __DIR__ . '/agentshell-stage2c-preview-spike.php';
$mu_dst = $mu_dir . '/agentshell-stage2c-preview-spike.php';
if ( ! is_dir( $mu_dir ) ) { mkdir( $mu_dir, 0755, true ); }
if ( ! file_exists( $mu_dst ) || md5_file( $mu_src ) !== md5_file( $mu_dst ) ) {
    copy( $mu_src, $mu_dst );
}

// --- Step 6: install the page-{orig_id}.php template into the theme root ---
// The template emits a unique marker that bakes in the original's id, so the
// test can assert the original's template (not the shadow's) was selected.
$tpl_src_marker = "STAGE2C_SPIKE_TEMPLATE_{$orig_id}";
$tpl_src = __DIR__ . '/page-11.php';
$tpl_dst = $WP_ROOT . '/wp-content/themes/agent-shell-theme/' . $orig_template;
if ( ! file_exists( $tpl_dst ) || md5_file( $tpl_src ) !== md5_file( $tpl_dst )
    || strpos( file_get_contents( $tpl_dst ), $tpl_src_marker ) === false ) {
    // Read the source template, replace the hardcoded marker with the dynamic one,
    // and write the destination. The source is a fixture; the destination is
    // per-fixture (one per original id).
    $src = file_get_contents( $tpl_src );
    $src = str_replace( 'STAGE2C_SPIKE_TEMPLATE_11', $tpl_src_marker, $src );
    file_put_contents( $tpl_dst, $src );
}

// --- Step 7: flush the object cache so the fresh post rows are visible ---
wp_cache_flush();

$tx_id  = 'tx-spike-001';
$actor  = 1;
$exp    = time() + 300;
$key    = hash_hmac( 'sha256', "agentshell-published-edit|{$orig_id}|{$shadow_id}|{$tx_id}|{$actor}|{$exp}", $secret );

// Build URLs that target the original's canonical permalink (the URL the
// public sees). The credential rides in the query string; the request is
// on the original's path, so the overlay is rendered under the original's
// URL (the four fields come from the shadow, the URL stays the original's).
$orig_path = '/spike-parent-slug/spike-orig-slug/';
$anon_url  = $SITE . $orig_path;
$cred_url  = $SITE . $orig_path . '?agentshell_pub_preview_oid=' . $orig_id
    . '&agentshell_pub_preview_sid=' . $shadow_id
    . '&agentshell_pub_preview_tx=' . urlencode( $tx_id )
    . '&agentshell_pub_preview_actor=' . $actor
    . '&agentshell_pub_preview_exp=' . $exp
    . '&agentshell_pub_preview_key=' . urlencode( $key );

$cred_url_bad = $SITE . $orig_path . '?agentshell_pub_preview_oid=' . $orig_id
    . '&agentshell_pub_preview_sid=' . $shadow_id
    . '&agentshell_pub_preview_tx=' . urlencode( $tx_id )
    . '&agentshell_pub_preview_actor=' . $actor
    . '&agentshell_pub_preview_exp=' . $exp
    . '&agentshell_pub_preview_key=' . urlencode( 'a' . substr( $key, 1 ) );

function fetch( $url ) {
    $ch = curl_init( $url );
    curl_setopt_array( $ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array( 'User-Agent: spike' ),
    ) );
    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );
    return array( 'code' => $code, 'body' => (string) $body );
}

echo "fixtures: parent_id={$parent_id}  orig_id={$orig_id}  shadow_id={$shadow_id}\n";
echo "\n--- INVARIANT (i): anonymous request, no credential ---\n";
$r = fetch( $anon_url );
$report( 'anonymous: HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$report( 'anonymous: original title "Original Title" present', strpos( $r['body'], 'Original Title' ) !== false );
$report( 'anonymous: shadow title "SHADOW Title (v2)" absent', strpos( $r['body'], 'SHADOW Title (v2)' ) === false );
$report( 'anonymous: original content "original content" present', strpos( $r['body'], 'original content' ) !== false );
$report( 'anonymous: template marker ' . $tpl_src_marker . ' present (original\'s template is selected by template loader)', strpos( $r['body'], $tpl_src_marker ) !== false );

echo "\n--- INVARIANT (j): unauthorised request, wrong credential ---\n";
$r = fetch( $cred_url_bad );
$report( 'unauthorised: HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$report( 'unauthorised: original title "Original Title" present', strpos( $r['body'], 'Original Title' ) !== false );
$report( 'unauthorised: shadow title "SHADOW Title (v2)" absent', strpos( $r['body'], 'SHADOW Title (v2)' ) === false );

echo "\n--- INVARIANT (a)-(h): authorised request, valid credential ---\n";
$r = fetch( $cred_url );
$report( 'authorised: HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$body = $r['body'];

// (c) the template loader selected page-{orig_id}.php because the original's id is orig_id
$report( '(c) template marker ' . $tpl_src_marker . ' present', strpos( $body, $tpl_src_marker ) !== false );

// The spike template prints get_queried_object_id(), get_the_ID(), post_parent, get_ancestors(),
// get_post_meta(_wp_page_template), get_post()->ID (cache read), the post_name data field,
// and the_permalink (the canonical URL).
preg_match( '#<span class="queried-object-id">(\d+)</span>#', $body, $m_q );
$queried = isset( $m_q[1] ) ? (int) $m_q[1] : -1;
preg_match( '#<span class="post-id">(\d+)</span>#', $body, $m_p );
$postid = isset( $m_p[1] ) ? (int) $m_p[1] : -1;
preg_match( '#<span class="post-parent">(\d+)</span>#', $body, $m_pp );
$parent = isset( $m_pp[1] ) ? (int) $m_pp[1] : -1;
preg_match( '#<span class="post-ancestors">([^<]*)</span>#', $body, $m_a );
$ancestors = isset( $m_a[1] ) ? $m_a[1] : '';
preg_match( '#<span class="post-template">([^<]*)</span>#', $body, $m_t );
$template = isset( $m_t[1] ) ? $m_t[1] : '';
preg_match( '#<span class="direct-get-post-id">(\d+)</span>#', $body, $m_d );
$direct_post_id = isset( $m_d[1] ) ? (int) $m_d[1] : -1;
preg_match( '#<span class="post-name">([^<]*)</span>#', $body, $m_n );
$post_name = isset( $m_n[1] ) ? $m_n[1] : '';
preg_match( '#<span class="permalink">([^<]*)</span>#', $body, $m_pl );
$permalink = isset( $m_pl[1] ) ? $m_pl[1] : '';

// (a) get_queried_object_id() returns the original's id
$report( '(a) get_queried_object_id() = orig_id', $queried === $orig_id, "queried={$queried} orig={$orig_id}" );
// (b) $post->ID is the original's id
$report( '(b) $post->ID = orig_id', $postid === $orig_id, "post_id={$postid} orig={$orig_id}" );
// (d) get_ancestors() returns the original's ancestors
$report( '(d) get_ancestors returns the original parent', strpos( $ancestors, (string) $parent_id ) !== false, "ancestors='{$ancestors}'" );
// (e) get_post_meta(_wp_page_template) returns the original's selection
$report( '(e) get_post_meta _wp_page_template = ' . $orig_template, $template === $orig_template, "template='{$template}'" );
// (f) direct get_post($id) inside the render returns the original's id
$report( '(f) direct get_post(id).ID = orig_id', $direct_post_id === $orig_id, "direct={$direct_post_id} orig={$orig_id}" );

// (g) four supported fields overlaid
$report( '(g) post_title overlaid: SHADOW Title (v2) in body', strpos( $body, 'SHADOW Title (v2)' ) !== false );
$report( '(g) post_content overlaid: SHADOW content body in body', strpos( $body, 'SHADOW content body' ) !== false );
$report( '(g) post_excerpt overlaid: SHADOW excerpt in body', strpos( $body, 'SHADOW excerpt' ) !== false );
// post_name overlay: read directly from $post->post_name (the WP_Post
// object substitution), not from the permalink. The permalink stays as
// the original's URL by design — the four-field overlay is for the data
// fields, not the canonical URL.
$report( '(g) post_name overlaid: $post->post_name = spike-shadow-slug', $post_name === 'spike-shadow-slug', "post_name='{$post_name}'" );
// The permalink (URL) is the original's URL — it must NOT be rewritten to
// the shadow's slug during a preview, because the request is on the
// original's path and the rendered page must link to itself consistently.
$report( '(g) permalink stays as original URL (spike-orig-slug)', strpos( $permalink, 'spike-orig-slug' ) !== false, "permalink='{$permalink}'" );
$report( '(g) permalink does NOT use shadow slug', strpos( $permalink, 'spike-shadow-slug' ) === false, "permalink='{$permalink}'" );

// (h) the persistent cache holds the original's row, not a poisoned version.
// A second request without any credential must see the original's content,
// not the shadow's. (The overlay is per-request; the cache is per-row.)
$r2 = fetch( $anon_url );
$report( '(h) after overlay: anonymous request still shows original title', strpos( $r2['body'], 'Original Title' ) !== false );
$report( '(h) after overlay: anonymous request does NOT show shadow title', strpos( $r2['body'], 'SHADOW Title (v2)' ) === false );

// --- Final: report summary ---
echo "\n--- SPIKE SUMMARY ---\n";
echo "{$pass} pass / {$fail} fail\n";
exit( $fail === 0 ? 0 : 1 );
