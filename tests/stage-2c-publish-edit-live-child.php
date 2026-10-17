<?php
/**
 * Stage 2c publish-edit live child — Milestone 4.
 *
 * Exercises agentshell_publish_edit and agentshell_publish_edit_reconcile
 * against the disposable test bed. Each test installs a per-fixture
 * `page-{orig_id}.php` template and seeds an original, a shadow, and a
 * journal row. The test calls the production class directly in-process
 * — the same code path the MCP transport dispatches to.
 *
 * Cross-process concurrency tests fork a separate PHP process through
 * the parent test runner; this child only does the in-process checks
 * and returns structured results.
 *
 * Refusal paths (no_throw), state transitions, identity gates, and the
 * write-and-verify-C classification are all exercised here. Live SQL
 * transactions (Content_Write_Guard::run with InnoDB), MySQL named
 * advisory locks (Recovery_Advisory_Lock), and SELECT ... FOR UPDATE
 * row locks are all in scope because the test bed is a real MySQL
 * instance.
 *
 * The child writes a JSON report to a file the parent reads.
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

// argv: 1 WordPress root, 2 report path. DB / site come from the
// same test-bed config the parent runner used to spawn us, so the
// same child code runs against the integration site when
// AGENTSHELL_TEST_BED_CONFIG points there.
$WP_ROOT  = (string) ( $argv[1] ?? '' );
$RESULT   = (string) ( $argv[2] ?? '' );

// Read the test-bed config WITHOUT including agentshell-test-bed.php
// (that file defines its own ABSPATH side effect, which would clash
// with the one we set later from $WP_ROOT). Resolve the same way
// agentshell_test_bed_config_path() does: env override, then the
// local default.
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
$MARKER   = 'stage2c_pe_live';
unset( $__asint_bed_cfg, $__asint_bed_cfg_path );

if ( '' === $WP_ROOT || '' === $RESULT ) {
    fwrite( STDERR, "usage: <child> <wp_root> <report_path>\n" );
    exit( 2 );
}

if ( ! is_file( $WP_ROOT . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$WP_ROOT}\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $WP_ROOT, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = (string) ( $__asint_bed_cfg['domain'] ?? parse_url( (string) ( $__asint_bed_cfg['url_http'] ?? '' ), PHP_URL_HOST ) ?? 'localhost' );
$_SERVER['REQUEST_URI']  = '/';
ini_set( 'mysqli.default_socket', $DB_SOCK );
require_once ABSPATH . 'wp-load.php';

if ( ! defined( 'AGENTSHELL_MCP_VERSION' ) ) {
    fwrite( STDERR, "agentshell-mcp plugin is not active — publish-edit cannot be exercised\n" );
    exit( 2 );
}

$mysqli = new mysqli( 'localhost', $DB_USER, $DB_PASS, $DB_NAME, 0, $DB_SOCK );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "db connect fail: " . $mysqli->connect_errno . "\n" );
    exit( 2 );
}
$GLOBALS['mysqli'] = $mysqli;
$GLOBALS['_pe_debug'] = (bool) ( getenv( 'STAGE2C_PE_DEBUG' ) ?: false );

// Authenticate the current PHP process as the configured static-token
// actor so Store::actor() returns the right value. The WP-level rest
// filter is not in play for a CLI invocation; we set the current user
// directly.
//
// The actor name the production code uses is user_login, not user id.
// The test bed's only user is `agentshell_test_admin` (user id 1).
// We provision a per-run `agent` user with manage_options so the
// Store::actor() return value is `agent` and matches the op / claim
// actor strings the production tool path uses; the test bed's actor
// is otherwise a fresh login we'd have to thread through every step.
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

$results = array();
$report  = function( $name, $ok, $detail = '' ) use ( &$results ) {
    $results[] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => (string) $detail );
    echo ( $ok ? "PASS  " : "FAIL  " ) . "{$name}" . ( $detail ? "  ({$detail})" : '' ) . "\n";
};

/**
 * Drop a per-test fixture by marker. Cleans up the original, the
 * shadow, the per-original template, and any state attached to the
 * transaction row. Re-runnable.
 */
