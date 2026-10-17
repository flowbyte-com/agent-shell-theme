<?php
/**
 * Stage 2c overlay live child — Milestone 3.
 *
 * The child runs in the test site's WordPress install: it bootstraps
 * WP, builds the fixtures, mints a production-credential, sends HTTP
 * requests, and writes structured results to a JSON file. The parent
 * (stage-2c-overlay-live-test.php) reads the file and reports the
 * pass/fail totals through the s2co_check counter.
 *
 * Disposability: this child installs a per-fixture `page-{orig_id}.php`
 * into the active theme and creates a parent page, an original page,
 * and a shadow draft. Each run cleans up the prior run's fixture rows
 * before re-installing them, so re-running the test is safe. The
 * `page-{orig_id}.php` template is regenerated with a unique marker
 * baked in (so the test can assert the original's template is the one
 * that was selected).
 *
 * No curl. The HTTP probe uses `file_get_contents` + `stream_context_create`,
 * with the credential riding in the query string (no Authorization
 * header) — the same pattern the spike uses.
 */

$WP_ROOT  = '/home/v/local/agentshell-test/app/public';
$SITE     = 'http://agentshell-test.local';
$DB_SOCK  = '/home/v/.config/Local/run/JcloZdmY7/mysql/mysqld.sock';
$DB_USER  = 'root';
$DB_PASS  = 'root';
$DB_NAME  = 'agentshell_test_bed';
$MARKER   = 'STAGE2C_OVR_LIVE';
$RESULT   = sys_get_temp_dir() . '/stage-2c-overlay-live-result.json';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( ! is_file( $WP_ROOT . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$WP_ROOT}\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $WP_ROOT, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'agentshell-test.local';
$_SERVER['REQUEST_URI']  = '/';
ini_set( 'mysqli.default_socket', $DB_SOCK );
require_once ABSPATH . 'wp-load.php';

if ( ! defined( 'AGENTSHELL_MCP_VERSION' ) ) {
    fwrite( STDERR, "agentshell-mcp plugin is not active — overlay cannot be exercised\n" );
    exit( 2 );
}

require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-post-preview.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-journal.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-shadow.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-preview-overlay.php';

use AgentShell_MCP\Post_Preview;
use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Transaction_Manager as Tx;

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
$report  = function( $name, $ok, $detail = '' ) use ( &$results ) {
    $results[] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => (string) $detail );
    echo ( $ok ? "PASS  " : "FAIL  " ) . "{$name}" . ( $detail ? "  ({$detail})" : '' ) . "\n";
};

// ── 1. Clean up any prior fixture and install fresh ─────────────────────

$db( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_name LIKE 'ovr-%' OR post_name LIKE 'ovr-parent-slug' OR post_name LIKE 'ovr-orig-slug' OR post_name LIKE 'ovr-shadow-slug')" );
$db( "DELETE FROM wp_posts WHERE post_name LIKE 'ovr-%' OR post_name LIKE 'ovr-parent-slug' OR post_name LIKE 'ovr-orig-slug' OR post_name LIKE 'ovr-shadow-slug'" );

// ── 2. Parent page ──────────────────────────────────────────────────────

$parent_row = $db( "SELECT ID FROM wp_posts WHERE post_name='ovr-parent-slug' AND post_type='page'" )->fetch_assoc();
$parent_id  = $parent_row ? (int) $parent_row['ID'] : 0;
if ( ! $parent_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'ovr parent', 'OVR Parent', '', 'ovr-parent-slug', 'publish', 'page', 0, NOW(), NOW(), 'http://agentshell-test.local/?page_id=9001', '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $parent_id = (int) $mysqli->insert_id;
}

// ── 3. Original page with a custom page template ────────────────────────

