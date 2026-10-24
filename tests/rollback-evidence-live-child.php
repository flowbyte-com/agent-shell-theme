<?php
/**
 * Child half of the live rollback-evidence suite. Runs inside a real
 * WordPress install and reports facts as JSON; the parent asserts them.
 *
 * The question this suite answers
 * -------------------------------
 * When a transaction is rolled back, its row is deleted — that is the
 * point of a rollback. So where is the evidence that it happened, and how
 * it ended? This suite shows that the answer is the audit log, and that
 * the log's entry carries no content body and no credential.
 *
 * It covers both shapes of rollback:
 *
 *   1. a configuration rollback — staged changes discarded, nothing written;
 *   2. a rollback after compensation — a draft created inside the
 *      transaction was compensated (deleted) and the rollback recorded that.
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
require_once $theme_root . '/agentshell-mcp/includes/class-content-write-guard.php';
require_once $theme_root . '/agentshell-mcp/includes/class-store.php';
require_once $theme_root . '/agentshell-mcp/includes/class-transactions.php';
require_once $theme_root . '/agentshell-mcp/includes/class-content-journal.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-get-audit-log.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-get-transaction.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-create-draft.php';

use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Get_Audit_Log;
use AgentShell_MCP\Tools\Get_Transaction;

$f = array();

function re_fact( $key, $value ) {
    global $f;
    $f[ $key ] = $value;
}

/** The audit entries for one operation. */
function re_entries( $operation ) {
    $tool = new Get_Audit_Log();
    $out  = $tool->execute( array( 'operation' => $operation, 'limit' => 100 ) );
    return isset( $out['entries'] ) && is_array( $out['entries'] ) ? $out['entries'] : array();
}

/** The audit entry that names a transaction id, if there is one. */
function re_entry_for( $operation, $tx_id ) {
    foreach ( re_entries( $operation ) as $entry ) {
        $changed = isset( $entry['changed'] ) && is_array( $entry['changed'] ) ? $entry['changed'] : array();
        if ( (string) ( $changed['transaction_id'] ?? '' ) === (string) $tx_id ) {
            return $entry;
        }
    }
    return null;
}

$token = 'ASre' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );

// The MCP transport authenticates as an administrator, and the compensation
// re-checks the per-post `delete_post` capability at the moment it deletes.
// A child that ran with no logged-in user would be refused that capability
// — correctly — and would prove nothing about the rollback.
wp_set_current_user( 1 );

// ── 1. a configuration rollback ───────────────────────────────────────────
$tx = Tx::instance()->begin( 'live rollback evidence ' . $token );
$tx_id = (string) ( $tx['id'] ?? '' );
re_fact( 'tx_id', $tx_id );

// Stage a change, so the rollback has something to discard.
$config = Tx::instance()->staged_config();
$config['design']['colors']['accent'] = '#123456';
Tx::instance()->stage( $config );
re_fact( 'staged', is_array( Tx::instance()->staged_config() ) );

$rolled = Tx::instance()->rollback();
re_fact( 'rolled_back', (bool) ( $rolled['rolled_back'] ?? false ) );

// The row is gone: there is no transaction to inspect any more.
$get_tx = new Get_Transaction();
try {
    $state = $get_tx->execute( array() );
    re_fact( 'transaction_after_rollback', isset( $state['transaction'] ) ? 'present' : 'absent' );
} catch ( \Throwable $e ) {
    re_fact( 'transaction_after_rollback', 'absent' );
}

// The evidence is in the audit log.
$entry = re_entry_for( 'transaction_rolled_back', $tx_id );
re_fact( 'rollback_entry_found', (bool) $entry );
if ( is_array( $entry ) ) {
    re_fact( 'rollback_entry', $entry );
    re_fact( 'rollback_entry_json', (string) wp_json_encode( $entry ) );
}

// The same entry, found by transaction id rather than by operation.
$tool = new Get_Audit_Log();
$by_id = $tool->execute( array( 'transaction_id' => $tx_id, 'limit' => 10 ) );
re_fact( 'entries_by_transaction_id', isset( $by_id['entries'] ) ? count( $by_id['entries'] ) : 0 );
re_fact( 'total_by_transaction_id', isset( $by_id['total'] ) ? (int) $by_id['total'] : 0 );

// ── 2. a rollback after compensation ─────────────────────────────────────
$tx2 = Tx::instance()->begin( 'live rollback compensation ' . $token );
$tx2_id = (string) ( $tx2['id'] ?? '' );
re_fact( 'tx2_id', $tx2_id );

$draft_tool = new \AgentShell_MCP\Tools\Create_Draft();
$draft = $draft_tool->execute( array(
    'title'           => 'ASre draft ' . $token,
    'content'         => 'ASre body ' . $token,
    'type'            => 'page',
    'idempotency_key' => 'asre-' . $token,
) );
$draft_id = (int) ( $draft['post_id'] ?? $draft['id'] ?? 0 );
re_fact( 'draft_id', $draft_id );
re_fact( 'draft_response', $draft );
// WordPress does not name a draft: it names a post when the post is
// published. A draft created without a slug therefore has an empty
// post_name, and the journal's after-image recorded it as empty — which
// the journal validator used to refuse, leaving the draft uncompensatable.
$draft_post = $draft_id ? get_post( $draft_id ) : null;
re_fact( 'draft_slug', $draft_post ? (string) $draft_post->post_name : '(gone)' );
$tx2_now = Tx::instance()->payload();
re_fact( 'journal_after_create', \AgentShell_MCP\Content_Journal::of( is_array( $tx2_now ) ? $tx2_now : array() ) );

$rolled2 = Tx::instance()->rollback();
re_fact( 'rolled_back_2', (bool) ( $rolled2['rolled_back'] ?? false ) );
re_fact( 'draft_gone', ! get_post( $draft_id ) );

$entry2 = re_entry_for( 'transaction_rolled_back_after_compensation', $tx2_id );
re_fact( 'compensation_entry_found', (bool) $entry2 );
if ( is_array( $entry2 ) ) {
    re_fact( 'compensation_entry_json', (string) wp_json_encode( $entry2 ) );
}

// The compensated rollback must not have left the content behind, and the
// entry must not carry the content either.
re_fact( 'entry_has_no_body', is_array( $entry2 ) && false === strpos( (string) wp_json_encode( $entry2 ), 'ASre body' ) );

file_put_contents( $report, json_encode( $f ) );
echo "reported\n";
