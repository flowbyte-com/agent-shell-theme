<?php
/**
 * Daemon version-compatibility policy.
 *
 * Pure functions for tests/daemon-version-test.php and the live
 * daemon. The daemon class calls agentshell_daemon_version_status()
 * during startup and routes the result to stderr at the appropriate
 * severity.
 *
 * Three version axes, and they are not the same thing
 * -------------------------------------------------
 * 1. **Protocol version** (`2025-03-26`) — the MCP wire format. Both
 *    ends declare it. It is negotiated in the `initialize` handshake:
 *    the client sends the version it speaks, the server answers with
 *    the version it will use. A different answer means the two ends
 *    speak different dialects, so the daemon refuses.
 * 2. **Product version** (`1.3.0`) — the AgentShell release the daemon
 *    and the WordPress plugin belong to. They are released together and
 *    carry the same number; a test keeps them equal so they cannot
 *    drift apart unnoticed.
 * 3. **Tool-surface version** — implied by the product version. A
 *    different *major* product version means a breaking change to the
 *    tool surface by semantic-versioning convention.
 *
 * Compatibility rule (semantic-versioning discipline):
 *
 *   same major (1.x == 1.y):  compatible
 *     exact:                  info
 *     server newer minor:     notice
 *     server older minor:     notice
 *
 *   different major:          INCOMPATIBLE — the daemon refuses to serve
 *                                    requests unless the operator
 *                                    overrides it explicitly
 *                                    (AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1)
 *
 *   missing / empty:          warning (compatible: the server did not
 *                                    say, which is not the same as
 *                                    saying something incompatible)
 *   malformed:                warning
 *
 * Rationale: the pre-fix behaviour hardcoded an exact-version string
 * ("1.1.0") and printed a loud warning on every startup when the server
 * reported anything else. That made every boot of a current
 * installation scream about a 1.1.0 / 1.3.0 mismatch that had no
 * actual operational impact. The new rule matches the project's
 * actual versioning discipline (semver; the daemon and the plugin ship
 * together at the same product version) and only refuses on the case
 * that genuinely matters: a major version apart.
 *
 * @param string|null $server_version Reported server version (from MCP initialize).
 * @param string      $daemon_version  This daemon's own version.
 * @return array{compatible: bool, relationship: string, level: string, message: string}
 */
function agentshell_daemon_version_status( $server_version, $daemon_version ) {
    if ( ! is_string( $server_version ) || $server_version === '' ) {
        return array(
            'compatible'   => true,
            'relationship' => 'missing',
            'level'        => 'warning',
            'message'      => 'server did not report a version',
        );
    }

    $server_parts = explode( '.', $server_version );
    $daemon_parts = explode( '.', $daemon_version );

    if ( count( $server_parts ) < 1 || count( $daemon_parts ) < 1
         || ! is_numeric( $server_parts[0] ) || ! is_numeric( $daemon_parts[0] ) ) {
        return array(
            'compatible'   => true,
            'relationship' => 'malformed',
            'level'        => 'warning',
            'message'      => 'server version is not parseable',
        );
    }

    $server_major = (int) $server_parts[0];
    $daemon_major = (int) $daemon_parts[0];

    if ( $server_major !== $daemon_major ) {
        return array(
            'compatible'   => false,
            'relationship' => 'major_mismatch',
            'level'        => 'error',
            'message'      => sprintf(
                'server major version %d does not match daemon major version %d; '
                    . 'a major version apart means the tool surface may differ, so the '
                    . 'daemon refuses to serve requests. Upgrade the daemon, or set '
                    . 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1 to proceed anyway',
                $server_major,
                $daemon_major
            ),
        );
    }

    if ( version_compare( $server_version, $daemon_version, '==' ) ) {
        return array(
            'compatible'   => true,
            'relationship' => 'exact',
            'level'        => 'info',
            'message'      => 'exact version match',
        );
    }

    if ( version_compare( $server_version, $daemon_version, '>' ) ) {
        return array(
            'compatible'   => true,
            'relationship' => 'server_newer',
            'level'        => 'notice',
            'message'      => sprintf(
                'server %s is newer than daemon %s; features beyond daemon coverage are tolerated',
                $server_version,
                $daemon_version
            ),
        );
    }

    return array(
        'compatible'   => true,
        'relationship' => 'server_older',
        'level'        => 'notice',
        'message'      => sprintf(
            'server %s is older than daemon %s; daemon features may be unavailable server-side',
            $server_version,
            $daemon_version
        ),
    );
}
/**
 * The protocol version's compatibility status.
 *
 * The MCP `initialize` handshake is a negotiation: the client states the
 * protocol version it speaks, and the server answers with the version it
 * will use. This daemon implements exactly one protocol version, so a
 * server that answers with a different one is speaking a dialect the
 * daemon does not implement — the response cannot be trusted to mean the
 * same thing.
 *
 * A server that does not answer with a protocol version at all is *not*
 * treated as incompatible: the handshake may predate the field, and
 * "the server did not say" is not "the server said something else".
 *
 * @param string|null $server_protocol  Protocol version the server answered with.
 * @param string      $daemon_protocol  The protocol version this daemon speaks.
 * @return array{compatible: bool, relationship: string, level: string, message: string}
 */
function agentshell_daemon_protocol_status( $server_protocol, $daemon_protocol ) {
    if ( ! is_string( $server_protocol ) || '' === $server_protocol ) {
        return array(
            'compatible'   => true,
            'relationship' => 'missing',
            'level'        => 'warning',
            'message'      => 'server did not report a protocol version',
        );
    }

    if ( ! is_string( $daemon_protocol ) || '' === $daemon_protocol ) {
        return array(
            'compatible'   => true,
            'relationship' => 'missing',
            'level'        => 'warning',
            'message'      => 'this daemon has no protocol version configured',
        );
    }

    if ( $server_protocol === $daemon_protocol ) {
        return array(
            'compatible'   => true,
            'relationship' => 'exact',
            'level'        => 'info',
            'message'      => 'exact protocol version match',
        );
    }

    return array(
        'compatible'   => false,
        'relationship' => 'mismatch',
        'level'        => 'error',
        'message'      => sprintf(
            'server protocol %s does not match daemon protocol %s; the two ends speak '
                . 'different dialects of MCP, so the daemon refuses to serve requests. '
                . 'Upgrade the daemon, or set AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1 to '
                . 'proceed anyway',
            $server_protocol,
            $daemon_protocol
        ),
    );
}

/**
 * The exit code the daemon uses when it refuses to serve because the two
 * ends are incompatible.
 *
 * It is distinct from the other exit codes so an operator (or a supervisor)
 * can tell "this daemon cannot talk to that server" from "the config file
 * is missing" (1) or "the config file is invalid" (2).
 */
function agentshell_daemon_incompatible_exit_code() {
    return 4;
}

/**
 * Whether the operator has explicitly accepted an incompatible pairing.
 *
 * The override is an environment variable rather than a config-file key on
 * purpose: it is a decision about *this* run, made by a human at the
 * console, and it must not be a setting that silently persists in a file
 * nobody remembers writing.
 */
function agentshell_daemon_incompatible_override() {
    $value = getenv( 'AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER' );
    if ( ! is_string( $value ) ) {
        return false;
    }
    $value = strtolower( trim( $value ) );
    return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
}
