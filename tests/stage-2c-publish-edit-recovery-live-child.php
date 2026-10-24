<?php
/**
 * Stage 2c M5-t2 — post-COMMIT recovery child.
 *
 * argv: 1 WordPress root  2 report path
 *
 * Boots a real WordPress, provisions a fixture (original + shadow +
 * staged op + claim), then drives a three-phase recovery scenario:
 *
 *   Phase A: spawn a pre-commit child that runs the publish SQL
 *            transaction and exits without reconciliation. The
 *            child raises a barrier when its post-COMMIT evidence
 *            is on disk; the orchestrator closes the process
 *            (simulating a post-COMMIT crash).
 *
 *   Phase B: spawn a recover child in a fresh PHP process. The
 *            child calls Publish_Edit_Reconcile::execute() against
 *            the op left in publication_committed by phase A.
 *
 *   Phase C: spawn another recover child to verify that re-running
 *            reconciliation against the now-settled op refuses
 *            with no side effects.
 *
 * The race patterns are re-derived from the M4 diagnostic scripts
 * (tests/diag-m4-issue-3-orchestrator.php,
 * tests/diag-m4-issue-3-pre-commit.php,
 * tests/diag-m4-issue-3-recover.php). The M4 diags proved the
 * contract; this file commits them as tracked regression coverage.
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
    // The profile names a database and its password, so its default
    // location is outside the repository: the repository is a theme, and
    // a theme is a directory a web server serves.
    $__asint_home = getenv( 'HOME' );
    if ( ! is_string( $__asint_home ) || '' === $__asint_home ) {
        $__asint_home = sys_get_temp_dir();
    }
    $__asint_bed_cfg_path = rtrim( $__asint_home, '/' ) . '/.config/agentshell/test-bed.json';
    if ( ! is_file( $__asint_bed_cfg_path ) ) {
        $__asint_bed_cfg_path = __DIR__ . '/config/test-bed.json';
    }
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
$MARKER   = 'stage2c_pe_recovery';
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
    fwrite( STDERR, "agentshell-mcp plugin is not active — recovery test cannot be exercised\n" );
    exit( 2 );
}

$mysqli = new mysqli( 'localhost', $DB_USER, $DB_PASS, $DB_NAME, 0, $DB_SOCK );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "db connect fail: " . $mysqli->connect_errno . "\n" );
    exit( 2 );
}
$GLOBALS['mysqli'] = $mysqli;

// Authenticate the current PHP process as the configured static-token actor.
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
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-recovery.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-base-tool.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit-reconcile.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Recovery_Advisory_Lock;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

$db = function( $sql ) use ( $mysqli ) {
    $res = $mysqli->query( $sql );
    if ( ! $res ) { throw new RuntimeException( "db fail: " . $mysqli->error . " on " . $sql ); }
    return $res;
};

// ── Helpers ────────────────────────────────────────────────────
function pe_rec_reset_state( $mysqli ) {
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

function pe_rec_seed_original( $mysqli, $marker, $suffix ) {
    $name = "{$marker}-orig-{$suffix}";
    $content = $mysqli->real_escape_string( "orig content {$suffix}" );
    $title   = $mysqli->real_escape_string( "Orig Title {$suffix}" );
    $excerpt = $mysqli->real_escape_string( "orig excerpt" );
    $name_q  = $mysqli->real_escape_string( $name );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$name_q}', 'publish', 'page', 0, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    return (int) $mysqli->insert_id;
}

function pe_rec_seed_shadow( $mysqli, $orig_id, $marker, $suffix, $staged, $op_id ) {
    $name = "{$marker}-shadow-{$suffix}";
    $title    = $mysqli->real_escape_string( (string) ( $staged['post_title']   ?? '' ) );
    $content  = $mysqli->real_escape_string( (string) ( $staged['post_content'] ?? '' ) );
    $excerpt  = $mysqli->real_escape_string( (string) ( $staged['post_excerpt'] ?? '' ) );
    $pname    = $mysqli->real_escape_string( (string) ( $staged['post_name']    ?? '' ) );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$pname}', 'agentshell-shadow', 'page', " . (int) $orig_id . ", '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    $shadow_id = (int) $mysqli->insert_id;
    update_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, (string) $orig_id );
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, (string) $op_id );
    return $shadow_id;
}

function pe_rec_drop_fixture( $mysqli, $orig_id, $shadow_id ) {
    $mysqli->query( "DELETE FROM wp_postmeta WHERE post_id IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_posts WHERE ID IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_claim_" . (int) $orig_id . "'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_published_claim_" . (int) $orig_id . "'" );
}

function pe_rec_spawn( $script, $args, $barrier_dir, $log_name, $wp_root ) {
    // The same PHP that is running this script, and the child scripts
    // that sit next to it. Both used to be absolute paths into one
    // developer's machine.
    $php = PHP_BINARY;
    $env = 'AGENTSHELL_TEST_WP_ROOT=' . escapeshellarg( $wp_root ) . ' ';
    if ( getenv( 'AGENTSHELL_TEST_BED_CONFIG' ) ) {
        $env .= 'AGENTSHELL_TEST_BED_CONFIG=' . escapeshellarg( (string) getenv( 'AGENTSHELL_TEST_BED_CONFIG' ) ) . ' ';
    }
    $ini = '-d mysqli.default_socket=/home/v/.config/Local/run/JcloZdmY7/mysql/mysqld.sock';
    $log = $barrier_dir . '/' . $log_name;
    $cmd = $env . escapeshellarg( $php ) . ' ' . $ini . ' ' . escapeshellarg( $script );
    foreach ( $args as $a ) { $cmd .= ' ' . escapeshellarg( $a ); }
    $cmd .= ' > ' . escapeshellarg( $log ) . ' 2>&1';
    $proc = proc_open( $cmd, array(), $pipes );
    if ( ! is_resource( $proc ) ) { fwrite( STDERR, "spawn failed: {$cmd}\n" ); return false; }
    return array( 'proc' => $proc, 'log' => $log );
}

function pe_rec_wait_for( $path, $timeout_seconds = 30.0 ) {
    $deadline = microtime( true ) + $timeout_seconds;
    while ( microtime( true ) < $deadline ) {
        if ( file_exists( $path ) ) { return true; }
        usleep( 5000 );
    }
    return false;
}

function pe_rec_read_json( $path ) {
    $raw = @file_get_contents( $path );
    if ( false === $raw || '' === $raw ) { return null; }
    $d = json_decode( $raw, true );
    return is_array( $d ) ? $d : null;
}

// ── Provision the fixture ──────────────────────────────────────
pe_rec_reset_state( $mysqli );
$suffix = 'r3-' . substr( md5( uniqid( '', true ) ), 0, 8 );
$orig_id = pe_rec_seed_original( $mysqli, $MARKER, $suffix );

$staged = array(
    'post_title'   => 'R3 published ' . $suffix,
    'post_content' => '<p>r3 published ' . $suffix . '</p>',
    'post_excerpt' => '',
    'post_name'    => 'r3-after-' . $suffix,
);
$shadow_id = pe_rec_seed_shadow( $mysqli, $orig_id, $MARKER, $suffix, $staged, '__pending__' );

// Capture baseline.
$current = $db( "SELECT post_title, post_content, post_excerpt, post_name, post_status, post_parent, post_type, post_author, post_date, post_date_gmt, post_mime_type, comment_status, ping_status, menu_order FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc();
$baseline = array(
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

// Open tx, stage the op, attach, claim.
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
);
$op_id = (string) $op['id'];
Content_Journal::attach( $tx, $op );
$row_now = (array) get_option( Tx::KEY, null );
$expected = $row_now;
$expected['content']['ops'][ $op_id ]['before'] = $baseline;
$expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
Tx::cas_write_row( $row_now, $expected );
update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, $op_id );
$claim_row = Published_Claim::shape( $tx_id, $orig_id, $shadow_id, 'agent', time(), time(), Published_Claim::TTL_SECONDS );
Published_Claim::create( $orig_id, $claim_row );

// ── Phase A: pre-commit child (publish SQL tx, no reconcile) ──
$barrier_dir = sys_get_temp_dir() . '/as-pe-recovery-' . getmypid();
@mkdir( $barrier_dir );
foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }

$pre_report = $barrier_dir . '/A.json';
$pre_proc = pe_rec_spawn(
    __DIR__ . '/stage-2c-publish-edit-recovery-child-pre-commit.php',
    array( $pre_report, $op_id, $barrier_dir, (string) $orig_id, (string) $shadow_id ),
    $barrier_dir,
    'A.log',
    $WP_ROOT
);
if ( ! is_array( $pre_proc ) ) {
    fwrite( STDERR, "phase A spawn failed\n" );
    exit( 4 );
}

// Wait for the pre-commit child to finish its post-COMMIT evidence write.
if ( ! pe_rec_wait_for( $barrier_dir . '/A_done', 30.0 ) ) {
    fwrite( STDERR, "phase A timed out\n" );
    proc_close( $pre_proc['proc'] );
    exit( 5 );
}

$A = pe_rec_read_json( $pre_report );
if ( null === $A ) { fwrite( STDERR, "phase A produced no report\n" ); proc_close( $pre_proc['proc'] ); exit( 5 ); }

// Tell the pre-commit child to exit (it blocks on A_exit).
file_put_contents( $barrier_dir . '/A_exit', '1' );
proc_close( $pre_proc['proc'] );

// ── Phase B: recover in a fresh process ───────────────────────
$rec_report = $barrier_dir . '/B.json';
$rec_proc = pe_rec_spawn(
    __DIR__ . '/stage-2c-publish-edit-recovery-child-recover.php',
    array( $rec_report, $op_id, (string) $orig_id, (string) $shadow_id ),
    $barrier_dir,
    'B.log',
    $WP_ROOT
);
if ( ! is_array( $rec_proc ) ) {
    fwrite( STDERR, "phase B spawn failed\n" );
    exit( 6 );
}
pe_rec_wait_for( $rec_report, 30.0 );
proc_close( $rec_proc['proc'] );
$B = pe_rec_read_json( $rec_report );
if ( null === $B ) { fwrite( STDERR, "phase B produced no report\n" ); exit( 6 ); }

// ── Phase C: repeat reconcile is mutation-free ────────────────
$rep_report = $barrier_dir . '/C.json';
$rep_proc = pe_rec_spawn(
    __DIR__ . '/stage-2c-publish-edit-recovery-child-recover.php',
    array( $rep_report, $op_id, (string) $orig_id, (string) $shadow_id ),
    $barrier_dir,
    'C.log',
    $WP_ROOT
);
if ( ! is_array( $rep_proc ) ) {
    fwrite( STDERR, "phase C spawn failed\n" );
    exit( 7 );
}
pe_rec_wait_for( $rep_report, 30.0 );
proc_close( $rep_proc['proc'] );
$C = pe_rec_read_json( $rep_report );
if ( null === $C ) { fwrite( STDERR, "phase C produced no report\n" ); exit( 7 ); }

// ── Cleanup ────────────────────────────────────────────────────
pe_rec_drop_fixture( $mysqli, $orig_id, $shadow_id );
pe_rec_reset_state( $mysqli );
$mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_audit_log'" );
wp_cache_flush();
foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }
@rmdir( $barrier_dir );

file_put_contents( $REPORT, json_encode( array(
    'phaseA' => $A,
    'phaseB' => $B,
    'phaseC' => $C,
) ) );
echo "stage-2c-publish-edit-recovery child reported\n";
