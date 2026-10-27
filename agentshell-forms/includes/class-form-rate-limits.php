<?php
/**
 * The rate-limit counter store.
 *
 * ## The algorithm, exactly
 *
 * The limit is a **fixed window per (scope, subject, form)**, and the limit is
 * baked into the SQL so the counter can never be driven past it by a flood.
 *
 * **Reserve a slot** — one atomic statement:
 *
 *     INSERT INTO … (scope, subject_hash, form_id, window_start, hits, …)
 *     VALUES (…, 1, …)
 *     ON DUPLICATE KEY UPDATE
 *            hits       = IF(hits <= :limit, hits + 1, hits),
 *            expires_at = VALUES(expires_at)
 *
 * The third parameter of the `IF` is the limit, so the counter stops at
 * `limit + 1` however many concurrent writers arrive.
 *
 * **Read the counter**, then **admit iff `hits <= limit`**.
 *
 * ## The property this gives — and its cost
 *
 * **The number of admitted requests in a window never exceeds the limit.** The
 * counter is monotonic and the admission test reads a value that is at least
 * the number of reservations made so far, so a request that finds the counter
 * at or below the limit is one of at most `limit` such requests.
 *
 * The cost is **under-admission under concurrency**: several requests may
 * reserve concurrently, drive the counter above the limit, and then all find
 * themselves refused even though fewer than `limit` slots were used. That is
 * the safe direction — the limit is never exceeded — and it is the right trade
 * for an abuse control.
 *
 * ## Fixed windows have a known property
 *
 * A subject can send `limit` requests at the end of one window and `limit` at
 * the start of the next. The site scope, the honeypot and Turnstile are the
 * controls for that; the rate limiter is not the only defence, and it is not
 * claimed to be.
 *
 * ## Why a table and not a transient
 *
 * Incrementing a transient is a read, an addition and a write. A guarded
 * `INSERT … ON DUPLICATE KEY UPDATE` with a unique bucket key is one statement,
 * and its result is the counter itself.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic fixed-window rate limiting.
 */
class Form_Rate_Limits {

	/**
	 * The site-wide scope identifier.
	 */
	const SCOPE_SITE = 'site';

	/**
	 * The per-form scope identifier.
	 */
	const SCOPE_FORM = 'form';

	/**
	 * The site-scope subject: a fixed constant, so the site bucket is shared by
	 * every request regardless of source address.
	 */
	const SITE_SUBJECT = 'site';

	/**
	 * Extra seconds a row is retained past the end of its window.
	 */
	const GRACE = 300;

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agentshell_form_rate_limits';
	}

	/**
	 * The window start for a timestamp, floored to the window.
	 *
	 * @param int $window Window length in seconds.
	 * @param int $time   Timestamp (default: now).
	 * @return int
	 */
	public static function window_start( $window, $time = null ) {
		$window = max( 1, (int) $window );
		$time   = null === $time ? time() : (int) $time;
		return (int) ( floor( $time / $window ) * $window );
	}

	/**
	 * Reserve a slot and report whether the request is admitted.
	 *
	 * @param string $scope        `form` or `site`.
	 * @param string $subject_hash HMAC of the source address (or the fixed site subject).
	 * @param string $form_id      The form's internal identifier, or '' for the site scope.
	 * @param int    $limit        Maximum admitted requests in the window.
	 * @param int    $window       Window length in seconds.
	 * @param int    $time         Timestamp, for tests.
	 * @return array{admitted:bool,hits:int,limit:int,window_start:int,window:int,error:?\WP_Error}
	 */
	public static function reserve( $scope, $subject_hash, $form_id, $limit, $window, $time = null ) {
		global $wpdb;

		$limit  = (int) $limit;
		$window = max( 1, (int) $window );
		$start  = self::window_start( $window, $time );
		$now    = null === $time ? time() : (int) $time;

		$result = array(
			'admitted'     => false,
			'hits'         => 0,
			'limit'        => $limit,
			'window_start' => $start,
			'window'       => $window,
			'error'        => null,
		);

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$result['error'] = new \WP_Error( 'form_rate_limit_failed', __( 'The database is unavailable.', 'agentshell-forms' ) );
			return $result;
		}

		$table = self::table();

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$result['error'] = new \WP_Error( 'form_rate_limit_missing_table', __( 'The rate-limit table does not exist.', 'agentshell-forms' ) );
			return $result;
		}

		$created_at = gmdate( 'Y-m-d H:i:s', $now );
		$expires_at = gmdate( 'Y-m-d H:i:s', $start + $window + self::GRACE );

		// The limit is inside the statement, so a flood cannot push the counter
		// past limit + 1 no matter how many writers arrive concurrently.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
			        (scope, subject_hash, form_id, window_start, hits, created_at, expires_at)
			 VALUES (%s, %s, %s, %d, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE
			        hits       = IF(hits <= %d, hits + 1, hits),
			        expires_at = VALUES(expires_at)",
			(string) $scope,
			(string) $subject_hash,
			(string) $form_id,
			(int) $start,
			$created_at,
			$expires_at,
			$limit
		);

		$ok = $wpdb->query( $sql );
		if ( false === $ok ) {
			$result['error'] = new \WP_Error( 'form_rate_limit_failed', __( 'The rate-limit reservation failed.', 'agentshell-forms' ) );
			return $result;
		}

		$hits = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$table} WHERE scope = %s AND subject_hash = %s AND form_id = %s AND window_start = %d",
				(string) $scope,
				(string) $subject_hash,
				(string) $form_id,
				(int) $start
			)
		);

		if ( null === $hits ) {
			$result['error'] = new \WP_Error( 'form_rate_limit_failed', __( 'The rate-limit counter could not be read back.', 'agentshell-forms' ) );
			return $result;
		}

		$result['hits']     = (int) $hits;
		$result['admitted'] = (int) $hits <= $limit;

		return $result;
	}

	/**
	 * Read a bucket's counter without reserving.
	 *
	 * @param string $scope        Scope.
	 * @param string $subject_hash Subject.
	 * @param string $form_id      Form id or ''.
	 * @param int    $window       Window length.
	 * @param int    $time         Timestamp, for tests.
	 * @return int
	 */
	public static function hits( $scope, $subject_hash, $form_id, $window, $time = null ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$start = self::window_start( $window, $time );
		$table = self::table();

		$hits = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$table} WHERE scope = %s AND subject_hash = %s AND form_id = %s AND window_start = %d",
				(string) $scope,
				(string) $subject_hash,
				(string) $form_id,
				(int) $start
			)
		);

		return null === $hits ? 0 : (int) $hits;
	}

	/**
	 * Delete expired rows.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$table  = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}

		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", gmdate( 'Y-m-d H:i:s' ) )
		);

		return (int) $removed;
	}

	/**
	 * Delete every row. Tests and an explicitly-opted-in uninstall only.
	 *
	 * @return void
	 */
	public static function delete_all() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$table  = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
