<?php
/**
 * The single-use challenge store.
 *
 * ## What a challenge is, and what it is not
 *
 * The challenge is a **protocol-correctness control**, not a spam control
 * (design §7.1). It gives:
 *
 *   - **replay prevention** — a token is claimed exactly once;
 *   - **duplicate suppression** — a double-clicked submit, or a client that
 *     retries after a network failure, carries the same token and is
 *     recognised;
 *
 * and it does **not** give server-side abuse protection: an attacker running
 * their own HTTP client can fetch the form, read the token out of the HTML and
 * post with it. The honeypot, Turnstile and the rate limiter are the spam
 * controls, and they are deliberately independent of this.
 *
 * It is also **not** a WordPress nonce. `wp_create_nonce()` for a logged-out
 * visitor derives from user id 0 and an empty session token, so every anonymous
 * visitor receives the same value; it is not a secret and it protects nothing
 * here.
 *
 * ## Why the row is claimed rather than deleted
 *
 * A genuine retry after a network failure must not create a second submission.
 * If the claim deleted the row, a retry would look identical to a replayed
 * token and could only be refused. Keeping the row and recording the
 * `submission_id` once the submission exists lets the plugin **replay the
 * success response** when the first attempt succeeded, and refuse with a fresh
 * challenge when it did not.
 *
 * ## Why a table and not a transient
 *
 * Claiming a transient is a read followed by a delete, and two concurrent
 * requests can both read it. A guarded `UPDATE … WHERE consumed_at IS NULL` is
 * a single atomic statement, and its affected-row count says who won.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint, claim, link and prune submission challenges.
 */
class Form_Challenges {

	/**
	 * Default time-to-live, in seconds. Two hours (§6.3c).
	 */
	const DEFAULT_TTL = 7200;

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agentshell_form_challenges';
	}

	/**
	 * Mint a challenge for a rendered form.
	 *
	 * Returns the **token** — the value that goes into the page. Only its
	 * SHA-256 is stored, so a database disclosure does not yield usable tokens.
	 *
	 * @param string $form_id      The form's internal identifier.
	 * @param int    $form_version The published version it was minted for.
	 * @param string $subject_hash HMAC of the source address, or ''.
	 * @param int    $ttl          Seconds until expiry.
	 * @return string|\WP_Error The token, or an error.
	 */
	public static function mint( $form_id, $form_version, $subject_hash = '', $ttl = self::DEFAULT_TTL ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return new \WP_Error( 'form_challenge_failed', __( 'The database is unavailable.', 'agentshell-forms' ) );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$exp   = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $ttl ) );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'token_hash'   => $hash,
				'form_id'      => (string) $form_id,
				'form_version' => (int) $form_version,
				'subject_hash' => (string) $subject_hash,
				'consumed_at'  => null,
				'submission_id' => 0,
				'created_at'   => $now,
				'expires_at'   => $exp,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $ok ) {
			return new \WP_Error( 'form_challenge_failed', __( 'The challenge could not be stored.', 'agentshell-forms' ) );
		}

		return $token;
	}

	/**
	 * Claim a challenge token.
	 *
	 * @param string $token The token the request presented.
	 * @return array{status:string,form_id:string,form_version:int,submission_id:int}
	 *         `status` is one of:
	 *           - `claimed`  — this request owns the challenge;
	 *           - `replay`   — an earlier attempt succeeded; replay the success;
	 *           - `pending`  — an earlier attempt is in flight or failed;
	 *           - `expired`  — the challenge existed but has expired;
	 *           - `absent`   — no such challenge;
	 *           - `error`    — the database refused the claim.
	 */
	public static function claim( $token ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array( 'status' => 'error', 'form_id' => '', 'form_version' => 0, 'submission_id' => 0 );
		}

		$hash = hash( 'sha256', (string) $token );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$table = self::table();

		// One statement, guarded on "not yet claimed" and "not yet expired".
		// The expiry guard is an addition to the design's §6.3a statement: the
		// design requires that every read re-checks expiry (§6.3), and a claim
		// that accepted an expired token would not.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %s WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s",
				$now,
				$hash,
				$now
			)
		);

		if ( 1 === (int) $affected ) {
			$row = self::row( $hash );
			return array(
				'status'        => 'claimed',
				'form_id'       => isset( $row['form_id'] ) ? (string) $row['form_id'] : '',
				'form_version'  => isset( $row['form_version'] ) ? (int) $row['form_version'] : 0,
				'submission_id' => isset( $row['submission_id'] ) ? (int) $row['submission_id'] : 0,
			);
		}

		$row = self::row( $hash );

		if ( null === $row ) {
			return array( 'status' => 'absent', 'form_id' => '', 'form_version' => 0, 'submission_id' => 0 );
		}

		$form_id      = (string) $row['form_id'];
		$form_version = (int) $row['form_version'];
		$submission   = (int) $row['submission_id'];
		$expires      = (string) $row['expires_at'];

		if ( '' !== $expires && $expires <= $now ) {
			return array(
				'status'        => 'expired',
				'form_id'       => $form_id,
				'form_version'  => $form_version,
				'submission_id' => $submission,
			);
		}

		if ( null !== $row['consumed_at'] ) {
			return array(
				'status'        => $submission > 0 ? 'replay' : 'pending',
				'form_id'       => $form_id,
				'form_version'  => $form_version,
				'submission_id' => $submission,
			);
		}

		// The row is unclaimed and unexpired, yet the guarded UPDATE changed
		// nothing. That is a database-level inconsistency rather than a race we
		// can classify, so it is reported as an error rather than guessed at.
		return array(
			'status'        => 'error',
			'form_id'       => $form_id,
			'form_version'  => $form_version,
			'submission_id' => $submission,
		);
	}

	/**
	 * Link a claimed challenge to the submission it produced.
	 *
	 * This is what makes a retry recognisable as a retry rather than as a
	 * replayed token.
	 *
	 * @param string $token         The token.
	 * @param int    $submission_id The entry post id.
	 * @return bool
	 */
	public static function link( $token, $submission_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET submission_id = %d WHERE token_hash = %s',
				(int) $submission_id,
				hash( 'sha256', (string) $token )
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * One row, by token hash.
	 *
	 * @param string $hash SHA-256 of the token.
	 * @return array|null
	 */
	private static function row( $hash ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT token_hash, form_id, form_version, subject_hash, consumed_at, submission_id, created_at, expires_at FROM ' . self::table() . ' WHERE token_hash = %s',
				(string) $hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete expired rows.
	 *
	 * Correctness never depends on this firing — every read re-checks expiry —
	 * so a missed run costs disk, not correctness.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$table = self::table();

		// The table may not exist yet on a fresh install where the prune job
		// was scheduled before the activation hook ran.
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

		$table = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
