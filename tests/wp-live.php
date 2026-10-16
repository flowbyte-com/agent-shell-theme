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
 * Two resolution paths are supported, with very different safety properties.
 * Callers pick the one they need:
 *
 *   1. **Test-bed config (opt-in, the only safe path for destructive
 *      work).** The config at `tests/config/test-bed.json` (or
 *      `AGENTSHELL_TEST_BED_CONFIG`) names the WordPress root, the PHP
 *      binary, the MySQL socket, the restricted-user credentials, and
 *      the expected instance fingerprint (server_uuid, datadir, port).
 *      Destructive live suites (`agentshell_wp_live_config_destructive`)
 *      refuse to run unless this path produces a valid config. The
 *      function will not fall back to auto-discovery, because the
 *      auto-discovery glob picks the alphabetically first LocalWP
 *      site — which is the dev site on a workstation with both dev
 *      and test installs, and which would be destroyed.
 *
 *   2. **Auto-discovery (legacy, non-destructive diagnostic only).**
 *      Glob `$HOME/local/<site>/app/public/wp-load.php` and the
 *      LocalWP lightning-services PHP, picking the alphabetically
 *      first match. This is the legacy behaviour and is **only**
 *      available through `agentshell_wp_live_config()`, which is the
 *      read-only diagnostic variant. A machine with no test-bed
 *      config and no LocalWP installation gets `null` from both
 *      functions, and the live suites skip cleanly.
 *
 * Every path can be overridden by environment variable, so the suite
 * skips cleanly on a machine with no LocalWP rather than failing.
 */

/**
 * Resolve how to run PHP against a real WordPress install, allowing
 * the legacy auto-discovery fallback.
 *
 * **This is the read-only / diagnostic variant.** Destructive live
 * suites must use {@see agentshell_wp_live_config_destructive()}
 * instead, which refuses to fall back to auto-discovery. The
 * fall-through here exists so a workstation with no test bed can
 * still run the read-only suites (config consistency, transaction
 * preview) against whatever WordPress it has, while the destructive
 * suites skip cleanly.
 *
 * @return array|null {
 *     @type string $php        Path to a PHP binary.
 *     @type array  $ini        Extra -d arguments (socket, etc).
 *     @type array  $env        Environment additions (LD_LIBRARY_PATH, ...).
 *     @type string $wp_root    WordPress root, i.e. the directory holding
 *                              wp-load.php.
 *     @type array  $cfg        Test-bed config (when present), passed
 *                              through so the child script can read
 *                              fixture prefixes, expected fingerprints,
 *                              and the baseline snapshot path.
 *     @type string $source     "test_bed_config" | "auto_discovery" —
 *                              surfaced in reports so an unexpected
 *                              auto-discovery run is visible.
 * } Null when no usable install was found.
 */
