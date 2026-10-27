<?php
/**
 * The storage primitives: a bounded SQL transaction, a named advisory lock,
 * and the four operations that touch a definition meta row.
 *
 * ## Why this class exists rather than using `update_post_meta()`
 *
 * `wp_postmeta` has **no uniqueness guarantee** (design F14):
 *
 *   - its only keys are `PRIMARY KEY (meta_id)` and two non-unique indexes;
 *   - `add_metadata()` is a `SELECT COUNT(*)` followed by an `INSERT`, so two
 *     concurrent first writes can both see zero and both insert;
 *   - `update_metadata()` reads **every** `meta_id` matching the key and
 *     updates **every one of them**, so a duplicated row silently receives the
 *     same value and `get_post_meta(..., true)` returns whichever came first.
 *
 * A compare-and-swap written against `(post_id, meta_key)` is therefore
 * written against a guarantee the schema does not provide. This class writes
 * only in terms of `meta_id`, which is a real primary key, and it counts rows
 * on every read so a duplicate that arrives from anywhere else is *detected*
 * rather than silently resolved.
 *
 * ## The three mechanisms
 *
 * 1. **Authoritative-row creation.** Under a MySQL named advisory lock
 *    (`GET_LOCK('agentshell_forms_def_<post id>', 0)`), the rows for the key
 *    are re-read from the database and then exactly one of three things
 *    happens: insert the single row, use the one row that exists, or refuse
 *    because more than one exists.
 *
 *    The lock is connection-scoped, not transaction-scoped, so a `KILL -9` of
 *    the owning process releases it through the server's connection-close
 *    handler — which an in-process `finally` block cannot guarantee.
 *
 * 2. **Duplicate detection.** Every read counts the rows. A count above one is
 *    corruption: the caller refuses to operate on that record and audits it.
 *    The lock prevents the plugin from *creating* a duplicate; the count
 *    detects one that arrived some other way. Neither depends on a schema
 *    guarantee.
 *
 * 3. **Guarded updates.** Every write to an existing row is a single-row update
 *    scoped by `meta_id` and guarded on the exact previous bytes:
 *
 *        UPDATE wp_postmeta SET meta_value = %s
 *         WHERE meta_id = %d AND meta_value = %s
 *
 *    Zero affected rows means somebody else wrote first, and the write is
 *    refused rather than overwriting them.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage primitives shared by every consumer in the plugin.
 */
class Form_DB {

	/**
	 * Transaction reentrancy depth. Zero means this call owns the transaction.
	 *
	 * MySQL has no nested transactions, and `START TRANSACTION` inside an open
	 * transaction implicitly commits it. A hook that opens its own write while
	 * an outer write is in flight would therefore silently commit the outer
	 * one. The depth counter makes a nested call join the transaction already
	 * open instead.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Cached transactional-engine verdicts, keyed by the table set.
	 *
	 * @var array<string,string>
	 */
	private static $engines = array();

	/**
	 * Whether the most recent `run()` really opened a transaction.
	 *
	 * Callers report this rather than assuming protection they did not get.
	 *
	 * @var bool
	 */
	private static $last_was_transactional = false;

