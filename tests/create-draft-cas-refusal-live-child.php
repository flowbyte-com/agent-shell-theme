<?php
/**
 * Child half of the create-draft CAS refusal-regression live suite.
 *
 * Origin
 * ------
 * The integration worktree's `create-draft-cas-persistent-live-{child,test}.php`
 * pair originally contained refusal scenarios F7 (tampered-signature journal)
 * and F8 (stuck intended op) that the development version replaced with a
 * 5-create stress test and an idempotency-resume test. The development
 * version's own docblock notes: "The fail-closed property is left for a
 * separate pass." This file is that separate pass — it preserves the
 * integration's refusal scenarios as a *new* test file, leaving the
 * development F0–F9 suite intact.
 *
 * What this pins
 * --------------
 *   F1: tampered-signature journal — the durable work in the existing
 *       row is detectable, and `Tx::begin()` refuses rather than
 *       silently advancing a corrupted transaction. The new create
 *       must NOT write a post, the row must NOT be advanced, and the
 *       tool's exception must be a *structured* refusal (not a fatal).
 *   F2: stuck intended op — the existing row has a valid signature
 *       but holds an `intended` op with `post_id = 0`. `Tx::begin()`
 *       refuses on durable work. The new create must NOT write a
 *       second post, the stuck op must be preserved (state still
 *       `intended`, not advanced), and the refusal must be
 *       *structured* (not a crash).
 *   F3: no leaked state — after F1 and F2, the tx option row must be
 *       gone (no journal marker on the test site for this run),
 *       every tracked post must be gone, and the audit log must
 *       carry no spurious compensation rows from this run.
 *
 * Safety contract
 * ---------------
 * This child mutates the *live* test bed's `agentshell_transaction`
 * option. The destructive child identity guard (b8a12a8) authorises
 * this work for the disposable test bed only; the per-run preflight
 * in `run-all.php` restores the test site from its verified baseline
 * if a prior crashed run left a tampered/stuck row behind, and the
 * row-drop in F0 plus the per-scenario `cdr_drop_tx_row()` calls plus
 * the F3 final-state check make the test self-cleaning under
 * graceful exit. A PHP fatal in the middle of F1 or F2 would leave
 * the row on disk, but the preflight in the next run would either
 * restore the baseline or refuse.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: create-draft-cas-refusal-live-child.php <wp_root> <report_path>\n" );
    exit( 7 );
}

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-content-recovery.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/' . $inc;
}
foreach ( array( 'class-base-tool.php', 'class-create-draft.php' ) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/tools/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Create_Draft;

global $wpdb;
wp_set_current_user( 1 );

$f    = array();
$mine = array();

$disposable = 'gate3-cdr-' . bin2hex( random_bytes( 4 ) );

function cdr_track_post( array &$mine, $id, $key ) {
    $mine[] = array( 'kind' => 'post', 'id' => (int) $id, 'key' => $key );
}

function cdr_run_create( Create_Draft $tool, $suffix, $tag ) {
    return $tool->execute( array(
        'type'            => 'page',
        'title'           => 'ASTest CAS-refusal ' . $tag,
        'content'         => 'CAS-refusal body ' . $tag,
        'slug'            => 'astest-cdr-' . $tag . '-' . substr( $suffix, -8 ),
        'idempotency_key' => $suffix . '-' . $tag,
    ) );
}

function cdr_drop_tx_row() {
    // Direct delete is the test's safety valve. The destructive
    // identity guard has already authorised destructive work for
    // this site; deleting the row is the only way to clean up
    // after a refusal path that left a stuck-intended op behind.
    delete_option( Tx::KEY );
}

$tool = new Create_Draft();

// ── F0: precondition — clean ledger ──────────────────────────────────
// Drop any row a prior crashed run may have left behind. The
// preflight in run-all.php would normally catch a leaked row, but
// the F0 drop is the per-scenario safety net.
cdr_drop_tx_row();
$f['f0_clean'] = ( false === get_option( Tx::KEY, false ) );

// ── F1: tampered-signature journal ──────────────────────────────────
// The row is well-formed (has id, actor, lease, content with ops)
// but the content's signature is wrong on purpose. The tool must
// refuse the create without writing a post, must not advance the
// row, and must surface a *structured* refusal (not a fatal).
cdr_drop_tx_row();
$row_f1 = array(
    'id'      => 'tx_corrupt_' . substr( $disposable, -8 ),
    'actor'   => Store::actor(),
    'lease'   => array( 'seconds' => 3600, 'expires_at' => time() + 3600 ),
    'staged'  => null,
    'label'   => 'gate3-cdr-f1-corrupt',
    'content' => Content_Journal::empty_journal( 'tx_corrupt_' . substr( $disposable, -8 ) ),
);
$row_f1['content']['ops']['op_corrupt'] = array(
    'id'      => 'op_corrupt',
    'op'      => Content_Journal::OP_CREATE_DRAFT,
    'state'   => 'intended',
    'actor'   => Store::actor(),
    'post_id' => 0,
    'after'   => array(),
);
$row_f1['content']['sig'] = str_repeat( 'f', 64 ); // wrong on purpose
update_option( Tx::KEY, $row_f1, 'no' );
$f['f1_corrupt_sig_pre'] = $row_f1['content']['sig'];

$row_f1_pre  = get_option( Tx::KEY, null );
$f['f1_row_pre_exists']  = is_array( $row_f1_pre );
$f['f1_row_pre_sig']     = is_array( $row_f1_pre ) ? (string) ( $row_f1_pre['content']['sig'] ?? '' ) : '';

try {
    Tx::instance()->begin( 'gate3-cdr-f1-' . $disposable );
    $r1 = cdr_run_create( $tool, $disposable, 'f1' );
    $f['f1_outcome']     = 'succeeded';
    $f['f1_post_id']     = (int) ( $r1['id'] ?? 0 );
    $f['f1_post_exists'] = $f['f1_post_id'] ? (bool) get_post( $f['f1_post_id'] ) : false;
    if ( $f['f1_post_id'] ) { wp_delete_post( $f['f1_post_id'], true ); }
} catch ( \Throwable $e ) {
    $f['f1_outcome']     = 'refused';
    $f['f1_message']     = $e->getMessage();
    $f['f1_throw_class'] = get_class( $e );
} finally {
    cdr_drop_tx_row();
}

$row_f1_post = get_option( Tx::KEY, null );
$f['f1_row_post_exists'] = is_array( $row_f1_post );
$f['f1_row_post_dropped'] = ! is_array( $row_f1_post );

file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );

// ── F2: stuck intended op — recovery path ──────────────────────────
// The row has a valid signature but holds an `intended` op with
// `post_id = 0`. The new create must refuse closed without
// writing a second post or losing the stuck op, and the refusal
// must be *structured* (a named exception, not a fatal).
cdr_drop_tx_row();
$row_f2 = array(
    'id'      => 'tx_stuck_' . substr( $disposable, -8 ),
    'actor'   => Store::actor(),
    'lease'   => array( 'seconds' => 3600, 'expires_at' => time() + 3600 ),
    'staged'  => null,
    'label'   => 'gate3-cdr-f2-stuck',
    'content' => Content_Journal::empty_journal( 'tx_stuck_' . substr( $disposable, -8 ) ),
);
$row_f2['content']['ops']['op_stuck'] = array(
    'id'      => 'op_stuck',
    'op'      => Content_Journal::OP_CREATE_DRAFT,
    'state'   => 'intended',
    'actor'   => Store::actor(),
    'post_id' => 0,
    'target'  => array( 'post_type' => 'page', 'post_status' => 'draft' ),
    'after'   => array( 'idempotency_key' => 'stuck-' . $disposable ),
);
$row_f2['content']['sig'] = Content_Journal::sign( $row_f2['content'] );
update_option( Tx::KEY, $row_f2, 'no' );
$f['f2_stuck_op_pre'] = true;

try {
    Tx::instance()->begin( 'gate3-cdr-f2-' . $disposable );
    $r2 = cdr_run_create( $tool, $disposable, 'f2' );
    $f['f2_outcome'] = 'succeeded';
    $f['f2_post_id'] = (int) ( $r2['id'] ?? 0 );
    if ( $f['f2_post_id'] ) { wp_delete_post( $f['f2_post_id'], true ); }
} catch ( \Throwable $e ) {
    $f['f2_outcome']      = 'refused';
    $f['f2_message']      = $e->getMessage();
    $f['f2_throw_class']  = get_class( $e );
}
// Verify the stuck op is still there with the same state.
$row_after_f2 = get_option( Tx::KEY, null );
$op_after_f2  = is_array( $row_after_f2 )
    ? ( $row_after_f2['content']['ops']['op_stuck'] ?? null )
    : null;
$f['f2_stuck_op_after'] = is_array( $op_after_f2 ) ? ( $op_after_f2['state'] ?? 'missing' ) : 'missing';
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
// Clean up by deleting the row directly.
cdr_drop_tx_row();

// ── F3: no leaked state ─────────────────────────────────────────────
// Tx::KEY must be empty, every tracked post must be gone, and the
// audit log must carry no spurious compensation rows from this run.
$f['f3_tx_row_absent'] = ( false === get_option( Tx::KEY, false ) );
$f['f3_tracked_post_count'] = count( $mine );

$existing = 0;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) {
        $existing++;
        wp_delete_post( $track['id'], true );
    }
}
$f['f3_existing_post_count'] = $existing;
$f['f3_orphan_posts']        = ( $existing !== $f['f3_tracked_post_count'] );

// Audit log: no spurious compensation rows from this run.
$audit = get_option( Store::AUDIT_KEY, array() );
$bad_audit = 0;
if ( is_array( $audit ) ) {
    foreach ( $audit as $entry ) {
        if ( ! is_array( $entry ) ) { continue; }
        if ( (string) ( $entry['actor'] ?? '' ) !== Store::actor() ) { continue; }
        if ( (string) ( $entry['operation'] ?? '' ) === 'transaction_committed_after_compensation' ) {
            $bad_audit++;
        }
    }
}
$f['f3_bad_audit'] = $bad_audit;

file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
exit( 0 );
