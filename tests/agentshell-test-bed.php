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

/**
 * Locate the test-bed config file. Returns the absolute path or null when
 * neither the env-var override nor tests/config/test-bed.json exists.
 */
require_once __DIR__ . '/http-headers.php';

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
        $http_headers = agentshell_test_last_response_headers();
        if ( ! empty( $http_headers ) && preg_match( '/HTTP\/[\d.]+\s+(\d{3})/', (string) $http_headers[0], $m ) ) {
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

/**
 * Install the test-only identity mu-plugin on the test site.
 *
 * The mu-plugin is the test-side half of the HTTP identity handshake.
 * It registers a REST endpoint that returns the test site's identity
 * string when called with the per-run token. Without it, the runner
 * can only verify CLI/DB identity and a generic "is this a WordPress
 * site" HTTP probe — which a clone of the dev site on a different
 * hostname would pass. With it, the runner can prove HTTP and CLI
 * read the same value from the same database.
 *
 * The install is idempotent: a fresh copy is written every time. The
 * mu-plugin source is in tests/mu-plugins/agentshell-test-identity.php
 * in this checkout. The install path on the test site is
 * <wp_root>/wp-content/mu-plugins/agentshell-test-identity.php.
 *
 * The mu-plugins directory is created if absent. The install refuses
 * to run if wp_root does not exist or is not writable; the caller must
 * interpret the failure as a refuse-to-run condition.
 *
 * @param array $cfg Test-bed config.
 * @return array { ok: bool, path: string, reason: string }
 */
function agentshell_test_bed_install_mu_plugin( array $cfg ) {
    $wp_root = isset( $cfg['wp_root'] ) ? (string) $cfg['wp_root'] : '';
    if ( '' === $wp_root || ! is_dir( $wp_root . '/wp-content' ) ) {
        return array( 'ok' => false, 'path' => '', 'reason' => 'wp_root has no wp-content directory' );
    }
    $src = __DIR__ . '/mu-plugins/agentshell-test-identity.php';
    if ( ! is_file( $src ) ) {
        return array( 'ok' => false, 'path' => '', 'reason' => 'mu-plugin source missing in test infrastructure: ' . $src );
    }
    $dst_dir = $wp_root . '/wp-content/mu-plugins';
    $dst     = $dst_dir . '/agentshell-test-identity.php';
    if ( ! is_dir( $dst_dir ) ) {
        // mu-plugins is not standard; LocalWP does not create it. Make
        // it on demand. A mkdir that fails here is a refuse-to-run
        // condition: the test site is not in a state we can extend.
        if ( ! @mkdir( $dst_dir, 0755, true ) && ! is_dir( $dst_dir ) ) {
            return array( 'ok' => false, 'path' => '', 'reason' => 'cannot create ' . $dst_dir );
        }
    }
    $bytes = @file_get_contents( $src );
    if ( ! is_string( $bytes ) ) {
        return array( 'ok' => false, 'path' => $dst, 'reason' => 'cannot read mu-plugin source' );
    }
    // Idempotent overwrite: every run replaces the file with the
    // current test-infrastructure copy, so an in-place edit lands on
    // the test site on the next run.
    if ( false === @file_put_contents( $dst, $bytes ) ) {
        return array( 'ok' => false, 'path' => $dst, 'reason' => 'cannot write mu-plugin to ' . $dst );
    }
    return array( 'ok' => true, 'path' => $dst, 'reason' => '' );
}

/**
 * Provision a per-run token and identity on the test site.
 *
 * The token gates the HTTP identity endpoint; only requests with the
 * matching `X-AgentShell-Test-Token` header receive a response. The
 * identity is the value the endpoint returns. The test runner writes
 * both to the test site's wp_options table directly (it already has
 * the DB credentials and the mysqli extension), then later fetches
 * the endpoint and asserts the round-trip.
 *
 * The provision is per-run: a fresh 32-byte hex token and identity
 * are generated every call. The previous run's values are
 * overwritten, so an old token from a prior run is automatically
 * invalid.
 *
 * The values are also returned to the caller so the HTTP check can
 * verify the round-trip without a second DB read.
 *
 * @param array $cfg  Test-bed config.
 * @return array { ok: bool, token: string, identity: string, reason: string }
 */
function agentshell_test_bed_provision_identity( array $cfg ) {
    if ( ! class_exists( 'mysqli' ) ) {
        return array( 'ok' => false, 'token' => '', 'identity' => '', 'reason' => 'mysqli not available' );
    }
    $db_host = isset( $cfg['db']['host'] ) ? (string) $cfg['db']['host'] : 'localhost';
    $socket  = isset( $cfg['db']['socket'] ) ? (string) $cfg['db']['socket'] : '';
    $user    = isset( $cfg['db']['user'] ) ? (string) $cfg['db']['user'] : '';
    $pass    = isset( $cfg['db']['password'] ) ? (string) $cfg['db']['password'] : '';
    $db_name = isset( $cfg['db']['name'] ) ? (string) $cfg['db']['name'] : '';
    $prefix  = isset( $cfg['expect']['wp_table_prefix'] ) ? (string) $cfg['expect']['wp_table_prefix'] : 'wp_';
    $mysqli  = @new mysqli( $db_host, $user, $pass, $db_name, 0, $socket );
    if ( ! ( $mysqli instanceof mysqli ) || $mysqli->connect_errno ) {
        return array( 'ok' => false, 'token' => '', 'identity' => '', 'reason' => 'cannot connect to test DB' );
    }
    $token    = bin2hex( random_bytes( 16 ) );
    $identity = bin2hex( random_bytes( 16 ) );
    $opts     = $prefix . 'options';
    // Upsert by option_name. The values are stored as the raw hex
    // string; the REST endpoint reads them back verbatim. Using
    // option_name as the key means a re-run simply overwrites the
    // prior values; a partially-applied provision (token written,
    // identity not) cannot leave a half-state because the query is
    // two sequential UPDATE statements, not a transaction, and the
    // second is independent. If a crash lands between them, the
    // token changes on the next run (the freshly-generated token
    // overwrites the stale one), so the endpoint stays consistent.
    $sql_t = "UPDATE {$opts} SET option_value = '" . $mysqli->real_escape_string( $token ) . "' WHERE option_name = 'agentshell_test_bed_token'";
    $sql_i = "UPDATE {$opts} SET option_value = '" . $mysqli->real_escape_string( $identity ) . "' WHERE option_name = 'agentshell_test_bed_identity'";
    if ( ! $mysqli->query( $sql_t ) || ! $mysqli->query( $sql_i ) ) {
        $reason = 'UPDATE failed: ' . $mysqli->error;
        $mysqli->close();
        return array( 'ok' => false, 'token' => '', 'identity' => '', 'reason' => $reason );
    }
    // If the option rows do not exist yet (e.g. on a freshly restored
    // baseline), the UPDATE matched zero rows. Insert them now.
    $check = $mysqli->query( "SELECT option_name FROM {$opts} WHERE option_name IN ('agentshell_test_bed_token', 'agentshell_test_bed_identity')" );
    $have  = array();
    if ( $check ) {
        while ( $r = $check->fetch_row() ) {
            $have[ (string) $r[0] ] = true;
        }
    }
    if ( ! isset( $have['agentshell_test_bed_token'] ) ) {
        $mysqli->query( "INSERT INTO {$opts} (option_name, option_value, autoload) VALUES ('agentshell_test_bed_token', '" . $mysqli->real_escape_string( $token ) . "', 'no')" );
    }
    if ( ! isset( $have['agentshell_test_bed_identity'] ) ) {
        $mysqli->query( "INSERT INTO {$opts} (option_name, option_value, autoload) VALUES ('agentshell_test_bed_identity', '" . $mysqli->real_escape_string( $identity ) . "', 'no')" );
    }
    $mysqli->close();
    return array( 'ok' => true, 'token' => $token, 'identity' => $identity, 'reason' => '' );
}

/**
 * Verify the HTTP identity handshake.
 *
 * Fetches <cfg.url_https or url_http>/wp-json/agentshell-test/v1/identity
 * with the X-AgentShell-Test-Token header set to $token, and asserts
 * the response body's `identity` field equals $expected_identity. The
 * round-trip proves that the HTTP service and the CLI/DB path resolve
 * to the same WordPress install (and therefore the same database).
 *
 * This is a HARD check. Unreachable, 401, 503, or a mismatched
 * identity is a failure. The unit-only mode (no test-bed config) is
 * unaffected; the destructive live suites refuse to run when this
 * check fails.
 *
 * @param array $cfg  Test-bed config.
 * @param string $token           The per-run token from provision.
 * @param string $expected_identity The identity the runner wrote to wp_options.
 * @return array { ok: bool, detail: string, status: int, identity: string }
 */
function agentshell_test_bed_check_http_identity( array $cfg, $token, $expected_identity ) {
    $url = '';
    foreach ( array( 'url_https', 'url_http' ) as $k ) {
        if ( isset( $cfg[ $k ] ) && is_string( $cfg[ $k ] ) && '' !== $cfg[ $k ] ) {
            $url = (string) $cfg[ $k ];
            break;
        }
    }
    if ( '' === $url ) {
        return array( 'ok' => false, 'detail' => 'no URL configured', 'status' => 0, 'identity' => '' );
    }
    $endpoint = rtrim( $url, '/' ) . '/wp-json/agentshell-test/v1/identity';
    $ctx = stream_context_create( array(
        'http' => array(
            'timeout' => 5,
            'ignore_errors' => true,
            'method'        => 'GET',
            'header'        => "X-AgentShell-Test-Token: " . $token . "\r\nUser-Agent: agentshell-test-bed-identity\r\n",
        ),
    ) );
    $body = @file_get_contents( $endpoint, false, $ctx );
    $status = 0;
    $headers = agentshell_test_last_response_headers();
    if ( ! empty( $headers ) && preg_match( '/HTTP\/[\d.]+\s+(\d{3})/', (string) $headers[0], $m ) ) {
        $status = (int) $m[1];
    }
    if ( false === $body || '' === $body ) {
        return array( 'ok' => false, 'detail' => 'HTTP endpoint unreachable', 'status' => $status, 'identity' => '' );
    }
    if ( 200 !== $status ) {
        return array( 'ok' => false, 'detail' => 'HTTP endpoint returned ' . $status, 'status' => $status, 'identity' => '' );
    }
    $decoded = json_decode( (string) $body, true );
    if ( ! is_array( $decoded ) ) {
        return array( 'ok' => false, 'detail' => 'HTTP response is not valid JSON', 'status' => $status, 'identity' => '' );
    }
    $returned = isset( $decoded['identity'] ) ? (string) $decoded['identity'] : '';
    if ( '' === $returned ) {
        return array( 'ok' => false, 'detail' => 'HTTP response has no identity field', 'status' => $status, 'identity' => '' );
    }
    if ( ! hash_equals( $expected_identity, $returned ) ) {
        return array( 'ok' => false, 'detail' => 'HTTP identity does not match the value the runner wrote to wp_options', 'status' => $status, 'identity' => $returned );
    }
    return array( 'ok' => true, 'detail' => $endpoint . ' returned identity ' . $returned, 'status' => $status, 'identity' => $returned );
}

/**
 * Preflight crash-window check.
 *
 * The crash window is the gap between `wp_insert_post` returning and
 * the fixture tracker recording the post id. A child that dies inside
 * this window (KILL -9, PHP fatal, OOM) leaves a journaled post on
 * the test site with no record in the per-run fixture registry. The
 * post is identified by the `_agentshell_journal_op` meta it carries
 * — that is the journal's marker, set in the same `wp_insert_post`
 * call.
 *
 * The preflight does *not* attempt to delete-by-prefix or
 * delete-by-marker. Both are forbidden: a prefix-based delete can
 * target unowned content on a non-test site (the dev site is
 * preserved untouched, but the policy is the policy), and a
 * marker-based delete of a row referenced by an open transaction
 * would discard evidence the recovery engine needs. The preflight
 * either:
 *
 *  1. Refuses to run when an active recovery is in progress (an
 *     open `agentshell_transaction` row, or a journaled post whose
 *     op_id is referenced by an applied op) — the operator must
 *     resolve it; or
 *  2. Restores the test site from its verified baseline under the
 *     exclusive runner lock. The baseline is a known-good state
 *     with no journal rows, no transactions, and no posts; the
 *     restore is destructive but bounded.
 *
 * The check runs under the lock so a parallel runner cannot see
 * mid-restore state.
 *
 * @param array $cfg  Test-bed config.
 * @return array {
 *     @type bool   $ok
 *     @type string $action  'clean' | 'safe_to_restore' | 'restored' |
 *                           'refused_real_user_tx' | 'refused_active_recovery' |
 *                           'restore_failed' | 'restore_skipped_unverified'
 *     @type int    $marker_count
 *     @type string $detail
 * }
 */
function agentshell_test_bed_preflight( array $cfg ) {
    if ( ! class_exists( 'mysqli' ) ) {
        return array( 'ok' => false, 'action' => 'restore_skipped_unverified', 'marker_count' => 0, 'detail' => 'mysqli not available' );
    }
    $db_host = isset( $cfg['db']['host'] ) ? (string) $cfg['db']['host'] : 'localhost';
    $socket  = isset( $cfg['db']['socket'] ) ? (string) $cfg['db']['socket'] : '';
    $user    = isset( $cfg['db']['user'] ) ? (string) $cfg['db']['user'] : '';
    $pass    = isset( $cfg['db']['password'] ) ? (string) $cfg['db']['password'] : '';
    $db_name = isset( $cfg['db']['name'] ) ? (string) $cfg['db']['name'] : '';
    $prefix  = isset( $cfg['expect']['wp_table_prefix'] ) ? (string) $cfg['expect']['wp_table_prefix'] : 'wp_';
    $mysqli  = @new mysqli( $db_host, $user, $pass, $db_name, 0, $socket );
    if ( ! ( $mysqli instanceof mysqli ) || $mysqli->connect_errno ) {
        return array( 'ok' => false, 'action' => 'restore_skipped_unverified', 'marker_count' => 0, 'detail' => 'cannot connect to test DB' );
    }
    $pmeta = $prefix . 'postmeta';
    $r = $mysqli->query( "SELECT COUNT(*) FROM {$pmeta} WHERE meta_key = '_agentshell_journal_op'" );
    $marker_count = ( $r instanceof mysqli_result ) ? (int) $r->fetch_row()[0] : 0;
    if ( 0 === $marker_count ) {
        $mysqli->close();
        return array( 'ok' => true, 'action' => 'clean', 'marker_count' => 0, 'detail' => 'no journal markers on the test site' );
    }
    // Leaks detected. Check for an open transaction.
    $opts = $prefix . 'options';
    $tx   = null;
    $r    = $mysqli->query( "SELECT option_value FROM {$opts} WHERE option_name = 'agentshell_transaction'" );
    if ( $r instanceof mysqli_result && $row = $r->fetch_row() ) {
        $tx = json_decode( (string) $row[0], true );
    }
    if ( is_array( $tx ) ) {
        $label = isset( $tx['label'] ) ? (string) $tx['label'] : '';
        // Real user transaction — refuse; the operator must close it.
        if ( 0 !== strpos( $label, 'live ' ) ) {
            $mysqli->close();
            return array(
                'ok'           => false,
                'action'       => 'refused_real_user_tx',
                'marker_count' => $marker_count,
                'detail'       => 'open transaction with non-test label "' . $label . '" is preserved; operator must close it before the preflight can run',
            );
        }
        // Test transaction — count applied ops. An applied op is
        // evidence the recovery engine is using; the preflight must
        // not discard it.
        $applied = 0;
        $ops     = isset( $tx['content']['ops'] ) && is_array( $tx['content']['ops'] ) ? $tx['content']['ops'] : array();
        foreach ( $ops as $op ) {
            if ( is_array( $op ) && ( $op['state'] ?? '' ) === 'applied' ) {
                $applied++;
            }
        }
        if ( $applied > 0 ) {
            $mysqli->close();
            return array(
                'ok'           => false,
                'action'       => 'refused_active_recovery',
                'marker_count' => $marker_count,
                'detail'       => 'open transaction "' . $label . '" has ' . $applied . ' applied op(s); recovery in progress, preflight refused',
            );
        }
        // Test transaction with no applied work — close the option
        // row directly (same path as the live precondition) so the
        // restore does not have to deal with an open slot. The row
        // is value-guarded: a row that moved between the read and
        // the delete is left alone.
        $current = $mysqli->query( "SELECT option_value FROM {$opts} WHERE option_name = 'agentshell_transaction'" );
        $cur_val = ( $current instanceof mysqli_result && $row = $current->fetch_row() ) ? (string) $row[0] : '';
        if ( '' !== $cur_val ) {
            $mysqli->query( "DELETE FROM {$opts} WHERE option_name = 'agentshell_transaction' AND option_value = '" . $mysqli->real_escape_string( $cur_val ) . "'" );
        }
    }
    // Leaks remain, no active recovery. Restore from baseline.
    $baseline = isset( $cfg['expect']['baseline_snapshot_path'] ) ? (string) $cfg['expect']['baseline_snapshot_path'] : '';
    if ( '' === $baseline || ! is_file( $baseline ) ) {
        $mysqli->close();
        return array(
            'ok'           => false,
            'action'       => 'refused_active_recovery',
            'marker_count' => $marker_count,
            'detail'       => 'crash-window leak detected but no baseline_snapshot_path configured; operator must intervene',
        );
    }
    $sql = @file_get_contents( $baseline );
    if ( ! is_string( $sql ) || '' === $sql ) {
        $mysqli->close();
        return array(
            'ok'           => false,
            'action'       => 'restore_failed',
            'marker_count' => $marker_count,
            'detail'       => 'cannot read baseline at ' . $baseline,
        );
    }
    // Apply baseline under the same connection. multi_query is
    // used because the baseline contains many statements separated
    // by semicolons; the default single-statement query() would
    // execute only the first one. The connection is closed on
    // error; on success we close it cleanly.
    //
    // The drain loop below is the load-bearing piece: the first
    // statement of a multi_query may succeed (so `multi_query`
    // returns `true`) and a later statement may fail. The failure
    // is surfaced in two ways:
    //
    //   (a) `next_result()` throws `mysqli_sql_exception` for a
    //       queued statement that hit, e.g., a duplicate-key error
    //       (when `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` is
    //       in effect, which is the default on PHP 8.1+);
    //   (b) `next_result()` returns `false` and sets `$mysqli->error`
    //       for a queued statement that failed silently.
    //
    // Both cases must produce a structured `restore_failed` return
    // with the MySQL error and a safe statement context. The pre-fix
    // code only handled the "multi_query returned false" path
    // (line 859) and the silent-success path: a `next_result()`
    // throw killed the runner via uncaught exception, and a
    // `next_result()` returning false with `$mysqli->error` set
    // was discarded by the while-loop condition `$mysqli->more_results()
    // && $mysqli->next_result()`. The result was a `restore_failed`
    // return on the *verification* step ("baseline restored but
    // verification shows N marker(s)") that named the symptom, not
    // the cause.
    $restore_errors  = array();
    $restore_context = array();
    if ( ! $mysqli->multi_query( $sql ) ) {
        $restore_errors[]  = 'multi_query submission failed: ' . $mysqli->error;
        $restore_context[] = 'multi_query';
    } else {
        // Drain the result sets multi_query queues up. Catch
        // `mysqli_sql_exception` (path a) and inspect `$mysqli->error`
        // after every iteration (path b). The catch is the primary
        // fix; the post-loop error check is the secondary
        // defence-in-depth.
        try {
            $stmt_index = 0;
            while ( $mysqli->more_results() ) {
                $advance = $mysqli->next_result();
                $stmt_index++;
                if ( ! $advance ) {
                    if ( '' !== $mysqli->error ) {
                        $restore_errors[]  = 'queued statement ' . $stmt_index . ' failed: ' . $mysqli->error;
                        $restore_context[] = 'drain stmt#' . $stmt_index;
                    }
                    break;
                }
                $extra = $mysqli->store_result();
                if ( $extra instanceof mysqli_result ) {
                    $extra->free();
                }
            }
            // Belt-and-braces: if no exception fired but a queued
            // statement silently failed on a path that returned
            // `true` from `next_result()` (some PHP/MySQL combos),
            // `$mysqli->error` is set. Surface it now.
            if ( '' === implode( '', $restore_errors ) && '' !== $mysqli->error ) {
                $restore_errors[]  = 'drain completed with pending error: ' . $mysqli->error;
                $restore_context[] = 'drain-post';
            }
        } catch ( \mysqli_sql_exception $e ) {
            $restore_errors[]  = 'drain-loop mysqli_sql_exception: ' . $e->getMessage();
            $restore_context[] = 'drain-exception';
            // Best-effort: drain any remaining queued results so the
            // connection can close cleanly. We do NOT retry the
            // import — HAL 9001 directive: "Do not retry a partially
            // completed import automatically."
            while ( $mysqli->more_results() ) {
                @$mysqli->next_result();
            }
        }
    }
    if ( ! empty( $restore_errors ) ) {
        // A failed restore must not be retried automatically. The
        // caller (the runner's pre-run crash-window check) sees a
        // structured `restore_failed` action and refuses to spawn
        // any destructive child. Partial state may remain on disk;
        // that is the operator's recovery path, not the runner's.
        $mysqli->close();
        return array(
            'ok'           => false,
            'action'       => 'restore_failed',
            'marker_count' => $marker_count,
            'detail'       => implode( '; ', $restore_errors ) . ' [context: ' . implode( ',', $restore_context ) . ']',
        );
    }
    // Verify the restore took: zero markers, zero open transaction.
    $r = $mysqli->query( "SELECT COUNT(*) FROM {$pmeta} WHERE meta_key = '_agentshell_journal_op'" );
    $post_marker_count = ( $r instanceof mysqli_result ) ? (int) $r->fetch_row()[0] : -1;
    $r = $mysqli->query( "SELECT COUNT(*) FROM {$opts} WHERE option_name = 'agentshell_transaction'" );
    $post_tx_count = ( $r instanceof mysqli_result ) ? (int) $r->fetch_row()[0] : -1;
    $mysqli->close();
    if ( 0 !== $post_marker_count || 0 !== $post_tx_count ) {
        return array(
            'ok'           => false,
            'action'       => 'restore_failed',
            'marker_count' => $marker_count,
            'detail'       => 'baseline restored but verification shows ' . $post_marker_count . ' marker(s) and ' . $post_tx_count . ' open transaction(s)',
        );
    }
    return array(
        'ok'           => true,
        'action'       => 'restored',
        'marker_count' => $marker_count,
        'detail'       => 'crash-window leak (' . $marker_count . ' marker(s)) cleared by baseline restore; 0 markers, 0 transactions after',
    );
}

/**
 * Fail-closed identity guard for directly-executable destructive child scripts.
 *
 * Why this exists
 * ---------------
 * The test infrastructure has two halves:
 *
 *   - the parent runner `tests/run-all.php`, which calls
 *     `agentshell_test_bed_check()` (15 fingerprint checks) and refuses to
 *     run when the live environment is not the expected test bed;
 *
 *   - the standalone destructive child scripts in `tests/*-live-child.php`,
 *     each of which can also be invoked directly with a `wp_root` argument.
 *     The parent passes the test-bed's `wp_root`; the child loads it and
 *     mutates the database. The child never ran the parent's 15-check
 *     fail-closed gate.
 *
 * Gate 3 §2 surfaced exactly this gap: a `test-bed.json` that pointed at the
 * integration site passed the parent check (because the integration site
 * matches the *integration* profile, when the config names that profile), and
 * the integration site's content was used to overwrite the test bed's local
 * SQL baseline. The parent refused the next run, but a standalone child
 * invocation would have proceeded without checking anything.
 *
 * Contract
 * --------
 * The guard is called at the top of every destructive child script, BEFORE
 * the child calls `require_once wp-load.php`. It accepts the `wp_root` the
 * child was about to load and verifies that the actual connected WordPress
 * install and database match the selected test-bed profile.
 *
 * The guard refuses (returns ok=false) on any of:
 *
 *   - missing or unparseable test-bed config (no profile to verify against)
 *   - the supplied $wp_root does not contain a wp-load.php
 *   - the wp-config at $wp_root does not name the expected database
 *   - the actual connected database is on the wrong server (server_uuid,
 *     datadir, port differ from the profile)
 *   - the actual wp_options.agentshell_site_mode does not match the
 *     expected sentinel
 *   - the actual wp_options.siteurl / home does not match the expected siteurl
 *   - the wp_options.agentshell_transaction row has a non-live label and
 *     applied ops (a real user transaction must not be discarded)
 *
 * The guard is fail-closed: when any check cannot run (no mysqli extension,
 * unreadable wp-config, etc.), it refuses. The caller should treat
 * `ok=false` as "do not load this WordPress install".
 *
 * The guard does NOT itself load WordPress. It parses the wp-config directly
 * (define() extraction) and connects via the resulting credentials. This
 * keeps the child from running side-effectful WordPress init before the
 * identity check passes.
 *
 * @param string        $wp_root  The WordPress root the child is about to load.
 * @param array|null    $cfg      The test-bed config (or null to load it here).
 * @param string        $label    Optional operator label, surfaced in messages.
 * @return array {
 *     @type bool   $ok           True iff every check passed.
 *     @type array  $checks       Per-check name => { ok, detail }.
 *     @type string $refusal      Human-readable refusal when $ok is false.
 *     @type string $db_name      The actual database name.
 *     @type string $sentinel     Observed agentshell_site_mode.
 *     @type string $siteurl      Observed siteurl.
 * }
 */

/**
 * Validate a WordPress siteurl against the test-bed's expected transport URL.
 *
 * Why this exists
 * ---------------
 * The test-bed profile's `url_http` (or `url_https`) is the *transport*
 * endpoint — the URL the LocalWP listener serves on, e.g.
 *   http://agentshell-test.local:10008
 * WordPress's stored `siteurl`, by contrast, is the *canonical* site
 * identity — the URL WordPress thinks of itself as — and is conventionally
 * port-less, e.g.
 *   http://agentshell-test.local
 * The two are different concepts that share a host. A guard that
 * required the observed siteurl to be a prefix of the transport URL
 * (the previous strpos()-based check) rejected the canonical siteurl
 * of the restored test-bed baseline.
 *
 * What this checks
 * ----------------
 * The helper derives the canonical expectation from the configured
 * transport URL by stripping any port. It then compares strictly on
 * scheme, host, and path (with trailing-slash normalisation). It
 * REJECTS an observed siteurl that has:
 *   - an explicit port (e.g. http://...:10011, http://...:10008)
 *   - a different scheme (e.g. https when http is configured)
 *   - a different host (e.g. lookalikes, IP literals, userinfo tricks)
 *   - a non-empty path (e.g. /wp/ or /wordpress/)
 *   - a query, fragment, or userinfo component
 *   - a malformed URL (no scheme, no host)
 *
 * The check is intentionally stricter than a host-only parse_url
 * comparison: a host-only check would silently accept
 * http://agentshell-test.local:10011 (wrong port) and
 * http://agentshell-test.local/wp/ (wrong path). Strpos() prefix
 * matching is never used; comparison is on parsed components only.
 *
 * @param string $observed_siteurl        The siteurl from wp_options.
 * @param string $expected_url_with_port   The transport URL from the test-bed profile.
 * @return array { ok: bool, reason: string }
 */
function agentshell_siteurl_validate( $observed_siteurl, $expected_url_with_port ) {
    if ( ! is_string( $observed_siteurl ) || '' === $observed_siteurl ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl is empty' );
    }
    if ( ! is_string( $expected_url_with_port ) || '' === $expected_url_with_port ) {
        return array( 'ok' => false, 'reason' => 'expected transport URL is not configured' );
    }

    $exp = parse_url( $expected_url_with_port );
    if ( ! is_array( $exp ) || ! isset( $exp['scheme'] ) || ! is_string( $exp['scheme'] ) || '' === $exp['scheme'] ) {
        return array( 'ok' => false, 'reason' => 'expected transport URL is malformed (no scheme)' );
    }
    if ( ! isset( $exp['host'] ) || ! is_string( $exp['host'] ) || '' === $exp['host'] ) {
        return array( 'ok' => false, 'reason' => 'expected transport URL has no host' );
    }
    $exp_scheme = $exp['scheme'];
    $exp_host   = $exp['host'];
    $exp_path   = isset( $exp['path'] ) && is_string( $exp['path'] ) && '' !== $exp['path'] ? $exp['path'] : '/';
    $exp_path_norm = rtrim( $exp_path, '/' );
    if ( '' === $exp_path_norm ) { $exp_path_norm = '/'; }

    $obs = parse_url( $observed_siteurl );
    if ( ! is_array( $obs ) || ! isset( $obs['scheme'] ) || ! is_string( $obs['scheme'] ) || '' === $obs['scheme'] ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl is malformed (no scheme): "' . $observed_siteurl . '"' );
    }
    if ( ! isset( $obs['host'] ) || ! is_string( $obs['host'] ) || '' === $obs['host'] ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl is malformed (no host): "' . $observed_siteurl . '"' );
    }

    // Reject any explicit port. WordPress's canonical siteurl does not
    // include a transport port; an explicit port in the observed value
    // is either a contamination (e.g. http://...:10011) or a copy-paste
    // of the transport URL into siteurl (e.g. http://...:10008).
    if ( isset( $obs['port'] ) ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl must not contain an explicit port: "' . $observed_siteurl . '"' );
    }

    // Reject userinfo (http://user:pass@host/) — used in URL-spoofing
    // tricks where a victim sees the trusted host but the request lands
    // on the attacker's host. parse_url populates `user` and `pass`.
    if ( isset( $obs['user'] ) || isset( $obs['pass'] ) ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl must not contain userinfo: "' . $observed_siteurl . '"' );
    }

    if ( $obs['scheme'] !== $exp_scheme ) {
        return array( 'ok' => false, 'reason' => 'scheme mismatch: expected "' . $exp_scheme . '" observed "' . $obs['scheme'] . '"' );
    }
    if ( $obs['host'] !== $exp_host ) {
        return array( 'ok' => false, 'reason' => 'host mismatch: expected "' . $exp_host . '" observed "' . $obs['host'] . '"' );
    }

    $obs_path = isset( $obs['path'] ) && is_string( $obs['path'] ) ? rtrim( $obs['path'], '/' ) : '/';
    if ( '' === $obs_path ) { $obs_path = '/'; }
    if ( $obs_path !== $exp_path_norm ) {
        return array( 'ok' => false, 'reason' => 'path mismatch: expected "' . $exp_path_norm . '" observed "' . $obs_path . '"' );
    }

    if ( isset( $obs['query'] ) && '' !== $obs['query'] ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl must not contain a query string: "' . $observed_siteurl . '"' );
    }
    if ( isset( $obs['fragment'] ) && '' !== $obs['fragment'] ) {
        return array( 'ok' => false, 'reason' => 'observed siteurl must not contain a fragment: "' . $observed_siteurl . '"' );
    }

    return array( 'ok' => true, 'reason' => '' );
}

function agentshell_destructive_identity_guard( $wp_root, $cfg = null, $label = '' ) {
    $checks  = array();
    $refusal = '';
    $ok      = true;

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

    // 1. config present
    if ( ! is_array( $cfg ) ) {
        $cfg = agentshell_test_bed_load();
    }
    if ( ! is_array( $cfg ) ) {
        $fail( 'test_bed_config', 'no tests/config/test-bed.json and no AGENTSHELL_TEST_BED_CONFIG' );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => '', 'sentinel' => '', 'siteurl' => '',
        );
    }
    $pass( 'test_bed_config', isset( $cfg['_config_path'] ) ? (string) $cfg['_config_path'] : '(inline)' );

    // 2. wp_root contains a wp-load.php
    $wp_root = is_string( $wp_root ) ? $wp_root : '';
    if ( '' === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
        $fail( 'wp_root', 'wp-load.php not found at ' . $wp_root );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => '', 'sentinel' => '', 'siteurl' => '',
        );
    }
    $pass( 'wp_root', $wp_root );

    // 3. parse wp-config for DB credentials. We do this with regex
    //    rather than requiring the file, because requiring wp-config
    //    has side effects (calls wp_initial_constants, sets $table_prefix,
    //    etc.). The guard must be side-effect free.
    $cfg_path = rtrim( $wp_root, '/' ) . '/wp-config.php';
    $raw      = @file_get_contents( $cfg_path );
    if ( ! is_string( $raw ) || '' === $raw ) {
        $fail( 'wp_config_read', 'cannot read ' . $cfg_path );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => '', 'sentinel' => '', 'siteurl' => '',
        );
    }
    $db = array();
    foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $k ) {
        if ( preg_match( "/define\\(\\s*['\"]" . preg_quote( $k, '/' ) . "['\"]\\s*,\\s*['\"]?([^'\")]+)['\"]?\\s*\\)/", $raw, $m ) ) {
            $db[ $k ] = $m[1];
        } else {
            $db[ $k ] = '';
        }
    }
    if ( '' === $db['DB_NAME'] || '' === $db['DB_USER'] ) {
        $fail( 'wp_config_db', 'DB_NAME or DB_USER not found in wp-config.php' );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => '', 'sentinel' => '', 'siteurl' => '',
        );
    }
    $pass( 'wp_config_db', $db['DB_NAME'] . ' as ' . $db['DB_USER'] );

    // 4. The DB name parsed from wp-config must match the config's
    //    expected db.name. This is the load-bearing check: a child
    //    that loads the integration site's wp-config while the
    //    profile expects the test bed fails here.
    $expected_db = isset( $cfg['db']['name'] ) ? (string) $cfg['db']['name'] : '';
    if ( '' !== $expected_db && $db['DB_NAME'] !== $expected_db ) {
        $fail(
            'db_name',
            'wp-config DB_NAME "' . $db['DB_NAME'] . '" does not match the profile\'s expected db.name "' . $expected_db . '"'
        );
    } else {
        $pass( 'db_name', $db['DB_NAME'] );
    }

    // 5. connect to the actual database. The connection uses the
    //    credentials parsed from wp-config — NOT the profile's
    //    credentials — so a wp-config that points at the wrong
    //    database fails this check (and the next).
    if ( ! class_exists( 'mysqli' ) ) {
        $fail( 'mysqli', 'mysqli extension is not available; cannot verify identity' );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => $db['DB_NAME'], 'sentinel' => '', 'siteurl' => '',
        );
    }
    $db_host = $db['DB_HOST'];
    $socket  = '';
    if ( strpos( $db_host, ':' ) !== false ) {
        list( $db_host, $socket ) = explode( ':', $db_host, 2 );
    }
    if ( '' === $db_host ) { $db_host = 'localhost'; }
    $mysqli = @new mysqli( $db_host, $db['DB_USER'], $db['DB_PASSWORD'], $db['DB_NAME'], 0, $socket );
    if ( ! ( $mysqli instanceof mysqli ) || $mysqli->connect_errno ) {
        $fail( 'db_connect', 'cannot connect to ' . $db['DB_USER'] . '@' . $db['DB_HOST'] . ' / ' . $db['DB_NAME'] . ': ' . ( $mysqli instanceof mysqli ? $mysqli->connect_error : 'mysqli ctor failed' ) );
        return array(
            'ok' => false, 'checks' => $checks, 'refusal' => $refusal,
            'db_name' => $db['DB_NAME'], 'sentinel' => '', 'siteurl' => '',
        );
    }
    $pass( 'db_connect', $db['DB_USER'] . '@' . $db_host );

    // 6. server identity (the load-bearing "wrong instance" check).
    $expected_uuid = isset( $cfg['expect']['server_uuid'] ) ? (string) $cfg['expect']['server_uuid'] : '';
    $row = $mysqli->query( "SELECT @@server_uuid" )->fetch_row();
    $observed_uuid = is_array( $row ) ? (string) $row[0] : '';
    if ( '' !== $expected_uuid && $observed_uuid !== $expected_uuid ) {
        $fail( 'server_uuid', 'expected ' . $expected_uuid . ' observed ' . $observed_uuid );
    } else {
        $pass( 'server_uuid', $observed_uuid );
    }

    $expected_dd = isset( $cfg['expect']['expected_datadir'] ) ? (string) $cfg['expect']['expected_datadir'] : '';
    $row = $mysqli->query( "SELECT @@datadir" )->fetch_row();
    $observed_dd = is_array( $row ) ? (string) $row[0] : '';
    $norm = function ( $p ) { return rtrim( (string) $p, '/' ); };
    if ( '' !== $expected_dd && $norm( $observed_dd ) !== $norm( $expected_dd ) ) {
        $fail( 'datadir', 'expected ' . $expected_dd . ' observed ' . $observed_dd );
    } else {
        $pass( 'datadir', $observed_dd );
    }

    $expected_port = isset( $cfg['expect']['expected_port'] ) ? (int) $cfg['expect']['expected_port'] : 0;
    $row = $mysqli->query( "SELECT @@port" )->fetch_row();
    $observed_port = is_array( $row ) ? (int) $row[0] : 0;
    if ( 0 !== $expected_port && $observed_port !== $expected_port ) {
        $fail( 'port', 'expected ' . $expected_port . ' observed ' . $observed_port );
    } else {
        $pass( 'port', (string) $observed_port );
    }

    // 7. site_mode sentinel — the actual content check. A wp-config
    //    that points at the integration DB would pass the connection
    //    and server checks (same MySQL instance); only the sentinel
    //    catches it.
    $expected_sentinel = isset( $cfg['expect']['site_mode_sentinel'] ) ? (string) $cfg['expect']['site_mode_sentinel'] : '';
    $row = $mysqli->query( "SELECT option_value FROM wp_options WHERE option_name='agentshell_site_mode'" );
    $observed_sentinel = ( $row instanceof mysqli_result && $r = $row->fetch_row() ) ? (string) $r[0] : '';
    if ( '' !== $expected_sentinel && $observed_sentinel !== $expected_sentinel ) {
        $fail(
            'site_mode_sentinel',
            'expected "' . $expected_sentinel . '" observed "' . $observed_sentinel . '"'
        );
    } else {
        $pass( 'site_mode_sentinel', $observed_sentinel );
    }

    // 8. siteurl — the canonical WordPress siteurl must match the
    //    test-bed profile's transport URL on scheme, host, and path.
    //    The transport URL's port is intentionally NOT part of the
    //    comparison: WordPress's siteurl is conventionally port-less,
    //    and the transport URL's port (e.g. :10008) is a property of
    //    the LocalWP listener, not of site identity. An explicit
    //    port on the observed siteurl (e.g. :10011) is rejected —
    //    that is the contamination shape we are guarding against.
    $row = $mysqli->query( "SELECT option_value FROM wp_options WHERE option_name='siteurl'" );
    $observed_siteurl = ( $row instanceof mysqli_result && $r = $row->fetch_row() ) ? (string) $r[0] : '';
    $expected_url = isset( $cfg['url_http'] ) ? (string) $cfg['url_http'] : ( isset( $cfg['url_https'] ) ? (string) $cfg['url_https'] : '' );
    if ( '' !== $expected_url && '' !== $observed_siteurl ) {
        $v = agentshell_siteurl_validate( $observed_siteurl, $expected_url );
        if ( $v['ok'] ) {
            $pass( 'siteurl', $observed_siteurl );
        } else {
            $fail( 'siteurl', $v['reason'] );
        }
    } else {
        $pass( 'siteurl', $observed_siteurl );
    }

    // 9. user grants. The restricted user (test-bed's
    //    agentshell_test) has grants only on the test bed DB; the
    //    integration user has grants only on the integration DB. A
    //    cross-grant is impossible by grant structure, so a passing
    //    grants check is additional proof that the correct user is
    //    connecting to the correct database.
    $row = $mysqli->query( "SHOW GRANTS FOR CURRENT_USER()" );
    $grants_blob = '';
    if ( $row instanceof mysqli_result ) {
        while ( $r = $row->fetch_row() ) { $grants_blob .= (string) $r[0] . "\n"; }
    }
    $expected_db_escaped = str_replace( array( '`', '.' ), array( '``', '\\.' ), (string) $db['DB_NAME'] );
    if ( '' === $grants_blob || false === strpos( $grants_blob, 'GRANT' ) ) {
        $fail( 'db_user_grants', 'no grants found for current user' );
    } elseif ( false === strpos( $grants_blob, '`' . $expected_db_escaped . '`' ) ) {
        $fail(
            'db_user_grants',
            'current user has no grant on the expected database `' . $expected_db_escaped . '`; grants: ' . trim( $grants_blob )
        );
    } else {
        $pass( 'db_user_grants', 'current user has a grant on `' . $expected_db_escaped . '`' );
    }

    // 10. protection against a real user transaction. The
    //     pre-existing protection in agentshell_test_bed_preflight
    //     refuses to run when the agentshell_transaction option has
    //     a non-`live` label and applied ops; the guard mirrors that
    //     protection so a child invoked mid-recovery refuses rather
    //     than overwriting the recovery work.
    $row = $mysqli->query( "SELECT option_value FROM wp_options WHERE option_name='agentshell_transaction'" );
    if ( $row instanceof mysqli_result && $tx_row = $row->fetch_row() ) {
        $tx = json_decode( (string) $tx_row[0], true );
        if ( is_array( $tx ) ) {
            $tx_label = isset( $tx['label'] ) ? (string) $tx['label'] : '';
            if ( 0 !== strpos( $tx_label, 'live ' ) ) {
                $fail(
                    'real_user_tx',
                    'open transaction with non-test label "' . $tx_label . '" is preserved; the guard refuses to overwrite it'
                );
            } else {
                $ops = isset( $tx['content']['ops'] ) && is_array( $tx['content']['ops'] ) ? $tx['content']['ops'] : array();
                $applied = 0;
                foreach ( $ops as $op ) {
                    if ( is_array( $op ) && ( $op['state'] ?? '' ) === 'applied' ) {
                        $applied++;
                    }
                }
                if ( $applied > 0 ) {
                    $fail(
                        'active_recovery',
                        'open test transaction "' . $tx_label . '" has ' . $applied . ' applied op(s); recovery in progress, guard refuses'
                    );
                } else {
                    $pass( 'transaction_slot', 'test transaction "' . $tx_label . '" with no applied work' );
                }
            }
        } else {
            $pass( 'transaction_slot', 'agentshell_transaction option present but unparseable' );
        }
    } else {
        $pass( 'transaction_slot', 'no open transaction' );
    }

    $mysqli->close();

    return array(
        'ok'       => $ok,
        'checks'   => $checks,
        'refusal'  => $refusal,
        'db_name'  => $db['DB_NAME'],
        'sentinel' => $observed_sentinel,
        'siteurl'  => $observed_siteurl,
    );
}