$orig_row = $db( "SELECT ID FROM wp_posts WHERE post_name='ovr-orig-slug' AND post_type='page'" )->fetch_assoc();
$orig_id  = $orig_row ? (int) $orig_row['ID'] : 0;
if ( ! $orig_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'OVR ORIGINAL content body', 'OVR Original Title', 'OVR original excerpt', 'ovr-orig-slug', 'publish', 'page', {$parent_id}, NOW(), NOW(), CONCAT('http://agentshell-test.local/?page_id=', LAST_INSERT_ID()+1), '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $orig_id = (int) $mysqli->insert_id;
    $db( "UPDATE wp_posts SET guid='http://agentshell-test.local/?page_id={$orig_id}' WHERE ID={$orig_id}" );
}
$orig_template = "page-{$orig_id}.php";
$db( "DELETE FROM wp_postmeta WHERE post_id={$orig_id} AND meta_key='_wp_page_template'" );
$db( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$orig_id}, '_wp_page_template', '{$orig_template}')" );

// ── 4. Shadow draft with the four staged fields ─────────────────────────

$shadow_row = $db( "SELECT ID FROM wp_posts WHERE post_name='ovr-shadow-slug' AND post_type='page'" )->fetch_assoc();
$shadow_id  = $shadow_row ? (int) $shadow_row['ID'] : 0;
if ( ! $shadow_id ) {
    $db( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, NOW(), NOW(), 'OVR SHADOW content body (staged v2)', 'OVR SHADOW Title (v2)', 'OVR SHADOW excerpt', 'ovr-shadow-slug', 'private', 'page', 0, NOW(), NOW(), 'http://agentshell-test.local/?page_id=9003', '', 'closed', 'closed', 0, '', '', '', '', 0)" );
    $shadow_id = (int) $mysqli->insert_id;
    $db( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$shadow_id}, '_agentshell_shadow_for', '{$orig_id}'), ({$shadow_id}, '_agentshell_shadow_tx_id', 'tx-ovr-live'), ({$shadow_id}, '_agentshell_shadow_op_id', 'op-ovr-live-1')" );
}

// ── 5. Install the page-{orig_id}.php template with a unique marker ────

$marker_text = "{$MARKER}_{$orig_id}";
// Use single quotes for the template literal: this is a PHP source
// file that will be re-included by WordPress's template loader.
// The {$post->post_name} syntax must survive to runtime, not be
// interpolated by the child (where $post is undefined).
$tpl_src     = '<?php' . "\n"
    . '/**' . "\n"
    . " * Stage 2c overlay live page template: page-{$orig_id}.php\n"
    . " *\n"
    . " * Disposability: regenerated on every run. The marker is the\n"
    . " * runtime's only distinguishing mark — when it appears in the\n"
    . " * response body, the original's template was selected.\n"
    . " */\n"
    . "?>\n"
    . "<!-- " . $marker_text . " -->\n"
    . '<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>' . "\n"
    . '    <header class="entry-header">' . "\n"
    . '        <h1 class="entry-title"><?php the_title(); ?></h1>' . "\n"
    . '    </header>' . "\n"
    . '    <div class="entry-content"><?php the_content(); ?></div>' . "\n"
    . '    <div class="entry-excerpt"><?php the_excerpt(); ?></div>' . "\n"
    . '    <footer class="entry-footer">' . "\n"
    . '        <span class="queried-object-id"><?php echo (int) get_queried_object_id(); ?></span>' . "\n"
    . '        <span class="post-id"><?php echo (int) get_the_ID(); ?></span>' . "\n"
    . '        <span class="post-parent"><?php echo (int) get_post()->post_parent; ?></span>' . "\n"
    . '        <span class="post-ancestors"><?php echo esc_html( implode( \',\', get_ancestors( get_the_ID(), \'page\' ) ) ); ?></span>' . "\n"
    . '        <span class="post-template"><?php echo esc_html( get_post_meta( get_the_ID(), \'_wp_page_template\', true ) ); ?></span>' . "\n"
    . '        <span class="direct-get-post-id"><?php echo (int) get_post( get_the_ID() )->ID; ?></span>' . "\n"
    . '        <span class="post-name"><?php echo esc_html( $post->post_name ); ?></span>' . "\n"
    . '        <span class="permalink"><?php the_permalink(); ?></span>' . "\n"
    . '    </footer>' . "\n"
    . '</article>' . "\n";
