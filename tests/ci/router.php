<?php
/**
 * Router for PHP's built-in web server (used by the CI integration job).
 *
 * PHP's built-in server serves an existing file when the request path maps
 * to one, and returns 404 for everything else. WordPress needs the second
 * case to reach `index.php`, because a permalink such as `/about/` is not a
 * file. This router draws that line:
 *
 *   - an existing *file* is served by the server itself (`return false`);
 *   - an existing *directory* with an index.php is that script;
 *   - everything else is the site's front controller.
 *
 * The document root comes from AGENTSHELL_CI_WP_ROOT so the router carries
 * no path of its own — the same script works for any provisioned site.
 *
 * This is test infrastructure. It is not part of the theme, it is not
 * deployed, and it never runs on a production host.
 *
 * @package AgentShell
 */

$root = getenv( 'AGENTSHELL_CI_WP_ROOT' );
if ( ! is_string( $root ) || '' === $root ) {
    http_response_code( 500 );
    header( 'Content-Type: text/plain' );
    echo "AGENTSHELL_CI_WP_ROOT is not set\n";
    return true;
}
$root = rtrim( $root, '/' );

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( ! is_string( $path ) || '' === $path ) {
    $path = '/';
}
$path = urldecode( $path );

// Refuse to serve anything outside the document root.
$candidate = $root . $path;
if ( false !== strpos( $candidate, "\0" ) || false !== strpos( $candidate, '..' ) ) {
    http_response_code( 400 );
    return true;
}

if ( '/' !== $path && is_file( $candidate ) ) {
    // A real file: let the built-in server serve it (static assets,
    // wp-admin/*.php, wp-login.php, …).
    return false;
}

if ( is_dir( $candidate ) ) {
    $index = rtrim( $candidate, '/' ) . '/index.php';
    if ( is_file( $index ) ) {
        $_SERVER['SCRIPT_NAME']     = rtrim( $path, '/' ) . '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $index;
        $_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
        require $index;
        return true;
    }
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
require $root . '/index.php';
return true;
