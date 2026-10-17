<?php
/**
 * Stage 2c M5-t3 — full end-to-end acceptance child.
 *
 * argv: 1 WordPress root  2 report path
 *
 * Drives the complete published-edit lifecycle against a real
 * WordPress install: page → claim → shadow → stage → preview
 * (real HTTP) → publish → reconcile. Negative cases cover
 * forged / expired / wrong-actor credentials, baseline drift,
 * and outside-the-four-fields shadow changes.
 *
 * The orchestrator and the parent test define the structured
 * JSON report shape. This child only writes the report.
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

// argv: 1 WordPress root, 2 report path. DB / site come from the
// same test-bed config the parent runner used to spawn us, so the
// same child code runs against the integration site when
// AGENTSHELL_TEST_BED_CONFIG points there. Read it directly (not
// via agentshell-test-bed.php) to avoid clashing with the
// ABSPATH define we set later from $WP_ROOT.
$WP_ROOT  = (string) ( $argv[1] ?? '' );
$REPORT   = (string) ( $argv[2] ?? '' );

$__asint_bed_cfg_path = getenv( 'AGENTSHELL_TEST_BED_CONFIG' );
if ( ! is_string( $__asint_bed_cfg_path ) || '' === $__asint_bed_cfg_path ) {
    $__asint_bed_cfg_path = __DIR__ . '/config/test-bed.json';
}
$__asint_bed_cfg = is_file( $__asint_bed_cfg_path )
    ? json_decode( (string) file_get_contents( $__asint_bed_cfg_path ), true )
    : null;
if ( ! is_array( $__asint_bed_cfg ) ) {
    fwrite( STDERR, "test-bed config not found or malformed at {$__asint_bed_cfg_path}\n" );
    exit( 2 );
}
$DB_SOCK  = (string) ( $__asint_bed_cfg['db']['socket']   ?? '' );
$DB_USER  = (string) ( $__asint_bed_cfg['db']['user']     ?? '' );
$DB_PASS  = (string) ( $__asint_bed_cfg['db']['password'] ?? '' );
$DB_NAME  = (string) ( $__asint_bed_cfg['db']['name']     ?? '' );
$SITE     = rtrim( (string) ( $__asint_bed_cfg['url_http'] ?? '' ), '/' );
$MARKER   = 'stage2c_e2e';
unset( $__asint_bed_cfg, $__asint_bed_cfg_path );

if ( '' === $WP_ROOT || '' === $REPORT ) {
    fwrite( STDERR, "usage: <child> <wp_root> <report_path>\n" );
    exit( 2 );
}
// Fail-closed identity guard — refuses the script if the wp_root
// does not match the test-bed profile. The guard itself checks
// wp-load.php existence plus the full identity chain (DB name,
// server_uuid, sentinel, siteurl, grants, open-transaction guard),
// and prints a structured refusal + exits 7 on any mismatch. The
// legacy `is_file(wp-load.php)` check that used to sit here is now
// redundant: the guard runs it as the very first check.
require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $WP_ROOT, __FILE__ );

define( 'ABSPATH', rtrim( $WP_ROOT, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = (string) ( $__asint_bed_cfg['domain'] ?? parse_url( (string) ( $__asint_bed_cfg['url_http'] ?? '' ), PHP_URL_HOST ) ?? 'localhost' );
$_SERVER['REQUEST_URI']  = '/';
ini_set( 'mysqli.default_socket', $DB_SOCK );
require_once ABSPATH . 'wp-load.php';

if ( ! defined( 'AGENTSHELL_MCP_VERSION' ) ) {
    fwrite( STDERR, "agentshell-mcp plugin is not active — end-to-end cannot be exercised\n" );
    exit( 2 );
}

$mysqli = new mysqli( 'localhost', $DB_USER, $DB_PASS, $DB_NAME, 0, $DB_SOCK );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "db connect fail: " . $mysqli->connect_errno . "\n" );
    exit( 2 );
}
$GLOBALS['mysqli'] = $mysqli;

$agent_user_id = $mysqli->query( "SELECT ID FROM wp_users WHERE user_login='agent'" )->fetch_assoc();
if ( $agent_user_id ) {
    $agent_user_id = (int) $agent_user_id['ID'];
} else {
    $mysqli->query(
        "INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_registered, user_activation_key, user_status, display_name) "
        . "VALUES ('agent', '', 'agent', 'agent@stage-2c-test.local', '2026-10-17 00:00:00', '', 0, 'Stage 2c agent')"
    );
    $agent_user_id = (int) $mysqli->insert_id;
}
wp_set_current_user( $agent_user_id );

require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-store.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-transactions.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-write-guard.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-journal.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-recovery-advisory-lock.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-claim.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-shadow.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-preview-overlay.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-post-preview.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-recovery.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-base-tool.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit-reconcile.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Published_Preview_Overlay;
use AgentShell_MCP\Post_Preview;
use AgentShell_MCP\Recovery_Advisory_Lock;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Publish_Edit;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

$db = function( $sql ) use ( $mysqli ) {
    $res = $mysqli->query( $sql );
    if ( ! $res ) { throw new RuntimeException( "db fail: " . $mysqli->error . " on " . $sql ); }
    return $res;
};

function e2e_reset_state( $mysqli ) {
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_claim_%'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_published_claim_%'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_audit_log'" );
    $mysqli->query( "DELETE FROM wp_postmeta WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')" );
    wp_cache_delete( 'agentshell_transaction', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( 'alloptions', 'options' );
    Content_Write_Guard::reset();
    Recovery_Advisory_Lock::reset();
}

function e2e_seed_original( $mysqli, $marker, $suffix ) {
    $name = "{$marker}-orig-{$suffix}";
    $content = $mysqli->real_escape_string( "orig content {$suffix}" );
    $title   = $mysqli->real_escape_string( "E2E Orig Title {$suffix}" );
    $excerpt = $mysqli->real_escape_string( "orig excerpt" );
    $name_q  = $mysqli->real_escape_string( $name );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$name_q}', 'publish', 'page', 0, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    return (int) $mysqli->insert_id;
}

function e2e_seed_shadow( $mysqli, $orig_id, $marker, $suffix, $staged, $op_id ) {
    $name = "{$marker}-shadow-{$suffix}";
    $title    = $mysqli->real_escape_string( (string) ( $staged['post_title']   ?? '' ) );
    $content  = $mysqli->real_escape_string( (string) ( $staged['post_content'] ?? '' ) );
    $excerpt  = $mysqli->real_escape_string( (string) ( $staged['post_excerpt'] ?? '' ) );
    $pname    = $mysqli->real_escape_string( (string) ( $staged['post_name']    ?? '' ) );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$pname}', 'private', 'page', " . (int) $orig_id . ", '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    $shadow_id = (int) $mysqli->insert_id;
    update_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, (string) $orig_id );
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, (string) $op_id );
    return $shadow_id;
}

function e2e_drop_fixture( $mysqli, $orig_id, $shadow_id ) {
    $mysqli->query( "DELETE FROM wp_postmeta WHERE post_id IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_posts WHERE ID IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_claim_" . (int) $orig_id . "'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_published_claim_" . (int) $orig_id . "'" );
}

function e2e_http_get( $url ) {
    $ctx = stream_context_create( array(
        'http' => array(
            'timeout'         => 15,
            'header'          => "User-Agent: stage-2c-e2e\r\n",
            'follow_location' => 1,
            'max_redirects'   => 2,
            'ignore_errors'   => true,
        ),
    ) );
    $body = @file_get_contents( $url, false, $ctx );
    $code = 0;
    foreach ( (array) ( $http_response_header ?? array() ) as $h ) {
        if ( preg_match( '#HTTP/\S+\s+(\d+)#', $h, $m ) ) { $code = (int) $m[1]; }
    }
    return array( 'code' => $code, 'body' => (string) $body );
}

// ── E1: happy path lifecycle ───────────────────────────────────
$e1 = array();
try {
    e2e_reset_state( $mysqli );
    $suffix = 'e1-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $orig_id = e2e_seed_original( $mysqli, $MARKER, $suffix );

    $staged = array(
        'post_title'   => 'E1 Staged Title ' . $suffix,
        'post_content' => '<p>e1 staged content ' . $suffix . '</p>',
        'post_excerpt' => 'E1 staged excerpt',
        'post_name'    => 'e1-staged-slug-' . $suffix,
    );

    // E1.1: capture baseline
    $current = $db( "SELECT post_title, post_content, post_excerpt, post_name, post_status, post_parent, post_type, post_author, post_date, post_date_gmt, post_mime_type, comment_status, ping_status, menu_order FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc();
    $e1['baseline'] = array(
        'post_type'    => (string) ( $current['post_type'] ?? '' ),
        'post_status'  => (string) ( $current['post_status'] ?? '' ),
        'post_title'   => (string) ( $current['post_title'] ?? '' ),
        'post_content' => (string) ( $current['post_content'] ?? '' ),
        'post_excerpt' => (string) ( $current['post_excerpt'] ?? '' ),
        'post_name'    => (string) ( $current['post_name'] ?? '' ),
        'post_author'  => (int) ( $current['post_author'] ?? 0 ),
        'post_parent'  => (int) ( $current['post_parent'] ?? 0 ),
    );

    // E1.3: create shadow with staged values (the seed inserts the shadow with
    // post_status='agentshell-shadow' to mark it as a private shadow)
    $shadow_id = e2e_seed_shadow( $mysqli, $orig_id, $MARKER, $suffix, $staged, '__pending__' );
    $shadow_row = $db( "SELECT post_status FROM wp_posts WHERE ID=" . (int) $shadow_id )->fetch_assoc();
    $e1['shadow_status'] = (string) ( $shadow_row['post_status'] ?? '' );

    // Open tx, stage the op, attach, claim
    $tx_mgr = Tx::instance();
    if ( $tx_mgr->is_active() ) { $tx_mgr->rollback(); }
    $tx = $tx_mgr->begin( 'agent' );
    $tx_id = (string) $tx['id'];
    $op = Content_Journal::record_intent(
        Content_Journal::OP_EDIT_PUBLISHED,
        array( 'post_type' => 'page', 'post_status' => 'publish', 'original_id' => (int) $orig_id, 'shadow_id' => (int) $shadow_id ),
        array(
            'post_title'   => $staged['post_title'],
            'post_content' => $staged['post_content'],
            'post_excerpt' => $staged['post_excerpt'],
            'post_name'    => $staged['post_name'],
        )
        // actor defaults to Store::actor() (user_login), matching the
        // production staging path; the overlay resolves the op's actor
        // to a user_id and compares it against the credential's actor.
    );
    $op_id = (string) $op['id'];
    Content_Journal::attach( $tx, $op );
    $row_now = (array) get_option( Tx::KEY, null );
    $expected = $row_now;
    $expected['content']['ops'][ $op_id ]['before'] = array(
        'post_type'             => 'page',
        'post_status'           => 'publish',
        'post_title'            => (string) ( $current['post_title'] ?? '' ),
        'post_content'          => (string) ( $current['post_content'] ?? '' ),
        'post_excerpt'          => (string) ( $current['post_excerpt'] ?? '' ),
        'post_name'             => (string) ( $current['post_name'] ?? '' ),
        'post_author'           => (int) ( $current['post_author'] ?? 0 ),
        'post_parent'           => (int) ( $current['post_parent'] ?? 0 ),
        'post_date'             => (string) ( $current['post_date'] ?? '' ),
        'post_date_gmt'         => (string) ( $current['post_date_gmt'] ?? '' ),
        'post_modified_gmt'     => (string) ( $current['post_date_gmt'] ?? '' ),
        'post_content_sha256'   => hash( 'sha256', (string) ( $current['post_content'] ?? '' ) ),
        'post_content_length'   => strlen( (string) ( $current['post_content'] ?? '' ) ),
    );
    $expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
    Tx::cas_write_row( $row_now, $expected );
    // The overlay's verify_and_install gate requires the shadow's META_OP_ID,
    // META_SHADOW_FOR, AND META_TX_ID to all match the credential. The seed
    // sets META_SHADOW_FOR before tx_id exists; we set the other two here
    // once the op + tx are known.
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, $op_id );
    update_post_meta( $shadow_id, Published_Shadow::META_TX_ID, $tx_id );
    $claim_row = Published_Claim::shape( $tx_id, $orig_id, $shadow_id, 'agent', time(), time(), Published_Claim::TTL_SECONDS );
    Published_Claim::create( $orig_id, $claim_row );

    $e1['claim_acquired'] = is_array( Published_Claim::read( $orig_id ) );
    $e1['staged'] = $staged;
    $e1['orig_id'] = (int) $orig_id;
    $e1['shadow_id'] = (int) $shadow_id;
    $e1['op_id'] = $op_id;
    $e1['tx_id'] = $tx_id;

    // E1.5: anonymous request to the original's slug returns the original
    // (no preview overlay, no shadow leak). We use the original's CURRENT slug
    // because the staged change has not been published yet.
    $e1['orig_slug'] = (string) ( $current['post_name'] ?? '' );
    $anon_url = $SITE . '/' . ltrim( $e1['orig_slug'], '/' ) . '/';
    $r = e2e_http_get( $anon_url );
    $e1['anon_status_before_publish'] = (int) $r['code'];
    $e1['anon_sees_staged_before_publish'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged['post_title'] );

    // E1.6: authorised private preview with HMAC credential
    $cred_args = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id, $agent_user_id );
    $cred_url = $anon_url . '?' . http_build_query( $cred_args );
    $r = e2e_http_get( $cred_url );
    $e1['preview_status'] = (int) $r['code'];
    $e1['preview_sees_staged_title'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged['post_title'] );

    // E1.7: publish
    $pub = ( new Publish_Edit() )->execute( array() );
    $e1['publish_state'] = (string) ( $pub['state'] ?? '' );
    $e1['recon_status'] = (string) ( $pub['reconciliation']['status'] ?? '' );
    $row_post = $db( "SELECT post_title, post_content, post_excerpt, post_name, post_status, post_parent, post_type, post_author FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc();
    $e1['post_after_publish'] = $row_post;
    $e1['post_title_after_publish'] = (string) ( $row_post['post_title'] ?? '' );

    // E1.9: journal + audit log
    $audit_published = (int) $db( "SELECT COUNT(*) FROM wp_options WHERE option_name = 'agentshell_audit_log' AND option_value LIKE '%transaction_published%'" )->fetch_assoc()['COUNT(*)'];
    $e1['audit_published_count'] = $audit_published;
    $row_now = (array) get_option( Tx::KEY, null );
    $e1['op_on_disk'] = $row_now['content']['ops'][ $op_id ] ?? null;

    // E1.11: anonymous request to the new slug returns the published content
    $new_slug = (string) ( $row_post['post_name'] ?? '' );
    $new_url = $SITE . '/' . ltrim( $new_slug, '/' ) . '/';
    $r = e2e_http_get( $new_url );
    $e1['anon_status_after_publish'] = (int) $r['code'];
    $e1['anon_sees_published_after_publish'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged['post_title'] );

    // E1.10: shadow, claim, caches
    $e1['shadow_exists_after_reconcile'] = (bool) $db( "SELECT ID FROM wp_posts WHERE ID=" . (int) $shadow_id )->fetch_assoc();
    $e1['claim_present_after_reconcile'] = is_array( Published_Claim::read( $orig_id ) );
    $e1['caches_invalidated'] = (bool) ( $e1['op_on_disk']['reconciliation']['caches_invalidated'] ?? false );
} catch ( \Throwable $t ) {
    $e1['threw'] = $t->getMessage();
    $e1['threw_class'] = get_class( $t );
}

// ── E2: retry safety ───────────────────────────────────────────
$e2 = array();
try {
    // E2.1: second publish_edit must be refused (op is already settled)
    $second_publish_threw = false;
    $second_publish_reason = '';
    try {
        ( new Publish_Edit() )->execute( array() );
    } catch ( \Throwable $t ) {
        $second_publish_threw = true;
        $second_publish_reason = $t->getMessage();
    }
    $e2['second_publish_outcome'] = $second_publish_threw ? 'refused' : 'committed';
    $e2['second_publish_reason'] = $second_publish_reason;

    // E2.2: second reconcile must be refused
    $second_reconcile_threw = false;
    $second_reconcile_reason = '';
    try {
        ( new Publish_Edit_Reconcile() )->execute( array( 'op_id' => $e1['op_id'] ) );
    } catch ( \Throwable $t ) {
        $second_reconcile_threw = true;
        $second_reconcile_reason = $t->getMessage();
    }
    $e2['second_reconcile_outcome'] = $second_reconcile_threw ? 'refused' : 'reconciled';
    $e2['second_reconcile_reason'] = $second_reconcile_reason;

    $audit_published_after = (int) $db( "SELECT COUNT(*) FROM wp_options WHERE option_name = 'agentshell_audit_log' AND option_value LIKE '%transaction_published%'" )->fetch_assoc()['COUNT(*)'];
    $e2['audit_published_unchanged'] = $audit_published_after;

    // E2.3: anonymous request to the shadow's slug (which was the staged
    // slug before the publish landed it on the original) should now serve
    // the original's PUBLISHED content (the slug is now the original's
    // post_name, not a private shadow). What we verify is that the
    // response is the original's published view, NOT a leak of the
    // shadow-as-private-content. With post_status='private' on the shadow
    // and the shadow deleted, the canonical redirect on the original's
    // new slug serves the published view.
    $shadow_slug = (string) ( $staged['post_name'] ?? '' );
    $shadow_url = $SITE . '/' . ltrim( $shadow_slug, '/' ) . '/';
    $r = e2e_http_get( $shadow_url );
    $e2['shadow_slug_status'] = (int) $r['code'];
    // After publish+reconcile, the original owns this slug. The published
    // title is exactly the staged title, so seeing it is the CORRECT
    // outcome. What we forbid is anything shadow-specific (the shadow is
    // gone). 200 + staged title is the correct behavior.
    $e2['shadow_slug_leaked'] = is_string( $r['body'] ) && (int) $r['code'] === 200 && false !== strpos( $r['body'], $staged['post_title'] );
} catch ( \Throwable $t ) {
    $e2['threw'] = $t->getMessage();
}

// ── E3: negative paths ─────────────────────────────────────────
$e3 = array();
try {
    // Provision a fresh fixture for the negative cases so E2's refusal
    // mutations don't poison the fixture for E3.
    e2e_reset_state( $mysqli );
    $suffix = 'e3-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $orig_id = e2e_seed_original( $mysqli, $MARKER, $suffix );
    $staged_n = array(
        'post_title'   => 'E3 Staged Title ' . $suffix,
        'post_content' => '<p>e3 staged content ' . $suffix . '</p>',
        'post_excerpt' => 'E3 staged excerpt',
        'post_name'    => 'e3-staged-slug-' . $suffix,
    );
    $shadow_id = e2e_seed_shadow( $mysqli, $orig_id, $MARKER, $suffix, $staged_n, '__pending__' );

    $tx_mgr = Tx::instance();
    if ( $tx_mgr->is_active() ) { $tx_mgr->rollback(); }
    $tx = $tx_mgr->begin( 'agent' );
    $tx_id = (string) $tx['id'];
    $op = Content_Journal::record_intent(
        Content_Journal::OP_EDIT_PUBLISHED,
        array( 'post_type' => 'page', 'post_status' => 'publish', 'original_id' => (int) $orig_id, 'shadow_id' => (int) $shadow_id ),
        array(
            'post_title'   => $staged_n['post_title'],
            'post_content' => $staged_n['post_content'],
            'post_excerpt' => $staged_n['post_excerpt'],
            'post_name'    => $staged_n['post_name'],
        )
    );
    $op_id = (string) $op['id'];
    Content_Journal::attach( $tx, $op );
    $row_now = (array) get_option( Tx::KEY, null );
    $expected = $row_now;
    $expected['content']['ops'][ $op_id ]['before'] = array(
        'post_type'             => 'page',
        'post_status'           => 'publish',
        'post_title'            => (string) ( $current['post_title'] ?? '' ),
        'post_content'          => (string) ( $current['post_content'] ?? '' ),
        'post_excerpt'          => (string) ( $current['post_excerpt'] ?? '' ),
        'post_name'             => (string) ( $current['post_name'] ?? '' ),
        'post_author'           => (int) ( $current['post_author'] ?? 0 ),
        'post_parent'           => (int) ( $current['post_parent'] ?? 0 ),
        'post_date'             => (string) ( $current['post_date'] ?? '' ),
        'post_date_gmt'         => (string) ( $current['post_date_gmt'] ?? '' ),
        'post_modified_gmt'     => (string) ( $current['post_date_gmt'] ?? '' ),
        'post_content_sha256'   => hash( 'sha256', (string) ( $current['post_content'] ?? '' ) ),
        'post_content_length'   => strlen( (string) ( $current['post_content'] ?? '' ) ),
    );
    $expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
    Tx::cas_write_row( $row_now, $expected );
    // See note in E1 — the overlay's gate requires META_OP_ID + META_TX_ID
    // (META_SHADOW_FOR was set at seed time).
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, $op_id );
    update_post_meta( $shadow_id, Published_Shadow::META_TX_ID, $tx_id );
    $claim_row = Published_Claim::shape( $tx_id, $orig_id, $shadow_id, 'agent', time(), time(), Published_Claim::TTL_SECONDS );
    Published_Claim::create( $orig_id, $claim_row );

    $e3['orig_slug'] = (string) ( $mysqli->query( "SELECT post_name FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc()['post_name'] ?? '' );
    $anon_url = $SITE . '/' . ltrim( $e3['orig_slug'], '/' ) . '/';

    // E3.1: forged credential — flip one hex char of the key
    $cred_args = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id, $agent_user_id );
    $decoded = json_decode( base64_decode( $cred_args[ Post_Preview::PUB_PREVIEW_CRED_ARG ], true ), true );
    $decoded['key'] = 'a' . substr( (string) ( $decoded['key'] ?? '' ), 1 );
    $tampered_args = array(
        Post_Preview::PUB_PREVIEW_FLAG_ARG => '1',
        Post_Preview::PUB_PREVIEW_CRED_ARG => base64_encode( json_encode( $decoded ) ),
    );
    $tampered_url = $anon_url . '?' . http_build_query( $tampered_args );
    $r = e2e_http_get( $tampered_url );
    $e3['forged_status'] = (int) $r['code'];
    $e3['forged_sees_staged'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged_n['post_title'] );

    // E3.2: expired credential — replay with exp=time()-60
    $decoded['exp'] = time() - 60;
    $secret = defined( 'AGENTSHELL_REST_TOKEN' ) ? AGENTSHELL_REST_TOKEN : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' ) );
    $decoded['key'] = hash_hmac( 'sha256',
        'agentshell-published-edit-preview|'
        . (int) $orig_id . '|'
        . (int) $shadow_id . '|'
        . $tx_id . '|'
        . $op_id . '|'
        . (int) $agent_user_id . '|'
        . (int) $decoded['exp'],
        $secret
    );
    $expired_args = array(
        Post_Preview::PUB_PREVIEW_FLAG_ARG => '1',
        Post_Preview::PUB_PREVIEW_CRED_ARG => base64_encode( json_encode( $decoded ) ),
    );
    $expired_url = $anon_url . '?' . http_build_query( $expired_args );
    $r = e2e_http_get( $expired_url );
    $e3['expired_sees_staged'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged_n['post_title'] );

    // E3.3: wrong-actor credential
    $wrong_actor_url = $anon_url . '?' . http_build_query(
        Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id, 7 )
    );
    $r = e2e_http_get( $wrong_actor_url );
    $e3['wrong_actor_sees_staged'] = is_string( $r['body'] ) && false !== strpos( $r['body'], $staged_n['post_title'] );

    // E3.4: baseline drift refusal — mutate the original AFTER staging, then
    // attempt publish. The publish-edit tool's before/after hash check refuses
    // a publish against a drifted baseline (the live original has diverged
    // from the staged op's before-image). The drift refusal lives at the
    // publish boundary, not the preview boundary — the overlay's HMAC and
    // meta gates are credential-scoped, not content-scoped.
    $drift_title = 'E3 drift orig ' . $suffix;
    $db( "UPDATE wp_posts SET post_title='" . $mysqli->real_escape_string( $drift_title ) . "' WHERE ID=" . (int) $orig_id );
    $drift_threw = false;
    $drift_reason = '';
    try {
        ( new Publish_Edit() )->execute( array() );
    } catch ( \Throwable $t ) {
        $drift_threw = true;
        $drift_reason = $t->getMessage();
    }
    $e3['drift_publish_threw']  = $drift_threw;
    $e3['drift_publish_reason'] = $drift_reason;
    // Restore the original's title so the E3 fixture is consistent.
    $db( "UPDATE wp_posts SET post_title='" . $mysqli->real_escape_string( (string) ( $current['post_title'] ?? '' ) ) . "' WHERE ID=" . (int) $orig_id );

    // E3.5: outside-the-four-fields change to the shadow must be refused
    // at the publish boundary. Publish_Edit::execute reads ONLY the four
    // staged fields (post_title, post_content, post_excerpt, post_name) from
    // the shadow row. A foreign column on the shadow (post_author=9999)
    // must NOT flow through to the original. The expected behavior is the
    // original's post_author is unchanged after a "publish" attempt.
    $db( "UPDATE wp_posts SET post_author=9999 WHERE ID=" . (int) $shadow_id );
    $orig_author_before = (int) $db( "SELECT post_author FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc()['post_author'];
    $outside_threw = false;
    $outside_reason = '';
    $orig_author_after = $orig_author_before;
    try {
        ( new Publish_Edit() )->execute( array() );
    } catch ( \Throwable $t ) {
        $outside_threw = true;
        $outside_reason = $t->getMessage();
    }
    $orig_author_after = (int) $db( "SELECT post_author FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc()['post_author'];
    // The publish-edit tool's only obligation on a fifth-field change is to
    // not write it to the original. The original's post_author is preserved
    // either by refusal (throw) OR by completion with no mutation. We assert
    // the original's post_author is unchanged, not the throw.
    $e3['outside_fields_outcome'] = ( $orig_author_after === $orig_author_before ) ? 'preserved' : 'allowed';
    $e3['outside_fields_threw']   = $outside_threw;
    $e3['outside_fields_reason']  = $outside_reason;
    $e3['orig_author_before']     = $orig_author_before;
    $e3['orig_author_after']      = $orig_author_after;

    e2e_drop_fixture( $mysqli, $orig_id, $shadow_id );
} catch ( \Throwable $t ) {
    $e3['threw'] = $t->getMessage();
}

// ── Final cleanup ──────────────────────────────────────────────
if ( isset( $e1['orig_id'] ) && isset( $e1['shadow_id'] ) ) {
    e2e_drop_fixture( $mysqli, $e1['orig_id'], $e1['shadow_id'] );
}
e2e_reset_state( $mysqli );
$mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_audit_log'" );
wp_cache_flush();

file_put_contents( $REPORT, json_encode( array(
    'E1' => $e1,
    'E2' => $e2,
    'E3' => $e3,
) ) );
echo "stage-2c-end-to-end child reported\n";
