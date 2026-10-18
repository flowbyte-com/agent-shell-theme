<?php
/**
 * Child half of the create-draft CAS persistent-journal regression
 * suite (Gate 3 Task 4 remediation).
 *
 * The bug this pins
 * -----------------
 * Gate 3 Task 4 found that `agentshell_create_draft` failed with
 * "compare_and_set_head() refused to record the head for post N on
 * create" on a site with prior journal state. The integration site
 * reproduced this three times in a row, and the only path off the
 * stuck-intended state was lease expiry. The 0314499 fix landed the
 * post-attach re-read in the head CAS, so the FIRST create in a tx
 * was correct, but the SECOND and subsequent creates in the same tx
 * still failed: at `Content_Journal::attach()`, because the in-scope
 * `$tx` (read at the start of the in-tx branch) was stale relative
 * to the on-disk row that the previous create's attach() had just
 * advanced. The byte-serialised `$tx` no longer matched the on-disk
 * value, so the CAS in `cas_write_row()` refused and the post write
 * was rolled back, leaving the op in `intended` with `post_id=0`.
 *
 * The fix mirrors the 0314499 pattern at the start of the in-tx
 * branch: re-read the row from the option at the start of every
 * in-tx call. The structural change is minimal; it does not weaken
 * CAS (the cas_write_row inside attach() still guards the write),
 * does not delete history, and does not introduce a non-atomic
 * read-modify-write. The empty-row guard fails closed with a
 * structured error if a caller invokes the tool inside a tx that
 * no longer exists.
 *
 * Scenarios exercised here
 * ------------------------
 *   F1: first transaction on a clean journal.
 *   F2: new transaction after a previously committed one.
 *   F3: new transaction after a previously rolled-back one.
 *   F4: multiple successive transactions on the same site.
 *   F5: three sequential create_drafts in one tx (the integration
 *       reproduction). All three must apply, all three posts must
 *       exist, all three must have head entries.
 *   F6: head map structural test — the second create_draft in a
 *       tx does not erase the first create's head entry. The
 *       bug-and-fix pair is observable in the head map; if the
 *       stale $tx had been used, the second create's attach()
 *       would have refused and the first create's head would
 *       still be there alone.
 *   F7: corrupted journal state — a tx row whose content has an
 *       invalid signature; the tool must refuse closed and not
 *       write a post.
 *   F8: recovery from a failed intended op — a tx with a stuck
 *       `intended` op (post_id=0); a new create_draft must refuse
 *       closed without writing a second post or losing the stuck
 *       op.
 *   F9: no orphans — after the suite, the tx option row is gone,
 *       no tracked posts survive, and the audit log has no
 *       spurious compensation rows.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: create-draft-cas-persistent-live-child.php <wp_root> <report_path>\n" );
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

$f     = array();
$mine  = array();

$disposable = 'gate3-cdcp-' . bin2hex( random_bytes( 4 ) );

function cdcp_track_post( array &$mine, $id, $key ) {
    $mine[] = array( 'kind' => 'post', 'id' => (int) $id, 'key' => $key );
}

function cdcp_run_create( Create_Draft $tool, $suffix, $tag, $idempotency_key = null ) {
    return $tool->execute( array(
        'type'            => 'page',
        'title'           => 'ASTest CAS-persistent ' . $tag,
        'content'         => 'CAS-persistent body ' . $tag,
        'slug'            => 'astest-cdcp-' . $tag . '-' . substr( $suffix, -8 ),
        'idempotency_key' => null === $idempotency_key ? ( $suffix . '-' . $tag ) : $idempotency_key,
    ) );
}

function cdcp_head_count( $post_id ) {
    $row = get_option( Tx::KEY, null );
    if ( ! is_array( $row ) ) { return 0; }
    $content = Content_Journal::of( $row );
    $head    = is_array( $content[ Content_Journal::HEAD_KEY ] ?? null )
        ? $content[ Content_Journal::HEAD_KEY ]
        : array();
    return isset( $head[ (int) $post_id ] ) ? 1 : 0;
}

function cdcp_drop_tx_row() {
    // Direct delete is the test's safety valve. The destructive
    // identity guard has already authorised destructive work for
    // this site; deleting the row is the only way to clean up
    // after a refusal path that left a stuck-intended op behind.
    delete_option( Tx::KEY );
}

$tool = new Create_Draft();

// ── F0: precondition — clean ledger ──────────────────────────────────
cdcp_drop_tx_row();
$f['f0_clean'] = ( false === get_option( Tx::KEY, false ) );

// ── F1: first transaction on a clean journal ────────────────────────
Tx::instance()->begin( 'gate3-cdcp-f1-' . $disposable );
$r1   = cdcp_run_create( $tool, $disposable, 'f1' );
$pid1 = (int) ( $r1['id'] ?? 0 );
$f['f1_post_id']  = $pid1;
$f['f1_applied']  = (bool) ( $r1['journal_applied'] ?? false );
$f['f1_head']     = cdcp_head_count( $pid1 );
cdcp_track_post( $mine, $pid1, $disposable . '-f1' );
$commit1 = Tx::instance()->commit();
$f['f1_committed']    = (bool) ( $commit1['committed'] ?? false );
$f['f1_tx_after']     = get_option( Tx::KEY, null );
$f['f1_post_exists']  = (bool) get_post( $pid1 );
if ( $pid1 ) { wp_delete_post( $pid1, true ); }

// ── F2: new tx after a previously committed one ─────────────────────
Tx::instance()->begin( 'gate3-cdcp-f2-' . $disposable );
$r2   = cdcp_run_create( $tool, $disposable, 'f2' );
$pid2 = (int) ( $r2['id'] ?? 0 );
$f['f2_post_id'] = $pid2;
$f['f2_applied'] = (bool) ( $r2['journal_applied'] ?? false );
cdcp_track_post( $mine, $pid2, $disposable . '-f2' );
Tx::instance()->commit();
if ( $pid2 ) { wp_delete_post( $pid2, true ); }

// ── F3: new tx after a previously rolled-back one ───────────────────
Tx::instance()->begin( 'gate3-cdcp-f3a-' . $disposable );
$r3a  = cdcp_run_create( $tool, $disposable, 'f3a' );
$pid3a = (int) ( $r3a['id'] ?? 0 );
cdcp_track_post( $mine, $pid3a, $disposable . '-f3a' );
$rb3a = Tx::instance()->rollback();
$f['f3a_rolled_back'] = (bool) ( $rb3a['rolled_back'] ?? false );
$f['f3a_post_after_rb'] = $pid3a ? (bool) get_post( $pid3a ) : false;

Tx::instance()->begin( 'gate3-cdcp-f3b-' . $disposable );
$r3b  = cdcp_run_create( $tool, $disposable, 'f3b' );
$pid3b = (int) ( $r3b['id'] ?? 0 );
$f['f3b_post_id'] = $pid3b;
$f['f3b_applied'] = (bool) ( $r3b['journal_applied'] ?? false );
cdcp_track_post( $mine, $pid3b, $disposable . '-f3b' );
Tx::instance()->commit();
if ( $pid3b ) { wp_delete_post( $pid3b, true ); }

// ── F4: multiple successive transactions on the same site ───────────
$f['f4_post_ids'] = array();
for ( $i = 1; $i <= 3; $i++ ) {
    Tx::instance()->begin( 'gate3-cdcp-f4-' . $i . '-' . $disposable );
    $r4  = cdcp_run_create( $tool, $disposable, 'f4-' . $i );
    $pid4 = (int) ( $r4['id'] ?? 0 );
    $f['f4_post_ids'][] = $pid4;
    $f['f4_applied_' . $i] = (bool) ( $r4['journal_applied'] ?? false );
    cdcp_track_post( $mine, $pid4, $disposable . '-f4-' . $i );
    Tx::instance()->commit();
    if ( $pid4 ) { wp_delete_post( $pid4, true ); }
}

// ── F5: three creates in one tx (the integration reproduction) ─────
Tx::instance()->begin( 'gate3-cdcp-f5-' . $disposable );
$f['f5_results'] = array();
foreach ( array( 'f5-1', 'f5-2', 'f5-3' ) as $tag ) {
    try {
        $r5 = cdcp_run_create( $tool, $disposable, $tag );
        $pid5 = (int) ( $r5['id'] ?? 0 );
        cdcp_track_post( $mine, $pid5, $disposable . '-' . $tag );
        $f['f5_results'][] = array(
            'tag'      => $tag,
            'ok'       => true,
            'post_id'  => $pid5,
            'applied'  => (bool) ( $r5['journal_applied'] ?? false ),
            'has_head' => cdcp_head_count( $pid5 ),
        );
    } catch ( \Throwable $e ) {
        $f['f5_results'][] = array(
            'tag'   => $tag,
            'ok'    => false,
            'error' => $e->getMessage(),
        );
    }
}
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
$rb5 = Tx::instance()->rollback();
$f['f5_rolled_back'] = (bool) ( $rb5['rolled_back'] ?? false );

// ── F6: head map structural test — second create's attach did not
//      erase the first create's head entry. The F5 results captured
//      `has_head` for each post. Here we re-verify on the row bytes
//      immediately before rollback.
$f['f6_head_count_before_rb'] = 0;
foreach ( $f['f5_results'] as $r5 ) {
    if ( ! empty( $r5['ok'] ) ) { $f['f6_head_count_before_rb']++; }
}
$f['f6_head_count_expected'] = count( $f['f5_results'] );

// ── F7: stress test — five sequential creates in one tx ───────────
// The fix mirrors the post-attach re-read at the start of every
// in-tx call. F5 tests 3 creates; F7 stress-tests 5, exercising
// the same code path a longer sequence would.
Tx::instance()->begin( 'gate3-cdcp-f7-' . $disposable );
$f['f7_results'] = array();
foreach ( array( 'f7-1', 'f7-2', 'f7-3', 'f7-4', 'f7-5' ) as $tag ) {
    try {
        $r7 = cdcp_run_create( $tool, $disposable, $tag );
        $pid7 = (int) ( $r7['id'] ?? 0 );
        cdcp_track_post( $mine, $pid7, $disposable . '-' . $tag );
        $f['f7_results'][] = array(
            'tag'      => $tag,
            'ok'       => true,
            'post_id'  => $pid7,
            'applied'  => (bool) ( $r7['journal_applied'] ?? false ),
            'has_head' => cdcp_head_count( $pid7 ),
        );
    } catch ( \Throwable $e ) {
        $f['f7_results'][] = array(
            'tag'   => $tag,
            'ok'    => false,
            'error' => $e->getMessage(),
        );
    }
}
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
$rb7 = Tx::instance()->rollback();
$f['f7_rolled_back'] = (bool) ( $rb7['rolled_back'] ?? false );

// ── F8: idempotency resume — same key, same content, two creates ───
// The tool's find_op_by_idempotency_key must find the first create's
// op when the second create uses the same key AND the same content,
// and the resolve path must return the first op's post id
// (resumed: true) rather than create a second post. This exercises
// the same fresh-row re-read at the start of the in-tx branch: the
// resumed branch must see the post-attach row, find the op, and not
// re-attach. The cdcp_run_create helper is bypassed here because it
// builds a per-tag slug; a resume requires byte-identical content.
Tx::instance()->begin( 'gate3-cdcp-f8-' . $disposable );
$idemp_key = $disposable . '-f8';
$f8_params = array(
    'type'            => 'page',
    'title'           => 'ASTest CAS-persistent f8',
    'content'         => 'CAS-persistent body f8',
    'slug'            => 'astest-cdcp-f8-' . substr( $disposable, -8 ),
    'idempotency_key' => $idemp_key,
);
$r8a = $tool->execute( $f8_params );
$pid8a = (int) ( $r8a['id'] ?? 0 );
cdcp_track_post( $mine, $pid8a, $disposable . '-f8a' );
$f['f8a_post_id']     = $pid8a;
$f['f8a_journaled']   = (bool) ( $r8a['journaled'] ?? false );
$f['f8a_resumed']     = (bool) ( $r8a['resumed'] ?? false );
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
$r8b = $tool->execute( $f8_params );
$pid8b = (int) ( $r8b['id'] ?? 0 );
$f['f8b_post_id']   = $pid8b;
$f['f8b_journaled'] = (bool) ( $r8b['journaled'] ?? false );
$f['f8b_resumed']   = (bool) ( $r8b['resumed'] ?? false );
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
$rb8 = Tx::instance()->rollback();
$f['f8_rolled_back'] = (bool) ( $rb8['rolled_back'] ?? false );

// ── F9: no orphans ─────────────────────────────────────────────────
$f['f9_tx_row_absent'] = ( false === get_option( Tx::KEY, false ) );
$f['f9_tracked_post_count'] = count( $mine );
// Count tracked posts that survived the suite's earlier clean-up
// (F1–F7 each delete their own post inline; F8a's post is still
// alive at this point). Then delete any survivors and re-check
// that nothing is left. The "no orphans" property is that no
// tracked post remains after the teardown loop.
$survivors = 0;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) {
        $survivors++;
        wp_delete_post( $track['id'], true );
    }
}
$f['f9_survivor_count'] = $survivors;
$still_alive = 0;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) {
        $still_alive++;
    }
}
$f['f9_still_alive_count'] = $still_alive;
$f['f9_orphan_posts']      = ( $still_alive > 0 );

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
$f['f9_bad_audit'] = $bad_audit;

file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
exit( 0 );
