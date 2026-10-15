<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A MySQL/MariaDB named advisory lock, scoped to a single post id.
 *
 * ## Why this exists, and not the previous add_option-based lock
 *
 * The previous recovery engine had a single concurrency guard: the
 * transaction row's CAS claim, refreshed every 120 seconds. That
 * guard is necessary but not sufficient. A `before_delete_post`
 * hook from a third-party plugin can take longer than 120 seconds,
 * and a `KILL -9` of the owning process between an `add_option`
 * lock write and the post delete leaves the lock row in
 * `wp_options` until cleared out-of-band. The PHP `finally` block
 * that should release it does not run when the process is
 * signal-killed; the SQL transaction only protects against a
 * crash, not against a concurrent caller that has already
 * swapped the row.
 *
 * A MySQL/MariaDB named advisory lock is what fits. The
 * `GET_LOCK(name, timeout)` function acquires a named lock tied
 * to the *current connection*; `RELEASE_LOCK(name)` releases it;
 * and the lock is released implicitly when the connection closes
 * — which is what happens on `KILL -9` (the server releases
 * every lock held by the dropped connection), on PHP-FPM worker
 * death, and on connection timeout. The lock is not stored in
 * `wp_options` (so a stuck lock cannot accumulate over restarts)
 * and not tied to the 120-second claim (so a long-running hook
 * above 120 seconds does not race the claim's takeover window).
 *
 * ## Fail-closed
 *
 * The probe establishes whether the database can service
 * `GET_LOCK` calls. A `NULL` return (database error) is the
 * dangerous one: the server cannot service lock requests, and
 * silently downgrading to an unguarded path would be the exact
 * failure mode the brief warns against. On an unavailable lock
 * service, recovery refuses to compensate and the operator must
 * reconcile manually. The probe is cached per process; the
 * result is not re-evaluated on every call.
 *
 * ## Engine requirement
 *
 * Advisory locks are a MySQL/MariaDB feature (MySQL 5.7+,
 * MariaDB 10.0.1+). On an engine that does not support them, the
 * probe returns false and the recovery engine refuses to
 * compensate.
 */
class Recovery_Advisory_Lock {

    const NAME_PREFIX = 'agentshell_recovery_lock_';

    /**
     * Cached availability flag, set by the first available() call.
     * Null means "not yet probed"; true / false are sticky for the
     * life of the request.
     */
    private static $available = null;

    /**
     * Is the database able to service GET_LOCK calls?
     *
     * Probes by attempting a one-shot lock with a fresh name and
     * releasing it. The probe only establishes that the function
     * is callable; it does not hold any lock. The result is
     * cached for the lifetime of the request.
     */
    public static function available() {
        if ( null !== self::$available ) {
            return self::$available;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return self::$available = false;
        }
        $probe = 'agentshell_recovery_lock_probe_' . bin2hex( random_bytes( 4 ) );
        $r = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $probe ) );
        if ( null === $r ) {
            return self::$available = false;
        }
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $probe ) );
        return self::$available = ( '1' === (string) $r );
    }

    /**
     * Reset the probe cache.
     *
     * Test seam only. Production code never calls this.
     */
    public static function reset() {
        self::$available = null;
    }

    /**
     * Try to acquire the lock for $post_id. Returns true on success.
     * Returns false if another connection holds it. Throws if the
     * database cannot service the call — fail closed, never silently
     * bypass.
     */
    public static function acquire( $post_id ) {
        if ( ! self::available() ) {
            throw new \RuntimeException(
                'Advisory locks are unavailable on this database; recovery is refusing '
                . 'to compensate to avoid a destructive step that cannot be serialised.'
            );
        }
        global $wpdb;
        $name = self::name_for( $post_id );
        $r = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
        if ( '1' === (string) $r ) {
            return true;
        }
        if ( '0' === (string) $r ) {
            return false;
        }
        throw new \RuntimeException(
            "GET_LOCK({$name}) returned NULL — the database refused the call. "
            . 'Refusing to compensate; investigate the database connection before retrying.'
        );
    }

    /**
     * Release the lock for $post_id. The implicit release on
     * connection close is what we rely on for the KILL -9 case;
     * this method is the orderly path for the happy termination
     * of a destructive step.
     */
    public static function release( $post_id ) {
        if ( ! self::available() ) {
            return;
        }
        global $wpdb;
        $name = self::name_for( $post_id );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    /**
     * The MySQL-capped (64 chars) lock name for a post id.
     *
     * MySQL caps GET_LOCK names at 64 characters, and the prefix
     * already eats most of that, so the post id is hashed rather
     * than appended in decimal. Two different post ids always
     * produce different names; the same post id always produces
     * the same name.
     */
    public static function name_for( $post_id ) {
        return self::NAME_PREFIX . substr( hash( 'sha256', (string) $post_id ), 0, 16 );
    }
}
