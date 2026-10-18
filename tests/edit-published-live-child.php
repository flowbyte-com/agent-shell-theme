<?php
/**
 * Stage 2c Milestone 6 — live regression for the new
 * `agentshell_edit_published` MCP tool (Gate 3 Task 5).
 *
 * The staging-side primitive. The publish-side primitive
 * (`agentshell_publish_edit`) was already exercised by the M4 live
 * suite at `stage-2c-publish-edit-live-child.php`, which seeded a
 * shadow + journal row by hand and called the commit tool. This
 * suite covers the staging tool itself — the path the MCP
 * transport dispatches to when an agent says "edit this published
 * post".
 *
 * Scenarios exercised here
 * ------------------------
 *   F0.  Precondition: tx option row absent, no per-original
 *        claim, no shadow meta, no shadow post — clean ledger.
 *   F1.  Fresh staging on a clean ledger returns the canonical
 *        result shape; the shadow exists; the journal op is in
 *        `staged`; the per-original claim is held by this tx;
 *        the original row is unchanged.
 *   F2.  Refusal: not in a transaction. The tool must throw a
 *        `not_in_tx` refusal before touching the shadow / claim
 *        / journal.
 *   F3.  Refusal: bad idempotency_key (empty / non-printable).
 *        Same fail-closed shape as F2.
 *   F4.  Refusal: original missing / wrong type / wrong status.
 *        Three sub-cases, all closed before any state change.
 *   F5.  Refusal: no fields supplied. Closed before any state
 *        change; the original is unchanged.
 *   F6.  Refusal: a different tx holds the claim. The tool must
 *        refuse with `claim_other_tx` and not advance the head.
 *   F7.  Retry-resume: same idempotency_key, same payload. The
 *        second call returns the prior result with `resumed: true`
 *        and does not create a second shadow / op.
 *   F8.  Idempotency conflict: same idempotency_key, different
 *        payload. The tool refuses with
 *        `idempotency_conflict`; the head is unchanged.
 *   F9.  Head advancement: different idempotency_key. The
 *        shadow's META_OP_ID stays the same (it points to the
 *        same op), but the shadow's post_content and the head-map
 *        entry for the original both reflect the new payload;
 *        the result carries `advanced: true`.
 *   F10. No orphans: after the suite, the tx option row is gone,
 *        no per-original claim rows survive, no shadow meta
 *        survives, and no published post is left in a state
 *        that the M5 leak check would catch.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: edit-published-live-child.php <wp_root> <report_path>\n" );
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
    'class-recovery-advisory-lock.php',
    'class-published-claim.php',
    'class-published-shadow.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/' . $inc;
}
foreach ( array(
    'class-base-tool.php',
    'class-edit-published.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/tools/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Recovery_Advisory_Lock;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Edit_Published;

global $wpdb;
wp_set_current_user( 1 );

$disposable = 'gate3-ep-' . bin2hex( random_bytes( 4 ) );

$f    = array();
$mine = array();

function ep_track_post( array &$mine, $id, $key ) {
    $mine[] = array( 'kind' => 'post', 'id' => (int) $id, 'key' => $key );
}

function ep_drop_tx_row() {
    delete_option( Tx::KEY );
    delete_option( 'agentshell_published_claim_' . '0' );
}

function ep_read_op( $op_id ) {
    $row = (array) get_option( Tx::KEY, null );
    if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id ] ) ) {
        return null;
    }
    return $row['content']['ops'][ $op_id ];
}

function ep_seed_published( $mark, $suffix ) {
    $name = "{$mark}-orig-{$suffix}";
    $existing = get_posts( array(
        'name'        => $name,
        'post_type'   => 'page',
        'post_status' => 'publish',
        'numberposts' => 1,
        'fields'      => 'ids',
    ) );
    if ( ! empty( $existing ) ) {
        return (int) $existing[0];
    }
    $post_id = wp_insert_post( array(
        'post_author'   => 1,
        'post_title'    => 'Orig Title ' . $suffix,
        'post_content'  => 'orig content ' . $suffix,
        'post_excerpt'  => 'orig excerpt',
        'post_name'     => $name,
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_date'     => '2026-10-01 00:00:00',
        'post_date_gmt' => '2026-10-01 00:00:00',
    ), true );
    if ( is_wp_error( $post_id ) ) {
        throw new RuntimeException( 'seed_published failed: ' . $post_id->get_error_message() );
    }
    return (int) $post_id;
}

function ep_drop_fixture( $orig_id, $shadow_id ) {
    if ( $shadow_id ) { wp_delete_post( $shadow_id, true ); }
    if ( $orig_id )   { wp_delete_post( $orig_id, true ); }
    delete_option( 'agentshell_published_claim_' . (int) $orig_id );
}

$tool = new Edit_Published();

// ── F0: precondition — clean ledger ──────────────────────────────────
ep_drop_tx_row();
delete_post_meta_by_key( '_agentshell_shadow_for' );
delete_post_meta_by_key( '_agentshell_shadow_tx_id' );
delete_post_meta_by_key( '_agentshell_shadow_op_id' );
$f['f0_clean'] = ( false === get_option( Tx::KEY, false ) );

// ── F1: fresh staging on a clean ledger ─────────────────────────────
$orig_id_f1 = ep_seed_published( 'gate3-ep', 'f1' );
ep_track_post( $mine, $orig_id_f1, $disposable . '-f1' );
Tx::instance()->begin( 'agent' );
$tx_id_f1 = (string) Tx::instance()->payload()['id'];
$res_f1   = $tool->execute( array(
    'original_id'     => $orig_id_f1,
    'post_title'      => 'ASTest edit-published F1',
    'post_content'    => 'F1 staged body',
    'post_excerpt'    => 'F1 excerpt',
    'post_name'       => 'gate3-ep-orig-f1-staged',
    'idempotency_key' => $disposable . '-f1',
) );
$op_id_f1 = (string) ( $res_f1['op_id'] ?? '' );
$shadow_id_f1 = (int) ( $res_f1['shadow_id'] ?? 0 );
ep_track_post( $mine, $shadow_id_f1, $disposable . '-f1-shadow' );
$f['f1_state']            = (string) ( $res_f1['state'] ?? '' );
$f['f1_resumed']          = (bool)   ( $res_f1['resumed'] ?? false );
$f['f1_advanced']         = (bool)   ( $res_f1['advanced'] ?? false );
$f['f1_op_id']            = $op_id_f1;
$f['f1_shadow_id']        = $shadow_id_f1;
$f['f1_op_idem']          = (string) ( $res_f1['idempotent']['key'] ?? '' );
$f['f1_claim_tx']         = (string) ( $res_f1['claim']['tx_id'] ?? '' );
$op_row_f1 = ep_read_op( $op_id_f1 );
$f['f1_journal_state']    = (string) ( $op_row_f1['state'] ?? '' );
$f['f1_journal_target_shadow_id'] = (int) ( $op_row_f1['target']['shadow_id'] ?? 0 );
$f['f1_journal_post_id']         = (int) ( $op_row_f1['post_id'] ?? 0 );
$shadow_row_f1 = get_post( $shadow_id_f1 );
$f['f1_shadow_exists']    = (bool) $shadow_row_f1;
$f['f1_shadow_for']       = (string) get_post_meta( $shadow_id_f1, Published_Shadow::META_SHADOW_FOR, true );
$f['f1_shadow_op_id']     = (string) get_post_meta( $shadow_id_f1, Published_Shadow::META_OP_ID, true );
$claim_f1 = Published_Claim::read( $orig_id_f1 );
$f['f1_claim_holder']     = (string) ( $claim_f1['tx_id'] ?? '' );
$orig_f1_row = get_post( $orig_id_f1 );
$f['f1_orig_unchanged_title'] = (string) ( $orig_f1_row->post_title ?? '' );
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f1, $shadow_id_f1 );
ep_drop_tx_row();

// ── F2: refusal — not in tx ──────────────────────────────────────────
$orig_id_f2 = ep_seed_published( 'gate3-ep', 'f2' );
ep_track_post( $mine, $orig_id_f2, $disposable . '-f2' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f2,
        'post_title'      => 'should refuse',
        'idempotency_key' => $disposable . '-f2',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f2_threw']      = $threw;
$f['f2_refused']    = $threw && ( false !== strpos( $reason, 'not_in_tx' ) );
$f['f2_orig_state'] = (string) ( get_post( $orig_id_f2 )->post_title ?? '' );
ep_drop_fixture( $orig_id_f2, 0 );

// ── F3: refusal — bad idempotency key ───────────────────────────────
Tx::instance()->begin( 'agent' );
$orig_id_f3 = ep_seed_published( 'gate3-ep', 'f3' );
ep_track_post( $mine, $orig_id_f3, $disposable . '-f3' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f3,
        'post_title'      => 'should refuse',
        'idempotency_key' => '',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f3_threw']   = $threw;
$f['f3_refused'] = $threw && ( false !== strpos( $reason, 'bad_idempotency_key' ) );
$op_count = Tx::instance()->payload();
$ops = is_array( $op_count['content']['ops'] ?? null ) ? $op_count['content']['ops'] : array();
$f['f3_journal_op_count'] = count( $ops );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f3, 0 );
ep_drop_tx_row();

// ── F4a: refusal — original missing ─────────────────────────────────
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => 999999,
        'post_title'      => 'should refuse',
        'idempotency_key' => $disposable . '-f4a',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f4a_threw']   = $threw;
$f['f4a_refused'] = $threw && ( false !== strpos( $reason, 'original_gone' ) );
Tx::instance()->rollback();

// ── F4b: refusal — original wrong type ──────────────────────────────
$orig_id_f4b = ep_seed_published( 'gate3-ep', 'f4b' );
ep_track_post( $mine, $orig_id_f4b, $disposable . '-f4b' );
// Force a non-supported type by direct DB update.
$wpdb->update(
    $wpdb->posts,
    array( 'post_type' => 'attachment' ),
    array( 'ID' => $orig_id_f4b ),
    array( '%s' ),
    array( '%d' )
);
wp_cache_delete( $orig_id_f4b, 'posts' );
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f4b,
        'post_title'      => 'should refuse',
        'idempotency_key' => $disposable . '-f4b',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f4b_threw']   = $threw;
$f['f4b_refused'] = $threw && ( false !== strpos( $reason, 'original_wrong_type' ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f4b, 0 );

// ── F4c: refusal — original unpublished ─────────────────────────────
$orig_id_f4c = ep_seed_published( 'gate3-ep', 'f4c' );
ep_track_post( $mine, $orig_id_f4c, $disposable . '-f4c' );
$wpdb->update(
    $wpdb->posts,
    array( 'post_status' => 'draft' ),
    array( 'ID' => $orig_id_f4c ),
    array( '%s' ),
    array( '%d' )
);
wp_cache_delete( $orig_id_f4c, 'posts' );
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f4c,
        'post_title'      => 'should refuse',
        'idempotency_key' => $disposable . '-f4c',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f4c_threw']   = $threw;
$f['f4c_refused'] = $threw && ( false !== strpos( $reason, 'original_unpublished' ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f4c, 0 );

// ── F5: refusal — no fields ─────────────────────────────────────────
$orig_id_f5 = ep_seed_published( 'gate3-ep', 'f5' );
ep_track_post( $mine, $orig_id_f5, $disposable . '-f5' );
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f5,
        'idempotency_key' => $disposable . '-f5',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f5_threw']   = $threw;
$f['f5_refused'] = $threw && ( false !== strpos( $reason, 'no_op' ) );
$op_count = Tx::instance()->payload();
$ops = is_array( $op_count['content']['ops'] ?? null ) ? $op_count['content']['ops'] : array();
$f['f5_journal_op_count'] = count( $ops );
$orig_f5 = get_post( $orig_id_f5 );
$f['f5_orig_unchanged_title'] = (string) ( $orig_f5->post_title ?? '' );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f5, 0 );
ep_drop_tx_row();

// ── F6: refusal — different tx holds claim ──────────────────────────
$orig_id_f6 = ep_seed_published( 'gate3-ep', 'f6' );
ep_track_post( $mine, $orig_id_f6, $disposable . '-f6' );
// Open a different tx, then hand-place a claim owned by that other
// tx for this original. Then open the current tx and try to stage.
Tx::instance()->begin( 'other-agent' );
Tx::instance()->rollback();
$other_claim = Published_Claim::shape(
    'tx_other_competing',
    $orig_id_f6,
    0,
    'other-actor',
    time(),
    time(),
    Published_Claim::TTL_SECONDS
);
Published_Claim::create( $orig_id_f6, $other_claim );
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f6,
        'post_title'      => 'should refuse',
        'idempotency_key' => $disposable . '-f6',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f6_threw']   = $threw;
$f['f6_refused'] = $threw && ( false !== strpos( $reason, 'claim_other_tx' ) );
$op_count = Tx::instance()->payload();
$ops = is_array( $op_count['content']['ops'] ?? null ) ? $op_count['content']['ops'] : array();
$f['f6_journal_op_count'] = count( $ops );
$orig_f6 = get_post( $orig_id_f6 );
$f['f6_orig_unchanged_title'] = (string) ( $orig_f6->post_title ?? '' );
Tx::instance()->rollback();
delete_option( 'agentshell_published_claim_' . (int) $orig_id_f6 );
ep_drop_fixture( $orig_id_f6, 0 );
ep_drop_tx_row();

// ── F7: retry-resume (same key, same payload) ───────────────────────
$orig_id_f7 = ep_seed_published( 'gate3-ep', 'f7' );
ep_track_post( $mine, $orig_id_f7, $disposable . '-f7' );
Tx::instance()->begin( 'agent' );
$res_f7a = $tool->execute( array(
    'original_id'     => $orig_id_f7,
    'post_title'      => 'ASTest F7 first',
    'post_content'    => 'F7 first body',
    'idempotency_key' => $disposable . '-f7',
) );
$op_id_f7    = (string) ( $res_f7a['op_id'] ?? '' );
$shadow_id_f7 = (int) ( $res_f7a['shadow_id'] ?? 0 );
ep_track_post( $mine, $shadow_id_f7, $disposable . '-f7-shadow' );
$res_f7b = $tool->execute( array(
    'original_id'     => $orig_id_f7,
    'post_title'      => 'ASTest F7 first',
    'post_content'    => 'F7 first body',
    'idempotency_key' => $disposable . '-f7',
) );
$f['f7a_resumed'] = (bool) ( $res_f7a['resumed'] ?? false );
$f['f7b_resumed'] = (bool) ( $res_f7b['resumed'] ?? false );
$f['f7b_advanced'] = (bool) ( $res_f7b['advanced'] ?? false );
$f['f7a_op_id']    = (string) ( $res_f7a['op_id'] ?? '' );
$f['f7b_op_id']    = (string) ( $res_f7b['op_id'] ?? '' );
$f['f7a_shadow_id'] = (int) ( $res_f7a['shadow_id'] ?? 0 );
$f['f7b_shadow_id'] = (int) ( $res_f7b['shadow_id'] ?? 0 );
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f7, $shadow_id_f7 );
ep_drop_tx_row();

// ── F8: idempotency conflict (same key, different payload) ──────────
$orig_id_f8 = ep_seed_published( 'gate3-ep', 'f8' );
ep_track_post( $mine, $orig_id_f8, $disposable . '-f8' );
Tx::instance()->begin( 'agent' );
$res_f8a = $tool->execute( array(
    'original_id'     => $orig_id_f8,
    'post_title'      => 'ASTest F8 first',
    'post_content'    => 'F8 first body',
    'idempotency_key' => $disposable . '-f8',
) );
$op_id_f8     = (string) ( $res_f8a['op_id'] ?? '' );
$shadow_id_f8 = (int) ( $res_f8a['shadow_id'] ?? 0 );
ep_track_post( $mine, $shadow_id_f8, $disposable . '-f8-shadow' );
$shadow_content_before = (string) get_post( $shadow_id_f8 )->post_content;
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_id_f8,
        'post_title'      => 'ASTest F8 second different',
        'post_content'    => 'F8 second different body',
        'idempotency_key' => $disposable . '-f8',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['f8_threw']   = $threw;
$f['f8_refused'] = $threw && ( false !== strpos( $reason, 'idempotency_conflict' ) );
$shadow_after = (string) get_post( $shadow_id_f8 )->post_content;
$f['f8_shadow_unchanged'] = ( $shadow_content_before === $shadow_after );
$op_after = ep_read_op( $op_id_f8 );
$f['f8_op_state_unchanged'] = ( 'staged' === (string) ( $op_after['state'] ?? '' ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f8, $shadow_id_f8 );
ep_drop_tx_row();

// ── F9: head advancement (different key) ────────────────────────────
$orig_id_f9 = ep_seed_published( 'gate3-ep', 'f9' );
ep_track_post( $mine, $orig_id_f9, $disposable . '-f9' );
Tx::instance()->begin( 'agent' );
$res_f9a = $tool->execute( array(
    'original_id'     => $orig_id_f9,
    'post_title'      => 'ASTest F9 first',
    'post_content'    => 'F9 first body',
    'idempotency_key' => $disposable . '-f9',
) );
$op_id_f9      = (string) ( $res_f9a['op_id'] ?? '' );
$shadow_id_f9  = (int) ( $res_f9a['shadow_id'] ?? 0 );
ep_track_post( $mine, $shadow_id_f9, $disposable . '-f9-shadow' );
$res_f9b = $tool->execute( array(
    'original_id'     => $orig_id_f9,
    'post_title'      => 'ASTest F9 second',
    'post_content'    => 'F9 second body',
    'idempotency_key' => $disposable . '-f9b',
) );
$f['f9a_advanced'] = (bool) ( $res_f9a['advanced'] ?? false );
$f['f9b_advanced'] = (bool) ( $res_f9b['advanced'] ?? false );
$f['f9a_op_id']    = $op_id_f9;
$f['f9b_op_id']    = (string) ( $res_f9b['op_id'] ?? '' );
$f['f9a_shadow_id'] = $shadow_id_f9;
$f['f9b_shadow_id'] = (int) ( $res_f9b['shadow_id'] ?? 0 );
$f['f9_shadow_content'] = (string) get_post( $shadow_id_f9 )->post_content;
$f['f9_shadow_op_id']   = (string) get_post_meta( $shadow_id_f9, Published_Shadow::META_OP_ID, true );
$op_row_f9 = ep_read_op( $op_id_f9 );
$f['f9_op_state']   = (string) ( $op_row_f9['state'] ?? '' );
$tx_row = (array) get_option( Tx::KEY, null );
$head   = is_array( $tx_row['content'][ Content_Journal::HEAD_KEY ] ?? null )
    ? $tx_row['content'][ Content_Journal::HEAD_KEY ]
    : array();
$f['f9_head_for_original'] = isset( $head[ (int) $orig_id_f9 ] ) ? 1 : 0;
$f['f9_head_op_meta'] = (string) ( $head[ (int) $orig_id_f9 ]['op_meta_value'] ?? '' );
file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
Tx::instance()->rollback();
ep_drop_fixture( $orig_id_f9, $shadow_id_f9 );
ep_drop_tx_row();

// ── F10: no orphans ─────────────────────────────────────────────────
$f['f10_tx_row_absent']   = ( false === get_option( Tx::KEY, false ) );
$f['f10_claim_count']     = 0;
$f['f10_shadow_meta_count'] = 0;
$all_claims = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'agentshell\\_published\\_claim\\_%' ESCAPE '\\\\'"
);
$f['f10_claim_count'] = is_array( $all_claims ) ? count( $all_claims ) : 0;
$all_shadow_meta = $wpdb->get_col(
    "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')"
);
$f['f10_shadow_meta_count'] = is_array( $all_shadow_meta ) ? count( $all_shadow_meta ) : 0;
$survivors = 0;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) { $survivors++; }
}
$f['f10_survivor_count'] = $survivors;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) {
        wp_delete_post( $track['id'], true );
    }
}
$still_alive = 0;
foreach ( $mine as $track ) {
    if ( get_post( $track['id'] ) ) { $still_alive++; }
}
$f['f10_orphan_count'] = $still_alive;

file_put_contents( $report_path, json_encode( $f, JSON_PRETTY_PRINT ) );
exit( 0 );
