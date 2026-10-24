<?php
/**
 * Stage 2c M5-t1 R2 child — calls Publish_Edit_Reconcile::execute()
 * against a shared op_id (left in STATE_PUBLICATION_COMMITTED by
 * the orchestrator) after waiting for a file-based barrier. The
 * orchestrator releases both R2_A and R2_B barriers at the same
 * instant; this child is one of the two racers.
 *
 * argv: 1 report_path  2 op_id  3 barrier_dir  4 scenario
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

$REPORT   = (string) ( $argv[1] ?? '' );
$OP_ID    = (string) ( $argv[2] ?? '' );
$BARRIER  = (string) ( $argv[3] ?? '' );
$SCENARIO = (string) ( $argv[4] ?? '' );

if ( '' === $REPORT || '' === $OP_ID || '' === $BARRIER || '' === $SCENARIO ) {
    fwrite( STDERR, "usage: <reconcile_child> <report> <op_id> <barrier_dir> <scenario>\n" );
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

use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Publish_Edit_Reconcile;

global $wpdb;
$agent_user_id = (int) ( $wpdb->get_var( "SELECT ID FROM {$wpdb->users} WHERE user_login='agent'" ) ?: 0 );
wp_set_current_user( $agent_user_id );

function pe_race_barrier_wait( $dir, $name ) {
    $path = rtrim( $dir, '/' ) . '/' . $name;
    $deadline = microtime( true ) + 30.0;
    while ( microtime( true ) < $deadline ) {
        if ( file_exists( $path ) ) { return; }
        usleep( 2000 );
    }
    fwrite( STDERR, "barrier timeout: {$path}\n" );
    exit( 4 );
}

pe_race_barrier_wait( $BARRIER, $SCENARIO . '_go' );

$result = array(
    'scenario' => $SCENARIO,
    'pid'      => getmypid(),
    'op_id'    => $OP_ID,
);
$start = microtime( true );
try {
    $exec = ( new Publish_Edit_Reconcile() )->execute( array( 'op_id' => $OP_ID ) );
    $result['outcome']      = 'reconciled';
    $result['state']        = (string) ( $exec['state'] ?? '' );
    $result['recon_status'] = (string) ( $exec['reconciliation']['status'] ?? '' );
} catch ( \Throwable $t ) {
    $result['outcome']     = 'refused';
    $result['threw']       = $t->getMessage();
    $result['threw_class'] = get_class( $t );
}
$result['elapsed_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

$row = (array) get_option( Tx::KEY, null );
$op_now = $row['content']['ops'][ $OP_ID ] ?? null;
$result['op_state_on_disk'] = (string) ( $op_now['state'] ?? '' );
$result['recon_status_field'] = (string) ( $op_now['reconciliation']['status'] ?? '' );

file_put_contents( $REPORT, json_encode( $result ) );
echo $SCENARIO . ': ' . $result['outcome'] . " (" . $result['elapsed_ms'] . "ms)\n";
