<?php
/**
 * Child half of the live custom-stylesheet-asset suite. Runs inside a real
 * WordPress install and reports facts as JSON; the parent asserts them.
 *
 * What it proves
 * --------------
 * 1. A staged stylesheet never reaches a file. The staged bytes are
 *    uncommitted work, and a file would publish them to anyone who guessed
 *    the URL.
 * 2. The preview response inlines the staged stylesheet and links to
 *    nothing.
 * 3. A commit materialises the stylesheet, and the file's bytes are the
 *    bytes the inline block would have carried.
 * 4. A rollback leaves the committed stylesheet — and its file — alone.
 * 5. A snapshot restore re-materialises.
 *
 * Every value it changes is restored before the child exits.
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
require_once $theme_root . '/agentshell-mcp/includes/class-transactions.php';
require_once $theme_root . '/agentshell-mcp/includes/class-store.php';
require_once $theme_root . '/agentshell-mcp/includes/class-custom-css-asset.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-set-custom-css.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-create-snapshot.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-restore-snapshot.php';

use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Set_Custom_CSS;
use AgentShell_MCP\Tools\Create_Snapshot;
use AgentShell_MCP\Tools\Restore_Snapshot;

$f = array();

/** Report one fact. */
function cca_fact( $key, $value ) {
    global $f;
    $f[ $key ] = $value;
}

/** The materialisation directory, and the files in it. */
function cca_dir() {
    $uploads = wp_upload_dir( null, false );
    return rtrim( (string) $uploads['basedir'], '/' ) . '/agentshell-css';
}

/** The names of the materialised files. */
function cca_files() {
    $dir = cca_dir();
    if ( ! is_dir( $dir ) ) { return array(); }
    $out = array_values( array_diff( (array) scandir( $dir ), array( '.', '..', '.htaccess' ) ) );
    sort( $out );
    return $out;
}

/** The committed configuration's custom stylesheet. */
function cca_committed_css() {
    $config = get_option( 'agentshell_config' );
    return is_array( $config ) ? (string) ( $config['custom_css'] ?? '' ) : '';
}

/** Restore the site to the state this child found it in. */
function cca_restore() {
    global $original_css;
    $config = get_option( 'agentshell_config' );
    if ( ! is_array( $config ) ) { return; }
    $config['custom_css'] = $original_css;
    update_option( 'agentshell_config', $config );
}
register_shutdown_function( 'cca_restore' );

$original_css = cca_committed_css();
$token        = 'AScss' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$staged_css   = "/* staged $token */\nbody { color: rgb(1, 2, 3); }";
$committed_css = "/* committed $token */\nbody { color: rgb(4, 5, 6); }";

wp_set_current_user( 1 );
$GLOBALS['_agentshell_test_user_caps'] = array( 'manage_options' => true );

$set_css    = new Set_Custom_CSS();
$snapshot   = new Create_Snapshot();
$restore    = new Restore_Snapshot();

// ── 1. a staged stylesheet never reaches a file ──────────────────────────
$files_before = cca_files();

$tx = Tx::instance()->begin( 'live custom css asset' );
cca_fact( 'tx_opened', true );

try {
    $set_css->execute( array( 'css' => $staged_css ) );
    cca_fact( 'staged_ok', true );
} catch ( \Throwable $e ) {
    cca_fact( 'staged_error', $e->getMessage() );
}

cca_fact( 'committed_unchanged_after_stage', cca_committed_css() === $original_css );
cca_fact( 'files_unchanged_after_stage', cca_files() === $files_before );

// ── 2. the preview inlines the staged stylesheet ─────────────────────────
try {
    $tx_row = Tx::instance()->payload();
    $tx_id  = (string) ( $tx_row['id'] ?? '' );
    $actor  = (string) ( $tx_row['actor'] ?? '' );
    $expires = time() + 300;

    $sign = new ReflectionMethod( Tx::class, 'sign_preview' );
    $sign->setAccessible( true );
    $key = (string) $sign->invoke( null, $tx_id, $actor, $expires, Tx::preview_secret() );

    $_GET = array(
        'agentshell_tx'     => $tx_id,
        'agentshell_tx_key' => $key,
        'agentshell_tx_exp' => (string) $expires,
    );

    ob_start();
    agentshell_inject_saved_styles();
    $html = (string) ob_get_clean();

    cca_fact( 'preview_inlines_staged', false !== strpos( $html, $token ) );
    cca_fact( 'preview_has_link', false !== strpos( $html, "rel='stylesheet' id='agentshell-custom-css'" ) );
    cca_fact( 'preview_has_style', false !== strpos( $html, "<style id='agentshell-custom-css'>" ) );
} catch ( \Throwable $e ) {
    cca_fact( 'preview_error', $e->getMessage() );
}
$_GET = array();