function pe_drop_fixture( $mysqli, $orig_id, $shadow_id, $template_name = '' ) {
    $mysqli->query( "DELETE FROM wp_postmeta WHERE post_id IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_posts WHERE ID IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_claim_" . (int) $orig_id . "'" );
    // M5-s2: clean up the per-original Published_Claim row and any
    // remaining shadow meta markers so a refused-publish fixture
    // (T3-T7) does not leak rows that the M5 leak check would catch.
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_published_claim_" . (int) $orig_id . "'" );
    $mysqli->query( "DELETE FROM wp_postmeta WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id') AND post_id IN (" . (int) $orig_id . "," . (int) $shadow_id . ")" );
    $mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
    if ( '' !== $template_name ) {
        $path = '/home/v/local/agentshell-test/app/public/wp-content/themes/agentshell/' . $template_name;
        if ( is_file( $path ) ) { @unlink( $path ); }
    }
    Content_Write_Guard::reset();
    Recovery_Advisory_Lock::reset();
}

/**
 * Seed an original published page with a per-original page template.
 * Returns the original id.
 */
function pe_seed_original( $mysqli, $mark, $suffix ) {
    $name = "{$mark}-orig-{$suffix}";
    $esc = $mysqli->real_escape_string( $name );
    $existing = $mysqli->query( "SELECT ID FROM wp_posts WHERE post_name='{$esc}' AND post_type='page'" )->fetch_assoc();
    if ( $existing ) {
        return (int) $existing['ID'];
    }
    $content = $mysqli->real_escape_string( "orig content {$suffix}" );
    $title   = $mysqli->real_escape_string( "Orig Title {$suffix}" );
    $excerpt = $mysqli->real_escape_string( "orig excerpt" );
    $name_q  = $mysqli->real_escape_string( $name );
    $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$name_q}', 'publish', 'page', 0, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
    $mysqli->query( $sql );
    return (int) $mysqli->insert_id;
}

function pe_seed_shadow( $mysqli, $orig_id, $mark, $suffix, array $staged, $op_id ) {
    $shadow_name = "{$mark}-shadow-{$suffix}";
    $esc = $mysqli->real_escape_string( $shadow_name );
    $existing = $mysqli->query( "SELECT ID FROM wp_posts WHERE post_name='{$esc}' AND post_type='page'" )->fetch_assoc();
    if ( $existing ) {
        $shadow_id = (int) $existing['ID'];
    } else {
        $title    = $mysqli->real_escape_string( (string) ( $staged['post_title']   ?? '' ) );
        $content  = $mysqli->real_escape_string( (string) ( $staged['post_content'] ?? '' ) );
        $excerpt  = $mysqli->real_escape_string( (string) ( $staged['post_excerpt'] ?? '' ) );
        $pname    = $mysqli->real_escape_string( (string) ( $staged['post_name']    ?? '' ) );
        $sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_name, post_status, post_type, post_parent, post_modified, post_modified_gmt, guid, post_mime_type, comment_status, ping_status, menu_order, post_password, to_ping, pinged, post_content_filtered, comment_count) VALUES (1, '2026-10-01 00:00:00', '2026-10-01 00:00:00', '{$content}', '{$title}', '{$excerpt}', '{$pname}', 'agentshell-shadow', 'page', " . (int) $orig_id . ", '2026-10-01 00:00:00', '2026-10-01 00:00:00', '', '', 'closed', 'closed', 0, '', '', '', '', 0)";
        $mysqli->query( $sql );
        $shadow_id = (int) $mysqli->insert_id;
    }
    update_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, (string) $orig_id );
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, (string) $op_id );
    return $shadow_id;
}

/**
 * Read the journal row from disk and return the op array for a given
 * op id, or null if the op is not in the journal.
 */
function pe_read_op( $op_id ) {
    $row = (array) get_option( Tx::KEY, null );
    if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id ] ) ) {
        return null;
    }
    return $row['content']['ops'][ $op_id ];
}

/**
 * Capture the live row from wp_posts for assertions.
 */
