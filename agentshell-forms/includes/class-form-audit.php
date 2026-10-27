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
 * ## Why the write is guarded
 *
 * An audit log that loses entries under concurrency is worse than no audit log,
 * because it looks complete. Appending is a read-modify-write, so it is done as
 * a compare-and-swap on the option's stored bytes and retried; a failed append
 * is reported to the caller rather than silently dropped.
 *
 * The ring is **bounded**. When it is full the oldest entry is dropped — that
 * is a property of a ring, not a failure — but a normalisation event (a
 * publication, a refusal, a grant) is never dropped *silently*: the ring's
 * overflow is visible in its own `dropped` counter.
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
	 * Record one event.
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

		$entry = array(
			'event'    => (string) $event,
			'at'       => gmdate( 'c' ),
			'actor'    => (int) $actor_id,
			'data'     => $data,
		);

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

			$dropped = 0;
			if ( count( $existing ) > self::MAX_ENTRIES ) {
				$dropped  = count( $existing ) - self::MAX_ENTRIES;
				$existing = array_slice( $existing, $dropped );
			}

			$payload = maybe_serialize( array_values( $existing ) );

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
		$total = (int) get_option( 'agentshell_forms_audit_dropped', 0 );
		update_option( 'agentshell_forms_audit_dropped', $total + (int) $count, false );
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
		delete_option( 'agentshell_forms_audit_dropped' );
	}
}
