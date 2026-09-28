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
 * version is compatible regardless of minor; different major emits a
 * loud warning but does not exit. Malformed / missing versions log
 * a warning but do not exit.
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
    'different major is loud-warning but not exit',
    function () {
        $r = agentshell_daemon_version_status( '2.0.0', '1.0.0' );
        Agentshell_Test::assert_true( $r['compatible'], 'major mismatch must still be treated as compatible (no hard exit on startup)' );
        Agentshell_Test::assert_equals( 'major_mismatch', $r['relationship'] );
        Agentshell_Test::assert_equals( 'warning', $r['level'] );
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