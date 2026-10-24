<?php
/**
 * Stage 2c M5-t1 — race regression child.
 *
 * argv: 1 WordPress root  2 report path
 *
 * Boots a real WordPress, provisions two fixtures (one per race),
 * spawns two PHP processes per race that wait on a file-based
 * barrier, then writes a JSON report with the per-race outcomes.
 *
 * The race patterns are re-derived from the M4 diagnostic scripts
 * (`tests/diag-m4-issue-1-orchestrator.php` and
 * `tests/diag-m4-issue-2-orchestrator.php`). The M4 diags proved
 * the contracts; this file commits them as tracked regression
 * coverage.
 *
 *   R1: same-op publication race
 *       - provision original + shadow
 *       - open tx, stage OP_EDIT_PUBLISHED, attach, claim
 *       - spawn two children that each call Publish_Edit::execute()
 *       - release both barriers at the same instant
 *       - assert exactly one committed, one refused
 *
 *   R2: same-op reconciliation race
 *       - provision + stage + manually drive the publish SQL
 *         transaction so the op is in STATE_PUBLICATION_COMMITTED
 *         without the reconciliation tail
 *       - spawn two children that each call Publish_Edit_Reconcile::execute()
 *       - release both barriers at the same instant
 *       - assert exactly one reconciled, one refused
 *
 * Uses the same MySQL / DB conventions as the publish-edit live
 * child (LocalWP socket, agent user, etc.).
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

// argv: 1 WordPress root, 2 report path. DB credentials come from
// the same test-bed config the parent runner used to spawn us, so
// the child runs against the integration site when
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
$MARKER   = 'stage2c_pe_race';
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
// The racers are spawned with the same socket, so that they reach the
// same database this script is talking to.
$GLOBALS['asint_db_sock'] = $DB_SOCK;
require_once ABSPATH . 'wp-load.php';

if ( ! defined( 'AGENTSHELL_MCP_VERSION' ) ) {
    fwrite( STDERR, "agentshell-mcp plugin is not active — race test cannot be exercised\n" );
    exit( 2 );
}

$mysqli = new mysqli( 'localhost', $DB_USER, $DB_PASS, $DB_NAME, 0, $DB_SOCK );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "db connect fail: " . $mysqli->connect_errno . "\n" );
    exit( 2 );
}
$GLOBALS['mysqli'] = $mysqli;

// Authenticate the current PHP process as the configured static-token
// actor so Store::actor() returns the right value.
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
use AgentShell_MCP\Tools\Publish_Edit;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

$db = function( $sql ) use ( $mysqli ) {
    $res = $mysqli->query( $sql );
    if ( ! $res ) { throw new RuntimeException( "db fail: " . $mysqli->error . " on " . $sql ); }
    return $res;
};

// ── Helpers ────────────────────────────────────────────────────
function pe_race_seed_original( $mysqli, $marker, $suffix ) {
    $name = "{$marker}-orig-{$suffix}";
    $esc = $mysqli->real_escape_string( $name );
    $content = $mysqli->real_escape_string( "orig content {$suffix}" );
    $title   = $mysqli->real_escape_string( "Orig Title {$suffix}" );
    $excerpt = $mysqli->real_escape_string( "orig excerpt" );
    $name_q  = $mysqli->real_escape_string( $name );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$name_q}', 'publish', 'page', 0, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    return (int) $mysqli->insert_id;
}

function pe_race_seed_shadow( $mysqli, $orig_id, $marker, $suffix, $staged, $op_id ) {
    $name = "{$marker}-shadow-{$suffix}";
    $esc = $mysqli->real_escape_string( $name );
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

function pe_race_drop_fixture( $mysqli, $orig_id, $shadow_id ) {
    $mysqli->query( "DELETE FROM wp_postmeta WHERE post_id IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_posts WHERE ID IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_claim_" . (int) $orig_id . "'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_published_claim_" . (int) $orig_id . "'" );
}

function pe_race_reset_state( $mysqli ) {
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_claim_%'" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_published_claim_%'" );
    $mysqli->query( "DELETE FROM wp_postmeta WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')" );
    wp_cache_delete( 'agentshell_transaction', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( 'alloptions', 'options' );
    Content_Write_Guard::reset();
    Recovery_Advisory_Lock::reset();
}

function pe_race_spawn( $scenario, $op_id, $barrier_dir, $wp_root, $mode = 'publish' ) {
    // The same PHP that is running this script, and the child scripts
    // that sit next to it. Both used to be absolute paths into one
    // developer's machine, which meant the race could only ever be
    // rehearsed there.
    $php = PHP_BINARY;
    $env = 'AGENTSHELL_TEST_WP_ROOT=' . escapeshellarg( $wp_root ) . ' ';
    if ( getenv( 'AGENTSHELL_TEST_BED_CONFIG' ) ) {
        $env .= 'AGENTSHELL_TEST_BED_CONFIG=' . escapeshellarg( (string) getenv( 'AGENTSHELL_TEST_BED_CONFIG' ) ) . ' ';
    }
    $ini = '';
    if ( ! empty( $GLOBALS['asint_db_sock'] ) ) {
        $ini = '-d mysqli.default_socket=' . escapeshellarg( (string) $GLOBALS['asint_db_sock'] );
    }
    $report = $barrier_dir . '/report-' . $scenario . '.json';
    $log    = $barrier_dir . '/log-' . $scenario . '.txt';
    $script = ( 'publish' === $mode )
        ? __DIR__ . '/stage-2c-publish-edit-race-child-publish.php'
        : __DIR__ . '/stage-2c-publish-edit-race-child-reconcile.php';
    $cmd = $env . escapeshellarg( $php ) . ' ' . $ini . ' ' . escapeshellarg( $script ) . ' '
         . escapeshellarg( $report ) . ' '
         . escapeshellarg( $op_id ) . ' '
         . escapeshellarg( $barrier_dir ) . ' '
         . escapeshellarg( $scenario )
         . ' > ' . escapeshellarg( $log ) . ' 2>&1';
    $proc = proc_open( $cmd, array(), $pipes );
    if ( ! is_resource( $proc ) ) { fwrite( STDERR, "spawn failed: {$cmd}\n" ); return false; }
    return array( 'proc' => $proc, 'pipes' => $pipes, 'report' => $report, 'log' => $log );
}

function pe_race_read_report( $path ) {
    $deadline = microtime( true ) + 30.0;
    while ( microtime( true ) < $deadline ) {
        $raw = @file_get_contents( $path );
        if ( false !== $raw && '' !== $raw ) {
            $d = json_decode( $raw, true );
            if ( is_array( $d ) ) { return $d; }
        }
        usleep( 2000 );
    }
    return array( 'error' => 'report_timeout', 'path' => $path );
}

// ── R1: same-op publication race ──────────────────────────────
$report_r1 = array( 'R1_outcome' => 'not_run' );
try {
    pe_race_reset_state( $mysqli );
    $suffix = 'r1-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $orig_id = pe_race_seed_original( $mysqli, $MARKER, $suffix );

    $staged = array(
        'post_title'   => 'R1 published ' . $suffix,
        'post_content' => '<p>r1 published ' . $suffix . '</p>',
        'post_excerpt' => '',
        'post_name'    => 'r1-after-' . $suffix,
    );
    $shadow_id = pe_race_seed_shadow( $mysqli, $orig_id, $MARKER, $suffix, $staged, '__pending__' );

    // Capture baseline for the op
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

    // Open a tx, stage the op, attach, and take the per-original claim.
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
    // Inject the before-image via CAS.
    $row_now = (array) get_option( Tx::KEY, null );
    $expected = $row_now;
    $expected['content']['ops'][ $op_id ]['before'] = $baseline;
    $expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
    Tx::cas_write_row( $row_now, $expected );
    // Stamp the shadow with the real op id.
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, $op_id );
    // Take the per-original claim.
    $claim_row = Published_Claim::shape( $tx_id, $orig_id, $shadow_id, 'agent', time(), time(), Published_Claim::TTL_SECONDS );
    Published_Claim::create( $orig_id, $claim_row );

    // The orchestrator does NOT commit the tx. Publish_Edit::execute()
    // in each racing child will begin a new tx, see the op on the
    // journal, and run the full publication+reconciliation under that
    // tx's advisory lock. The orchestrator's tx remains open on the
    // journal row (committed in a child, not here).

    $barrier_dir = sys_get_temp_dir() . '/as-pe-race-r1-' . getmypid();
    @mkdir( $barrier_dir );
    foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }

    $a = pe_race_spawn( 'R1_A', $op_id, $barrier_dir, $WP_ROOT, 'publish' );
    $b = pe_race_spawn( 'R1_B', $op_id, $barrier_dir, $WP_ROOT, 'publish' );
    if ( ! is_array( $a ) || ! is_array( $b ) ) { throw new RuntimeException( 'spawn failed for R1' ); }
    usleep( 200000 );
    file_put_contents( $barrier_dir . '/R1_A_go', '1' );
    file_put_contents( $barrier_dir . '/R1_B_go', '1' );
    usleep( 5000000 );

    $A = pe_race_read_report( $a['report'] );
    $B = pe_race_read_report( $b['report'] );
    proc_close( $a['proc'] );
    proc_close( $b['proc'] );

    $outcomes = array( (string) ( $A['outcome'] ?? 'unknown' ), (string) ( $B['outcome'] ?? 'unknown' ) );
    sort( $outcomes );
    $row_after = (array) get_option( Tx::KEY, null );
    $op_after = $row_after['content']['ops'][ $op_id ] ?? null;
    $post_title_final = (string) $db( "SELECT post_title FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc()['post_title'];
    $shadow_final = (bool) $db( "SELECT ID FROM wp_posts WHERE ID=" . (int) $shadow_id )->fetch_assoc();

    $report_r1 = array(
        'staged_title'    => $staged['post_title'],
        'A'               => $A,
        'B'               => $B,
        'outcomes_sorted' => $outcomes,
        'op_state'        => (string) ( $op_after['state'] ?? '' ),
        'recon_status'    => (string) ( $op_after['reconciliation']['status'] ?? '' ),
        'post_title_final' => $post_title_final,
        'shadow_gone'     => ! $shadow_final,
    );

    // Cleanup R1
    pe_race_drop_fixture( $mysqli, $orig_id, $shadow_id );
    pe_race_reset_state( $mysqli );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_audit_log'" );
    wp_cache_flush();
    foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }
    @rmdir( $barrier_dir );
} catch ( \Throwable $t ) {
    fwrite( STDERR, "R1 failed: " . $t->getMessage() . "\n" );
    $report_r1 = array( 'R1_outcome' => 'orchestrator_threw', 'threw' => $t->getMessage() );
}

// ── R2: same-op reconciliation race ───────────────────────────
$report_r2 = array( 'R2_outcome' => 'not_run' );
try {
    pe_race_reset_state( $mysqli );
    $suffix = 'r2-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $orig_id = pe_race_seed_original( $mysqli, $MARKER, $suffix );

    $staged = array(
        'post_title'   => 'R2 published ' . $suffix,
        'post_content' => '<p>r2 published ' . $suffix . '</p>',
        'post_excerpt' => '',
        'post_name'    => 'r2-after-' . $suffix,
    );
    $shadow_id = pe_race_seed_shadow( $mysqli, $orig_id, $MARKER, $suffix, $staged, '__pending__' );

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

    // Open a tx, stage the op, attach, claim.
    $tx = Tx::instance();
    if ( $tx->is_active() ) { $tx->rollback(); }
    $tx = $tx->begin( 'agent' );
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

    // Manually drive the publish SQL transaction so the op is in
    // STATE_PUBLICATION_COMMITTED (without the reconciliation tail).
    $col_list = 'ID, post_type, post_status, post_author, post_parent, post_title, post_content, post_excerpt, post_name, post_date_gmt, post_modified_gmt';
    $after_arr = array(
        'ID' => (int) $orig_id,
        'post_title' => $staged['post_title'],
        'post_content' => $staged['post_content'],
        'post_excerpt' => $staged['post_excerpt'],
        'post_name' => $staged['post_name'],
    );
    $closure = function () use ( $orig_id, $op_id, $after_arr, $col_list, $shadow_id, $tx_id ) {
        global $wpdb;
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT {$col_list} FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $orig_id ), ARRAY_A );
        if ( ! $current ) { throw new \RuntimeException( 'orig_gone' ); }
        $upd = wp_update_post( wp_slash( $after_arr ), true );
        if ( is_wp_error( $upd ) || ! $upd ) { throw new \RuntimeException( 'wp_update_post failed' ); }
        $row = (array) get_option( Tx::KEY, null );
        $expected = $row;
        $row['content']['ops'][ $op_id ]['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
        $row['content']['sig'] = Content_Journal::sign( $row['content'] );
        $ok = Tx::cas_write_row( $expected, $row );
        if ( ! $ok ) { throw new \RuntimeException( 'cas_lost' ); }
        Store::record_event( 'agent', 'transaction_published', array(
            'transaction_id' => (string) $tx_id,
            'op_id'          => (string) $op_id,
            'original_id'    => (int) $orig_id,
            'shadow_id'      => (int) $shadow_id,
        ) );
        return $row;
    };
    Content_Write_Guard::run( $closure );

    $barrier_dir = sys_get_temp_dir() . '/as-pe-race-r2-' . getmypid();
    @mkdir( $barrier_dir );
    foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }

    $a = pe_race_spawn( 'R2_A', $op_id, $barrier_dir, $WP_ROOT, 'reconcile' );
    $b = pe_race_spawn( 'R2_B', $op_id, $barrier_dir, $WP_ROOT, 'reconcile' );
    if ( ! is_array( $a ) || ! is_array( $b ) ) { throw new RuntimeException( 'spawn failed for R2' ); }
    usleep( 200000 );
    file_put_contents( $barrier_dir . '/R2_A_go', '1' );
    file_put_contents( $barrier_dir . '/R2_B_go', '1' );
    usleep( 5000000 );

    $A = pe_race_read_report( $a['report'] );
    $B = pe_race_read_report( $b['report'] );
    proc_close( $a['proc'] );
    proc_close( $b['proc'] );

    $outcomes = array( (string) ( $A['outcome'] ?? 'unknown' ), (string) ( $B['outcome'] ?? 'unknown' ) );
    sort( $outcomes );
    $row_final = (array) get_option( Tx::KEY, null );
    $op_final = $row_final['content']['ops'][ $op_id ] ?? null;
    $shadow_final = (bool) $db( "SELECT ID FROM wp_posts WHERE ID=" . (int) $shadow_id )->fetch_assoc();
    $claim_final = Published_Claim::read( $orig_id );

    $report_r2 = array(
        'A'                    => $A,
        'B'                    => $B,
        'outcomes_sorted'      => $outcomes,
        'op_state_final'       => (string) ( $op_final['state'] ?? '' ),
        'recon_status_final'   => (string) ( $op_final['reconciliation']['status'] ?? '' ),
        'shadow_deleted_final' => ! $shadow_final,
        'claim_final_present'  => is_array( $claim_final ),
    );

    // Cleanup R2
    pe_race_drop_fixture( $mysqli, $orig_id, $shadow_id );
    pe_race_reset_state( $mysqli );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_audit_log'" );
    wp_cache_flush();
    foreach ( glob( $barrier_dir . '/*' ) as $f ) { @unlink( $f ); }
    @rmdir( $barrier_dir );
} catch ( \Throwable $t ) {
    fwrite( STDERR, "R2 failed: " . $t->getMessage() . "\n" );
    $report_r2 = array( 'R2_outcome' => 'orchestrator_threw', 'threw' => $t->getMessage() );
}

file_put_contents( $REPORT, json_encode( array( 'R1' => $report_r1, 'R2' => $report_r2 ) ) );
echo "stage-2c-publish-edit-race child reported\n";