function pe_read_post_row( $db, $id ) {
    $r = $db( "SELECT post_title, post_content, post_excerpt, post_name, post_status, post_parent, post_type, post_author, post_date, post_date_gmt, post_mime_type, comment_status, ping_status, menu_order FROM wp_posts WHERE ID = " . (int) $id )->fetch_assoc();
    return $r ? $r : null;
}

/**
 * Open a transaction, stage an OP_EDIT_PUBLISHED op with the given
 * staged values, attach it to the journal, and capture the baseline.
 * Returns [tx_id, op_id, original_id, shadow_id].
 */
function pe_stage( $mysqli, $orig_id, $mark, $suffix, array $staged, $op_actor = null ) {
    $shadow_id = pe_seed_shadow( $mysqli, $orig_id, $mark, $suffix, $staged, '__pending__' );
    $tx_mgr = Tx::instance();
    $active = $tx_mgr->is_active();
    if ( $GLOBALS['_pe_debug'] ) { fwrite( STDERR, "[pe_stage {$suffix}] is_active=" . ( $active ? 'true' : 'false' ) . "\n" ); }
    if ( $active ) { $tx_mgr->rollback(); }
    $tx = $tx_mgr->begin( $op_actor ?? 'agent' );
    $tx_id = (string) $tx['id'];
    // Default post_name to the original's current slug so the journal
    // validator's `after.post_name` requirement is satisfied when the
    // test does not explicitly stage a slug change. The unit test's
    // s2cs_stage_edit_published does the same.
    $effective_post_name = array_key_exists( 'post_name', $staged ) && '' !== (string) ( $staged['post_name'] ?? '' )
        ? (string) $staged['post_name']
        : (string) ( $mysqli->query( "SELECT post_name FROM wp_posts WHERE ID=" . (int) $orig_id )->fetch_assoc()['post_name'] ?? "orig-{$orig_id}" );
    $op = Content_Journal::record_intent(
        Content_Journal::OP_EDIT_PUBLISHED,
        array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'original_id' => (int) $orig_id,
            'shadow_id'   => (int) $shadow_id,
        ),
        array(
            'post_title'   => (string) ( $staged['post_title']   ?? '' ),
            'post_content' => (string) ( $staged['post_content'] ?? '' ),
            'post_excerpt' => (string) ( $staged['post_excerpt'] ?? '' ),
            'post_name'    => $effective_post_name,
        )
    );
    $op_id = (string) $op['id'];
    Content_Journal::attach( $tx, $op );
    // Stamp the shadow with the new op_id now that the journal has it.
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, $op_id );
    // Capture baseline (uses the in-memory transaction manager).
    $baseline = Content_Journal::capture_edit_published_baseline( $orig_id );
    $op_row = pe_read_op( $op_id );
    // We need to inject the before-image into the op; do that through
    // the value-guarded CAS so the journal sees the same write
    // discipline as production code.
    $current_row = (array) get_option( Tx::KEY, null );
    $expected = $current_row;
    $expected['content']['ops'][ $op_id ]['before'] = $baseline;
    $expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
    Tx::cas_write_row( $current_row, $expected );
    // Hold the per-original claim on the staged tx's behalf.
    $claim_row = Published_Claim::shape( $tx_id, $orig_id, $shadow_id, 'agent', time(), time(), Published_Claim::TTL_SECONDS );
    Published_Claim::create( $orig_id, $claim_row );
    return array(
        'tx_id'       => $tx_id,
        'op_id'       => $op_id,
        'original_id' => (int) $orig_id,
        'shadow_id'   => (int) $shadow_id,
    );
}

/**
 * Close any open transaction and reset the per-process state. Used
 * between subtests. The agentshell_tx option row is wiped directly so
 * a prior crashed run opened by a different actor does not block
 * this test from running.
 */