function agentshell_wp_live_config() {
    $home = getenv( 'HOME' );
    if ( ! is_string( $home ) || '' === $home ) {
        return null;
    }

    // 1. Try the opt-in test-bed config. This is the path the test
    //    infrastructure expects when a real test bed has been
    //    provisioned.
    if ( function_exists( 'agentshell_test_bed_load' ) ) {
        $cfg = agentshell_test_bed_load();
        if ( is_array( $cfg ) && isset( $cfg['wp_root'] ) && isset( $cfg['php']['binary'] ) ) {
            $wp_root = (string) $cfg['wp_root'];
            $php     = (string) $cfg['php']['binary'];
            if ( is_file( $wp_root . '/wp-load.php' ) && is_executable( $php ) ) {
                $socket = isset( $cfg['db']['socket'] ) ? (string) $cfg['db']['socket'] : '';
                $libs   = isset( $cfg['php']['shared_libs'] ) ? (string) $cfg['php']['shared_libs'] : '';

                $ini = array();
                $env = array();
                if ( '' !== $socket ) {
                    $ini[] = 'mysqli.default_socket=' . $socket;
                }
                if ( '' !== $libs && is_dir( $libs ) ) {
                    $env['LD_LIBRARY_PATH'] = $libs;
                }
                return array(
                    'php'     => $php,
                    'ini'     => $ini,
                    'env'     => $env,
                    'wp_root' => $wp_root,
                    'cfg'     => $cfg,
                    'source'  => 'test_bed_config',
                );
            }
        }
    }

    // 2. Auto-discovery fallback. **Read-only diagnostic only** —
    //    destructive live suites refuse this path via
    //    agentshell_wp_live_config_destructive(). Picks the
    //    alphabetically first LocalWP site (typically the dev site,
    //    on a system with only one site) and the alphabetically
    //    first LocalWP PHP. **Machines with both a dev and a test
    //    install must use the test-bed config path; auto-discovery
    //    cannot tell them apart.**
    $wp_root = getenv( 'AGENTSHELL_TEST_WP_ROOT' );
    if ( ! is_string( $wp_root ) || '' === $wp_root ) {
        foreach ( glob( $home . '/local/<asterisk>/app/public/wp-load.php' ) ?: array() as $candidate ) {
            $wp_root = dirname( $candidate );
            break;
        }
    }
    if ( ! is_string( $wp_root ) || ! is_file( $wp_root . '/wp-load.php' ) ) {
        return null;
    }

    $php = getenv( 'AGENTSHELL_TEST_PHP' );
    if ( ! is_string( $php ) || ! is_executable( $php ) ) {
        $php = PHP_BINARY;
        foreach ( glob( $home . '/.config/Local/lightning-services/php-<asterisk>/bin/linux/bin/php' ) ?: array() as $candidate ) {
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

    foreach ( glob( $home . '/.config/Local/run/<asterisk>/mysql/mysqld.sock' ) ?: array() as $socket ) {
        $ini[] = 'mysqli.default_socket=' . $socket;
        break;
    }

    return array(
        'php'     => $php,
        'ini'     => $ini,
        'env'     => $env,
        'wp_root' => $wp_root,
        'cfg'     => null,
        'source'  => 'auto_discovery',
    );
}

/**
 * Resolve how to run PHP against a real WordPress install for
 * **destructive** work.
 *
 * The legacy auto-discovery fallback in {@see agentshell_wp_live_config()}
 * picks the alphabetically first LocalWP site, which is the developer
 * site on any system that has both a dev and a test install. A
 * destructive suite (one that creates, edits, or deletes content) run
 * against the dev site is the failure mode the entire test-bed
 * config exists to prevent. This function refuses to fall back to
 * auto-discovery: when the test-bed config is missing, malformed, or
 * points at an unapproved site, this function returns `null` and
 * the destructive live suite **skips cleanly**, the same as a
 * machine with no LocalWP.
 *
 * A null return from this function is the safe answer. Destructive
 * callers must treat null as a skip, not a fail.
 *
 * @return array|null Same shape as `agentshell_wp_live_config()`. Null
 *                     when no test-bed config was found, or when the
 *                     config exists but does not satisfy the minimum
 *                     safety fields (wp_root/wp-load.php, php binary).
 *                     The `source` field, when set, is always
 *                     `'test_bed_config'`; auto-discovery is never
 *                     permitted through this function.
 */
function agentshell_wp_live_config_destructive() {
    if ( ! function_exists( 'agentshell_test_bed_load' ) ) {
        return null;
    }
    $cfg = agentshell_test_bed_load();
    if ( ! is_array( $cfg ) || ! isset( $cfg['wp_root'] ) || ! isset( $cfg['php']['binary'] ) ) {
        return null;
    }
    $wp_root = (string) $cfg['wp_root'];
    $php     = (string) $cfg['php']['binary'];
    if ( ! is_file( $wp_root . '/wp-load.php' ) || ! is_executable( $php ) ) {
        return null;
    }
    $socket = isset( $cfg['db']['socket'] ) ? (string) $cfg['db']['socket'] : '';
    $libs   = isset( $cfg['php']['shared_libs'] ) ? (string) $cfg['php']['shared_libs'] : '';

    $ini = array();
    $env = array();
    if ( '' !== $socket ) {
        $ini[] = 'mysqli.default_socket=' . $socket;
    }
    if ( '' !== $libs && is_dir( $libs ) ) {
        $env['LD_LIBRARY_PATH'] = $libs;
    }
    return array(
        'php'     => $php,
        'ini'     => $ini,
        'env'     => $env,
        'wp_root' => $wp_root,
        'cfg'     => $cfg,
        'source'  => 'test_bed_config',
    );
}

/**
 * Run a PHP script against a real WordPress install and capture its stdout.
 *
 * The child is spawned with the same environment additions the runner
 * would have made (`LD_LIBRARY_PATH` for LocalWP's PHP shared libs) and
 * with `AGENTSHELL_TEST_WP_ROOT` set to the resolved wp root, plus
 * `AGENTSHELL_TEST_BED_CONFIG` set to the config path (when one was
 * found), so the child bootstrap can read the same config the runner
 * read without re-discovering it.
 *
 * @param array  $config  As returned by `agentshell_wp_live_config()` or
 *                        `agentshell_wp_live_config_destructive()`.
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
    if ( is_array( $config['cfg'] ) && isset( $config['cfg']['_config_path'] ) ) {
        $cmd .= 'AGENTSHELL_TEST_BED_CONFIG=' . escapeshellarg( (string) $config['cfg']['_config_path'] ) . ' ';
    }
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