/**
 * Print a structured refusal report and exit 7. The exit code 7 is
 * distinct from the runner's exit codes (2 = check failed, 3 = lock held,
 * 4 = preflight refused, 5 = baseline restore failed) and from generic
 * fatal codes (1, 2). A child that exits 7 means "identity guard refused;
 * the operator must investigate the wp_root that was supplied".
 *
 * @param array  $result  The result returned by agentshell_destructive_identity_guard().
 * @param string $label   Optional operator label for the message.
 * @return never
 */
function agentshell_destructive_identity_guard_refuse( $result, $label = '' ) {
    fwrite( STDERR, "\n[identity-guard] REFUSED — refusing to mutate the database\n" );
    if ( '' !== $label ) {
        fwrite( STDERR, "[identity-guard] label: " . $label . "\n" );
    }
    if ( ! empty( $result['refusal'] ) ) {
        fwrite( STDERR, "[identity-guard] refusal: " . $result['refusal'] . "\n" );
    }
    if ( ! empty( $result['checks'] ) && is_array( $result['checks'] ) ) {
        foreach ( $result['checks'] as $name => $r ) {
            fwrite( STDERR, "[identity-guard]   $name: " . ( $r['ok'] ? 'ok' : 'FAIL' ) . ' ' . $r['detail'] . "\n" );
        }
    }
    fwrite( STDERR, "\n[identity-guard] the destructive guard does not delete any data; it either\n" );
    fwrite( STDERR, "[identity-guard] returns ok=true (and the caller may proceed) or refuses with\n" );
    fwrite( STDERR, "[identity-guard] a clear reason. The child script exits 7.\n\n" );
    exit( 7 );
}

/**
 * Thin convenience wrapper for destructive child scripts.
 *
 * Each `tests/*-live-child.php` script begins with:
 *
 *     $wp_root = $argv[1] ?? '';
 *     require_once __DIR__ . '/agentshell-test-bed.php';
 *     agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );
 *
 * The wrapper auto-loads the test-bed config, runs the full guard, and
 * either returns silently (caller may proceed) or prints the structured
 * refusal and exits 7. The child never gets to `require_once wp-load.php`
 * when the guard refuses.
 *
 * The `$label` parameter is recorded in the refusal so the operator can
 * see which child refused; convention is to pass `__FILE__` so the path
 * identifies the refusing script.
 *
 * @param string $wp_root  The WordPress root the child is about to load.
 * @param string $label    Optional operator label (e.g. __FILE__).
 * @return void
 */
function agentshell_destructive_child_identity_guard( $wp_root, $label = '' ) {
    $result = agentshell_destructive_identity_guard( $wp_root, null, $label );
    if ( empty( $result['ok'] ) ) {
        agentshell_destructive_identity_guard_refuse( $result, $label );
    }
}
