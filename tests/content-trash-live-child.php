<?php
/**
 * Child half of the live content-trash suite. Runs inside a real WordPress
 * install and reports facts as JSON; the parent asserts them.
 *
 * What it proves
 * --------------
 * 1. Trashing moves the content to the Trash and does not delete it: the
 *    row is still there, with its body.
 * 2. Restoring brings it back to the status it had.
 * 3. Both operations are idempotent: a second trash, or a restore of
 *    something that was never trashed, changes nothing and says so.
 * 4. A caller without the `delete_post` capability for that post is
 *    refused, and the content is unchanged.
 * 5. A call inside an open transaction is refused, and the content is
 *    unchanged.
 * 6. A call for content that does not exist is refused.
 * 7. The audit log records the trash and the restore.
 *
 * The page it creates is disposable and is deleted at the end.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
require_once $theme_root . '/agentshell-mcp/includes/class-content.php';
require_once $theme_root . '/agentshell-mcp/includes/class-content-trash.php';
require_once $theme_root . '/agentshell-mcp/includes/class-content-write-guard.php';
require_once $theme_root . '/agentshell-mcp/includes/class-store.php';
require_once $theme_root . '/agentshell-mcp/includes/class-transactions.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-trash-content.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-restore-content.php';

use AgentShell_MCP\Content_Primitives;
use AgentShell_MCP\Content_Trash;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Restore_Content;
use AgentShell_MCP\Tools\Trash_Content;

$f = array();

function ss_fact( $key, $value ) {
    global $f;
    $f[ $key ] = $value;
}

/** The refusal message of a call that was expected to be refused. */
function ss_refusal( callable $fn ) {
    try {
        $fn();
        return 'ACCEPTED';
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
}

// An administrator, which is who the MCP transport authenticates as.
wp_set_current_user( 1 );

$token = 'AStr' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$page  = Content_Primitives::create( 'page', 'AStr ' . $token, 'AStr body ' . $token, 'publish' );
$id    = (int) $page['id'];
ss_fact( 'page_id', $id );

$trash   = new Trash_Content();
$restore = new Restore_Content();

// ── 1. trash moves it to the Trash and keeps the row ──────────────────────
$result = $trash->execute( array( 'id' => $id, 'type' => 'page' ) );
ss_fact( 'trash_result', $result );
$after = get_post( $id );
ss_fact( 'status_after_trash', $after ? $after->post_status : '(gone)' );
ss_fact( 'body_after_trash', $after ? (string) $after->post_content : '(gone)' );
ss_fact( 'row_survived', (bool) $after );
ss_fact( 'previous_status_reported', $result['previous_status'] ?? null );

// ── 2. restore brings it back ─────────────────────────────────────────────
// WordPress restores trashed content as a *draft* since 5.6; the
// "previous" option asks for the status it had before.
$result = $restore->execute( array( 'id' => $id, 'type' => 'page', 'status' => 'previous' ) );
ss_fact( 'restore_result', $result );
$after = get_post( $id );
ss_fact( 'status_after_restore', $after ? $after->post_status : '(gone)' );
ss_fact( 'body_after_restore', $after ? (string) $after->post_content : '(gone)' );
ss_fact( 'restored_as_reported', $result['restored_as'] ?? null );

// ── 2b. a default restore comes back as a draft ──────────────────────────
$trash->execute( array( 'id' => $id, 'type' => 'page' ) );
$result = $restore->execute( array( 'id' => $id, 'type' => 'page' ) );
ss_fact( 'status_after_default_restore', get_post( $id ) ? get_post( $id )->post_status : '(gone)' );

// ── 3. idempotency ────────────────────────────────────────────────────────
$trash->execute( array( 'id' => $id, 'type' => 'page' ) );
$result = $trash->execute( array( 'id' => $id, 'type' => 'page' ) );
ss_fact( 'second_trash_already', $result['already'] ?? null );
$result = $trash->execute( array( 'id' => $id, 'type' => 'page' ) );
ss_fact( 'third_trash_already', $result['already'] ?? null );
ss_fact( 'status_after_repeat', get_post( $id ) ? get_post( $id )->post_status : '(gone)' );

$restore->execute( array( 'id' => $id, 'type' => 'page' ) );
$result = $restore->execute( array( 'id' => $id, 'type' => 'page' ) );
ss_fact( 'restore_already', $result['already'] ?? null );
ss_fact( 'status_after_restore_again', get_post( $id ) ? get_post( $id )->post_status : '(gone)' );

// ── 4. a caller without the capability is refused ─────────────────────────
$subscriber_id = 0;
$existing      = get_user_by( 'login', 'as_trash_subscriber' );
if ( $existing ) {
    $subscriber_id = (int) $existing->ID;
} else {
    $subscriber_id = wp_insert_user( array(
        'user_login' => 'as_trash_subscriber',
        'user_pass'  => wp_generate_password( 24 ),
        'role'       => 'subscriber',
    ) );
}
ss_fact( 'subscriber_id', (int) $subscriber_id );

if ( $subscriber_id && ! is_wp_error( $subscriber_id ) ) {
    wp_set_current_user( (int) $subscriber_id );
    $before = get_post( $id );
    $before_status = $before ? $before->post_status : '(gone)';
    ss_fact( 'subscriber_refusal', ss_refusal( function () use ( $trash, $id ) {
        $trash->execute( array( 'id' => $id, 'type' => 'page' ) );
    } ) );
    $after = get_post( $id );
    ss_fact( 'status_after_subscriber', $after ? $after->post_status : '(gone)' );
    ss_fact( 'unchanged_after_subscriber', ( $after ? $after->post_status : '(gone)' ) === $before_status );
    wp_set_current_user( 1 );
} else {
    ss_fact( 'subscriber_refusal', 'skipped: no subscriber' );
    ss_fact( 'unchanged_after_subscriber', true );
}

// ── 5. inside a transaction it is refused ─────────────────────────────────
$tx = Tx::instance()->begin( 'live trash' );
ss_fact( 'tx_opened', Tx::instance()->is_active() );
$before = get_post( $id );
$before_status = $before ? $before->post_status : '(gone)';
ss_fact( 'tx_refusal', ss_refusal( function () use ( $trash, $id ) {
    $trash->execute( array( 'id' => $id, 'type' => 'page' ) );
} ) );
$after = get_post( $id );
ss_fact( 'status_after_tx_refusal', $after ? $after->post_status : '(gone)' );
ss_fact( 'unchanged_after_tx_refusal', ( $after ? $after->post_status : '(gone)' ) === $before_status );
try {
    Tx::instance()->rollback();
} catch ( \Throwable $e ) {
    ss_fact( 'rollback_error', $e->getMessage() );
}

// ── 6. content that does not exist ────────────────────────────────────────
ss_fact( 'missing_refusal', ss_refusal( function () use ( $trash ) {
    $trash->execute( array( 'id' => 987654321, 'type' => 'page' ) );
} ) );

// ── 7. the audit log records it ───────────────────────────────────────────
$entries = Store::audit_log();
$ops     = array();
foreach ( $entries as $entry ) {
    if ( isset( $entry['operation'] ) ) {
        $ops[] = $entry['operation'];
    }
}
ss_fact( 'audit_has_trashed', in_array( 'content_trashed', $ops, true ) );
ss_fact( 'audit_has_restored', in_array( 'content_restored', $ops, true ) );

// ── cleanup ───────────────────────────────────────────────────────────────
if ( $id > 0 ) {
    wp_delete_post( $id, true );
}
ss_fact( 'cleanup_done', ! get_post( $id ) );

file_put_contents( $report, json_encode( $f ) );
echo "reported\n";