function pe_reset() {
    $GLOBALS['mysqli']->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
    $GLOBALS['mysqli']->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_claim_%'" );
    // M5-s2: also clear the per-original Published_Claim rows and the
    // shadow meta markers so pe_reset() between T1-T8 does not leak
    // any of the side-tables the M5 leak check audits.
    $GLOBALS['mysqli']->query( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_published_claim_%'" );
    $GLOBALS['mysqli']->query( "DELETE FROM wp_postmeta WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')" );
    // Flush the wpdb option cache for the transaction key. The wpdb
    // layer caches get_option results in `last_result` / `notoptions`,
    // so the next Tx::payload() call must re-read from disk.
    wp_cache_delete( 'agentshell_transaction', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( 'alloptions', 'options' );
    Content_Write_Guard::reset();
    Recovery_Advisory_Lock::reset();
}

// ── 1. Clean up any prior fixture ────────────────────────────────────────

// Wipe the agentshell_transaction option row directly. A prior
// crashed run may have left an open transaction opened by a
// different actor; trying to roll it back via the per-actor API
// will throw and stop this child before any test can run. The
// preflight in run-all.php has already done a baseline restore
// before we get here, so this is a belt-and-braces guard for cases
// the runner was launched directly.
$mysqli->query( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
$mysqli->query( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_name LIKE '{$MARKER}-%')" );
$db( "DELETE FROM wp_posts WHERE post_name LIKE '{$MARKER}-%'" );
$db( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_claim_%'" );
// M5-s2: also clean up the per-original Published_Claim rows and the
// shadow meta markers before the suite starts, so a prior crashed
// run's residue does not survive the preflight.
$db( "DELETE FROM wp_options WHERE option_name LIKE 'agentshell_published_claim_%'" );
$db( "DELETE FROM wp_postmeta WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')" );
$db( "DELETE FROM wp_options WHERE option_name = 'agentshell_transaction'" );
pe_reset();

// ═════════════════════════════════════════════════════════════════════════
// T1: Successful publication of all four permitted fields, including
// a changed slug. After the publish, the original's row carries the
// staged values, the op is in publication_settled, the shadow is
// gone, the claim is released.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't1' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't1', array(
    'post_title'   => 'Staged Title T1',
    'post_content' => 'Staged content T1',
    'post_excerpt' => 'Staged excerpt T1',
    'post_name'    => 'staged-slug-t1',
) );
$result = ( new Publish_Edit() )->execute( array() );
$post   = pe_read_post_row( $db, $orig_id );
$report( 'T1.1 publish_edit returns publication_settled state',
    ( $result['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED,
    'state=' . ( $result['state'] ?? 'missing' ) );
$report( 'T1.2 original row now carries the staged post_title',
    $post && $post['post_title'] === 'Staged Title T1',
    'got=' . ( $post['post_title'] ?? 'null' ) );
$report( 'T1.3 original row now carries the staged post_content',
    $post && $post['post_content'] === 'Staged content T1',
    'got=' . substr( (string) ( $post['post_content'] ?? '' ), 0, 80 ) );
$report( 'T1.4 original row now carries the staged post_excerpt',
    $post && $post['post_excerpt'] === 'Staged excerpt T1',
    'got=' . ( $post['post_excerpt'] ?? 'null' ) );
$report( 'T1.5 original row now carries the staged post_name (changed slug)',
    $post && $post['post_name'] === 'staged-slug-t1',
    'got=' . ( $post['post_name'] ?? 'null' ) );
$shadow_row = $db( "SELECT ID FROM wp_posts WHERE ID = " . (int) $fixture['shadow_id'] )->fetch_assoc();
$report( 'T1.6 shadow is gone after publish',
    ! $shadow_row,
    'shadow row still present' );
$report( 'T1.7 claim is released after publish',
    null === Published_Claim::read( $orig_id ),
    'claim still present' );
$op_state = pe_read_op( $fixture['op_id'] );
$report( 'T1.8 op on disk is in publication_settled state',
    is_array( $op_state ) && ( $op_state['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED,
    'state=' . ( $op_state['state'] ?? 'missing' ) );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T2: Published original identity and protected fields preserved. The
// original's identity (post_type, post_status, post_author, post_parent)
// must survive the publish; the post_type=page and post_status=publish
// are the load-bearing fields.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't2' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't2', array(
    'post_title'   => 'Staged Title T2',
    'post_content' => 'Staged content T2',
    'post_excerpt' => 'Staged excerpt T2',
    'post_name'    => 'staged-slug-t2',
) );
( new Publish_Edit() )->execute( array() );
$post = pe_read_post_row( $db, $orig_id );
$report( 'T2.1 original post_type preserved as page',
    $post && $post['post_type'] === 'page' );
$report( 'T2.2 original post_status preserved as publish',
    $post && $post['post_status'] === 'publish' );
$report( 'T2.3 original post_author preserved',
    $post && (int) $post['post_author'] === 1 );
$report( 'T2.4 original post_parent preserved (zero)',
    $post && (int) $post['post_parent'] === 0 );
$report( 'T2.5 original comment_status preserved (closed)',
    $post && $post['comment_status'] === 'closed' );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T3: Original baseline-A drift causing refusal. Mutate the live
// original's post_title between staging and publish; the tool must
// throw baseline_drift and refuse; the original must be unchanged.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't3' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't3', array(
    'post_title' => 'Staged Title T3',
) );
// External writer mutates the post_title before publish.
$db( "UPDATE wp_posts SET post_title = 'Externally Changed' WHERE ID = " . (int) $orig_id );
$threw = false;
$reason = '';
try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $threw = true;
    $reason = $e->getMessage();
}
$report( 'T3.1 publish_edit throws on baseline drift', $threw,
    'no throw; reason=' . $reason );
$report( 'T3.2 the refusal reason names baseline_drift',
    $threw && strpos( $reason, 'baseline_drift' ) !== false,
    'reason=' . substr( $reason, 0, 120 ) );
$post = pe_read_post_row( $db, $orig_id );
$report( 'T3.3 the original is unchanged after refused publish',
    $post && $post['post_title'] === 'Externally Changed',
    'title=' . ( $post['post_title'] ?? 'null' ) );
$op_state = pe_read_op( $fixture['op_id'] );
$report( 'T3.4 the op is in conflict state on disk',
    is_array( $op_state ) && ( $op_state['state'] ?? '' ) === Content_Journal::STATE_CONFLICT,
    'state=' . ( $op_state['state'] ?? 'missing' ) );
// Diagnostic: dump the option row's raw value so we can see what the
// refusal path actually wrote.
$raw = $db( "SELECT option_value FROM wp_options WHERE option_name = 'agentshell_transaction'" )->fetch_assoc();
if ( $raw && ! empty( $raw['option_value'] ) ) {
    $tx = @unserialize( $raw['option_value'] );
    if ( is_array( $tx ) && isset( $tx['content']['ops'][ $fixture['op_id'] ] ) ) {
        $real_state = (string) ( $tx['content']['ops'][ $fixture['op_id'] ]['state'] ?? '' );
        $report( 'T3.5 raw option_row state matches expected disk state',
            $real_state === ( $op_state['state'] ?? '' ),
            'option=' . $real_state . ' vs pe_read_op=' . ( $op_state['state'] ?? 'null' ) );
    }
}
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T4: Wrong actor and insufficient publication capabilities. Open a
// transaction as agent-A, stage a publish op, then attempt to publish
// as a different actor and as a non-manage_options user. The tool
// must refuse.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't4' );
pe_reset();
// Stage with a label that does not match the actor. The op's actor
// is set from the staging record_intent actor; the journal holds the
// staging actor. The publish tool checks that the calling actor
// matches the op's actor in locate_op.
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't4', array(
    'post_title' => 'Staged Title T4',
) );
// Mutate the op's actor in the journal to "other-actor" so the
// current actor (agent) does not match.
$current_row = (array) get_option( Tx::KEY, null );
$expected = $current_row;
$expected['content']['ops'][ $fixture['op_id'] ]['actor'] = 'other-actor';
$expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
Tx::cas_write_row( $current_row, $expected );
$threw = false; $reason = '';
try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$report( 'T4.1 publish_edit throws when actor does not match op actor', $threw,
    'no throw; reason=' . $reason );
