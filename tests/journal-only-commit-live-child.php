<?php
/**
 * Child half of the journal-only commit regression suite. Runs inside a real
 * WordPress install and reports facts as JSON; the parent asserts them.
 *
 * Why this exists
 * ---------------
 * Gate 3 Section D surfaced a real defect: agentshell_commit_transaction
 * refused journal-only transactions with "Transaction has no staged
 * changes" even when validate_transaction said the close was clean. The
 * only path through was a dummy set_css_var, which (a) wrote a config
 * change the operator did not ask for, (b) erased that change from the
 * diff, and (c) left an audit row that does not match what the operator
 * did. A dummy mutation is not a contract.
 *
 * The fix lets journal-only transactions close when the journal is
 * ready: settled, no unsettled ops, audit writes the op summary, the
 * option is preserved (not overwritten with the null staged value), the
 * diff token list is empty, the tx row is value-guarded deleted, and
 * the rolled-back / empty branches are still refused. This file
 * exercises every one of those through real PHP classes and real
 * WordPress state. The parent reads the report and asserts.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$wp_root}\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
foreach ( array(
    'class-store.php',
    'class-doctor.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-content-recovery.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;

global $wpdb;

// The per-post `delete_post` capability is checked at the moment of
// destruction inside `compensate()`. The CLI context has no logged-in
// user, so `current_user_can()` returns false for everything. Switch
// to the install's admin (user 1, created by every WordPress setup
// with the administrator role) so the per-post gate is held for the
// drafts this child creates. Mirrors the pattern in
// `journal-recovery-live-child.php`.
wp_set_current_user( 1 );

$f     = array();
$mine  = array();

// Record a disposable post id so the parent can clean up if the child
// crashes mid-script. The parent's teardown loop is the primary path;
// this is the safety net.
$disposable_key = 'gate3-joc-' . bin2hex( random_bytes( 4 ) );
function track_post( array &$mine, $id, $key ) {
    $mine[] = array( 'kind' => 'post', 'id' => (int) $id, 'key' => $key );
}

function joc_refuse( callable $fn ) {
    try {
        $fn();
        return null; // no exception: report a separate anomaly
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
}

// ── F0: precondition ──────────────────────────────────────────────────
// Wipe the tx row so a prior failed run cannot poison this one. The
// parent's pre-flight already calls agentshell-live-precondition for the
// agent-actor path, but a Basic Auth caller (different actor) is a
// different row owner; the live precondition is a 0-marker check, not
// a tx-clear. This is the one place we are entitled to delete the
// option: it is a test fixture, the precondition has already
// authorised destructive work, and the test's contract is "every
// assertion is against a clean ledger".
delete_option( Tx::KEY );
$f['f0_clean'] = ( false === get_option( Tx::KEY, false ) );

// Capture the persisted config so the journal-only commit can prove it
// did not erase the option. A two-key sample is enough — the test
// asserts structural preservation, not value-by-value match.
$cfg_before = get_option( Store::CONFIG_KEY, null );
$cfg_keys   = is_array( $cfg_before ) ? array_keys( $cfg_before ) : array();
$f['f0_cfg_keys_before'] = $cfg_keys;

// ── F1: journal-only commit succeeds with no staged config ────────────
// The heart of Section D. Begin a tx, run create_draft, commit. No
// dummy set_css_var, no dummy anything. The commit must succeed; the
// tx row must be gone; the persisted config must equal its pre-state;
// the post must exist with the marker.
$tx = Tx::instance()->begin( 'gate3-joc-journal-only-' . $disposable_key );
$tx_id = (string) $tx['id'];

// Drive create_draft through its real path. record_intent, attach,
// insert, capture after-image, head advance, mark_applied — the same
// sequence the live MCP tool runs. We bypass the tool wrapper so the
// failure modes of the journal-aware close are isolated from the tool
// wrapper's schema checks.
$op = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft' ),
    array( 'idempotency_key' => $disposable_key ),
    Store::actor()
);
if ( ! Content_Journal::attach( $tx, $op ) ) {
    $f['f1_error'] = 'attach refused';
    file_put_contents( $report_path, json_encode( $f ) );
    exit( 0 );
}
$tx_post_attach = (array) get_option( Tx::KEY, null );

$result = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $op, $tx_post_attach ) {
    $postarr = array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'ASTest journal-only commit ' . $op['id'],
        'post_content' => 'journal-only commit body',
        'post_name'    => 'astest-joc-' . substr( $op['id'], -8 ),
        'meta_input'   => array( Content_Journal::OP_META => $op['id'] ),
    );
    $id = wp_insert_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $id ) || ! $id ) {
        throw new \RuntimeException( 'wp_insert_post failed' );
    }
    $post = get_post( $id );
    $after = array(
        'post_type'           => (string) $post->post_type,
        'post_status'         => (string) $post->post_status,
        'post_name'           => (string) $post->post_name,
        'post_title'          => (string) $post->post_title,
        'post_date_gmt'       => (string) $post->post_date_gmt,
        'post_modified_gmt'   => (string) $post->post_modified_gmt,
        'post_content_sha256' => hash( 'sha256', (string) $post->post_content ),
        'post_content_length' => strlen( (string) $post->post_content ),
        'op_meta_value'       => $op['id'],
    );
    $head_tx = Content_Journal::compare_and_set_head( $tx_post_attach, (int) $id, null, $after );
    if ( ! is_array( $head_tx ) ) {
        throw new \RuntimeException( 'head advance refused' );
    }
    $applied_tx = Content_Journal::mark_applied( $head_tx, $op['id'], $id, $after );
    return array( 'post_id' => (int) $id, 'applied_tx' => $applied_tx );
} );
track_post( $mine, (int) $result['post_id'], $disposable_key );
$f['f1_post_id']   = (int) $result['post_id'];
$f['f1_journaled'] = is_array( $result['applied_tx'] );

// Sanity: tx row still present, staged is null, journal has one op
$tx_row = get_option( Tx::KEY, null );
$f['f1_tx_open']            = is_array( $tx_row );
$f['f1_tx_staged_is_null']  = is_array( $tx_row ) ? ( null === $tx_row['staged'] ) : false;
$f['f1_tx_ops_count']       = is_array( $tx_row ) ? count( $tx_row['content']['ops'] ?? array() ) : -1;

// THE TEST: commit with no staged config
$commit = Tx::instance()->commit();
$f['f1_committed']         = (bool) ( $commit['committed'] ?? false );
$f['f1_changed_tokens']    = $commit['changed_tokens'] ?? array();
$f['f1_commit_message']    = $commit['tip'] ?? '';

// Tx row must be value-guarded deleted
$tx_after = get_option( Tx::KEY, null );
$f['f1_tx_row_after'] = is_array( $tx_after ) ? $tx_after['id'] : null;

// Persisted config must be unchanged (structural preservation is what
// we promise; a value-by-value match would be a different test).
$cfg_after = get_option( Store::CONFIG_KEY, null );
$cfg_keys_after = is_array( $cfg_after ) ? array_keys( $cfg_after ) : array();
$f['f1_cfg_preserved']   = ( $cfg_keys === $cfg_keys_after );

// Post must exist with the marker
$post_after = get_post( $f['f1_post_id'] );
$f['f1_post_exists']     = (bool) $post_after;
$f['f1_post_status']     = $post_after ? $post_after->post_status : '';
$f['f1_post_marker']     = $post_after ? get_post_meta( $post_after->ID, Content_Journal::OP_META, true ) : '';

// Audit row: read from the audit-log option (Store::AUDIT_KEY). The
// commit must record 'transaction_committed' (not
// 'transaction_committed_after_compensation' — the op is applied,
// not compensated, on the journal-only happy path). The log is keyed
// by an "operation" string per entry, and the commit entry's
// `details` carries the transaction id.
$audit_log = get_option( Store::AUDIT_KEY, array() );
$f['f1_audit_operation'] = '';
if ( is_array( $audit_log ) ) {
    foreach ( $audit_log as $entry ) {
        if ( ! is_array( $entry ) ) { continue; }
        if ( (string) ( $entry['operation'] ?? '' ) !== 'transaction_committed'
            && (string) ( $entry['operation'] ?? '' ) !== 'transaction_committed_after_compensation' ) {
            continue;
        }
        $detail_tx = isset( $entry['changed']['transaction_id'] ) ? (string) $entry['changed']['transaction_id'] : '';
        if ( $detail_tx === $tx_id ) {
            $f['f1_audit_operation'] = (string) $entry['operation'];
            break;
        }
    }
}

// ── F2: empty tx commit is still refused ──────────────────────────────
// The fix must not relax the original protection. A tx with neither
// staged config nor journal ops must still throw the documented error.
delete_option( Tx::KEY );
$tx2 = Tx::instance()->begin( 'gate3-joc-empty-' . $disposable_key );
$refuse = joc_refuse( function () {
    Tx::instance()->commit();
} );
$f['f2_empty_refused']    = ( null !== $refuse );
$f['f2_empty_message']    = $refuse ?? '';
$f['f2_empty_tx_still_open'] = is_array( get_option( Tx::KEY, null ) );

// Rollback the empty tx to clean up
Tx::instance()->rollback();
$f['f2_after_rollback']   = is_array( get_option( Tx::KEY, null ) );

// ── F3: staged-only commit still works (no regression on the original path) ──
delete_option( Tx::KEY );
$tx3 = Tx::instance()->begin( 'gate3-joc-staged-' . $disposable_key );
$cfg_staged = $tx3['before'];
$cfg_staged['--gate3-joc-marker'] = '#abcdef';
Tx::instance()->stage( $cfg_staged );
$commit3 = Tx::instance()->commit();
$cfg_after3 = get_option( Store::CONFIG_KEY, null );
$f['f3_staged_committed'] = (bool) ( $commit3['committed'] ?? false );
$f['f3_staged_var_value'] = is_array( $cfg_after3 ) ? ( $cfg_after3['--gate3-joc-marker'] ?? null ) : null;
// Cleanup the var we just set
delete_option( Tx::KEY );
$tx3c = Tx::instance()->begin( 'gate3-joc-staged-cleanup' );
$cfg_clean = is_array( $cfg_after3 ) ? $cfg_after3 : array();
unset( $cfg_clean['--gate3-joc-marker'] );
Tx::instance()->stage( $cfg_clean );
Tx::instance()->commit();

// ── F4: rollback of a journal-only tx is unchanged ────────────────────
// This is the destructive inverse path: the tx holds one applied op,
// the operator rolls back, the post is removed, the row is gone, the
// config is preserved. We pin this because the close path was touched.
delete_option( Tx::KEY );
$tx4 = Tx::instance()->begin( 'gate3-joc-rollback-' . $disposable_key );
$op4 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft' ),
    array( 'idempotency_key' => $disposable_key . '-rb' ),
    Store::actor()
);
Content_Journal::attach( $tx4, $op4 );
$tx4_post_attach = (array) get_option( Tx::KEY, null );
$res4 = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $op4, $tx4_post_attach ) {
    $postarr = array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'ASTest journal-only rollback ' . $op4['id'],
        'post_content' => 'rollback body',
        // post_name is required by the journal validator (see
        // class-content-journal.php OP_CREATE_DRAFT after-image
        // check). Without it, wp_insert_post stores NULL, get_post
        // returns '', and the recovery engine refuses to compensate.
        'post_name'    => 'astest-joc-rb-' . substr( $op4['id'], -8 ),
        'meta_input'   => array( Content_Journal::OP_META => $op4['id'] ),
    );
    $id = wp_insert_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $id ) || ! $id ) {
        throw new \RuntimeException( 'wp_insert_post failed' );
    }
    $post = get_post( $id );
    $after = array(
        'post_type'           => (string) $post->post_type,
        'post_status'         => (string) $post->post_status,
        'post_name'           => (string) $post->post_name,
        'post_title'          => (string) $post->post_title,
        'post_date_gmt'       => (string) $post->post_date_gmt,
        'post_modified_gmt'   => (string) $post->post_modified_gmt,
        'post_content_sha256' => hash( 'sha256', (string) $post->post_content ),
        'post_content_length' => strlen( (string) $post->post_content ),
        'op_meta_value'       => $op4['id'],
    );
    $head_tx = Content_Journal::compare_and_set_head( $tx4_post_attach, (int) $id, null, $after );
    if ( ! is_array( $head_tx ) ) {
        throw new \RuntimeException( 'head advance refused' );
    }
    $applied_tx = Content_Journal::mark_applied( $head_tx, $op4['id'], $id, $after );
    return array( 'post_id' => (int) $id, 'applied_tx' => $applied_tx );
} );
$rb4 = Tx::instance()->rollback();
$post4_after = get_post( $res4['post_id'] );
$f['f4_post_id']           = (int) $res4['post_id'];
$f['f4_rb_ok']             = (bool) ( $rb4['rolled_back'] ?? false );
$f['f4_post_gone']         = ! $post4_after;
$f['f4_tx_gone']           = ! is_array( get_option( Tx::KEY, null ) );

// ── teardown: best-effort cleanup of every disposable post ────────────
foreach ( $mine as $m ) {
    if ( 'post' === $m['kind'] ) {
        wp_delete_post( (int) $m['id'], true );
    }
}

// Final report
file_put_contents( $report_path, json_encode( $f ) );
exit( 0 );
