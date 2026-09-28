<?php
/**
 * Daemon version-compatibility policy.
 *
 * Pure functions for tests/daemon-version-test.php and the live
 * daemon. The daemon class calls agentshell_daemon_version_status()
 * during startup and routes the result to stderr at the appropriate
 * severity.
 *
 * Compatibility rule (semantic-versioning discipline):
 *
 *   same major (1.x == 1.y):  compatible
 *     exact:                  info
 *     server newer minor:     notice
 *     server older minor:     notice
 *
 *   different major:          warning (still compatible — we do not
 *                                    hard-exit on major mismatch;
 *                                    operators may be in an upgrade
 *                                    window)
 *
 *   missing / empty:          warning
 *   malformed:                warning
 *
 * Rationale: the pre-fix behaviour hardcoded an exact-version string
 * ("1.1.0") and printed a loud warning on every startup when the server
 * reported anything else. That made every boot of a current
 * installation scream about a 1.1.0 / 1.3.0 mismatch that had no
 * actual operational impact. The new rule matches the project's
 * actual versioning discipline (semver; plugin at 1.3.0, daemon at
 * 1.0.0, same major) and only warns loudly on the cases that matter.
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
            'compatible'   => true,
            'relationship' => 'major_mismatch',
            'level'        => 'warning',
            'message'      => sprintf(
                'server major version %d does not match daemon major version %d; verify both are compatible',
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