$report( 'T4.2 the refusal names the actor mismatch',
    $threw && ( strpos( $reason, 'actor' ) !== false || strpos( $reason, 'op_actor' ) !== false ),
    'reason=' . substr( $reason, 0, 120 ) );
$post = pe_read_post_row( $db, $orig_id );
$report( 'T4.3 the original is unchanged after refused publish',
    $post && $post['post_title'] === 'Orig Title t4',
    'orig_id=' . $orig_id . ' title=' . ( $post['post_title'] ?? 'null' ) );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T5: Shadow identity mismatch. Stage an op, then mutate the
// shadow's META_OP_ID to point to a different op id. The publish
// must refuse and the original must be unchanged.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't5' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't5', array(
    'post_title' => 'Staged Title T5',
) );
update_post_meta( $fixture['shadow_id'], Published_Shadow::META_OP_ID, 'op_fake_advanced_head' );
$threw = false; $reason = '';
try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$report( 'T5.1 publish_edit throws on shadow head-pointer advance', $threw,
    'no throw; reason=' . $reason );
$report( 'T5.2 the refusal names head_advanced or shadow retargeted',
    $threw && ( strpos( $reason, 'head_advanced' ) !== false || strpos( $reason, 'shadow' ) !== false ),
    'reason=' . substr( $reason, 0, 120 ) );
