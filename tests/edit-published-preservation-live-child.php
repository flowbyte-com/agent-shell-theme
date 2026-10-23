<?php
/**
 * Field-preservation regression for the published-content edit path
 * (HAL 9001 remediation, defect D2).
 *
 * The defect
 * ----------
 * `agentshell_edit_published` treated "the caller did not mention this
 * field" as "this field is the empty string". The staged after-image was
 * therefore incomplete, the shadow carried the empty strings, and
 * `agentshell_publish_edit` — which writes the shadow's four fields to
 * the published original — erased every field the edit did not name.
 * A content-only edit published an empty title, an empty excerpt and an
 * empty slug.
 *
 * What this suite covers
 * ----------------------
 *
 *   P1. A content-only edit preserves the published title, slug and
 *       excerpt — through the staging, through the publish, and in the
 *       live row afterwards.
 *   P2. An excerpt-only edit preserves the title, slug and content.
 *   P3. An explicitly empty excerpt is honoured (it clears the excerpt)
 *       and still preserves everything else. An explicit empty value
 *       is not the same thing as an omitted field.
 *   P4. An explicitly empty title is refused by name.
 *   P5. An explicitly empty body is refused by name.
 *   P6. A call that names no field at all is still refused (no-op).
 *   P7. Head advancement inherits the SHADOW's staged values, not the
 *       original's published values: a second edit that omits a field
 *       must not drop the first edit's staged body.
 *   P8. The publish step refuses an op whose staged after-image moved a
 *       field outside the authorised change set (the destructive
 *       boundary proves the invariant, not just the staging path).
 *   P9. Rolling back an unwanted edit leaves the published original
 *       exactly as it was and removes the shadow.
 *
 * Everything runs against a real WordPress (the disposable test bed).
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: edit-published-preservation-live-child.php <wp_root> <report_path>\n" );
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
    'class-publish-edit.php',
    'class-publish-edit-reconcile.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/tools/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Edit_Published;
use AgentShell_MCP\Tools\Publish_Edit;

global $wpdb;
wp_set_current_user( 1 );

$disposable = 'gate3-epp-' . bin2hex( random_bytes( 4 ) );

$f    = array();
$mine = array();

function epp_track_post( array &$mine, $id, $key ) {
    $mine[] = array( 'kind' => 'post', 'id' => (int) $id, 'key' => $key );
}

function epp_drop_tx_row() {
    delete_option( Tx::KEY );
}

function epp_read_op( $op_id ) {
    $row = (array) get_option( Tx::KEY, null );
    if ( ! is_array( $row ) || empty( $row['content']['ops'][ $op_id ] ) ) {
        return null;
    }
    return $row['content']['ops'][ $op_id ];
}

/**
 * Seed a published page with a known title, body, excerpt and slug.
 */
function epp_seed_published( $mark, $suffix ) {
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
        'post_excerpt'  => 'orig excerpt ' . $suffix,
        'post_name'     => $name,
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_date'     => '2026-10-01 00:00:00',
        'post_date_gmt' => '2026-10-01 00:00:00',
    ), true );
    if ( is_wp_error( $post_id ) ) {
        throw new RuntimeException( 'seed_published failed: ' . $post_id->getMessage() );
    }
    return (int) $post_id;
}

/**
 * Run a tool call and capture a refusal instead of letting it abort the
 * child. A scenario that refuses is a *result*, not a crash: the parent
 * asserts on the recorded facts, so a build that refuses everything still
 * produces a report and the parent can say which expectations failed.
 */
function epp_call( callable $fn, &$error = null ) {
    $error = null;
    try {
        return $fn();
    } catch ( \Throwable $e ) {
        $error = $e->getMessage();
        return null;
    }
}

function epp_drop_fixture( $orig_id, $shadow_id ) {
    if ( $shadow_id ) { wp_delete_post( $shadow_id, true ); }
    if ( $orig_id )   { wp_delete_post( $orig_id, true ); }
    delete_option( 'agentshell_published_claim_' . (int) $orig_id );
}

$tool = new Edit_Published();

// ── P0: precondition — clean ledger ─────────────────────────────────
epp_drop_tx_row();
delete_post_meta_by_key( '_agentshell_shadow_for' );
delete_post_meta_by_key( '_agentshell_shadow_tx_id' );
delete_post_meta_by_key( '_agentshell_shadow_op_id' );
$f['p0_clean'] = ( false === get_option( Tx::KEY, false ) );

// ── P1: a content-only edit preserves everything else ────────────────
$orig_p1 = epp_seed_published( $disposable, 'p1' );
epp_track_post( $mine, $orig_p1, $disposable . '-p1' );
$before_p1 = get_post( $orig_p1 );
$title_p1 = (string) $before_p1->post_title;
$excerpt_p1 = (string) $before_p1->post_excerpt;
$slug_p1 = (string) $before_p1->post_name;

