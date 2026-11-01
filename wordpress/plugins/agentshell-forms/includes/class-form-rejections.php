<?php
/**
 * The bounded rejection log.
 *
 * ## Why a log separate from the audit ring
 *
 * A refusal usually has **no submission to be recorded on** (§11.2): a
 * `required` Turnstile failure happens before a submission row exists, and a
 * rate-limited request never gets one. The operator still has to be able to
 * tell "people are being blocked because Cloudflare is down" from "people are
 * being blocked because the secret is wrong" from "the rate limit is too
 * tight". This log is that answer.
 *
 * It is the plugin's own ring, not the AgentShell audit ring, because the forms
 * plugin must not depend on the MCP plugin (§4.1). It has the same bounded
 * shape: a fixed number of entries, oldest dropped first, and each write is a
 * compare-and-swap so two concurrent writers cannot corrupt it.
 *
 * ## What is never in it
 *
 * **No submitted value. Ever.** An entry carries the form's internal
 * identifier, a reason code, the Cloudflare error codes where they apply, the
 * timestamp and the hashed source address. That is enough to diagnose a
 * control, and it is not personal data about the person who triggered it.
 *
 * Under a flood the ring may drop entries; the design says so plainly. The
 * *volume* is recorded exactly by the rate-limit counters, so a flood stays
 * visible even after the log has rolled over.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small, bounded, personal-data-free record of public refusals.
 */
class Form_Rejections {

	/**
	 * The option holding the ring.
	 */
	const OPTION = 'agentshell_forms_rejections';

	/**
	 * How many entries are kept.
	 */
	const MAX_ENTRIES = 50;

	/**
	 * How many times a compare-and-swap is retried.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * The reason vocabulary.
	 *
	 * The design names five of these (§11.2a); the rest are protocol states
	 * that are equally useful to an operator and equally free of personal
	 * data.
	 *
	 * @return array<int,string>
	 */
	public static function reasons() {
		return array(
			'turnstile_failed',
			'turnstile_unavailable',
			'turnstile_misconfigured',
			'rate_limited',
			'challenge_invalid',
			'challenge_expired',
			'challenge_pending',
			'malformed',
			'too_large',
			'stale',
			'honeypot',
			'write_failed',
			'mail_failed',
		);
	}

	/**
	 * Append one refusal.
	 *
	 * Best effort by construction: there is no state change waiting on it, and
	 * a submission is never refused *because* the log could not be written.
	 * The caller is told whether it landed so it can say so rather than assume.
	 *
	 * @param string $form_id      The form's internal identifier, or ''.
	 * @param string $reason       One of `reasons()`.
	 * @param array  $codes        Extra machine-readable facts (e.g. Cloudflare codes).
	 * @param string $subject_hash HMAC of the source address, or ''.
	 * @return bool
	 */
	public static function record( $form_id, $reason, array $codes = array(), $subject_hash = '' ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$reason = (string) $reason;

		if ( ! in_array( $reason, self::reasons(), true ) ) {
			return false;
		}

		$entry = array(
			'form_id'      => (string) $form_id,
			'reason'       => $reason,
			'codes'        => array_values( array_map( 'strval', $codes ) ),
			'at'           => gmdate( 'c' ),
			'subject_hash' => (string) $subject_hash,
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

				// Somebody else created the row between the read and the
				// insert; try again against theirs.
				continue;
			}

			$existing = maybe_unserialize( $row->option_value );

			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$existing[] = $entry;

			if ( count( $existing ) > self::MAX_ENTRIES ) {
				$existing = array_slice( $existing, count( $existing ) - self::MAX_ENTRIES );
			}

			$payload = maybe_serialize( $existing );

			// The value guard is the whole concurrency story: a writer that
			// lost the race sees zero affected rows and retries against the
			// row it found, rather than overwriting somebody else's entry.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$payload,
					self::OPTION,
					$row->option_value
				)
			);

			if ( 1 === (int) $updated ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( self::OPTION, 'options' );
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * The recorded refusals, oldest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * The most recent refusals, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int,array>
	 */
	public static function recent( $limit = 20 ) {
		$entries = self::entries();
		$limit   = max( 1, (int) $limit );

		return array_slice( array_reverse( $entries ), 0, $limit );
	}

	/**
	 * Count the refusals with one reason, for the operator's summary.
	 *
	 * @param string $reason Reason code.
	 * @return int
	 */
	public static function count( $reason ) {
		$count = 0;

		foreach ( self::entries() as $entry ) {
			if ( isset( $entry['reason'] ) && (string) $entry['reason'] === (string) $reason ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Remove every entry. Tests and an explicitly opted-in uninstall only.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
