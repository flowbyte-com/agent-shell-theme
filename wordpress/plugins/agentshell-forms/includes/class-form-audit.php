<?php
/**
 * The plugin's own bounded audit log.
 *
 * ## Why it is not the AgentShell audit log
 *
 * The rest of the system records events through `Store::record_event()` in the
 * MCP plugin. This plugin must work when the MCP plugin is not installed
 * (§4.1), so it cannot call that. The design says so explicitly for the
 * rejection log (§11.2a) — "the plugin's own bounded log, the same shape as the
 * configuration event ring the rest of the system uses, but owned by this
 * plugin" — and the same reasoning applies to the administrative events.
 *
 * It is a *shape* match, not a storage match: the events have the same meaning
 * and the same discipline, but they live in this plugin's own option.
 *
 * ## Two ways to append, and why the difference matters
 *
 * `record_locked()` is the way every **definition mutation** appends, and it
 * requires an open transaction. It reads the ring with `SELECT … FOR UPDATE`
 * and writes it with a compare-and-swap, so the record commits with the change
 * it describes. A publication whose audit row cannot be written is rolled back
 * — the state never moves without its record (§9.3 step 5).
 *
 * `record()` is the **diagnostic** path: it appends outside any transaction,
 * retries the compare-and-swap, and reports failure to its caller. It is used
 * where the event describes a *refusal* (a duplicate row detected, a refused
 * grant) rather than a state change — there is no state to keep in step, so
 * there is nothing to make atomic with.
 *
 * Neither path drops an entry silently. The ring is **bounded**; when it is
 * full the oldest entry is dropped as a property of a ring, and the overflow is
 * counted in its own option so "the log is short" is distinguishable from "the
 * log rolled over".
 *
 * ## The veto filter
 *
 * `agentshell_forms_audit_before_append` can refuse an append. It exists so a
 * site can route administrative events elsewhere, and so the tests can prove
 * the important property: a mutation that requires an audit row refuses when
 * the row cannot be written. It is a filter on *recording*, not on
 * authorisation — a site that suppresses the local ring is responsible for
 * keeping the record somewhere.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded, plugin-owned audit log.
 */
class Form_Audit {

	/**
	 * Option name.
	 */
	const OPTION = 'agentshell_forms_audit';

	/**
	 * Option holding the running count of entries the ring has dropped.
	 */
	const DROPPED_OPTION = 'agentshell_forms_audit_dropped';

	/**
	 * Maximum number of retained entries.
	 *
	 * The same order of magnitude as the configuration event ring (100), for
	 * the same reason: enough to answer "what happened" after an incident,
	 * small enough that the option stays cheap.
	 */
	const MAX_ENTRIES = 100;

	/**
	 * Attempts at the compare-and-swap before giving up.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * The filter that can refuse an append.
	 */
	const VETO_FILTER = 'agentshell_forms_audit_before_append';

	/**
	 * Append one event **inside the caller's transaction**.
	 *
	 * The append is serialised by a named advisory lock and then performed as
	 * a read-modify-write under a row lock, so two transactions appending at
	 * once cannot lose one another's entries — and, crucially, the entry
	 * becomes durable at the same instant as the change it records.
	 *
	 * The advisory lock is not decoration. Creating the ring is an `INSERT`
	 * into a `UNIQUE` option name, and concurrent inserts of the same
	 * not-yet-existing row deadlock in InnoDB: every transaction takes the
	 * same gap lock and then tries to insert into it, and the server resolves
	 * that by killing one. Measured: six concurrent creations against a real
	 * MySQL produced four deadlock victims, each of which had to refuse its
	 * creation. `GET_LOCK` serialises the section without participating in the
	 * transaction, so the ring's creation is a single-writer event and no
	 * victim exists to refuse.
	 *
	 * Returns `false` rather than pretending, and the caller must treat that as
	 * a failure of the whole operation. A state change whose record could not
	 * be written is refused, not committed with a gap in the log.
	 *
	 * Must be called inside `Form_DB::run()`. Outside a transaction the entry
	 * would be a separate write that the caller's rollback could not undo, so
	 * it is refused rather than silently downgraded.
	 *
	 * @param string $event    Event name, e.g. `form_published`.
	 * @param array  $data     Event payload. **Never** contains a submitted value.
	 * @param int    $actor_id User id responsible, or 0 for system.
	 * @return bool True when the entry was written in this transaction.
	 */
	public static function record_locked( $event, array $data = array(), $actor_id = 0 ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		if ( ! Form_DB::in_transaction() ) {
			return false;
		}

		if ( ! self::allowed( $event, $data, $actor_id ) ) {
			return false;
		}

		$entry = self::entry( $event, $data, $actor_id );

		$lock = Form_DB::lock( self::lock_name(), 5 );

		if ( false === $lock ) {
			// Without the lock two appends can deadlock, and a deadlock takes
			// the caller's whole transaction with it. Refusing is the honest
			// answer: the mutation does not happen, and it is reported.
			return false;
		}

		try {
			// Create the ring with an insert that already carries this entry.
			// It cannot deadlock here — the advisory lock above means there is
			// only ever one writer in this section.
			if ( self::insert_option( self::serialise( array( $entry ) ) ) ) {
				wp_cache_delete( self::OPTION, 'options' );
				return true;
			}

			$row = self::row_for_update();

			if ( null === $row ) {
				// The insert was ignored and the row is still unreadable:
				// either the ring was removed between the two statements, or
				// the database is not behaving. Fail closed rather than claim
				// a record.
				return false;
			}

			$existing = maybe_unserialize( $row->option_value );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$existing[] = $entry;
			list( $payload, $dropped ) = self::ring( $existing );

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$payload,
					self::OPTION,
					$row->option_value
				)
			);