Tx::instance()->begin( 'agent' );
$err = null;
$res_p1 = epp_call( function () use ( $tool, $orig_p1, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p1,
        'post_content'    => 'P1 staged body',
        'idempotency_key' => $disposable . '-p1',
    ) );
}, $err );
$f['p1_staging_error'] = (string) $err;
$op_p1     = (string) ( $res_p1['op_id'] ?? '' );
$shadow_p1 = (int) ( $res_p1['shadow_id'] ?? 0 );
epp_track_post( $mine, $shadow_p1, $disposable . '-p1-shadow' );

$op_row_p1 = epp_read_op( $op_p1 );
$f['p1_after_title_is_original']   = (string) ( $op_row_p1['after']['post_title'] ?? '' ) === $title_p1;
$f['p1_after_excerpt_is_original'] = (string) ( $op_row_p1['after']['post_excerpt'] ?? '' ) === $excerpt_p1;
$f['p1_after_name_is_original']    = (string) ( $op_row_p1['after']['post_name'] ?? '' ) === $slug_p1;
$f['p1_after_content_is_new']      = (string) ( $op_row_p1['after']['post_content'] ?? '' ) === 'P1 staged body';
$f['p1_staged_fields']             = (array) ( $op_row_p1['after']['staged_fields'] ?? array() );
$f['p1_staged_fields_is_content']  = ( $f['p1_staged_fields'] === array( 'post_content' ) );
$f['p1_result_staged_fields']      = (array) ( $res_p1['staged']['fields'] ?? array() );
$f['p1_result_inherited']          = (array) ( $res_p1['staged']['inherited'] ?? array() );
$f['p1_result_inherited_count']    = count( $f['p1_result_inherited'] );

// The shadow must carry the inherited values too.
$shadow_post_p1 = get_post( $shadow_p1 );
$f['p1_shadow_title_is_original']   = (string) $shadow_post_p1->post_title === $title_p1;
$f['p1_shadow_excerpt_is_original'] = (string) $shadow_post_p1->post_excerpt === $excerpt_p1;
$f['p1_shadow_staged_name']         = (string) get_post_meta( $shadow_p1, Published_Shadow::META_STAGED_POST_NAME, true ) === $slug_p1;

// Publish it, and check the live row.
$err = null;
$publish_p1 = epp_call( function () { return ( new Publish_Edit() )->execute( array() ); }, $err );
$f['p1_publish_error'] = (string) $err;
$f['p1_publish_state'] = (string) ( $publish_p1['state'] ?? '' );
$after_p1 = get_post( $orig_p1 );
$f['p1_live_title_preserved']   = (string) $after_p1->post_title === $title_p1;
$f['p1_live_excerpt_preserved'] = (string) $after_p1->post_excerpt === $excerpt_p1;
$f['p1_live_slug_preserved']    = (string) $after_p1->post_name === $slug_p1;
$f['p1_live_content_changed']   = (string) $after_p1->post_content === 'P1 staged body';
epp_drop_fixture( $orig_p1, 0 );
epp_drop_tx_row();

// ── P2: an excerpt-only edit preserves title, slug and content ──────
$orig_p2 = epp_seed_published( $disposable, 'p2' );
epp_track_post( $mine, $orig_p2, $disposable . '-p2' );
$before_p2 = get_post( $orig_p2 );
$title_p2 = (string) $before_p2->post_title;
$slug_p2 = (string) $before_p2->post_name;
$content_p2 = (string) $before_p2->post_content;

Tx::instance()->begin( 'agent' );
$err = null;
epp_call( function () use ( $tool, $orig_p2, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p2,
        'post_excerpt'    => 'P2 new excerpt',
        'idempotency_key' => $disposable . '-p2',
    ) );
}, $err );
$f['p2_staging_error'] = (string) $err;
epp_call( function () { return ( new Publish_Edit() )->execute( array() ); }, $err );
$f['p2_publish_error'] = (string) $err;
$after_p2 = get_post( $orig_p2 );
$f['p2_live_title_preserved']    = (string) $after_p2->post_title === $title_p2;
$f['p2_live_slug_preserved']     = (string) $after_p2->post_name === $slug_p2;
$f['p2_live_content_preserved']  = (string) $after_p2->post_content === $content_p2;
$f['p2_live_excerpt_changed']    = (string) $after_p2->post_excerpt === 'P2 new excerpt';
epp_drop_fixture( $orig_p2, 0 );
epp_drop_tx_row();