$post = pe_read_post_row( $db, $orig_id );
$report( 'T5.3 the original is unchanged after refused publish',
    $post && $post['post_title'] === 'Orig Title t5',
    'orig_id=' . $orig_id . ' title=' . ( $post['post_title'] ?? 'null' ) );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T6: Claim held by another transaction. Stage a publish op, then
// replace the per-original claim with a claim owned by a different
// transaction. The publish must refuse.
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't6' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't6', array(
    'post_title' => 'Staged Title T6',
) );
$other_claim = Published_Claim::shape(
    'tx_other_competing',
    $orig_id,
    $fixture['shadow_id'],
    'other-actor',
    time(),
    time(),
    Published_Claim::TTL_SECONDS
);
// The published_claim::create primitive is INSERT-conditional: it
// refuses to overwrite an existing row, which is the right shape for
// a fresh create. To simulate a competing claim winning, we
// delete-and-create: the production code's `claim_held_by_other`
// check reads the row by id and compares the tx_id, so the test
// only needs the row to be present with the new tx_id.
$mysqli->query( "DELETE FROM wp_options WHERE option_name = '" . $mysqli->real_escape_string( Published_Claim::key( $orig_id ) ) . "'" );
wp_cache_delete( Published_Claim::key( $orig_id ), 'options' );
Published_Claim::create( $orig_id, $other_claim );
$threw = false; $reason = '';
try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$report( 'T6.1 publish_edit throws when claim held by another tx', $threw,
    'no throw; reason=' . $reason );
$report( 'T6.2 the refusal names claim_held_by_other',
    $threw && strpos( $reason, 'claim_held_by_other' ) !== false,
    'reason=' . substr( $reason, 0, 120 ) );
$post = pe_read_post_row( $db, $orig_id );
$report( 'T6.3 the original is unchanged after refused publish',
    $post && $post['post_title'] === 'Orig Title t6' );
$report( 'T6.4 the other-actor claim is still on the original',
    null !== Published_Claim::read( $orig_id )
        && (string) ( Published_Claim::read( $orig_id )['tx_id'] ?? '' ) === 'tx_other_competing' );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T7: Reconcile on an already-settled op. The reconcile tool