			// The row is locked, so a zero here is not contention — it is the
			// row having moved in a way this transaction cannot account for.
			// Fail closed: the caller refuses the mutation.
			if ( 1 !== (int) $updated ) {
				return false;
			}

			wp_cache_delete( self::OPTION, 'options' );

			if ( $dropped > 0 ) {
				self::bump_dropped_locked( $dropped );
			}

			return true;
		} finally {
			Form_DB::unlock( $lock );
		}
	}

	/**
	 * The advisory lock that serialises the ring's read-modify-write.
	 *
	 * Scoped by the options table name, because `GET_LOCK` names are shared by
	 * every database on the server: two sites on one MySQL must not wait on one
	 * another's audit appends.
	 *
	 * @return string
	 */
	private static function lock_name() {
		global $wpdb;

		$table = isset( $wpdb->options ) ? (string) $wpdb->options : 'wp_options';

		return 'agentshell_forms_audit_' . substr( hash( 'sha256', $table ), 0, 40 );
	}

	/**
	 * Record one event outside any transaction.
	 *
	 * Best effort by construction, which is why it is used for **refusals** and
	 * diagnostics rather than for state changes: there is no state to keep in
	 * step with it. Callers still check the result, and report a failed append
	 * rather than assuming the event was recorded.
	 *
	 * @param string $event    Event name, e.g. `form_published`.
	 * @param array  $data     Event payload. **Never** contains a submitted value.
	 * @param int    $actor_id User id responsible, or 0 for system.
	 * @return bool True when the entry was durably written.
	 */
	public static function record( $event, array $data = array(), $actor_id = 0 ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		if ( ! self::allowed( $event, $data, $actor_id ) ) {
			return false;
		}

		$entry = self::entry( $event, $data, $actor_id );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					self::OPTION
				)
			);

			if ( null === $row ) {
				$created = add_option( self::OPTION, array( $entry ), '', 'no' );
				if ( $created ) {
					return true;
				}
				// Somebody else created it between the read and the insert.
				continue;
			}

			$existing = maybe_unserialize( $row->option_value );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$existing[] = $entry;
			list( $payload, $dropped ) = self::ring( $existing );

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$payload,
					self::OPTION,
					$row->option_value
				)
			);

			if ( 1 === (int) $updated ) {
				wp_cache_delete( self::OPTION, 'options' );
				self::bump_dropped( $dropped );
				return true;
			}
			// The row moved between the read and the write. Retry against the
			// new value rather than overwriting it.
		}

		return false;
	}

	/**
	 * Whether the append is allowed to proceed.
	 *
	 * @param string $event    Event name.
	 * @param array  $data     Payload.
	 * @param int    $actor_id Actor.
	 * @return bool
	 */
	private static function allowed( $event, array $data, $actor_id ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}

		/**
		 * Filters whether an audit entry may be appended.
		 *
		 * Returning false refuses the append. A definition mutation that
		 * requires its audit row will then refuse the whole change, because a
		 * state change must never be committed without its record.
		 *
		 * @param bool   $allow    Whether to append. Default true.
		 * @param string $event    Event name.
		 * @param array  $data     Event payload.
		 * @param int    $actor_id Actor.
		 */
		return (bool) apply_filters( self::VETO_FILTER, true, $event, $data, $actor_id );
	}

	/**
	 * Build one entry.
	 *
	 * @param string $event    Event name.
	 * @param array  $data     Payload.
	 * @param int    $actor_id Actor.
	 * @return array
	 */
	private static function entry( $event, array $data, $actor_id ) {
		return array(
			'event' => (string) $event,
			'at'    => gmdate( 'c' ),
			'actor' => (int) $actor_id,
			'data'  => $data,
		);
	}

	/**
	 * Apply the ring bound.
	 *
	 * @param array $entries Entries after appending.
	 * @return array{0:string,1:int} The serialised payload and how many were dropped.
	 */
	private static function ring( array $entries ) {
		$dropped = 0;

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$dropped  = count( $entries ) - self::MAX_ENTRIES;
			$entries  = array_slice( $entries, $dropped );
		}

		return array( self::serialise( array_values( $entries ) ), (int) $dropped );
	}

	/**
	 * Serialise the ring.
	 *
	 * @param array $entries Entries.
	 * @return string
	 */
	private static function serialise( array $entries ) {
		return (string) maybe_serialize( $entries );
	}

	/**
	 * Read the ring row with a lock, inside the caller's transaction.
	 *
	 * @return object|null
	 */
	private static function row_for_update() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE",
				self::OPTION
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Create the ring row if it does not exist, with error output suppressed.
	 *
	 * `INSERT IGNORE`, not `$wpdb->insert()`: a duplicate key is an expected
	 * outcome (another transaction created the ring at the same instant), and
	 * it must be *ignored* rather than reported. It is also what keeps this
	 * path out of the gap-lock deadlock described in `record_locked()`.
	 *
	 * The caller cannot tell "inserted" from "ignored" by the return value
	 * alone — MySQL reports one affected row for the insert and zero for the
	 * ignore — and it does not need to: zero means the row exists, and the
	 * append that follows reads it under a lock.
	 *
	 * @param string $payload Serialised entries.
	 * @return bool True when this call created the row.
	 */
	private static function insert_option( $payload ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				$payload
			)
		);
		$wpdb->suppress_errors( $suppress );

		return 1 === (int) $inserted;
	}

	/**
	 * Add to the dropped-entry counter, inside the caller's transaction.
	 *
	 * Bookkeeping for the ring's overflow, and deliberately **not** able to
	 * fail the mutation: the entry itself is the record, and it is already
	 * written. A failure here under-reports the overflow, which is the safe
	 * direction for a counter.
	 *
	 * @param int $count Entries dropped by this append.
	 * @return void
	 */
	private static function bump_dropped_locked( $count ) {
		global $wpdb;

		if ( (int) $count < 1 ) {
			return;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE",
				self::DROPPED_OPTION
			)
		);

		if ( ! is_object( $row ) ) {
			self::insert_option_for( self::DROPPED_OPTION, (string) (int) $count );
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) ( (int) $row->option_value + (int) $count ),
				self::DROPPED_OPTION,
				$row->option_value
			)
		);
	}

	/**
	 * Insert one option row, with error output suppressed.
	 *
	 * @param string $name    Option name.
	 * @param string $value   Option value.
	 * @return bool
	 */
	private static function insert_option_for( $name, $value ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				(string) $name,
				(string) $value
			)
		);
		$wpdb->suppress_errors( $suppress );

		return 1 === (int) $inserted;
	}

	/**
	 * Record how many entries the ring has dropped in total.
	 *
	 * Kept in its own option so an operator can tell "the log is short" from
	 * "the log rolled over". Best effort: a failure here must not fail an
	 * event that was already recorded.
	 *
	 * @param int $count Entries dropped by this append.
	 * @return void
	 */
	private static function bump_dropped( $count ) {
		if ( (int) $count < 1 ) {
			return;
		}
		$total = (int) get_option( self::DROPPED_OPTION, 0 );
		update_option( self::DROPPED_OPTION, $total + (int) $count, false );
	}

	/**
	 * Every retained entry, oldest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( $raw );
	}

	/**
	 * The most recent N entries, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int,array>
	 */
	public static function recent( $limit = 20 ) {
		$entries = array_reverse( self::entries() );
		return array_slice( $entries, 0, max( 0, (int) $limit ) );
	}

	/**
	 * Remove every entry.
	 *
	 * Used by the test suite's teardown and by an explicit, confirmed data
	 * operation. Never called by ordinary editing.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
		delete_option( self::DROPPED_OPTION );
	}
}
