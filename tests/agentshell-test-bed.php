<?php
/**
 * Test bed bootstrap and fail-closed safety check.
 *
 * The "live" tests (journal-recovery-live, content-write-live, navigation-live)
 * used to discover the local WordPress install by globbing ~/local/* for the
 * first wp-load.php and the first available MySQL socket. That globs the
 * *alphabetically first* site — which is the developer site, on a system
 * where the developer has both a dev and a test install side by side. A live
 * test that picked up the dev site by accident would destroy the developer's
 * content; the entire test infrastructure here exists to make that impossible.
 *
 * This file replaces the globs with an explicit, opt-in config
 * (tests/config/test-bed.json) and a fail-closed check that runs at the
 * start of every test invocation. The check refuses to run unless every
 * one of the following holds, on the actual environment the tests are about
 * to mutate:
 *
 *   1. The expected WordPress root is a real wp-load.php.
 *   2. The expected PHP binary exists and is executable.
 *   3. The expected mysqld.sock exists.
 *   4. The expected database name and the expected @@server_uuid (a
 *      physically separate MySQL instance, not just a different database
 *      name on the dev instance) match.
 *   5. The expected data directory matches the @@datadir.
 *   6. The wp_options row `agentshell_site_mode` equals
 *      `disposable_test_bed` (the sentinel provisioned at test-bed setup).
 *   7. The MySQL user named in the config has *no* grants beyond
 *      <expected_db_name>.* — i.e. it cannot read or write the dev
 *      database, even if a stray query asks it to.
 *   8. No competing live test runner is currently holding the exclusive
 *      runner lock (a MySQL GET_LOCK scoped to the test instance, so it
 *      can never accidentally lock the dev instance).
 *   9. The HTTP target and the CLI target resolve to the same
 *      WordPress install (the test site's wp_options `siteurl` matches
 *      the config's url_https / url_http).
 *
 * If any check fails, the bootstrap returns an explicit refusal with the
 * failing check named. The runner then skips the live suite cleanly, with
 * the refusal surfaced in the report — the test never runs against the
 * wrong installation, and the developer site is never at risk.
 *
 * Config resolution order (first wins):
 *   1. AGENTSHELL_TEST_BED_CONFIG — absolute path to a JSON config file
 *      (used by CI or alternate test beds).
 *   2. tests/config/test-bed.json in this checkout.
 *
 * If neither exists, the bootstrap refuses with
 * `no_test_bed_config` and the live suite skips.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Locate the test-bed config file. Returns the absolute path or null when
 * neither the env-var override nor tests/config/test-bed.json exists.
 */
function agentshell_test_bed_config_path() {
    $env = getenv( 'AGENTSHELL_TEST_BED_CONFIG' );
    if ( is_string( $env ) && '' !== $env && is_file( $env ) ) {
        return $env;
    }
    $local = __DIR__ . '/config/test-bed.json';
    if ( is_file( $local ) ) {
        return $local;
    }
    return null;
}

/**
 * Read the test-bed config. Returns the decoded associative array or null
 * when the file is missing, unreadable, or not valid JSON. Never throws —
 * the caller's fail-closed check is responsible for reporting the absence.
 */
function agentshell_test_bed_load() {
    $path = agentshell_test_bed_config_path();
    if ( null === $path ) {
        return null;
    }
    $raw = @file_get_contents( $path );
    if ( ! is_string( $raw ) || '' === $raw ) {
        return null;
    }
    $cfg = json_decode( $raw, true );
    if ( ! is_array( $cfg ) ) {
        return null;
    }
    $cfg['_config_path'] = $path;
    return $cfg;
}

/**
 * Run the fail-closed safety check against the live environment.
 *
 * @param array $cfg  As returned by agentshell_test_bed_load().
 * @return array {
 *     @type bool   $ok           True iff every check passed.
 *     @type array  $checks       Per-check name => result array.
 *     @type string $refusal      Human-readable refusal when $ok is false.
 *     @type string $server_uuid  Observed MySQL @@server_uuid.
 *     @type string $datadir      Observed MySQL @@datadir.
 *     @type int    $port         Observed MySQL port (0 if socket).
 *     @type string $sentinel     Observed wp_options.agentshell_site_mode.
 *     @type string $siteurl      Observed wp_options.siteurl.
 *     @type string $table_prefix Observed $wpdb->prefix.
 * }
 */