// ── 3. a rollback leaves everything alone ─────────────────────────────────
try {
    Tx::instance()->rollback();
    cca_fact( 'rolled_back', true );
} catch ( \Throwable $e ) {
    cca_fact( 'rollback_error', $e->getMessage() );
}
cca_fact( 'committed_unchanged_after_rollback', cca_committed_css() === $original_css );
cca_fact( 'files_unchanged_after_rollback', cca_files() === $files_before );

// ── 4. a commit materialises the stylesheet ──────────────────────────────
$tx = Tx::instance()->begin( 'live custom css asset commit' );
try {
    $set_css->execute( array( 'css' => $committed_css ) );
    Tx::instance()->commit();
    cca_fact( 'committed', true );
} catch ( \Throwable $e ) {
    cca_fact( 'commit_error', $e->getMessage() );
}

cca_fact( 'committed_css_is_new', cca_committed_css() === $committed_css );

$files_after = cca_files();
cca_fact( 'files_after_commit', $files_after );
cca_fact( 'file_count_delta', count( $files_after ) - count( $files_before ) );

$materialised = \AgentShell_MCP\Custom_CSS_Asset::state( get_option( 'agentshell_config' ) );
cca_fact( 'materialised_url', (string) $materialised['url'] );
cca_fact( 'materialised_inline', (bool) $materialised['inline'] );
cca_fact( 'materialised_hash', (string) $materialised['hash'] );

if ( ! empty( $materialised['path'] ) && is_file( $materialised['path'] ) ) {
    $bytes = (string) file_get_contents( $materialised['path'] );
    cca_fact( 'file_bytes', $bytes );
    cca_fact( 'file_bytes_match_inline', $bytes === trim( agentshell_custom_css_bytes( $committed_css ) ) );
} else {
    cca_fact( 'file_bytes', '' );
    cca_fact( 'file_bytes_match_inline', false );
}

// The render links to the file rather than inlining it.
ob_start();
agentshell_inject_saved_styles();
$html = (string) ob_get_clean();
cca_fact( 'render_links', false !== strpos( $html, "rel='stylesheet' id='agentshell-custom-css'" ) );
cca_fact( 'render_inlines', false !== strpos( $html, "<style id='agentshell-custom-css'>" ) );

// ── 5. a snapshot restore re-materialises ────────────────────────────────
try {
    $snapshot->execute( array( 'name' => 'cca-' . $token ) );
    cca_fact( 'snapshot_created', true );
} catch ( \Throwable $e ) {
    cca_fact( 'snapshot_error', $e->getMessage() );
}

// Change the stylesheet again, so the restore has something to undo.
$tx = Tx::instance()->begin( 'live custom css asset second change' );
try {
    $set_css->execute( array( 'css' => '/* second ' . $token . " */\nbody { color: rgb(7, 8, 9); }" ) );
    Tx::instance()->commit();
    cca_fact( 'second_commit', true );
} catch ( \Throwable $e ) {
    cca_fact( 'second_commit_error', $e->getMessage() );
}

try {
    $restore->execute( array( 'name' => 'cca-' . $token ) );
    cca_fact( 'restored', true );
} catch ( \Throwable $e ) {
    cca_fact( 'restore_error', $e->getMessage() );
}

$after_restore = \AgentShell_MCP\Custom_CSS_Asset::state( get_option( 'agentshell_config' ) );
cca_fact( 'restored_url', (string) $after_restore['url'] );
cca_fact( 'restored_matches_first', (string) $after_restore['url'] === (string) $materialised['url'] );

file_put_contents( $report, json_encode( $f ) );
