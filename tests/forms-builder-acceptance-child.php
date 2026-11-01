<?php
/**
 * Prepare and clean the acceptance harness for the Phase 4 builder.
 *
 * argv: 1 WordPress root  2 report path  3 mode (prepare|cleanup)
 *
 * The harness is a page served from the site's own origin. It logs in as the
 * administrator, loads the builder in a same-origin iframe, and drives the
 * real interface against the real REST API and database. Nothing about the
 * plugin is mocked.
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';
$mode    = $argv[3] ?? 'prepare';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

require_once dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php';

use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Post_Types;

$token = 'ASF4-' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );

/**
 * The harness page.
 *
 * @param string $script The scenario script.
 * @return string
 */
function asb4_harness( $script ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>pending</title></head>'
        . '<body><p>AgentShell Forms builder acceptance harness</p>'
        . '<iframe id="builder" style="width:1280px;height:900px"></iframe>'
        . '<script>' . $script . '</script></body></html>';
}

if ( 'cleanup' === $mode ) {
    $forms = get_posts(
        array(
            'post_type'      => Form_Post_Types::FORM,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            's'              => 'ASF4',
        )
    );

    foreach ( $forms as $form_id ) {
        wp_delete_post( (int) $form_id, true );
    }

    $pages = get_posts(
        array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            's'              => 'ASF4',
        )
    );

    foreach ( $pages as $page_id ) {
        wp_delete_post( (int) $page_id, true );
    }

    @unlink( $wp_root . '/asf-test-harness.php' );
    @unlink( $wp_root . '/asf-test-router.php' );

    // Deactivate and unlink only what this harness created.
    $active = (array) get_option( 'active_plugins', array() );
    $active = array_values( array_filter( $active, function ( $plugin ) {
        return false === strpos( (string) $plugin, 'agentshell-forms' );
    } ) );
    update_option( 'active_plugins', $active );

    file_put_contents( $report, wp_json_encode( array( 'cleaned' => true, 'token' => $token ) ) );
    echo "cleaned\n";
    exit( 0 );
}

// ── the plugin must be active for HTTP requests ────────────────────────────
$plugin_dir = $wp_root . '/wp-content/plugins';

if ( ! is_dir( $plugin_dir ) ) {
    mkdir( $plugin_dir, 0777, true );
}

$link = $plugin_dir . '/agentshell-forms';

if ( ! file_exists( $link ) ) {
    @symlink( dirname( __DIR__ ) . '/agentshell-forms', $link );
}

$active = (array) get_option( 'active_plugins', array() );
$entry  = 'agentshell-forms/agentshell-forms.php';

if ( ! in_array( $entry, $active, true ) ) {
    $active[] = $entry;
    update_option( 'active_plugins', array_values( array_unique( $active ) ) );
}

// ── the router: serve WordPress on this origin ─────────────────────────────
$router = '<?php
$path = parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH );
$file = __DIR__ . $path;

if ( ! defined( "WP_HOME" ) ) { define( "WP_HOME", "http://" . $_SERVER["HTTP_HOST"] ); }
if ( ! defined( "WP_SITEURL" ) ) { define( "WP_SITEURL", "http://" . $_SERVER["HTTP_HOST"] ); }

if ( "/" !== $path && is_file( $file ) ) { return false; }

require __DIR__ . "/index.php";
';

file_put_contents( $wp_root . '/asf-test-router.php', $router );

// ── the harness: log in, load the builder in a same-origin iframe ──────────
$script = (string) file_get_contents( __DIR__ . '/forms-builder-acceptance.js' );

$harness = '<?php
require __DIR__ . "/wp-load.php";

if ( ! is_user_logged_in() ) {
    wp_set_current_user( 1 );
    wp_set_auth_cookie( 1, false, false );
}

header( "Content-Type: text/html; charset=utf-8" );
echo ' . var_export( asb4_harness( $script ), true ) . ';
';

file_put_contents( $wp_root . '/asf-test-harness.php', $harness );

file_put_contents( $report, wp_json_encode( array( 'token' => $token, 'prepared' => true ) ) );
echo "prepared\n";
