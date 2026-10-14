<?php
/**
 * Locating and running a real WordPress install from the test runner.
 *
 * The runner's own PHP cannot usually load WordPress: the system CLI here has
 * no mysqli, and LocalWP's PHP is a separate build that needs its own shared
 * libraries and an explicit socket. So a "live" suite is really a two-process
 * affair — this file resolves a PHP that *can* talk to the database, and spawns
 * the child with it.
 *
 * Everything is discovered rather than hard-coded, and every path can be
 * overridden by environment variable, so the suite skips cleanly on a machine
 * with no LocalWP rather than failing.
 */

/**
 * Resolve how to run PHP against a real WordPress install.
 *
 * @return array|null {
 *     @type string $php        Path to a PHP binary.
 *     @type array  $ini        Extra -d arguments (socket, etc).
 *     @type array  $env        Environment additions (LD_LIBRARY_PATH, ...).
 *     @type string $wp_root    WordPress root, i.e. the directory holding
 *                              wp-load.php.
 * } Null when no usable install was found.
 */
function agentshell_wp_live_config() {
    $home = getenv( 'HOME' );
    if ( ! is_string( $home ) || '' === $home ) {
        return null;
    }

    $wp_root = getenv( 'AGENTSHELL_TEST_WP_ROOT' );
    if ( ! is_string( $wp_root ) || '' === $wp_root ) {
        foreach ( glob( $home . '/local/*/app/public/wp-load.php' ) ?: array() as $candidate ) {
            $wp_root = dirname( $candidate );
            break;
        }
    }
    if ( ! is_string( $wp_root ) || ! is_file( $wp_root . '/wp-load.php' ) ) {
        return null;
    }

    // Prefer an explicitly nominated binary, then LocalWP's, then whatever the
    // runner is itself using (which works when it has mysqli).
    $php = getenv( 'AGENTSHELL_TEST_PHP' );
    if ( ! is_string( $php ) || ! is_executable( $php ) ) {
        $php = PHP_BINARY;
        foreach ( glob( $home . '/.config/Local/lightning-services/php-*/bin/linux/bin/php' ) ?: array() as $candidate ) {
            $php = $candidate;
            break;
        }
    }

    $ini = array();
    $env = array();

    $libs = dirname( dirname( $php ) ) . '/shared-libs';
    if ( is_dir( $libs ) ) {
        $env['LD_LIBRARY_PATH'] = $libs;
    }

    foreach ( glob( $home . '/.config/Local/run/*/mysql/mysqld.sock' ) ?: array() as $socket ) {
        $ini[] = 'mysqli.default_socket=' . $socket;
        break;
    }

    return array(
        'php'     => $php,
        'ini'     => $ini,
        'env'     => $env,
        'wp_root' => $wp_root,
    );
}

/**
 * Run a PHP script against a real WordPress install and capture its stdout.
 *
 * @param array  $config  As returned by agentshell_wp_live_config().
 * @param string $script  Absolute path to the child script.
 * @param array  $argv    Extra arguments passed to the child.
 * @return array { 'output' => string, 'status' => int }
 */
function agentshell_wp_live_run( array $config, $script, array $argv = array() ) {
    $cmd = '';
    foreach ( $config['env'] as $k => $v ) {
        $cmd .= $k . '=' . escapeshellarg( $v ) . ' ';
    }
    $cmd .= 'AGENTSHELL_TEST_WP_ROOT=' . escapeshellarg( $config['wp_root'] ) . ' ';
    $cmd .= escapeshellarg( $config['php'] );
    foreach ( $config['ini'] as $arg ) {
        $cmd .= ' -d ' . escapeshellarg( $arg );
    }
    $cmd .= ' ' . escapeshellarg( $script );
    foreach ( $argv as $arg ) {
        $cmd .= ' ' . escapeshellarg( $arg );
    }
    $cmd .= ' 2>&1';

    $output = array();
    $status = 0;
    exec( $cmd, $output, $status );

    return array( 'output' => implode( "\n", $output ), 'status' => $status );
}