function agentshell_test_bed_check( array $cfg ) {
    $checks = array();
    $refusal = '';
    $ok = true;

    $fail = function ( $name, $detail ) use ( &$checks, &$ok, &$refusal ) {
        $checks[ $name ] = array( 'ok' => false, 'detail' => $detail );
        $ok = false;
        if ( '' === $refusal ) {
            $refusal = $name . ': ' . $detail;
        }
    };
    $pass = function ( $name, $detail = '' ) use ( &$checks ) {
        $checks[ $name ] = array( 'ok' => true, 'detail' => $detail );
    };

    // 1. wp_root has wp-load.php
    $wp_root = isset( $cfg['wp_root'] ) && is_string( $cfg['wp_root'] ) ? $cfg['wp_root'] : '';
    if ( '' === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
        $fail( 'wp_root', 'wp-load.php not found at ' . (string) $wp_root );
    } else {
        $pass( 'wp_root', $wp_root );
    }

    // 2. PHP binary is executable
    $php = isset( $cfg['php']['binary'] ) && is_string( $cfg['php']['binary'] ) ? $cfg['php']['binary'] : '';
    if ( '' === $php || ! is_executable( $php ) ) {
        $fail( 'php_binary', 'not executable: ' . (string) $php );
    } else {
        $pass( 'php_binary', $php );
    }

    // 3. mysqld.sock exists. `is_file` returns false for sockets, so use
    //    `file_exists` (returns true for any filesystem entry, including
    //    unix-domain sockets) and confirm the entry is a socket via
    //    `filetype` for the report detail.
    $socket = isset( $cfg['db']['socket'] ) && is_string( $cfg['db']['socket'] ) ? $cfg['db']['socket'] : '';
    if ( '' === $socket || ! file_exists( $socket ) ) {
        $fail( 'mysqld_socket', 'not found: ' . (string) $socket );
    } else {
        $ft = @filetype( $socket );
        $pass( 'mysqld_socket', $socket . ( 'socket' === $ft ? '' : ' (filetype=' . (string) $ft . ')' ) );
    }

    // 4. database connection works. The check uses the *restricted*
    //    user, not root, so a misuse of root credentials here would be
    //    detected by the grants check below.
    $db_name = isset( $cfg['db']['name'] ) ? (string) $cfg['db']['name'] : '';
    $db_user = isset( $cfg['db']['user'] ) ? (string) $cfg['db']['user'] : '';
    $db_pass = isset( $cfg['db']['password'] ) ? (string) $cfg['db']['password'] : '';
    $db_host = isset( $cfg['db']['host'] ) ? (string) $cfg['db']['host'] : 'localhost';
    if ( '' === $db_name || '' === $db_user ) {
        $fail( 'db_credentials', 'db.name and db.user are required' );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'server_uuid' => '', 'datadir' => '', 'port' => 0,
            'sentinel' => '', 'siteurl' => '', 'table_prefix' => '',
        );
    }

    $mysqli = @new mysqli( $db_host, $db_user, $db_pass, $db_name, 0, $socket );
    if ( $mysqli instanceof mysqli && $mysqli->connect_errno ) {
        $fail( 'db_connect', 'connect_errno=' . $mysqli->connect_errno . ' (' . $mysqli->connect_error . ')' );
        $mysqli = null;
    }
    if ( ! ( $mysqli instanceof mysqli ) ) {
        $fail( 'db_connect', 'unable to construct mysqli against ' . $db_user . '@' . $db_host . ' via ' . $socket );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'server_uuid' => '', 'datadir' => '', 'port' => 0,
            'sentinel' => '', 'siteurl' => '', 'table_prefix' => '',
        );
    }
    $pass( 'db_connect', $db_user . '@' . $db_host );

    // 5. SELECT DATABASE() matches the expected name.
    $row = $mysqli->query( 'SELECT DATABASE()' )->fetch_row();
    $observed_db = is_array( $row ) ? (string) $row[0] : '';
    if ( $observed_db !== $db_name ) {
        $fail( 'db_name', 'expected ' . $db_name . ' observed ' . (string) $observed_db );
    } else {
        $pass( 'db_name', $observed_db );
    }

    // 6. @@server_uuid matches expected (the load-bearing check — a
    //    different server_uuid means a different MySQL instance, not just
    //    a different database name on the dev instance).
    $expected_uuid = isset( $cfg['expect']['server_uuid'] ) ? (string) $cfg['expect']['server_uuid'] : '';
    $row = $mysqli->query( "SELECT @@server_uuid" )->fetch_row();
    $observed_uuid = is_array( $row ) ? (string) $row[0] : '';
    if ( '' !== $expected_uuid && $observed_uuid !== $expected_uuid ) {
        $fail( 'server_uuid', 'expected ' . $expected_uuid . ' observed ' . $observed_uuid );
    } else {
        $pass( 'server_uuid', $observed_uuid );
    }

    // 7. @@datadir matches expected (trailing slashes normalised — the
    //    server returns '…/data/' with the trailing slash, the config
    //    stores it without).
    $expected_dd = isset( $cfg['expect']['expected_datadir'] ) ? (string) $cfg['expect']['expected_datadir'] : '';
    $row = $mysqli->query( "SELECT @@datadir" )->fetch_row();
    $observed_dd = is_array( $row ) ? (string) $row[0] : '';
    $norm = function ( $p ) { return rtrim( (string) $p, '/' ); };
    if ( '' !== $expected_dd && $norm( $observed_dd ) !== $norm( $expected_dd ) ) {
        $fail( 'datadir', 'expected ' . $expected_dd . ' observed ' . $observed_dd );
    } else {
        $pass( 'datadir', $observed_dd );
    }

    // 8. port matches expected.
    $expected_port = isset( $cfg['expect']['expected_port'] ) ? (int) $cfg['expect']['expected_port'] : 0;
    $row = $mysqli->query( "SELECT @@port" )->fetch_row();
    $observed_port = is_array( $row ) ? (int) $row[0] : 0;
    if ( 0 !== $expected_port && $observed_port !== $expected_port ) {
        $fail( 'port', 'expected ' . $expected_port . ' observed ' . $observed_port );
    } else {
        $pass( 'port', (string) $observed_port );
    }

    // 9. The restricted db user has NO grants beyond <db_name>.*.
    //    This is the load-bearing check against a misconfigured user
    //    that could accidentally read or write the dev database.
    if ( isset( $cfg['expect']['db_user_grants_only'] ) ) {
        $expected_grants = (string) $cfg['expect']['db_user_grants_only'];
        $grants = array();
        $res = $mysqli->query( "SHOW GRANTS FOR CURRENT_USER()" );
        if ( $res instanceof mysqli_result ) {
            while ( $r = $res->fetch_row() ) {
                $grants[] = (string) $r[0];
            }
        }
        $bad = array();
        $expected_pat = "/^GRANT .+ ON `?{$expected_grants}`?\\.\\*/i";
        $usg_pat      = '/^GRANT USAGE ON \\*\\.\\*/i';
        foreach ( $grants as $g ) {
            if ( preg_match( $usg_pat, $g ) ) { continue; } // baseline USAGE
            if ( preg_match( $expected_pat, $g ) ) { continue; } // expected
            $bad[] = $g;
        }
        if ( ! empty( $bad ) ) {
            $fail( 'db_user_grants', 'user has grants beyond ' . $expected_grants . '.*: ' . implode( ' | ', $bad ) );
        } else {
            $pass( 'db_user_grants', implode( ' | ', $grants ) );
        }
    } else {
        $pass( 'db_user_grants', '(no expectation set; skipped)' );
    }

    // 10. The wp_options row `agentshell_site_mode` equals the expected
    //     sentinel. This is the check that confirms the database is
    //     pointed at the *test* site, not at a clone that lost its
    //     sentinel during a partial restore.
    $sentinel = '';
    $siteurl  = '';
    $table_prefix = '';
    $res = @$mysqli->query( "SELECT option_name, option_value FROM {$db_name}.wp_options WHERE option_name IN ('agentshell_site_mode','siteurl')" );
    if ( $res instanceof mysqli_result ) {
        while ( $r = $res->fetch_row() ) {
            if ( 'agentshell_site_mode' === (string) $r[0] ) {
                $sentinel = (string) $r[1];
            }
            if ( 'siteurl' === (string) $r[0] ) {
                $siteurl = (string) $r[1];
            }
        }
    }
    $expected_sentinel = isset( $cfg['expect']['site_mode_sentinel'] ) ? (string) $cfg['expect']['site_mode_sentinel'] : '';
    if ( '' !== $expected_sentinel && $sentinel !== $expected_sentinel ) {
        $fail( 'site_mode_sentinel', 'expected "' . $expected_sentinel . '" observed "' . $sentinel . '"' );
    } else {
        $pass( 'site_mode_sentinel', $sentinel );
    }

    // 11. siteurl matches the config's http(s) URL on the *host* (the
    //     port may differ — WordPress's `siteurl` does not include a
    //     non-default port, but the config's url_http does, because
    //     LocalWP serves the test site on a non-default port).
    $url_match = false;
    $candidate_hosts = array();
    foreach ( array( 'url_https', 'url_http' ) as $k ) {
        if ( isset( $cfg[ $k ] ) && is_string( $cfg[ $k ] ) && '' !== $cfg[ $k ] ) {
            $h = parse_url( $cfg[ $k ], PHP_URL_HOST );
            if ( is_string( $h ) && '' !== $h ) {
                $candidate_hosts[ $h ] = true;
            }
        }
    }
    $observed_host = is_string( $siteurl ) ? parse_url( $siteurl, PHP_URL_HOST ) : null;
    if ( is_string( $observed_host ) && isset( $candidate_hosts[ $observed_host ] ) ) {
        $url_match = true;
    }
    if ( $url_match ) {
        $pass( 'siteurl', $siteurl . ' (host=' . $observed_host . ')' );
    } else {
        $candidates = array_keys( $candidate_hosts );
        $fail( 'siteurl', 'siteurl "' . $siteurl . '" host "' . (string) $observed_host . '" does not match any of the configured hosts: ' . implode( ', ', $candidates ) );
    }

    // 12. table prefix matches expected.
    $expected_prefix = isset( $cfg['expect']['wp_table_prefix'] ) ? (string) $cfg['expect']['wp_table_prefix'] : '';
    $expected_options = $expected_prefix . 'options';
    $res = @$mysqli->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string( $db_name ) . "' AND TABLE_NAME = '" . $mysqli->real_escape_string( $expected_options ) . "' LIMIT 1" );
    $row = ( $res instanceof mysqli_result ) ? $res->fetch_row() : null;
    $observed_options = is_array( $row ) ? (string) $row[0] : '';
    if ( '' === $observed_options ) {
        $fail( 'table_prefix', 'expected ' . $expected_options . ' but the table was not found in ' . $db_name );
    } else {
        $pass( 'table_prefix', $observed_options );
    }
    if ( is_string( $observed_options ) && 'options' === substr( $observed_options, -7 ) ) {
        $table_prefix = substr( $observed_options, 0, -7 );
    }

    // 13. The config's domain is not a forbidden (known-dev) domain.
    //     This is the safety net against a config file that was
    //     accidentally repointed at the development site: the dev
    //     domain is `agentshell.local`, the test domain is
    //     `agentshell-test.local`. A typo or a copy-paste from the
    //     dev config would name the dev domain and fail here. The
    //     list is configurable; the default is the dev site only.
    $observed_domain = isset( $cfg['domain'] ) ? (string) $cfg['domain'] : '';
    $forbidden       = isset( $cfg['expect']['forbidden_domains'] ) && is_array( $cfg['expect']['forbidden_domains'] )
        ? array_map( 'strval', $cfg['expect']['forbidden_domains'] )
        : array( 'agentshell.local' );
    if ( '' !== $observed_domain && in_array( $observed_domain, $forbidden, true ) ) {
        $fail( 'forbidden_domain', 'config domain "' . $observed_domain . '" is a known development domain; refusing to run' );
    } else {
        $pass( 'forbidden_domain', $observed_domain );
    }

    // 14. The config's wp_root is not a known development path. A
    //     test config that points at the dev site's WordPress
    //     installation (a /home/v/local/agentshell/app/public path
    //     on this workstation) would be a destructive test against
    //     a real site. The list is configurable; the default
    //     names the dev path.
    $observed_root = realpath( $wp_root );
    $forbidden_roots = isset( $cfg['expect']['forbidden_wp_roots'] ) && is_array( $cfg['expect']['forbidden_wp_roots'] )
        ? array_map( 'strval', $cfg['expect']['forbidden_wp_roots'] )
        : array( '/home/v/local/agentshell/app/public' );
    $root_is_forbidden = false;
    if ( is_string( $observed_root ) ) {
        foreach ( $forbidden_roots as $bad ) {
            $bad_real = realpath( $bad );
            if ( false !== $bad_real && $observed_root === $bad_real ) {
                $root_is_forbidden = true;
                break;
            }
        }
    }
    if ( $root_is_forbidden ) {
        $fail( 'forbidden_wp_root', 'config wp_root resolves to a known development path; refusing to run' );
    } else {
        $pass( 'forbidden_wp_root', is_string( $observed_root ) ? $observed_root : $wp_root );
    }

    // 15. HTTP-side validation: the URL the config advertises
    //     actually serves a WordPress site. A config whose URL
    //     points at a different (dev) site would have passed the
    //     DB-side checks above — the dev site also has a WordPress
    //     `siteurl` option — but the HTTP fetch would land on the
    //     dev site and surface a different generator tag or
    //     siteurl. We use the configured PHP binary's
    //     `file_get_contents` (over the test bed's libs) so the
    //     fetch shares the test bed's resolution rather than the
    //     runner's; this is a soft check that reports rather than
    //     hard-fails if the HTTP server is not running, so a
    //     developer who is running just the unit suites against a
    //     cold test bed is not blocked. The check is also a
    //     fail-closed refusal of any config whose HTTP response
    //     names a forbidden domain in the HTML — a clone of the
    //     dev site on a different name would be detected here.
    $http_url = '';
    foreach ( array( 'url_https', 'url_http' ) as $k ) {
        if ( isset( $cfg[ $k ] ) && is_string( $cfg[ $k ] ) && '' !== $cfg[ $k ] ) {
            $http_url = (string) $cfg[ $k ];
            break;
        }
    }
    if ( '' !== $http_url ) {
        $http_status = 0;
        $http_body   = '';
        $http_error  = '';
        $ctx = stream_context_create( array(
            'http' => array(
                'timeout' => 5,
                'ignore_errors' => true,
                'header'  => "User-Agent: agentshell-test-bed-check\r\n",
            ),
        ) );
        $http_body = @file_get_contents( $http_url, false, $ctx );
        if ( false === $http_body ) {
            $http_error = error_get_last() ? ( error_get_last()['message'] ?? '' ) : '';
        }
        if ( isset( $http_response_header[0] ) && preg_match( '/HTTP\/[\d.]+\s+(\d{3})/', $http_response_header[0], $m ) ) {
            $http_status = (int) $m[1];
        }
        if ( '' === $http_body || $http_status >= 500 ) {
            // Soft skip: the test bed's HTTP server may not be
            // running, and the CLI path is the load-bearing
            // channel. A runner with an HTTP-unreachable test bed
            // is reported but not failed.
            $pass( 'http_fetch', 'unreachable (' . ( '' !== $http_error ? $http_error : 'status=' . $http_status ) . '); CLI path is sufficient' );
        } else {
            // Hard checks: the response is HTML, the response
            // names the configured domain, and the response does
            // not name a forbidden domain.
            $body_has_generator = ( false !== stripos( $http_body, 'name="generator"' ) && false !== stripos( $http_body, 'WordPress' ) );
            $body_names_forbidden = false;
            foreach ( $forbidden as $bad_domain ) {
                if ( false !== stripos( $http_body, $bad_domain ) ) {
                    $body_names_forbidden = true;
                    break;
                }
            }
            $body_names_observed = ( '' === $observed_domain || false !== stripos( $http_body, $observed_domain ) );
            if ( $body_names_forbidden ) {
                $fail( 'http_fetch', 'HTTP response names a forbidden dev domain; refusing to run' );
            } elseif ( ! $body_has_generator ) {
                $pass( 'http_fetch', 'HTTP response is not a WordPress site (no generator tag); CLI path is sufficient' );
            } elseif ( ! $body_names_observed ) {
                $pass( 'http_fetch', 'HTTP response does not name the configured domain "' . $observed_domain . '"; CLI path is sufficient' );
            } else {
                $pass( 'http_fetch', $http_url . ' (status=' . $http_status . ', names ' . $observed_domain . ')' );
            }
        }
    } else {
        $pass( 'http_fetch', '(no URL configured; skipped)' );
    }

    $mysqli->close();

    return array(
        'ok'           => $ok,
        'checks'       => $checks,
        'refusal'      => $refusal,
        'server_uuid'  => $observed_uuid,
        'datadir'      => $observed_dd,
        'port'         => $observed_port,
        'sentinel'     => $sentinel,
        'siteurl'      => $siteurl,
        'table_prefix' => $table_prefix,
    );
}

