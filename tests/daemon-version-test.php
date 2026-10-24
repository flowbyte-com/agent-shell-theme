<?php
/**
 * Daemon version-negotiation tests.
 *
 * Pre-fix state: the daemon hardcoded EXPECTED_SERVER_VERSION = '1.1.0'
 * and compared the actual server-reported version with strict equality,
 * printing a VERSION MISMATCH warning to stderr on every startup when
 * the numbers diverged. The server is at 1.3.0; the warning fired on
 * every boot.
 *
 * Post-fix: the daemon uses a semantic compatibility rule. Same major
 * version is compatible regardless of minor. A *different* major is
 * incompatible — a major version apart means the tool surface may differ
 * — and the daemon refuses to serve requests unless the operator
 * overrides it for the run. Malformed / missing versions log a warning
 * but do not exit: the server not saying is not the server saying
 * something incompatible.
 *
 * Three version axes are kept apart (see daemon-version.php): the MCP
 * *protocol* version, the *product* version the daemon and the plugin
 * share, and the tool surface the product version implies.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp-daemon/daemon-version.php';

echo "daemon version negotiation tests\n";

// ─── tests ─────────────────────────────────────────────────────

Agentshell_Test::run(
    'exact match is compatible',
    function () {
        $r = agentshell_daemon_version_status( '1.0.0', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'exact', $r['relationship'] );
        Agentshell_Test::assert_equals( 'info', $r['level'] );
    }
);

Agentshell_Test::run(
    'newer minor same major is compatible (no scary warning)',
    function () {
        // Daemon is 1.0.0; server is 1.3.0 (the production state). Must
        // be compatible and produce a notice, not a warning.
        $r = agentshell_daemon_version_status( '1.3.0', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'server_newer', $r['relationship'] );
        Agentshell_Test::assert_equals( 'notice', $r['level'] );
        // Notice is below warning: must NOT be the loud level.
        Agentshell_Test::assert_not_equals( 'warning', $r['level'] );
    }
);

Agentshell_Test::run(
    'older minor same major is compatible',
    function () {
        $r = agentshell_daemon_version_status( '1.0.0', '1.3.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'server_older', $r['relationship'] );
        Agentshell_Test::assert_equals( 'notice', $r['level'] );
    }
);

Agentshell_Test::run(
    'different major is incompatible: the daemon refuses to serve',
    function () {
        $r = agentshell_daemon_version_status( '2.0.0', '1.0.0' );
        Agentshell_Test::assert_false( $r['compatible'], 'a major version apart is not compatible' );
        Agentshell_Test::assert_equals( 'major_mismatch', $r['relationship'] );
        Agentshell_Test::assert_equals( 'error', $r['level'] );
        Agentshell_Test::assert_true(
            false !== stripos( $r['message'], 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER' ),
            'the refusal must name the operator override'
        );
    }
);

Agentshell_Test::run(
    'the refusal exit code is distinct from the configuration errors',
    function () {
        Agentshell_Test::assert_equals( 4, agentshell_daemon_incompatible_exit_code() );
    }
);

Agentshell_Test::run(
    'the operator override is off unless it is explicitly set',
    function () {
        $previous = getenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER' );
        putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER' );
        Agentshell_Test::assert_false( agentshell_daemon_incompatible_override() );

        putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=0' );
        Agentshell_Test::assert_false( agentshell_daemon_incompatible_override(), '0 must not enable the override' );

        putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1' );
        Agentshell_Test::assert_true( agentshell_daemon_incompatible_override() );

        putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=true' );
        Agentshell_Test::assert_true( agentshell_daemon_incompatible_override() );

        if ( false === $previous ) {
            putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER' );
        } else {
            putenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=' . $previous );
        }
    }
);

// ── the protocol version: the wire format ─────────────────────────────────
Agentshell_Test::run(
    'an identical protocol version is compatible',
    function () {
        $r = agentshell_daemon_protocol_status( '2025-03-26', '2025-03-26' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'exact', $r['relationship'] );
        Agentshell_Test::assert_equals( 'info', $r['level'] );
    }
);

Agentshell_Test::run(
    'a different protocol version is incompatible',
    function () {
        $r = agentshell_daemon_protocol_status( '2024-11-05', '2025-03-26' );
        Agentshell_Test::assert_false( $r['compatible'], 'a different dialect is not compatible' );
        Agentshell_Test::assert_equals( 'mismatch', $r['relationship'] );
        Agentshell_Test::assert_equals( 'error', $r['level'] );
    }
);

Agentshell_Test::run(
    'a server that reports no protocol version is a warning, not a refusal',
    function () {
        // "The server did not say" is not "the server said something else".
        $r = agentshell_daemon_protocol_status( null, '2025-03-26' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'missing', $r['relationship'] );
        Agentshell_Test::assert_equals( 'warning', $r['level'] );
    }
);

// ── the product version cannot drift between the two halves ───────────────
Agentshell_Test::run(
    'the daemon and the MCP plugin carry the same product version',
    function () {
        $daemon_source = (string) file_get_contents( __DIR__ . '/../agentshell-mcp-daemon/daemon.php' );
        Agentshell_Test::assert_true(
            1 === preg_match( "/const\s+DAEMON_VERSION\s*=\s*'([^']+)'/", $daemon_source, $m ),
            'the daemon must declare its version'
        );
        $daemon_version = $m[1];

        $plugin_source = (string) file_get_contents( __DIR__ . '/../agentshell-mcp/agentshell-mcp.php' );
        Agentshell_Test::assert_true(
            1 === preg_match( "/define\(\s*'AGENTSHELL_MCP_VERSION',\s*'([^']+)'\s*\)/", $plugin_source, $pm ),
            'the plugin must declare its version'
        );

        Agentshell_Test::assert_equals(
            $pm[1],
            $daemon_version,
            'the daemon and the plugin ship together and must carry the same version'
        );
    }
);

Agentshell_Test::run(
    'the daemon and the plugin declare the same protocol version',
    function () {
        $daemon_source = (string) file_get_contents( __DIR__ . '/../agentshell-mcp-daemon/daemon.php' );
        Agentshell_Test::assert_true(
            1 === preg_match( "/const\s+PROTOCOL_VERSION\s*=\s*'([^']+)'/", $daemon_source, $m ),
            'the daemon must declare the protocol version it speaks'
        );

        $server_source = (string) file_get_contents( __DIR__ . '/../agentshell-mcp/includes/class-server.php' );
        Agentshell_Test::assert_true(
            1 === preg_match( "/const\s+PROTOCOL_VERSION\s*=\s*'([^']+)'/", $server_source, $sm ),
            'the server must declare the protocol version it speaks'
        );

        Agentshell_Test::assert_equals(
            $sm[1],
            $m[1],
            'the two ends must speak the same protocol version'
        );
    }
);

// ── stdout stays a JSON-RPC channel ───────────────────────────────────────
Agentshell_Test::run(
    'the daemon writes no diagnostics to stdout',
    function () {
        // stdout carries the JSON-RPC stream. Anything printed there is
        // protocol pollution: a strict client parses it and fails. The
        // daemon writes to stderr (fwrite(STDERR, …)) and to the
        // transport's line writer, so no echo/print may appear in its
        // sources.
        $sources = array(
            __DIR__ . '/../agentshell-mcp-daemon/daemon.php',
            __DIR__ . '/../agentshell-mcp-daemon/daemon-version.php',
            __DIR__ . '/../agentshell-mcp-daemon/src/Client.php',
            __DIR__ . '/../agentshell-mcp-daemon/src/JsonRpc.php',
            __DIR__ . '/../agentshell-mcp-daemon/src/Transport.php',
        );
        $offenders = array();
        foreach ( $sources as $path ) {
            $body = (string) file_get_contents( $path );
            if ( preg_match( '/^\s*(echo|print|printf|var_dump|print_r)\s/mi', $body ) ) {
                $offenders[] = basename( $path );
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $offenders,
            'these daemon sources print to stdout: ' . implode( ', ', $offenders )
        );
    }
);

Agentshell_Test::run(
    'missing server version is a warning, not exit',
    function () {
        $r = agentshell_daemon_version_status( null, '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'missing', $r['relationship'] );
        Agentshell_Test::assert_equals( 'warning', $r['level'] );
    }
);

Agentshell_Test::run(
    'empty server version is a warning',
    function () {
        $r = agentshell_daemon_version_status( '', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'missing', $r['relationship'] );
        Agentshell_Test::assert_equals( 'warning', $r['level'] );
    }
);

Agentshell_Test::run(
    'malformed server version is a warning',
    function () {
        $r = agentshell_daemon_version_status( 'not-a-version', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'malformed', $r['relationship'] );
        Agentshell_Test::assert_equals( 'warning', $r['level'] );
    }
);

Agentshell_Test::run(
    'pre-fix case (server 1.3.0 against hardcoded expectation 1.1.0) now resolves as notice',
    function () {
        // This is the case that fired the WARNING on every pre-fix
        // boot. After this commit the daemon no longer hardcodes 1.1.0;
        // it compares against its own version (1.0.0). So 1.3.0 vs 1.0.0
        // is server_newer → notice, not warning.
        $r = agentshell_daemon_version_status( '1.3.0', '1.0.0' );
        Agentshell_Test::assert_equals( 'notice', $r['level'] );
        Agentshell_Test::assert_not_equals( 'warning', $r['level'] );
    }
);

Agentshell_Test::run(
    'semver two-part versions are accepted',
    function () {
        $r = agentshell_daemon_version_status( '1.0', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
    }
);

Agentshell_Test::run(
    'pre-release tags in the server version are tolerated',
    function () {
        $r = agentshell_daemon_version_status( '1.3.0-rc.1', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'] );
        Agentshell_Test::assert_equals( 'server_newer', $r['relationship'] );
    }
);