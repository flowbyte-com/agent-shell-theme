<?php
/**
 * Stage 2c M5-t2 R3 Phase A — pre-commit child.
 *
 * Runs the publish SQL transaction in a child process. After
 * the SQL COMMIT lands (the publication is durable), the child
 * writes its post-COMMIT evidence to a file, raises a barrier
 * (A_done), and blocks on A_exit. When the orchestrator
 * acknowledges by creating A_exit, this child exits WITHOUT
 * running the reconciliation tail. This simulates a post-COMMIT
 * interruption (e.g. a host kill, a process crash, or a network
 * drop) between SQL COMMIT and the reconciliation tail.
 *
 * The fresh-process recovery happens in
 * stage-2c-publish-edit-recovery-child-recover.php, which is
 * spawned by the orchestrator AFTER this child exits.
 *
 * argv: 1 report_path  2 op_id  3 barrier_dir  4 orig_id  5 shadow_id
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

$REPORT     = (string) ( $argv[1] ?? '' );
$OP_ID      = (string) ( $argv[2] ?? '' );
$BARRIER    = (string) ( $argv[3] ?? '' );
$ORIG_ID    = (int) ( $argv[4] ?? 0 );
$SHADOW_ID  = (int) ( $argv[5] ?? 0 );
if ( '' === $REPORT || '' === $OP_ID || '' === $BARRIER || ! $ORIG_ID || ! $SHADOW_ID ) {
    fwrite( STDERR, "usage: <pre-commit-child> <report> <op_id> <barrier_dir> <orig_id> <shadow_id>\n" );
    exit( 2 );
}

$wp_root = getenv( 'AGENTSHELL_TEST_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "AGENTSHELL_TEST_WP_ROOT not set or wp-load.php missing\n" );
    exit( 2 );
}
define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'agentshell-test.local';
$_SERVER['REQUEST_URI']  = '/';
// The database socket comes from the test-bed profile, not from a path
// baked into this file: the profile is what says where the database is,
// and it is different on every machine the suite runs on.
$__asint_bed_cfg_path = getenv( 'AGENTSHELL_TEST_BED_CONFIG' );
if ( ! is_string( $__asint_bed_cfg_path ) || '' === $__asint_bed_cfg_path ) {
    $__asint_bed_cfg_path = __DIR__ . '/config/test-bed.json';
}
$__asint_bed_cfg = is_file( $__asint_bed_cfg_path )
    ? json_decode( (string) file_get_contents( $__asint_bed_cfg_path ), true )
    : null;
$__asint_db_sock = is_array( $__asint_bed_cfg )
    ? (string) ( $__asint_bed_cfg['db']['socket'] ?? '' )
    : '';
if ( '' !== $__asint_db_sock ) {
    ini_set( 'mysqli.default_socket', $__asint_db_sock );
}
require_once ABSPATH . 'wp-load.php';

require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-store.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-transactions.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-journal.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-content-write-guard.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-recovery-advisory-lock.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-claim.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Recovery_Advisory_Lock;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Store;

global $wpdb;
$agent_user_id = (int) ( $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_login='agent'" ) ?: 0 );
wp_set_current_user( $agent_user_id );

// Read the staged op and after-image from the journal.
$row = (array) get_option( Tx::KEY, null );
$tx = $row;
$op = $tx['content']['ops'][$OP_ID] ?? null;
if ( ! is_array( $op ) ) {
    fwrite( STDERR, "op not found in journal: {$OP_ID}\n" );
    exit( 3 );
}
$after = (array) ( $op['after'] ?? array() );
$tx_id = (string) ( $tx['id'] ?? '' );

// Acquire the per-tx advisory lock.
$lock_held = Recovery_Advisory_Lock::acquire( 'tx_' . $tx_id );
if ( ! $lock_held ) {
    fwrite( STDERR, "could not acquire per-tx advisory lock\n" );
    exit( 4 );
}

$col_list = 'ID, post_type, post_status, post_author, post_parent, post_title, post_content, post_excerpt, post_name, post_date_gmt, post_modified_gmt';
$postarr = array(
    'ID'           => $ORIG_ID,
    'post_title'   => (string) ( $after['post_title'] ?? '' ),
    'post_content' => (string) ( $after['post_content'] ?? '' ),
    'post_excerpt' => (string) ( $after['post_excerpt'] ?? '' ),
    'post_name'    => (string) ( $after['post_name']    ?? '' ),
);

$closure = function () use ( $ORIG_ID, $OP_ID, $postarr, $col_list, $tx_id, $SHADOW_ID ) {
    global $wpdb;
    $current = $wpdb->get_row( $wpdb->prepare( "SELECT {$col_list} FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $ORIG_ID ), ARRAY_A );
    if ( ! $current ) { throw new \RuntimeException( 'orig_gone' ); }
    $upd = wp_update_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $upd ) || ! $upd ) { throw new \RuntimeException( 'wp_update_post failed' ); }
    $row = (array) get_option( Tx::KEY, null );
    $expected = $row;
    $row['content']['ops'][ $OP_ID ]['state'] = Content_Journal::STATE_PUBLICATION_COMMITTED;
    $row['content']['sig'] = Content_Journal::sign( $row['content'] );
    $ok = Tx::cas_write_row( $expected, $row );
    if ( ! $ok ) { throw new \RuntimeException( 'cas_lost' ); }
    Store::record_event( 'agent', 'transaction_published', array(
        'transaction_id' => (string) $tx_id,
        'op_id'          => (string) $OP_ID,
        'original_id'    => (int) $ORIG_ID,
        'shadow_id'      => (int) $SHADOW_ID,
    ) );
    return $row;
};
try {
    Content_Write_Guard::run( $closure );
} catch ( \Throwable $t ) {
    if ( $lock_held ) { Recovery_Advisory_Lock::release( 'tx_' . $tx_id ); }
    file_put_contents( $REPORT, json_encode( array( 'phase' => 'A_publish', 'threw' => $t->getMessage() ) ) );
    exit( 5 );
}

// Release the per-tx advisory lock so the recovery process can acquire it.
Recovery_Advisory_Lock::release( 'tx_' . $tx_id );

// Read the post-COMMIT state. The publication is durable;
// the reconciliation tail has NOT run.
$row_after_commit = (array) get_option( Tx::KEY, null );
$op_after_commit = $row_after_commit['content']['ops'][$OP_ID] ?? null;
$post_after_commit = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title, post_content, post_status FROM {$wpdb->posts} WHERE ID=%d", $ORIG_ID ), ARRAY_A );
$audit_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'agentshell_audit_log' AND option_value LIKE '%transaction_published%'" );
$shadow_after_commit = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $SHADOW_ID ) );
$claim_after_commit = Published_Claim::read( $ORIG_ID );

$evidence = array(
    'phase'                  => 'A_publish',
    'sql_committed'          => true,
    'op_state_after_commit'  => (string) ( $op_after_commit['state'] ?? '' ),
    'recon_status_after_commit' => (string) ( $op_after_commit['reconciliation']['status'] ?? '' ),
    'shadow_deleted'         => (bool) ( $op_after_commit['reconciliation']['shadow_deleted'] ?? false ),
    'claim_released'         => (bool) ( $op_after_commit['reconciliation']['claim_released'] ?? false ),
    'caches_invalidated'     => (bool) ( $op_after_commit['reconciliation']['caches_invalidated'] ?? false ),
    'audit_count'            => $audit_count,
    'post_title'             => (string) ( $post_after_commit['post_title'] ?? '' ),
    'post_status'            => (string) ( $post_after_commit['post_status'] ?? '' ),
    'shadow_still_exists'    => $shadow_after_commit,
    'claim_present'          => is_array( $claim_after_commit ),
    'interruption_after_commit' => true, // <-- the whole point of this phase
);

file_put_contents( $REPORT, json_encode( $evidence ) );

// Tell the orchestrator we're done with phase A.
file_put_contents( rtrim( $BARRIER, '/' ) . '/A_done', '1' );

// Block until the orchestrator has confirmed it has read our evidence, then exit (no reconciliation tail).
$deadline = microtime( true ) + 30.0;
while ( microtime( true ) < $deadline ) {
    if ( file_exists( rtrim( $BARRIER, '/' ) . '/A_exit' ) ) { break; }
    usleep( 2000 );
}
exit( 0 );