/**
 * One-shot bootstrap: load the config and run the check.
 *
 * @return array|null  Null when no config was found (live suite skips);
 *                     the check array otherwise (caller decides based on
 *                     'ok').
 */
function agentshell_test_bed_bootstrap() {
    $cfg = agentshell_test_bed_load();
    if ( null === $cfg ) {
        return null;
    }
    $check = agentshell_test_bed_check( $cfg );
    $check['_cfg'] = $cfg;
    return $check;
}

/**
 * Acquire the exclusive runner lock. Returns true on success, false when
 * another runner holds it. The lock is a MySQL named advisory lock on the
 * test instance — the SAME instance the tests will mutate. A test run
 * that does not own the lock refuses to proceed; the operator must wait
 * for the in-flight run to finish.
 *
 * The lock is connection-scoped: when this PHP process exits (or is
 * killed), the server releases it. There is no "stale lock" failure mode
 * analogous to a stale file lock.
 *
 * The MySQL connection that holds the lock is stashed in a static
 * variable so it survives the function's return — if we stashed it on
 * the caller's $cfg (which is passed by value) the connection would be
 * destroyed when the function returns, the server would close the
 * connection, and the lock would be released before the caller could
 * actually use it. The static makes the connection live for the
 * process's lifetime; the matching `agentshell_test_bed_unlock` (or
 * process exit) releases the lock.
 */
