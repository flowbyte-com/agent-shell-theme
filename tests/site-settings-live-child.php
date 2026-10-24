<?php
/**
 * Child half of the live site-settings suite. Runs inside a real WordPress
 * install and reports facts as JSON; the parent asserts them.
 *
 * What it proves
 * --------------
 * 1. A site setting set inside a transaction is *staged*: WordPress's own
 *    option is untouched until the transaction commits.
 * 2. The commit projects the setting onto the WordPress option.
 * 3. A rollback discards the staged setting, and WordPress's option is
 *    still the old value — the site did not change.
 * 4. A refused setting changes nothing at all.
 * 5. A setting outside the bounded surface is refused by name, and the
 *    option it would have touched is untouched.
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
require_once $theme_root . '/agentshell-mcp/includes/class-site-settings.php';
require_once $theme_root . '/agentshell-mcp/includes/class-transactions.php';
require_once $theme_root . '/agentshell-mcp/includes/class-store.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-set-site-settings.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-get-site-settings.php';

use AgentShell_MCP\Site_Settings;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Set_Site_Settings;
use AgentShell_MCP\Tools\Get_Site_Settings;

global $wpdb;

$f = array();

$token     = 'ASss' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$new_title = 'ASss title ' . $token;
$old_title = (string) get_option( 'blogname' );
$old_tag   = (string) get_option( 'blogdescription' );
$f['old_title'] = $old_title;
$f['old_tagline'] = $old_tag;

/**
 * Report one fact.
 */
function ss_fact( $key, $value ) {
    global $f;
    $f[ $key ] = $value;
}

/**
 * Restore the site to the state this child found it in.
 */
function ss_restore() {
    global $old_title, $old_tag;
    update_option( 'blogname', $old_title );
    update_option( 'blogdescription', $old_tag );
    // Remove the configuration's site section, so the next run starts
    // from the same state this one did.
    $config = get_option( 'agentshell_config' );
    if ( is_array( $config ) && isset( $config['site'] ) ) {
        unset( $config['site'] );
        update_option( 'agentshell_config', $config );
    }
}
register_shutdown_function( 'ss_restore' );

// The tool needs an administrator. The test site runs as user 1.
wp_set_current_user( 1 );
$GLOBALS['_agentshell_test_user_caps'] = array( 'manage_options' => true );

$tool = new Set_Site_Settings();
$get  = new Get_Site_Settings();

// ── 1. a staged setting does not touch WordPress ──────────────────────────
$tx = Tx::instance()->begin( 'live site settings' );
ss_fact( 'tx_opened', true );

try {
    $result = $tool->execute( array( 'title' => $new_title ) );
    ss_fact( 'staged_result', $result );
} catch ( \Throwable $e ) {
    ss_fact( 'staged_error', $e->getMessage() );
}
ss_fact( 'option_after_stage', (string) get_option( 'blogname' ) );
ss_fact( 'option_unchanged_after_stage', (string) get_option( 'blogname' ) === $old_title );

// The preview shows the setting as pending.
try {
    $preview = Tx::instance()->preview();
    $paths   = array();
    foreach ( (array) ( $preview['structural'] ?? array() ) as $entry ) {
        if ( isset( $entry['path'] ) ) {
            $paths[] = $entry['path'];
        }
    }
    ss_fact( 'preview_paths', $paths );
} catch ( \Throwable $e ) {
    ss_fact( 'preview_error', $e->getMessage() );
}

// ── 2. a rollback discards it ─────────────────────────────────────────────
try {
    Tx::instance()->rollback();
    ss_fact( 'rolled_back', true );
} catch ( \Throwable $e ) {
    ss_fact( 'rollback_error', $e->getMessage() );
}
ss_fact( 'option_after_rollback', (string) get_option( 'blogname' ) );
ss_fact( 'option_unchanged_after_rollback', (string) get_option( 'blogname' ) === $old_title );

// ── 3. a commit applies it ────────────────────────────────────────────────
$tx = Tx::instance()->begin( 'live site settings commit' );
try {
    $tool->execute( array( 'title' => $new_title, 'tagline' => 'ASss tagline ' . $token ) );
    ss_fact( 'commit_stage_ok', true );
} catch ( \Throwable $e ) {
    ss_fact( 'commit_stage_error', $e->getMessage() );
}
ss_fact( 'option_before_commit', (string) get_option( 'blogname' ) );
try {
    Tx::instance()->commit();
    ss_fact( 'committed', true );
} catch ( \Throwable $e ) {
    ss_fact( 'commit_error', $e->getMessage() );
}
ss_fact( 'option_after_commit', (string) get_option( 'blogname' ) );
ss_fact( 'tagline_after_commit', (string) get_option( 'blogdescription' ) );
ss_fact( 'option_applied_on_commit', (string) get_option( 'blogname' ) === $new_title );

// ── 4. a refused setting changes nothing ───────────────────────────────────
$before_refusal = (string) get_option( 'blogname' );
$refusals       = array();
foreach ( array(
    array( 'siteurl' => 'https://elsewhere.example' ),
    array( 'front_page' => 999999 ),
    array( 'posts_per_page' => 5000 ),
) as $bad ) {
    try {
        $tool->execute( $bad );
        $refusals[] = 'accepted:' . key( $bad );
    } catch ( \Throwable $e ) {
        $refusals[] = $e->getMessage();
    }
}
ss_fact( 'refusals', $refusals );
ss_fact( 'option_unchanged_after_refusal', (string) get_option( 'blogname' ) === $before_refusal );
ss_fact( 'siteurl_untouched', (string) get_option( 'siteurl' ) );

// ── 5. the read tool reports the managed and the live values ──────────────
try {
    $read = $get->execute( array() );
    ss_fact( 'read_settings_title', $read['settings']['title'] ?? null );
    ss_fact( 'read_managed_title', $read['managed']['title'] ?? null );
    ss_fact( 'read_live_title', $read['live']['title'] ?? null );
    ss_fact( 'read_writable', $read['writable'] ?? array() );
} catch ( \Throwable $e ) {
    ss_fact( 'read_error', $e->getMessage() );
}

// ── 6. the restore actually restores ──────────────────────────────────────
ss_restore();
ss_fact( 'option_after_restore', (string) get_option( 'blogname' ) );
ss_fact( 'option_restored', (string) get_option( 'blogname' ) === $old_title );

file_put_contents( $report, json_encode( $f ) );
echo "reported\n";
