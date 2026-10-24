<?php
/**
 * Stage 2c M5-t2 R3 Phase B / C — recover child.
 *
 * Fresh PHP process. Reads the journal left by Phase A (op in
 * publication_committed with shadow + claim still on disk),
 * calls Publish_Edit_Reconcile::execute() against the op_id, and
 * reports the outcome. Phase B expects "reconciled"; Phase C
 * expects "refused" because the op is already settled.
 *
 * argv: 1 report_path  2 op_id  3 orig_id  4 shadow_id
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

$REPORT     = (string) ( $argv[1] ?? '' );
$OP_ID      = (string) ( $argv[2] ?? '' );
$ORIG_ID    = (int) ( $argv[3] ?? 0 );
$SHADOW_ID  = (int) ( $argv[4] ?? 0 );
if ( '' === $REPORT || '' === $OP_ID || ! $ORIG_ID || ! $SHADOW_ID ) {
    fwrite( STDERR, "usage: <recover-child> <report> <op_id> <orig_id> <shadow_id>\n" );
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
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/class-published-shadow.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-base-tool.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit.php';
require_once ABSPATH . 'wp-content/plugins/agentshell-mcp/includes/tools/class-publish-edit-reconcile.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

global $wpdb;
$agent_user_id = (int) ( $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_login='agent'" ) ?: 0 );
wp_set_current_user( $agent_user_id );

$result = array(
    'op_id'     => $OP_ID,
    'pid'       => getmypid(),
);
$start = microtime( true );
try {
    $exec = ( new Publish_Edit_Reconcile() )->execute( array( 'op_id' => $OP_ID ) );
    $result['outcome']     = 'reconciled';
    $result['op_state_after'] = (string) ( $exec['state'] ?? '' );
    $result['recon_status'] = (string) ( $exec['reconciliation']['status'] ?? '' );
} catch ( \Throwable $t ) {
    $result['outcome']     = 'refused';
    $result['threw']       = $t->getMessage();
    $result['threw_class'] = get_class( $t );
}
$result['elapsed_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

$row = (array) get_option( Tx::KEY, null );
$op_now = $row['content']['ops'][ $OP_ID ] ?? null;
$result['op_state_field']     = (string) ( $op_now['state'] ?? '' );
$result['recon_status_field'] = (string) ( $op_now['reconciliation']['status'] ?? '' );
$result['shadow_deleted_field']  = (bool) ( $op_now['reconciliation']['shadow_deleted'] ?? false );
$result['claim_released_field']  = (bool) ( $op_now['reconciliation']['claim_released'] ?? false );
$result['caches_invalidated']    = (bool) ( $op_now['reconciliation']['caches_invalidated'] ?? false );
$result['shadow_exists_after']   = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $SHADOW_ID ) );
$result['claim_present_after']   = is_array( Published_Claim::read( $ORIG_ID ) );
$result['post_title_after']      = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID=%d", $ORIG_ID ) );
$result['post_unchanged']        = ( $result['post_title_after'] === (string) ( $row['content']['ops'][ $OP_ID ]['after']['post_title'] ?? '' ) );
$result['audit_published_total']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'agentshell_audit_log' AND option_value LIKE '%transaction_published%'" );
$result['audit_reconciled_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'agentshell_audit_log' AND option_value LIKE '%transaction_published_reconciled%'" );

file_put_contents( $REPORT, json_encode( $result ) );
echo 'recover:' . $result['outcome'] . " (" . $result['elapsed_ms'] . "ms)\n";