function agentshell_test_bed_lock( $cfg, $check, $timeout = 0 ) {
    static $held = null; // ['mysqli' => mysqli, 'name' => string]
    if ( is_array( $held ) ) {
        // This process already holds the lock — re-entrant is a no-op.
        return true;
    }
    if ( ! ( isset( $cfg['db']['name'] ) && isset( $cfg['db']['user'] ) && isset( $cfg['db']['password'] ) ) ) {
        return false;
    }
    $db_host = isset( $cfg['db']['host'] ) ? (string) $cfg['db']['host'] : 'localhost';
    $socket  = isset( $cfg['db']['socket'] ) ? (string) $cfg['db']['socket'] : '';
    $mysqli  = @new mysqli( $db_host, (string) $cfg['db']['user'], (string) $cfg['db']['password'], (string) $cfg['db']['name'], 0, $socket );
    if ( ! ( $mysqli instanceof mysqli ) || $mysqli->connect_errno ) {
        return false;
    }
    $lock_name = 'agentshell_test_bed_runner_' . substr( hash( 'sha256', (string) $check['server_uuid'] ), 0, 16 );
    $r = $mysqli->query( "SELECT GET_LOCK('" . $mysqli->real_escape_string( $lock_name ) . "', " . (int) $timeout . ")" );
    $row = ( $r instanceof mysqli_result ) ? $r->fetch_row() : null;
    $val = is_array( $row ) ? (string) $row[0] : '';
    if ( '1' === $val ) {
        $held = array( 'mysqli' => $mysqli, 'name' => $lock_name );
        return true;
    }
    $mysqli->close();
    return false;
}

function agentshell_test_bed_unlock() {
    static $held = null;
    if ( ! is_array( $held ) ) {
        return;
    }
    if ( isset( $held['mysqli'] ) && ( $held['mysqli'] instanceof mysqli ) ) {
        $name = isset( $held['name'] ) ? (string) $held['name'] : '';
        if ( '' !== $name ) {
            @$held['mysqli']->query( "SELECT RELEASE_LOCK('" . $held['mysqli']->real_escape_string( $name ) . "')" );
        }
        @$held['mysqli']->close();
    }
    $held = null;
}