// ── P3: an explicitly empty excerpt is honoured ─────────────────────
$orig_p3 = epp_seed_published( $disposable, 'p3' );
epp_track_post( $mine, $orig_p3, $disposable . '-p3' );
$before_p3 = get_post( $orig_p3 );
$title_p3 = (string) $before_p3->post_title;
$slug_p3 = (string) $before_p3->post_name;
$content_p3 = (string) $before_p3->post_content;

Tx::instance()->begin( 'agent' );
$err = null;
epp_call( function () use ( $tool, $orig_p3, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p3,
        'post_excerpt'    => '',
        'idempotency_key' => $disposable . '-p3',
    ) );
}, $err );
$f['p3_staging_error'] = (string) $err;
epp_call( function () { return ( new Publish_Edit() )->execute( array() ); }, $err );
$f['p3_publish_error'] = (string) $err;
$after_p3 = get_post( $orig_p3 );
$f['p3_live_excerpt_cleared']   = (string) $after_p3->post_excerpt === '';
$f['p3_live_title_preserved']   = (string) $after_p3->post_title === $title_p3;
$f['p3_live_slug_preserved']    = (string) $after_p3->post_name === $slug_p3;
$f['p3_live_content_preserved'] = (string) $after_p3->post_content === $content_p3;
epp_drop_fixture( $orig_p3, 0 );
epp_drop_tx_row();

// ── P4/P5/P6: refusals ─────────────────────────────────────────────
$orig_p4 = epp_seed_published( $disposable, 'p4' );
epp_track_post( $mine, $orig_p4, $disposable . '-p4' );
Tx::instance()->begin( 'agent' );
$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_p4,
        'post_title'      => '',
        'idempotency_key' => $disposable . '-p4',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['p4_threw']   = $threw;
$f['p4_refused'] = $threw && ( false !== strpos( $reason, 'empty_title' ) );
$f['p4_no_op']   = count( (array) ( ( epp_read_op( 'x' ) ?? array() ) ) );

$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_p4,
        'post_content'    => '',
        'idempotency_key' => $disposable . '-p4b',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['p5_threw']   = $threw;
$f['p5_refused'] = $threw && ( false !== strpos( $reason, 'empty_content' ) );

$threw = false; $reason = '';
try {
    $tool->execute( array(
        'original_id'     => $orig_p4,
        'idempotency_key' => $disposable . '-p4c',
    ) );
} catch ( \InvalidArgumentException $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['p6_threw']   = $threw;
$f['p6_refused'] = $threw && ( false !== strpos( $reason, 'no_op' ) );

// The refusals must not have staged anything.
$row_p4 = (array) get_option( Tx::KEY, null );
$f['p4_journal_ops'] = count( (array) ( $row_p4['content']['ops'] ?? array() ) );
$orig_p4_after = get_post( $orig_p4 );
$f['p4_orig_title_untouched'] = (string) $orig_p4_after->post_title === 'Orig Title p4';
Tx::instance()->rollback();
epp_drop_fixture( $orig_p4, 0 );
epp_drop_tx_row();

// ── P7: head advancement inherits the SHADOW's staged values ────────
$orig_p7 = epp_seed_published( $disposable, 'p7' );
epp_track_post( $mine, $orig_p7, $disposable . '-p7' );
Tx::instance()->begin( 'agent' );
$err = null;
$res_p7a = epp_call( function () use ( $tool, $orig_p7, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p7,
        'post_content'    => 'P7 body from op one',
        'idempotency_key' => $disposable . '-p7a',
    ) );
}, $err );
$f['p7a_staging_error'] = (string) $err;
$shadow_p7 = (int) ( $res_p7a['shadow_id'] ?? 0 );
epp_track_post( $mine, $shadow_p7, $disposable . '-p7-shadow' );
// A second edit that touches only the title must not drop the body
// the first edit staged.
$res_p7b = epp_call( function () use ( $tool, $orig_p7, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p7,
        'post_title'      => 'P7 title from op two',
        'idempotency_key' => $disposable . '-p7b',
    ) );
}, $err );
$f['p7b_staging_error'] = (string) $err;
$f['p7_advanced'] = (bool) ( $res_p7b['advanced'] ?? false );
$shadow_post_p7 = get_post( $shadow_p7 );
$f['p7_shadow_content_kept'] = (string) $shadow_post_p7->post_content === 'P7 body from op one';
$f['p7_shadow_title_new']    = (string) $shadow_post_p7->post_title === 'P7 title from op two';
$f['p7_staged_fields_union'] = (array) ( epp_read_op( (string) ( $res_p7b['op_id'] ?? '' ) )['after']['staged_fields'] ?? array() );
$f['p7_union_has_content']   = in_array( 'post_content', $f['p7_staged_fields_union'], true );
$f['p7_union_has_title']     = in_array( 'post_title', $f['p7_staged_fields_union'], true );
epp_call( function () { return ( new Publish_Edit() )->execute( array() ); }, $err );
$f['p7_publish_error'] = (string) $err;
$after_p7 = get_post( $orig_p7 );
$f['p7_live_title_new']    = (string) $after_p7->post_title === 'P7 title from op two';
$f['p7_live_content_new'] = (string) $after_p7->post_content === 'P7 body from op one';
epp_drop_fixture( $orig_p7, 0 );
epp_drop_tx_row();

