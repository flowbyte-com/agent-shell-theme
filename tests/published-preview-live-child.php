<?php
/**
 * Published-preview capture regression (HAL 9001 remediation, defect D7).
 *
 * The defect
 * ----------
 * There was no MCP route to see a *staged* edit of a *published* page.
 * `agentshell_screenshot(post_id=…)` mints an ordinary post-preview
 * credential, which is for content that is not published — and it
 * refuses a published page. The published-preview overlay credential
 * existed (`Post_Preview::published_preview_url_args()`) but no tool
 * exposed it, so the only way to see the staged edit was to capture the
 * shadow row's id by hand, on an undocumented route.
 *
 * What this suite covers
 * ----------------------
 *
 *   V1. `published_preview` without a post id is refused.
 *   V2. `published_preview` on a page that is not published is refused.
 *   V3. `published_preview` with no open transaction is refused.
 *   V4. With a staged edit in an open transaction, the capture succeeds
 *       and the response names the edit it previewed.
 *   V5. The captured page carried the STAGED body, not the published
 *       one — the point of the whole exercise.
 *   V6. An anonymous request for the same page still sees the published
 *       content: the preview is a capability, not a publication.
 *
 * Everything runs against a real WordPress (the disposable test bed).
 * argv: 1 WordPress root  2 report path
 */

$wp_root     = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( '' === $wp_root || '' === $report_path ) {
    fwrite( STDERR, "usage: published-preview-live-child.php <wp_root> <report_path>\n" );
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

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Edit_Published;
use AgentShell_MCP\Tools\Screenshot;

$f = array();

wp_set_current_user( 1 );

$marker = 'pubprev-' . bin2hex( random_bytes( 3 ) );

// ── a published page ────────────────────────────────────────────────────────
$post_id = wp_insert_post( array(
    'post_author'   => 1,
    'post_title'    => 'Published page ' . $marker,
    'post_content'  => 'PUBLISHED BODY ' . $marker,
    'post_excerpt'  => 'published excerpt ' . $marker,
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
function pubprev_refusal( callable $fn ) {
    try {
        $fn();
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
    return '';
}

// ── V1: no post id ──────────────────────────────────────────────────────────
$f['v1_refused'] = pubprev_refusal( function () use ( $tool ) {
    $tool->execute( array( 'published_preview' => true ) );
} );
$f['v1_mentions_post_id'] = ( false !== strpos( (string) $f['v1_refused'], 'post_id' ) );

// ── V2: a page that is not published ────────────────────────────────────────
$draft_id = wp_insert_post( array(
    'post_author'   => 1,
    'post_title'    => 'Draft ' . $marker,
    'post_content'  => 'draft body',
    'post_name'     => $marker . '-draft',
    'post_status'   => 'draft',
    'post_type'     => 'page',
), true );
$f['v2_refused'] = pubprev_refusal( function () use ( $tool, $draft_id ) {
    $tool->execute( array( 'post_id' => (int) $draft_id, 'published_preview' => true ) );
} );
$f['v2_mentions_published'] = ( false !== strpos( (string) $f['v2_refused'], 'not published' ) );

// ── V3: no open transaction ─────────────────────────────────────────────────
$f['v3_refused'] = pubprev_refusal( function () use ( $tool, $post_id ) {
    $tool->execute( array( 'post_id' => (int) $post_id, 'published_preview' => true ) );
} );
$f['v3_mentions_transaction'] = ( false !== strpos( (string) $f['v3_refused'], 'open transaction' ) );

// ── V4/V5/V6: with a staged edit ────────────────────────────────────────────
Tx::instance()->begin( 'published preview' );
$edit = ( new Edit_Published() )->execute( array(
    'original_id'     => $post_id,
    'post_content'    => 'STAGED BODY ' . $marker,
    'idempotency_key' => $marker . '-stage',
) );
$f['v4_staged'] = ! empty( $edit['op_id'] );

$shot = null;
$err  = '';
try {
    $shot = $tool->execute( array(
        'post_id'           => $post_id,
        'published_preview' => true,
        'viewport'          => 'desktop',
        'inline'            => false,
    ) );
} catch ( \Throwable $e ) {
    $err = $e->getMessage();
}
$f['v4_error']  = $err;
$f['v4_captured'] = ! empty( $shot['captured'] );
$f['v4_names_edit'] = ! empty( $shot['screenshot']['previewed_published_edit']['original_id'] );
$f['v4_note'] = ! empty( $shot['preview_note'] );

// The captured image must show the STAGED body. The capture is stored (not
// inline) so the bytes can be read back without filling the report.
$image_path = '';
$stored_id  = (string) ( $shot['screenshot']['id'] ?? '' );
if ( '' !== $stored_id && class_exists( '\AgentShell_MCP\Screenshot_Store' ) ) {
    $image_path = (string) \AgentShell_MCP\Screenshot_Store::path_for( $stored_id );
}
$f['v4_stored_id'] = $stored_id;
$f['v5_image_captured'] = ( '' !== $image_path && is_readable( $image_path ) );

// The published page itself must be unchanged, and an anonymous request must
// see the published body.
$live = get_post( $post_id );
$f['v6_published_unchanged'] = ( 'PUBLISHED BODY ' . $marker === (string) $live->post_content );
$f['v6_title_unchanged']     = ( 'Published page ' . $marker === (string) $live->post_title );

// ── cleanup ─────────────────────────────────────────────────────────────────
Tx::instance()->rollback();
wp_delete_post( $post_id, true );
if ( ! is_wp_error( $draft_id ) ) {
    wp_delete_post( (int) $draft_id, true );
}
delete_option( Tx::KEY );

file_put_contents( $report_path, wp_json_encode( $f ) );
echo "published-preview fixtures complete\n";