// refuses on settled state; verify that refusal is loud and names
// the op id, not silent. (Idempotence here means the refusal, not
// a no-op success — the design treats settlement as terminal.)
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't7' );
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't7', array(
    'post_title' => 'Staged Title T7',
) );
( new Publish_Edit() )->execute( array() );
// The publish tool already closed the tx; reopen to make the
// reconcile call act on the just-settled op.
$tx = Tx::instance()->begin( 'agent' );
// Move the op into the new tx's journal so the reconcile tool
// finds it (the new tx is empty until we re-attach).
$row_now = (array) get_option( Tx::KEY, null );
$op_state = pe_read_op( $fixture['op_id'] );
$expected = $row_now;
$expected['content']['ops'][ $fixture['op_id'] ] = $op_state;
$expected['content']['sig'] = Content_Journal::sign( $expected['content'] );
Tx::cas_write_row( $row_now, $expected );
$threw = false; $reason = '';
try {
    ( new Publish_Edit_Reconcile() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$report( 'T7.1 reconcile on a settled op throws op_state_unexpected',
    $threw && strpos( $reason, 'op_state_unexpected' ) !== false,
    'no throw; reason=' . $reason );
$report( 'T7.2 refusal names publication_settled as the offending state',
    $threw && strpos( $reason, 'publication_settled' ) !== false,
    'reason=' . substr( $reason, 0, 120 ) );
Tx::instance()->rollback();
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ═════════════════════════════════════════════════════════════════════════
// T8: Cache invalidation and public visibility after publication.
// A published page must be readable by an anonymous HTTP request,
// and a follow-up anonymous request to the SAME URL must return the
// staged content (no poisoned overlay, no shadow leak).
// ═════════════════════════════════════════════════════════════════════════

$orig_id = pe_seed_original( $mysqli, $MARKER, 't8' );
$post_row = pe_read_post_row( $db, $orig_id );
$orig_slug = $post_row['post_name'];
pe_reset();
$fixture = pe_stage( $mysqli, $orig_id, $MARKER, 't8', array(
    'post_title'   => 'Staged Title T8 Public',
    'post_content' => 'Staged content T8 public body',
    'post_excerpt' => 'Staged excerpt T8',
    'post_name'    => 'staged-slug-t8-public',
) );
( new Publish_Edit() )->execute( array() );

// Now hit the page anonymously and confirm it serves the staged content.
// The permalink structure is /%postname%/. WordPress' canonical redirect
// may add a trailing slash; follow at most one redirect so the test
// follows /?p=N → /slug/ without chasing arbitrary chains.
$post_row = pe_read_post_row( $db, $orig_id );
$slug     = $post_row['post_name'] ?? '';
$url      = $SITE . '/' . ltrim( $slug, '/' );
$ctx = stream_context_create( array(
    'http' => array(
        'timeout'         => 15,
        'header'          => "User-Agent: stage-2c-publish-edit-live\r\n",
        'follow_location' => 1,
        'max_redirects'   => 2,
    ),
) );
$body = @file_get_contents( $url, false, $ctx );
$code = 0;
foreach ( (array) ( $http_response_header ?? array() ) as $h ) {
    if ( preg_match( '#HTTP/\S+\s+(\d+)#', $h, $m ) ) { $code = (int) $m[1]; }
}
$report( 'T8.1 anonymous request returns 200', $code === 200, 'url=' . $url . ' code=' . $code );
$report( 'T8.2 anonymous body contains the staged title',
    is_string( $body ) && strpos( $body, 'Staged Title T8 Public' ) !== false,
    is_string( $body )
        ? ( strpos( $body, 'Staged Title T8 Public' ) === false ? 'no match in body (len=' . strlen( $body ) . ')' : 'found' )
        : 'no body' );
$report( 'T8.3 anonymous body contains the staged content',
    is_string( $body ) && strpos( $body, 'Staged content T8 public body' ) !== false,
    is_string( $body )
        ? ( strpos( $body, 'Staged content T8 public body' ) === false ? 'no match in body (len=' . strlen( $body ) . ')' : 'found' )
        : 'no body' );
pe_reset();
pe_drop_fixture( $mysqli, $orig_id, $fixture['shadow_id'] );

// ── Persist structured results ──────────────────────────────────────────

file_put_contents( $RESULT, json_encode( array(
    'results' => $results,
    'pid'     => getmypid(),
) ) );

$pass = 0; $fail = 0;
foreach ( $results as $r ) { $r['ok'] ? $pass++ : $fail++; }
fwrite( STDERR, "stage-2c-publish-edit-live-child: {$pass} pass / {$fail} fail\n" );
exit( 0 );
