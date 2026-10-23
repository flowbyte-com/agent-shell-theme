<?php
/**
 * Published-preview ownership regression (HAL 9001 remediation).
 *
 * The defect
 * ----------
 * `agentshell_screenshot(post_id=…, published_preview=true)` reads the open
 * transaction's journal to find the staged edit of a published page, and
 * mints a credential for it that is bound to the CALLER's identity.
 *
 * That read had no ownership check. The transaction row is a single option
 * row, so ANY actor who could reach the tool found the staging in it —
 * including one who had nothing to do with the transaction.
 *
 * The gate in front of it was `edit_post` on the page. That gate is
 * correct for the PAGE (the caller must be allowed to edit what they are
 * previewing), and it was the wrong gate for the STAGING (the staged edit
 * is another actor's uncommitted work, and a transaction's contents are
 * not the world's to read).
 *
 * So an editor who could edit the page could obtain a credential for —
 * and therefore read — another actor's unpublished staged edit of it.
 *
 * The fix
 * -------
 * The staging may only be previewed by the actor whose transaction holds
 * it. The credential is signed with the caller's own identity, so once
 * minted it is the caller's to use — which is precisely why the MINTING
 * step has to be the gate, not the later verification.
 *
 * What this suite covers
 * ----------------------
 *
 *   O1. The transaction owner CAN preview their own staged edit.
 *   O2. A DIFFERENT actor who can edit the same page is REFUSED.
 *   O3. The refusal names both actors, so the boundary is diagnosable.
 *   O4. The owner's own capture still works after the refusal — the rule
 *       does not break the legitimate workflow.
 *
 * Everything runs against a real WordPress (the disposable test bed).
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: published-preview-ownership-live-child.php <wp_root> <report_path>\n" );
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
    'class-post-preview.php',
    'class-published-preview-overlay.php',
    'class-screenshot.php',
    'class-screenshot-store.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/' . $inc;
}
foreach ( array(
    'class-base-tool.php',
    'class-edit-published.php',
    'class-screenshot.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/tools/' . $inc;
}

use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Edit_Published;
use AgentShell_MCP\Tools\Screenshot;

$f = array();

$marker = 'ppown-' . bin2hex( random_bytes( 3 ) );

/**
 * A second actor: a real user who can edit pages, and who is not the
 * actor that opens the transaction.
 */
$other_id = 0;
$other    = wp_insert_user( array(
    'user_login' => 'ppown-other-' . $marker,
    'user_pass'  => wp_generate_password( 20 ),
    'role'       => 'editor',
) );
if ( ! is_wp_error( $other ) ) {
    $other_id = (int) $other;
}
$f['o0_other_user_created'] = ( $other_id > 0 );

// The owner.
$owner_id = 1;
wp_set_current_user( $owner_id );

// ── a published page ────────────────────────────────────────────────────────
$post_id = wp_insert_post( array(
    'post_author'   => $owner_id,
    'post_title'    => 'Ownership page ' . $marker,
    'post_content'  => 'PUBLISHED BODY ' . $marker,
    'post_name'     => $marker,
    'post_status'   => 'publish',
    'post_type'     => 'page',
    'post_date'     => '2026-01-01 00:00:00',
    'post_date_gmt' => '2026-01-01 00:00:00',
), true );
if ( is_wp_error( $post_id ) ) {
    fwrite( STDERR, 'seed failed: ' . $post_id->get_error_message() . "\n" );
    exit( 1 );
}
$post_id = (int) $post_id;

$tool = new Screenshot();

/** The refusal message, or '' when the call was allowed to proceed. */
function ppown_refusal( callable $fn ) {
    try {
        $fn();
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
    return '';
}

// ── the owner stages an edit, in a transaction the owner owns ──────────────
Tx::instance()->begin( 'ownership preview' );
$edit = ( new Edit_Published() )->execute( array(
    'original_id'     => $post_id,
    'post_content'    => 'STAGED BODY ' . $marker,
    'idempotency_key' => $marker . '-stage',
) );
$f['o0_staged'] = ! empty( $edit['op_id'] );

// ── O1: the owner CAN preview their own staged edit ────────────────────────
$owner_err = ppown_refusal( function () use ( $tool, $post_id ) {
    $tool->execute( array(
        'post_id'           => (int) $post_id,
        'published_preview' => true,
        'viewport'          => 'desktop',
        'inline'            => false,
    ) );
} );
$f['o1_owner_allowed'] = ( '' === (string) $owner_err );
$f['o1_owner_error']   = (string) $owner_err;

// ── O2: a DIFFERENT actor who can edit the same page is REFUSED ───────────
//
// The other actor is an editor, so `edit_post` on the page passes for
// them. The staging does not belong to them, so the preview must be
// refused anyway.
$other_err = '';
if ( $other_id > 0 ) {
    wp_set_current_user( $other_id );
    $f['o2_other_can_edit_page'] = (bool) current_user_can( 'edit_post', $post_id );
    $other_err = ppown_refusal( function () use ( $tool, $post_id ) {
        $tool->execute( array(
            'post_id'           => (int) $post_id,
            'published_preview' => true,
            'viewport'          => 'desktop',
            'inline'            => false,
        ) );
    } );
} else {
    $f['o2_other_can_edit_page'] = false;
}
$f['o2_other_refused'] = ( '' !== (string) $other_err );
$f['o2_other_error']   = (string) $other_err;

// ── O3: the refusal names both actors ─────────────────────────────────────
$f['o3_names_owner'] = ( false !== strpos( (string) $other_err, 'agentshell_test_admin' )
    || false !== strpos( (string) $other_err, 'belongs to a transaction opened by' ) );
$f['o3_names_other'] = ( false !== strpos( (string) $other_err, 'ppown-other-' )
    || false !== strpos( (string) $other_err, 'you are' ) );

// ── O4: the owner can still preview after the refusal ─────────────────────
wp_set_current_user( $owner_id );
$owner_err_2 = ppown_refusal( function () use ( $tool, $post_id ) {
    $tool->execute( array(
        'post_id'           => (int) $post_id,
        'published_preview' => true,
        'viewport'          => 'desktop',
        'inline'            => false,
    ) );
} );
$f['o4_owner_still_allowed'] = ( '' === (string) $owner_err_2 );
$f['o4_owner_error']         = (string) $owner_err_2;

// ── cleanup ─────────────────────────────────────────────────────────────────
Tx::instance()->rollback();
wp_delete_post( $post_id, true );
delete_option( Tx::KEY );
if ( $other_id > 0 ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $other_id );
}

file_put_contents( $report_path, wp_json_encode( $f ) );
echo "published-preview ownership fixtures complete\n";
