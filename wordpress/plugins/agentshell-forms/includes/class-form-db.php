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
	 * The last transaction-control failure, if any.
	 *
	 * `run()` returns a `WP_Error` for every failure it can report through its
	 * return value. The exception path cannot: a thrown exception is re-thrown,
	 * so a failed rollback during unwinding has nowhere to go. It is recorded
	 * here instead, so a caller that catches the exception can still ask
	 * whether the failed write was actually rolled back.
	 *
	 * @var \WP_Error|null
	 */
	private static $last_error = null;

	/**
	 * Whether a real SQL transaction is open on this connection.
	 *
	 * @var bool
	 */
	private static $tx_open = false;

	/**
	 * A transaction is mandatory.
	 *
	 * The callback does not run at all when the tables cannot roll back, when
	 * the connection cannot start a transaction, or when the database handle is
	 * unusable. This is the default, and it is the right default: an operation
	 * that needs atomicity and silently does not get it is a write that can be
	 * left half-applied with nobody told.
	 */
	const TRANSACTION_REQUIRED = 'required';

	/**
	 * A transaction may be skipped when one cannot be opened.
	 *
	 * Every operation that passes this must be safe as a single statement. Only
	 * an operation that says so, in its own call, gets the old behaviour — the
	 * fallback is never chosen on the caller's behalf.
	 */
	const TRANSACTION_OPTIONAL = 'optional';

	/**
	 * Run a callback inside one bounded SQL transaction over the named tables.
	 *
	 * The callback returns a value. A `WP_Error`, `false`, `null` or a numeric
	 * zero is treated as failure and the transaction is rolled back; anything
	 * else commits. A thrown exception is rolled back and re-thrown.
	 *
	 * ## Fail closed
	 *
	 * Under `TRANSACTION_REQUIRED` the callback **does not run** unless a real
	 * transaction was opened. The three ways that can fail — no database
	 * handle, a non-transactional engine, a refused `START TRANSACTION` — all
	 * return a named `WP_Error`, and none of them runs the write. A caller that
	 * genuinely does not need atomicity asks for `TRANSACTION_OPTIONAL`
	 * explicitly; nothing gets it by accident.
	 *
	 * ## The commit is checked
	 *
	 * `COMMIT` is a statement that can fail, and its return value is the only
	 * evidence that the writes are durable. A failed commit leaves the outcome
	 * **unknown** — the server may have committed before the client lost the
	 * answer — so this method never reports success for it. It returns
	 * `form_commit_uncertain`, whose data says the outcome is unknown, and the
	 * caller reconciles by re-reading what it wrote (`reconcile_writes()`).
	 *
	 * ## Nested calls join, they never nest
	 *
	 * MySQL has no nested transactions and `START TRANSACTION` inside an open
	 * one implicitly commits it. A nested call therefore runs as part of the
	 * transaction already open and opens nothing of its own — it can neither
	 * commit the outer write early nor pretend to have its own boundary.
	 *
	 * @param array    $tables      Table names the write touches.
	 * @param callable $write       The write to perform.
	 * @param string   $transaction `TRANSACTION_REQUIRED` (default) or
	 *                              `TRANSACTION_OPTIONAL`.
	 * @return mixed Whatever the callback returned, or a `WP_Error`.
	 * @throws \Throwable Whatever the callback threw, after rolling back.
	 */
	public static function run( array $tables, callable $write, $transaction = self::TRANSACTION_REQUIRED ) {
		global $wpdb;

		self::$last_error = null;

		$required = ( self::TRANSACTION_OPTIONAL !== $transaction );

		// A nested call joins the open transaction. It must not open its own:
		// MySQL would implicitly commit the outer one, and the outer caller
		// would then be told "rolled back" about a write that is already
		// durable.
		if ( self::$depth > 0 ) {
			return self::run_unprotected( $write );
		}

		$available = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' );
		$engine    = $available ? (string) self::engine_for( $tables ) : '';

		if ( '' === $engine ) {
			if ( $required ) {
				return self::transaction_unavailable(
					$available ? 'non_transactional_engine' : 'no_database_handle',
					$tables
				);
			}
			return self::run_unprotected( $write );
		}

		$started = ( false !== $wpdb->query( 'START TRANSACTION' ) );
		self::$last_was_transactional = $started;
		self::$tx_open                = $started;

		if ( ! $started ) {
			if ( $required ) {
				return self::transaction_unavailable( 'begin_failed', $tables );
			}
			return self::run_unprotected( $write );
		}

		self::$depth++;
		try {
			$result = $write();
		} catch ( \Throwable $e ) {
			self::$depth--;
			self::roll_back( 'exception', $tables );
			self::$tx_open = false;
			throw $e;
		}
		self::$depth--;

		if ( self::is_failure( $result ) ) {
			$rolled_back = self::roll_back( 'failure', $tables );
			// Whether or not the rollback was confirmed, the transaction this
			// process opened is no longer one it can rely on.
			self::$tx_open = false;

			// The write failed, but the rollback did not confirm. Both facts
			// matter, and neither may be reported as the other: the caller is
			// told the outcome is unknown and the original failure is attached.
			if ( ! $rolled_back && self::$last_error instanceof \WP_Error ) {
				$data = self::$last_error->get_error_data();
				$data = is_array( $data ) ? $data : array();
				$data['cause'] = $result;
				self::$last_error->add_data( $data );
				return self::$last_error;
			}

			return $result;
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// Unknown, not failed. The server may have committed and the
			// answer been lost; guessing either way would be a lie. Cached
			// copies are dropped so the reconciliation reads the database, and
			// the transaction is treated as finished — an append must never be
			// recorded as "inside" a transaction whose state is unknown.
			self::$tx_open = false;
			self::discard_caches();
			self::$last_error = new \WP_Error(
				'form_commit_uncertain',
				__( 'The database did not confirm the commit. Whether the change was applied is unknown, so it is reported as unknown rather than as a success or a failure.', 'agentshell-forms' ),
				array(
					'reason'              => 'commit_uncertain',
					'transaction_outcome' => 'unknown',
					'tables'              => array_values( $tables ),
				)
			);

			return self::$last_error;
		}

		self::$tx_open = false;

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
	 * Whether a real SQL transaction is open on this connection.
	 *
	 * Deliberately **not** `depth > 0`: a callback run under
	 * `TRANSACTION_OPTIONAL` when no transaction could be opened is still
	 * inside `run()`, and an audit append that treated that as transactional
	 * would be a separate write the caller's rollback could never undo. The
	 * flag is set only when `START TRANSACTION` actually succeeded and cleared
	 * when the transaction ends, whichever way it ends.
	 *
	 * @return bool
	 */
	public static function in_transaction() {
		return self::$tx_open;
	}

	/**
	 * The last transaction-control failure, or null.
	 *
	 * @return \WP_Error|null
	 */
	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * Run a callback with no transaction, as an explicit choice.
	 *
	 * Only reached from `TRANSACTION_OPTIONAL` or from a nested call that is
	 * already inside its caller's transaction.
	 *
	 * @param callable $write The write.
	 * @return mixed
	 */
	private static function run_unprotected( callable $write ) {
		self::$depth++;
		try {
			return $write();
		} finally {
			self::$depth--;
		}
	}

	/**
	 * The error returned when a required transaction could not be opened.
	 *
	 * @param string $reason Why.
	 * @param array  $tables The table set.
	 * @return \WP_Error
	 */
	private static function transaction_unavailable( $reason, array $tables ) {
		$messages = array(
			'no_database_handle'       => __( 'The database is unavailable, so the change was not attempted.', 'agentshell-forms' ),
			'non_transactional_engine' => __( 'The affected tables cannot roll back, so the change was not attempted rather than applied without protection.', 'agentshell-forms' ),
			'begin_failed'             => __( 'The database did not start a transaction, so the change was not attempted.', 'agentshell-forms' ),
		);

		self::$last_error = new \WP_Error(
			'form_transaction_unavailable',
			isset( $messages[ $reason ] ) ? $messages[ $reason ] : $messages['begin_failed'],
			array(
				'reason'              => (string) $reason,
				'transaction_outcome' => 'not_attempted',
				'tables'              => array_values( $tables ),
			)
		);

		return self::$last_error;
	}

	/**
	 * Roll back and report whether the rollback was confirmed.
	 *
	 * @param string $context What was being rolled back (`failure`, `exception`).
	 * @param array  $tables  The table set.
	 * @return bool True when the rollback was confirmed.
	 */
	private static function roll_back( $context, array $tables ) {
		global $wpdb;

		self::discard_caches();

		$confirmed = ( false !== $wpdb->query( 'ROLLBACK' ) );

		if ( ! $confirmed ) {
			self::$last_error = new \WP_Error(
				'form_rollback_failed',
				__( 'The change failed and the rollback could not be confirmed. Whether any part of it was applied is unknown.', 'agentshell-forms' ),
				array(
					'reason'              => 'rollback_failed',
					'transaction_outcome' => 'unknown',
					'rollback_context'    => (string) $context,
					'tables'              => array_values( $tables ),
				)
			);
		}

		return $confirmed;
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
	 * The options table is included because every definition mutation now
	 * writes its audit row **inside the same transaction** (§9.3 step 5), and a
	 * guarantee that spans two tables is only a guarantee if both of them can
	 * roll back. A site whose options table is not transactional therefore
	 * refuses the mutation rather than committing a change whose record could
	 * be lost — see `run()`'s fail-closed contract.
	 *
	 * @return array
	 */
	public static function definition_tables() {
		global $wpdb;
		return array( $wpdb->posts, $wpdb->postmeta, $wpdb->options );
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
		return self::classify( $post_id, $meta_key, self::rows( $post_id, $meta_key ) );
	}

	/**
	 * Read one row for a key with a **locking** read.
	 *
	 * `SELECT … FOR UPDATE` is a current read: it sees the latest committed
	 * row, not the snapshot this transaction may have established earlier, and
	 * it holds an exclusive lock on the row until the transaction ends. That is
	 * what makes "verify the state the caller observed" a check of reality
	 * rather than of a stale snapshot — and it is the reason a publisher cannot
	 * be fooled by an editor who committed after the publisher's first read.
	 *
	 * Must be called inside a transaction; outside one the lock would last only
	 * for the statement and the result would be no better than `read_one()`.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return array Same shape as `read_one()`.
	 */
	public static function read_one_locked( $post_id, $meta_key ) {
		global $wpdb;

		$out = array();

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return self::classify( $post_id, $meta_key, $out );
		}

		$sql = $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC FOR UPDATE",
			(int) $post_id,
			(string) $meta_key
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[] = array(
					'meta_id'    => (int) $row['meta_id'],
					'meta_value' => (string) $row['meta_value'],
				);
			}
		}

		return self::classify( $post_id, $meta_key, $out );
	}

	/**
	 * Turn the rows for one key into absence, a single row, or corruption.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @param array  $rows     Rows, each `{ meta_id, meta_value }`.
	 * @return array{status:string,value:?string,meta_id:?int,count:int,error:?\WP_Error}
	 */
	private static function classify( $post_id, $meta_key, array $rows ) {
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

	/**
	 * Decide what an unconfirmed commit actually did, by reading the rows back.
	 *
	 * A failed `COMMIT` is not a failed write: the server may have committed
	 * and the answer been lost on the way back. The only evidence available is
	 * the data itself, so this compares what each row holds now against the
	 * value the caller intended and the value the caller observed before:
	 *
	 *   - every row holds the **intended** value ⇒ the transaction committed;
	 *   - every row holds the **observed** value ⇒ it did not;
	 *   - anything else ⇒ the outcome cannot be established, and the caller is
	 *     told so rather than given a guess.
	 *
	 * A row whose intended value equals its observed value cannot discriminate
	 * (a no-op write leaves the same bytes either way) and is not counted.
	 * When *no* row can discriminate, the answer is `uncertain` — "everything
	 * looks unchanged" is not evidence when nothing was going to change.
	 *
	 * @param int   $post_id Post id.
	 * @param array $writes  Each `{ key, value }`, as passed to the transaction.
	 * @param array $state   The state the caller observed (from `read_state()`).
	 * @return array{outcome:string,rows:array<string,array>} `outcome` is
	 *         `applied`, `not_applied` or `uncertain`.
	 */
	public static function reconcile_writes( $post_id, array $writes, array $state ) {
		$raw = isset( $state['raw'] ) && is_array( $state['raw'] ) ? $state['raw'] : array();

		$discriminating = 0;
		$matched_new    = 0;
		$matched_old    = 0;
		$rows           = array();

		foreach ( $writes as $write ) {
			$key      = (string) $write['key'];
			$intended = (string) $write['value'];
			$observed = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) && 'one' === ( $raw[ $key ]['status'] ?? '' )
				? (string) $raw[ $key ]['value']
				: null;

			$current = self::read_one( (int) $post_id, $key );
			$value   = 'one' === $current['status'] ? (string) $current['value'] : null;

			$rows[ $key ] = array(
				'intended' => $intended,
				'observed' => $observed,
				'current'  => $value,
			);

			if ( $intended === $observed ) {
				continue; // cannot discriminate
			}

			$discriminating++;

			if ( $value === $intended ) {
				$matched_new++;
			} elseif ( $value === $observed ) {
				$matched_old++;
			}
		}

		if ( 0 === $discriminating ) {
			return array( 'outcome' => 'uncertain', 'rows' => $rows );
		}

		if ( $matched_new === $discriminating ) {
			return array( 'outcome' => 'applied', 'rows' => $rows );
		}

		if ( $matched_old === $discriminating ) {
			return array( 'outcome' => 'not_applied', 'rows' => $rows );
		}

		return array( 'outcome' => 'uncertain', 'rows' => $rows );
	}

	/**
	 * Decide what an unconfirmed **creation** actually did.
	 *
	 * A creation has no previous row to compare against, so it is reconciled
	 * by the one fact the creation was for: does a form now claim this internal
	 * identifier?
	 *
	 * @param string $form_id The internal identifier the creation was writing.
	 * @return array{outcome:string,post_ids:array<int,int>}
	 */
	public static function reconcile_creation( $form_id ) {
		$ids = Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_FORM_ID, (string) $form_id );

		if ( 1 === count( $ids ) ) {
			return array( 'outcome' => 'applied', 'post_ids' => $ids );
		}

		if ( 0 === count( $ids ) ) {
			return array( 'outcome' => 'not_applied', 'post_ids' => array() );
		}

		return array( 'outcome' => 'uncertain', 'post_ids' => $ids );
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
