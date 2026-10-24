<?php
/**
 * Child half of the live compact-response suite. Runs inside a real
 * WordPress install and reports facts as JSON; the parent asserts them.
 *
 * What it proves
 * --------------
 * 1. The default response still carries the body: the existing contract is
 *    unchanged, and a caller that reads `content.raw` keeps working.
 * 2. `include_content: false` returns the metadata and the length, and not
 *    the body — so a large body does not travel back through the
 *    transport.
 * 3. The compact response really is smaller: the difference is measured,
 *    not asserted by eye.
 * 4. The full body is still reachable, through the explicit read.
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
require_once $theme_root . '/agentshell-mcp/includes/class-content-write-guard.php';
require_once $theme_root . '/agentshell-mcp/includes/class-store.php';
require_once $theme_root . '/agentshell-mcp/includes/class-transactions.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-create-page.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-get-content.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-update-content.php';

use AgentShell_MCP\Tools\Create_Page;
use AgentShell_MCP\Tools\Get_Content;
use AgentShell_MCP\Tools\Update_Content;

$f = array();

function cr_fact( $key, $value ) {
    global $f;
    $f[ $key ] = $value;
}

// A body big enough that carrying it back is worth avoiding.
$body = '<p>' . str_repeat( 'AScp body text. ', 4000 ) . '</p>';

$create = new Create_Page();
$get    = new Get_Content();
$update = new Update_Content();

// ── 1. the default response still carries the body ────────────────────────
$full = $create->execute( array(
    'title'   => 'AScp default',
    'content' => $body,
    'status'  => 'draft',
) );
$id = (int) ( $full['id'] ?? 0 );
cr_fact( 'page_id', $id );
cr_fact( 'default_has_raw', isset( $full['content']['raw'] ) );
cr_fact( 'default_raw_length', strlen( (string) ( $full['content']['raw'] ?? '' ) ) );
cr_fact( 'default_response_bytes', strlen( (string) wp_json_encode( $full ) ) );

// ── 2. the compact response carries the length, not the body ──────────────
$compact = $create->execute( array(
    'title'           => 'AScp compact',
    'content'         => $body,
    'status'          => 'draft',
    'include_content' => false,
) );
cr_fact( 'compact_has_raw', isset( $compact['content']['raw'] ) );
cr_fact( 'compact_omitted', $compact['content']['omitted'] ?? null );
cr_fact( 'compact_length', $compact['content']['length'] ?? null );
cr_fact( 'compact_response_bytes', strlen( (string) wp_json_encode( $compact ) ) );

// ── 3. the update path too ────────────────────────────────────────────────
$updated = $update->execute( array(
    'id'              => $id,
    'type'            => 'page',
    'title'           => 'AScp default renamed',
    'include_content' => false,
) );
cr_fact( 'update_has_raw', isset( $updated['content']['raw'] ) );
cr_fact( 'update_omitted', $updated['content']['omitted'] ?? null );
cr_fact( 'update_response_bytes', strlen( (string) wp_json_encode( $updated ) ) );

// ── 4. the full body is still reachable, on request ───────────────────────
$read = $get->execute( array( 'id' => $id, 'type' => 'page' ) );
cr_fact( 'read_has_raw', isset( $read['content']['raw'] ) );
cr_fact( 'read_raw_length', strlen( (string) ( $read['content']['raw'] ?? '' ) ) );

$read_compact = $get->execute( array( 'id' => $id, 'type' => 'page', 'include_content' => false ) );
cr_fact( 'read_compact_has_raw', isset( $read_compact['content']['raw'] ) );
cr_fact( 'read_compact_length', $read_compact['content']['length'] ?? null );

// ── cleanup ───────────────────────────────────────────────────────────────
foreach ( array( $id, (int) ( $compact['id'] ?? 0 ) ) as $victim ) {
    if ( $victim > 0 ) {
        wp_delete_post( $victim, true );
    }
}
cr_fact( 'cleanup_done', ! get_post( $id ) );

file_put_contents( $report, json_encode( $f ) );
echo "reported\n";