	/**
	 * Run a callback inside one bounded SQL transaction over the named tables.
	 *
	 * The callback returns a value. A `WP_Error`, `false`, `null` or a numeric
	 * zero is treated as failure and the transaction is rolled back; anything
	 * else commits. A thrown exception is rolled back and re-thrown.
	 *
	 * When the tables are not backed by a transactional engine, or the
	 * database refuses to open a transaction, the callback runs **unguarded**
	 * rather than not at all — refusing to store a form would be a worse
	 * failure than an unprotected store — and `last_was_transactional()`
	 * reports the truth.
	 *
	 * @param array    $tables Table names the write touches.
	 * @param callable $write  The write to perform.
	 * @return mixed Whatever the callback returned.
	 * @throws \Throwable Whatever the callback threw, after rolling back.
	 */
	public static function run( array $tables, callable $write ) {
		global $wpdb;

		$engine = self::engine_for( $tables );

		if ( self::$depth > 0 || '' === $engine ) {
			self::$depth++;
			try {
				return $write();
			} finally {
				self::$depth--;
			}
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			self::$depth++;
			try {
				return $write();
			} finally {
				self::$depth--;
			}
		}

		$started = ( false !== @$wpdb->query( 'START TRANSACTION' ) );
		self::$last_was_transactional = $started;

		if ( ! $started ) {
			self::$depth++;
			try {
				return $write();
			} finally {
				self::$depth--;
			}
		}

		self::$depth++;
		try {
			$result = $write();
		} catch ( \Throwable $e ) {
			self::$depth--;
			$wpdb->query( 'ROLLBACK' );
			self::discard_caches();
			throw $e;
		}
		self::$depth--;

		if ( self::is_failure( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::discard_caches();
			return $result;
		}

		$wpdb->query( 'COMMIT' );
		return $result;
	}

	/**
	 * Whether the last `run()` opened a real transaction.
	 *
	 * @return bool
	 */
	public static function last_was_transactional() {
		return self::$last_was_transactional;
	}

	/**
	 * Did a write fail?
	 *
	 * Fails closed: anything that is not an unambiguous success counts as a
	 * failure, because the cost of guessing wrong is a caller told a write
	 * landed when it did not.
	 *
	 * @param mixed $result The callback's return value.
	 * @return bool
	 */
	public static function is_failure( $result ) {
		if ( is_wp_error( $result ) ) {
			return true;
		}
		if ( null === $result || false === $result ) {
			return true;
		}
		if ( is_numeric( $result ) && (int) $result < 1 ) {
			return true;
		}
		return false;
	}

	/**
	 * The transactional engine backing the named tables, or '' if none can
	 * roll back.
	 *
	 * Checked once per table set per process. A site on MyISAM gets an honest
	 * "not transactional" rather than a false promise.
	 *
	 * @param array $tables Table names.
	 * @return string The engine name, or ''.
	 */
	public static function engine_for( array $tables ) {
		global $wpdb;

		sort( $tables );
		$key = implode( ',', $tables );

		if ( array_key_exists( $key, self::$engines ) ) {
			return self::$engines[ $key ];
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			self::$engines[ $key ] = '';
			return self::$engines[ $key ];
		}

		$in = array();
		foreach ( $tables as $table ) {
			$in[] = $wpdb->prepare( '%s', $table );
		}

		$sql = 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
			. implode( ',', $in ) . ')';

		$rows = $wpdb->get_col( $sql );

		if ( ! is_array( $rows ) || count( $rows ) !== count( $tables ) ) {
			self::$engines[ $key ] = '';
			return self::$engines[ $key ];
		}

		foreach ( $rows as $engine ) {
			if ( 0 !== strcasecmp( (string) $engine, 'InnoDB' ) ) {
				self::$engines[ $key ] = '';
				return self::$engines[ $key ];
			}
		}

		self::$engines[ $key ] = 'InnoDB';
		return self::$engines[ $key ];
	}

	/**
	 * Drop the object cache for the tables a rolled-back write touched.
	 *
	 * A rolled-back write leaves nothing in the database, but a cached copy is
	 * not in the database and would still be served. Discarding it is what
	 * makes "rolled back" mean "invisible".
	 */
	public static function discard_caches() {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
			wp_cache_flush_group( 'posts' );
			return;
		}
		// Older cores: a full flush is the only correct option, because the
		// specific keys are not enumerable.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * The tables a definition write touches.
	 *
	 * @return array
	 */
	public static function definition_tables() {
		global $wpdb;
		return array( $wpdb->posts, $wpdb->postmeta );
	}

	/* ---------------------------------------------------------------------
	 * Meta rows, addressed by meta_id
	 * ------------------------------------------------------------------ */

	/**
	 * Every row for one (post, key), read directly from the database.
	 *
	 * Deliberately not `get_post_meta()`: that collapses duplicates into one
	 * value, which is exactly the corruption this class exists to detect.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return array<int,array{meta_id:int,meta_value:string}>
	 */
	public static function rows( $post_id, $meta_key ) {
		global $wpdb;

		$out = array();
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $out;
		}

		$sql = $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			(int) $post_id,
			(string) $meta_key
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$out[] = array(
				'meta_id'    => (int) $row['meta_id'],
				'meta_value' => (string) $row['meta_value'],
			);
		}
		return $out;
	}

	/**
	 * Read exactly one row for a key, distinguishing absence, a single row and
	 * corruption.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return array{status:string,value:?string,meta_id:?int,count:int,error:?\WP_Error}
	 *         `status` is `absent`, `one` or `duplicate`.
	 */
	public static function read_one( $post_id, $meta_key ) {
		$rows  = self::rows( $post_id, $meta_key );
		$count = count( $rows );

		if ( 0 === $count ) {
			return array(
				'status'  => 'absent',
				'value'   => null,
				'meta_id' => null,
				'count'   => 0,
				'error'   => null,
			);
		}

		if ( 1 === $count ) {
			return array(
				'status'  => 'one',
				'value'   => $rows[0]['meta_value'],
				'meta_id' => $rows[0]['meta_id'],
				'count'   => 1,
				'error'   => null,
			);
		}

		return array(
			'status'  => 'duplicate',
			'value'   => null,
			'meta_id' => null,
			'count'   => $count,
			'error'   => new \WP_Error(
				'form_meta_duplicate',
				sprintf(
					/* translators: 1: meta key, 2: post id, 3: row count */
					__( 'The %1$s meta key on form %2$d has %3$d rows; refusing to choose one.', 'agentshell-forms' ),
					$meta_key,
					(int) $post_id,
					$count
				),
				array(
					'reason'   => 'duplicate_meta',
					'meta_key' => $meta_key,
					'post_id'  => (int) $post_id,
					'count'    => $count,
				)
			),
		);
	}

	/**
	 * Create the single authoritative row for a key.
	 *
	 * Must be called with the form's advisory lock held. Returns the new
	 * `meta_id`, or `false`.
	 *
	 * A direct INSERT rather than `add_post_meta()`, because that function's
	 * `SELECT COUNT(*)`-then-`INSERT` is the race the lock exists to close.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @param string $value    Raw value (already serialised by the caller).
	 * @return int|false
	 */
	public static function insert_row( $post_id, $meta_key, $value ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$ok = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $value,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			),
			array( '%d', '%s', '%s' )
		);

		if ( false === $ok ) {
			return false;
		}

		$meta_id = (int) $wpdb->insert_id;
		wp_cache_delete( (int) $post_id, 'post_meta' );
		return $meta_id > 0 ? $meta_id : false;
	}

	/**
	 * Compare-and-swap one row's value, guarded on its previous bytes.
	 *
	 * @param int    $meta_id    The row's primary key.
	 * @param string $new_value  New raw value.
	 * @param string $old_value  Exact previous raw value.
	 * @param int    $post_id    The owning post, for cache invalidation. The
	 *                           `post_meta` cache group is keyed by post, not
	 *                           by row, so this is the only key that
	 *                           invalidates the value WordPress will read
	 *                           back through `get_post_meta()`.
	 * @return bool True when exactly one row changed.
	 */
	public static function cas_update( $meta_id, $new_value, $old_value, $post_id = 0 ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND meta_value = %s",
			(string) $new_value,
			(int) $meta_id,
			(string) $old_value
		);

		$affected = $wpdb->query( $sql );

		if ( 1 === (int) $affected ) {
			if ( (int) $post_id > 0 ) {
				wp_cache_delete( (int) $post_id, 'post_meta' );
			}
			return true;
		}

		if ( false === $affected ) {
			return false;
		}

		// Zero affected rows is not proof of failure. MySQL reports the number
		// of rows *changed*, so writing the value a row already holds changes
		// nothing and reports 0 — and a bookkeeping write legitimately does
		// that when two writes land in the same second. The guard must still
		// hold, so the postcondition is verified directly: the row must exist,
		// still be addressed by the same primary key, and now hold exactly the
		// value that was asked for. Anything else is a lost race and is
		// refused, never overwritten.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d",
				(int) $meta_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || (string) $row['meta_value'] !== (string) $new_value ) {
			return false;
		}

		if ( (int) $post_id > 0 ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Named advisory locks
	 * ------------------------------------------------------------------ */

	/**
	 * Take a MySQL named advisory lock for the duration of this connection.
	 *
	 * `GET_LOCK` is connection-scoped. A process that dies releases the lock
	 * when the server notices the connection closed, which is the property an
	 * in-process `finally` block cannot provide.
	 *
	 * When the server does not support `GET_LOCK` (MySQL < 5.7, MariaDB <
	 * 10.0.1) this returns `false`, and the caller must decide whether it can
	 * proceed safely. For **creation** it cannot — proceeding without the lock
	 * reintroduces exactly the duplicate-row race — so the caller refuses.
	 *
	 * @param string $name  Lock name (max 64 chars).
	 * @param int    $wait  Seconds to wait; 0 = try once.
	 * @return string|false The lock name on success, false otherwise.
	 */
	public static function lock( $name, $wait = 0 ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$name = substr( (string) $name, 0, 64 );

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, (int) $wait ) );
		if ( null === $got || '1' !== (string) $got ) {
			return false;
		}
		return $name;
	}

	/**
	 * Release a lock taken by `lock()`.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function unlock( $name ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || '' === (string) $name ) {
			return;
		}

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', (string) $name ) );
	}

	/**
	 * The advisory lock name for one form's definition rows.
	 *
	 * @param int $post_id Form post id.
	 * @return string
	 */
	public static function definition_lock_name( $post_id ) {
		return 'agentshell_forms_def_' . (int) $post_id;
	}

	/**
	 * The advisory lock name for one slug.
	 *
	 * Slug uniqueness has no database index behind it either — the slug lives
	 * in post meta — so a check-then-write would let two concurrent creates
	 * claim the same slug. The lock is site-scoped per slug, which is the
	 * smallest scope that closes it.
	 *
	 * @param string $slug The slug being claimed.
	 * @return string
	 */
	public static function slug_lock_name( $slug ) {
		return 'agentshell_forms_slug_' . substr( hash( 'sha256', (string) $slug ), 0, 40 );
	}

	/**
	 * Serialise one form's publication for the duration of a transaction.
	 *
	 * `SELECT … FOR UPDATE` on the post row is a current read that holds an
	 * exclusive row lock until the transaction ends, so a second publication of
	 * the same form blocks and then observes the first one's committed state.
	 *
	 * @param int $post_id Form post id.
	 * @return bool True when the row exists and is locked.
	 */
	public static function lock_post_row( $post_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", (int) $post_id )
		);

		return null !== $id;
	}
}