$tpl_dst = $WP_ROOT . '/wp-content/themes/agent-shell-theme/' . $orig_template;
file_put_contents( $tpl_dst, $tpl_src );

// ── 6. Stage a transaction row with one OP_EDIT_PUBLISHED op ──────────

$tx_id = 'tx-ovr-live';
$actor = 1;
$op_id = 'op-ovr-live-1';
$op = array(
    'id'          => $op_id,
    'op'          => Content_Journal::OP_EDIT_PUBLISHED,
    'v'           => Content_Journal::VERSION,
    'state'       => 'staged',
    'actor'       => (string) $actor,
    'recorded_at' => time(),
    'updated_at'  => time(),
    'target'      => array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        'original_id' => (int) $orig_id,
        'shadow_id'   => (int) $shadow_id,
    ),
    'before'      => array(),
    'after'       => array(
        'post_title'   => 'OVR SHADOW Title (v2)',
        'post_content' => 'OVR SHADOW content body (staged v2)',
        'post_excerpt' => 'OVR SHADOW excerpt',
        'post_name'    => 'ovr-shadow-slug',
    ),
);
$content = array(
    'v'   => Content_Journal::VERSION,
    'tx'  => $tx_id,
    'ops' => array( $op_id => $op ),
);
$content['sig'] = Content_Journal::sign( $content );
$tx_row = array(
    'id'        => $tx_id,
    'label'     => 'OVR live test',
    'actor'     => (string) $actor,
    'timestamp' => time(),
    'before'    => array(),
    'staged'    => null,
    'content'   => $content,
);
update_option( Tx::KEY, $tx_row );

// ── 7. Build URLs ───────────────────────────────────────────────────────

$orig_path = '/ovr-parent-slug/ovr-orig-slug/';
$anon_url  = $SITE . $orig_path;

$cred_args = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $actor );
$cred_url  = $anon_url . '?' . http_build_query( $cred_args );

// Tampered credential: change one hex char of the key.
$decoded   = json_decode( base64_decode( $cred_args[ Post_Preview::PUB_PREVIEW_CRED_ARG ], true ), true );
$decoded['key'] = 'a' . substr( $decoded['key'], 1 );
$tampered_args = array(
    Post_Preview::PUB_PREVIEW_FLAG_ARG => '1',
    Post_Preview::PUB_PREVIEW_CRED_ARG => base64_encode( json_encode( $decoded ) ),
);
$tampered_url = $anon_url . '?' . http_build_query( $tampered_args );

// Expired credential: replay the same args with exp=time()-60.
$decoded['exp'] = time() - 60;
$decoded['key'] = hash_hmac( 'sha256',
    'agentshell-published-edit-preview|'
    . (int) $orig_id . '|'
    . (int) $shadow_id . '|'
    . $tx_id . '|'
    . (int) $actor . '|'
    . (int) $decoded['exp'],
    defined( 'AGENTSHELL_REST_TOKEN' ) ? AGENTSHELL_REST_TOKEN : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' ) )
);
$expired_args = array(
    Post_Preview::PUB_PREVIEW_FLAG_ARG => '1',
    Post_Preview::PUB_PREVIEW_CRED_ARG => base64_encode( json_encode( $decoded ) ),
);
$expired_url = $anon_url . '?' . http_build_query( $expired_args );

// Wrong-actor credential.
$wrong_actor_url = $anon_url . '?' . http_build_query(
    Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, 7 /* not the op's actor */ )
);

// Refused-state credential: re-stage the transaction with state=compensated.
$comp_op = $op;
$comp_op['state'] = 'compensated';
$comp_content = array(
    'v'   => Content_Journal::VERSION,
    'tx'  => $tx_id,
    'ops' => array( $op_id => $comp_op ),
);
$comp_content['sig'] = Content_Journal::sign( $comp_content );
$comp_tx_row = $tx_row;
$comp_tx_row['content'] = $comp_content;
update_option( Tx::KEY, $comp_tx_row );
$comp_url = $anon_url . '?' . http_build_query(
    Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $actor )
);
// NOTE: the tx row is left in the compensated state here. The (m) probe
// fires below against the refused-state row. The staged state is only
// restored AFTER the (m) probe completes, just before the authorised probe.