// ── P8: the publish step refuses an unstaged-field drift ───────────
$orig_p8 = epp_seed_published( $disposable, 'p8' );
epp_track_post( $mine, $orig_p8, $disposable . '-p8' );
$before_p8 = get_post( $orig_p8 );
$title_p8 = (string) $before_p8->post_title;
Tx::instance()->begin( 'agent' );
$err = null;
$res_p8 = epp_call( function () use ( $tool, $orig_p8, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p8,
        'post_content'    => 'P8 body',
        'idempotency_key' => $disposable . '-p8',
    ) );
}, $err );
$f['p8_staging_error'] = (string) $err;
$op_p8 = (string) ( $res_p8['op_id'] ?? '' );
$shadow_p8 = (int) ( $res_p8['shadow_id'] ?? 0 );
epp_track_post( $mine, $shadow_p8, $disposable . '-p8-shadow' );
// Simulate the defect returning: the staged after-image claims a title
// that was never authorised, and the shadow agrees with it. The publish
// step must refuse rather than write it.
$row_p8 = (array) get_option( Tx::KEY, null );
$row_p8['content']['ops'][ $op_p8 ]['after']['post_title'] = 'Injected title';
$row_p8['content']['sig'] = Content_Journal::sign( $row_p8['content'] );
Tx::cas_write_row( (array) get_option( Tx::KEY, null ), $row_p8 );
wp_update_post( array(
    'ID'         => $shadow_p8,
    'post_title' => 'Injected title',
), true );
$threw = false; $reason = '';
try {
    ( new Publish_Edit() )->execute( array() );
} catch ( \Throwable $e ) {
    $threw = true; $reason = $e->getMessage();
}
$f['p8_threw'] = $threw;
$f['p8_refused'] = $threw && (
    false !== strpos( $reason, 'unstaged_field_drift' )
    || false !== strpos( $reason, 'shadow_after_mismatch' )
);
$after_p8 = get_post( $orig_p8 );
$f['p8_orig_title_unchanged'] = (string) $after_p8->post_title === $title_p8;
epp_drop_fixture( $orig_p8, $shadow_p8 );
epp_drop_tx_row();

// ── P9: rollback leaves the published original alone ────────────────
$orig_p9 = epp_seed_published( $disposable, 'p9' );
epp_track_post( $mine, $orig_p9, $disposable . '-p9' );
$before_p9 = get_post( $orig_p9 );
$title_p9 = (string) $before_p9->post_title;
$content_p9 = (string) $before_p9->post_content;
Tx::instance()->begin( 'agent' );
$err = null;
$res_p9 = epp_call( function () use ( $tool, $orig_p9, $disposable ) {
    return $tool->execute( array(
        'original_id'     => $orig_p9,
        'post_content'    => 'P9 unwanted body',
        'idempotency_key' => $disposable . '-p9',
    ) );
}, $err );
$f['p9_staging_error'] = (string) $err;
$shadow_p9 = (int) ( $res_p9['shadow_id'] ?? 0 );
epp_track_post( $mine, $shadow_p9, $disposable . '-p9-shadow' );
Tx::instance()->rollback();
$after_p9 = get_post( $orig_p9 );
$f['p9_live_title_unchanged']    = (string) $after_p9->post_title === $title_p9;
$f['p9_live_content_unchanged'] = (string) $after_p9->post_content === $content_p9;
$f['p9_shadow_gone']            = ( null === get_post( $shadow_p9 ) );
epp_drop_fixture( $orig_p9, 0 );
epp_drop_tx_row();

// ── cleanup ─────────────────────────────────────────────────────────
foreach ( $mine as $entry ) {
    if ( 'post' === $entry['kind'] ) {
        $post = get_post( (int) $entry['id'] );
        if ( $post && (int) $post->post_author === 1
            && 0 === strpos( (string) $post->post_name, $disposable ) ) {
            wp_delete_post( (int) $entry['id'], true );
        }
    }
}
delete_post_meta_by_key( '_agentshell_shadow_for' );
delete_post_meta_by_key( '_agentshell_shadow_tx_id' );
delete_post_meta_by_key( '_agentshell_shadow_op_id' );
epp_drop_tx_row();

file_put_contents( $report_path, wp_json_encode( $f ) );
echo "preservation fixtures complete\n";
