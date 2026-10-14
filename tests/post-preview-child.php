<?php
/**
 * Post-preview checks that cannot share the suite's process.
 *
 * Both modes below test behaviour that depends on PHP constants or on their
 * absence. A constant, once defined, cannot be undefined; and `preview_secret()`
 * is a static with a fixed fallback chain. Asserting the source "mentions"
 * REST_REQUEST, or that a credential with an empty key is refused, would prove
 * neither branch — so each runs here, in a process configured for it.
 *
 * Usage: php post-preview-child.php route <REST_REQUEST|XMLRPC_REQUEST|''>
 *        php post-preview-child.php no-secret
 *
 * Prints one verdict line. Anything else is a failure.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }
function is_admin() { return false; }
function rest_get_url_prefix() { return 'wp-json'; }
function get_post( $id = null ) {
    return $GLOBALS['_agentshell_test_posts'][ (int) $id ] ?? null;
}

use AgentShell_MCP\Post_Preview;

$mode = $argv[1] ?? '';

if ( 'route' === $mode ) {
    if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'route-mode-auth-salt' ); }

    $constant = $argv[2] ?? '';
    if ( 'REST_REQUEST' === $constant ) {
        define( 'REST_REQUEST', true );
    } elseif ( 'XMLRPC_REQUEST' === $constant ) {
        define( 'XMLRPC_REQUEST', true );
    } elseif ( '' !== $constant ) {
        fwrite( STDERR, "unknown constant: {$constant}\n" );
        exit( 2 );
    }

    require_once dirname( __DIR__ ) . '/functions.php';

    // The plain front-end request these constants are supposed to spoil.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/?page_id=10&preview=true';

    echo agentshell_post_preview_route_allowed() ? "allowed\n" : "refused\n";
    exit( 0 );
}

if ( 'no-secret' === $mode ) {
    // Deliberately neither AGENTSHELL_REST_TOKEN (bootstrap defines it empty)
    // nor AUTH_SALT, so preview_secret() has nothing to sign with.
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transactions.php';
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-post-preview.php';

    $results = array();

    $results['secret-is-empty'] = ( '' === Post_Preview::preview_secret() );

    // A credential that is otherwise perfect must still be refused.
    $exp   = time() + 300;
    $key   = hash_hmac( 'sha256', 'agentshell-post-preview|10|1|' . $exp, 'irrelevant' );
    $results['authenticate-refused'] = ( 0 === Post_Preview::authenticate( '10', '1', $key, (string) $exp ) );

    // And minting must refuse loudly rather than emit an unsigned credential.
    $GLOBALS['_agentshell_test_posts'][10] = (object) array(
        'ID' => 10, 'post_status' => 'draft', 'post_type' => 'page', 'post_title' => 'Draft',
    );
    $GLOBALS['_agentshell_test_user_id']   = 1;
    $GLOBALS['_agentshell_test_user_caps'] = array( 'edit_post' => array( 10 ) );
    $threw = false;
    $message = '';
    try {
        Post_Preview::preview_args( 10 );
    } catch ( \InvalidArgumentException $e ) {
        $threw   = true;
        $message = $e->getMessage();
    }
    $results['preview-args-throws'] = $threw;
    $results['message-explains']    = str_contains( $message, 'signing secret' );

    foreach ( $results as $name => $ok ) {
        printf( "%s %s\n", $ok ? 'ok' : 'FAIL', $name );
    }
    exit( in_array( false, $results, true ) ? 1 : 0 );
}

fwrite( STDERR, "unknown mode: {$mode}\n" );
exit( 2 );