// ── 8. HTTP probe ───────────────────────────────────────────────────────

function fetch( $url, &$headers = null ) {
    $ctx = stream_context_create( array(
        'http' => array(
            'timeout' => 15,
            'header'  => "User-Agent: stage-2c-overlay-live\r\n",
            'follow_location' => 0,
        ),
    ) );
    $body = file_get_contents( $url, false, $ctx );
    $code = 0;
    if ( isset( $http_response_header[0] )
        && preg_match( '#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m ) ) {
        $code = (int) $m[1];
    }
    $hdrs = array();
    foreach ( (array) ( $http_response_header ?? array() ) as $line ) {
        if ( strpos( $line, ':' ) !== false ) {
            list( $k, $v ) = explode( ':', $line, 2 );
            $hdrs[ strtolower( trim( $k ) ) ] = trim( $v );
        }
    }
    $headers = $hdrs;
    return array( 'code' => $code, 'body' => (string) ( $body ?: '' ) );
}

echo "fixtures: parent_id={$parent_id}  orig_id={$orig_id}  shadow_id={$shadow_id}\n";

// ── 9. Invariant (i) — anonymous request ────────────────────────────────

$h = null;
$r = fetch( $anon_url, $h );
$report( 'live (i) anonymous: HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$report( 'live (i) anonymous: original title present', strpos( $r['body'], 'OVR Original Title' ) !== false );
$report( 'live (i) anonymous: shadow title absent', strpos( $r['body'], 'OVR SHADOW Title (v2)' ) === false );
$report( 'live (i) anonymous: template marker ' . $marker_text . ' present', strpos( $r['body'], $marker_text ) !== false );

// ── 10. Invariant (j) — tampered credential refused ─────────────────────

$r = fetch( $tampered_url, $h );
$report( 'live (j) tampered: HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$report( 'live (j) tampered: original title present', strpos( $r['body'], 'OVR Original Title' ) !== false );
$report( 'live (j) tampered: shadow title absent', strpos( $r['body'], 'OVR SHADOW Title (v2)' ) === false );

// ── 11. Invariant (k) — expired credential refused ──────────────────────

$r = fetch( $expired_url, $h );
$report( 'live (k) expired: original title present', strpos( $r['body'], 'OVR Original Title' ) !== false );
$report( 'live (k) expired: shadow title absent', strpos( $r['body'], 'OVR SHADOW Title (v2)' ) === false );

// ── 12. Invariant (l) — wrong-actor credential refused ──────────────────

$r = fetch( $wrong_actor_url, $h );
$report( 'live (l) wrong actor: original title present', strpos( $r['body'], 'OVR Original Title' ) !== false );
$report( 'live (l) wrong actor: shadow title absent', strpos( $r['body'], 'OVR SHADOW Title (v2)' ) === false );

// ── 13. Invariant (m) — refused-state op refused ────────────────────────

$r = fetch( $comp_url, $h );
$report( 'live (m) refused state: original title present', strpos( $r['body'], 'OVR Original Title' ) !== false );
$report( 'live (m) refused state: shadow title absent', strpos( $r['body'], 'OVR SHADOW Title (v2)' ) === false );

// Restore the staged state for the main authorised probe. The (m) probe
// ran against the compensated state; the (auth) probe needs the staged
// state. The credential was minted with the staged state (the comp
// probe reused a separately-issued credential bound only to the
// (oid, sid, tx, actor, exp) tuple), so the same URL would verify in
// both states, but the overlay must refuse the refused state.
update_option( Tx::KEY, $tx_row );

// ── 14. Invariant (a)-(h) — authorised request, valid credential ───────

$r = fetch( $cred_url, $h );
$report( 'live (auth) HTTP 200', $r['code'] === 200, "code={$r['code']}" );
$body = $r['body'];

$report( 'live (c) template marker ' . $marker_text . ' present', strpos( $body, $marker_text ) !== false );

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

$report( 'live (a) get_queried_object_id() = orig_id', $queried === $orig_id, "queried={$queried} orig={$orig_id}" );
$report( 'live (b) $post->ID = orig_id', $postid === $orig_id, "post_id={$postid} orig={$orig_id}" );
$report( 'live (d) get_ancestors returns the original parent', strpos( $ancestors, (string) $parent_id ) !== false, "ancestors='{$ancestors}'" );
$report( 'live (e) get_post_meta _wp_page_template = ' . $orig_template, $template === $orig_template, "template='{$template}'" );
$report( 'live (f) direct get_post(id).ID = orig_id', $direct_post_id === $orig_id, "direct={$direct_post_id} orig={$orig_id}" );

// (g) four supported fields overlaid.
$report( 'live (g) post_title overlaid: SHADOW title in body', strpos( $body, 'OVR SHADOW Title (v2)' ) !== false );
$report( 'live (g) post_content overlaid: SHADOW content in body', strpos( $body, 'OVR SHADOW content body' ) !== false );
$report( 'live (g) post_excerpt overlaid: SHADOW excerpt in body', strpos( $body, 'OVR SHADOW excerpt' ) !== false );
$report( 'live (g) post_name overlaid: $post->post_name = ovr-shadow-slug', $post_name === 'ovr-shadow-slug', "post_name='{$post_name}'" );

// (g-link) design §1.3 step 6 option (a): the response body's permalink
// reflects the staged slug, so the preview is consistent with what the
// visitor will see after publish. The request URL stays on the original's
// permalink.
$report( 'live (g-link) permalink reflects the staged slug', strpos( $permalink, 'ovr-shadow-slug' ) !== false, "permalink='{$permalink}'" );
$report( 'live (g-link) permalink does NOT use the original slug', strpos( $permalink, 'ovr-orig-slug' ) === false, "permalink='{$permalink}'" );

// (n) noindex, no-store headers.
$report( 'live (n) X-Robots-Tag: noindex', isset( $h['x-robots-tag'] ) && stripos( $h['x-robots-tag'], 'noindex' ) !== false, "x-robots-tag=" . ( $h['x-robots-tag'] ?? '' ) );
$report( 'live (n) Cache-Control: no-store', isset( $h['cache-control'] ) && stripos( $h['cache-control'], 'no-store' ) !== false, "cache-control=" . ( $h['cache-control'] ?? '' ) );

// ── 15. Invariant (h) — persistent cache holds the original's row ──────

$r2 = fetch( $anon_url, $h );
$report( 'live (h) after overlay: anonymous request still shows original title', strpos( $r2['body'], 'OVR Original Title' ) !== false );
$report( 'live (h) after overlay: anonymous request does NOT show shadow title', strpos( $r2['body'], 'OVR SHADOW Title (v2)' ) === false );

// ── 16. Cleanup — restore the transaction option so a follow-up test run
// (e.g. journal-recovery-live) does not see this test's tx-ovr-live row.
$saved_tx = get_option( Tx::KEY );
if ( is_array( $saved_tx ) && isset( $saved_tx['id'] ) && 'tx-ovr-live' === $saved_tx['id'] ) {
    delete_option( Tx::KEY );
}

// Also remove the per-run page-{orig_id}.php template. The test bed
// theme dir is a symlink to the worktree root, so a leftover here
// would fail the checkout integrity check at the end of run-all.
if ( is_file( $tpl_dst ) ) {
    @unlink( $tpl_dst );
}

// ── 17. Write the structured result for the parent ──────────────────────

file_put_contents( $RESULT, json_encode( array( 'results' => $results ) ) );

$fail = 0;
foreach ( $results as $r ) { if ( ! $r['ok'] ) { $fail++; } }
exit( $fail === 0 ? 0 : 1 );
