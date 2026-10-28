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
				'created_at'    => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'consumed_at'   => isset( $row['consumed_at'] ) ? (string) $row['consumed_at'] : '',
			);
		}

		$row = self::row( $hash );

		if ( null === $row ) {
			return array( 'status' => 'absent', 'form_id' => '', 'form_version' => 0, 'submission_id' => 0, 'created_at' => '', 'consumed_at' => '' );
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
				'created_at'    => (string) $row['created_at'],
				'consumed_at'   => null === $row['consumed_at'] ? '' : (string) $row['consumed_at'],
			);
		}

		if ( null !== $row['consumed_at'] ) {
			return array(
				'status'        => $submission > 0 ? 'replay' : 'pending',
				'form_id'       => $form_id,
				'form_version'  => $form_version,
				'submission_id' => $submission,
				'created_at'    => (string) $row['created_at'],
				'consumed_at'   => (string) $row['consumed_at'],
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
			'created_at'    => (string) $row['created_at'],
			'consumed_at'   => '',
		);
	}

	/**
	 * Read a challenge's state **without claiming it**.
	 *
	 * The submission pipeline needs to know whether a token is already spent
	 * before it does the expensive work — a replay should return the earlier
	 * success, a pending attempt should not be duplicated, an expired token
	 * should be refused — but it must not consume a token just to find out.
	 * Validation happens between this read and the claim, so a visitor who
	 * mistyped a field keeps the token they were given.
	 *
	 * This is a snapshot, not a reservation: another request may claim the
	 * token a microsecond later. `claim()` is still the only thing that
	 * decides ownership, and its answer is authoritative.
	 *
	 * @param string $token The token.
	 * @return array{status:string,form_id:string,form_version:int,submission_id:int,created_at:string,consumed_at:string}
	 *         `status` is `unclaimed`, `replay`, `pending`, `expired`,
	 *         `absent`, `invalid` or `error`.
	 */
	public static function inspect( $token ) {
		$result = array(
			'status'        => 'invalid',
			'form_id'       => '',
			'form_version'  => 0,
			'submission_id' => 0,
			'created_at'    => '',
			'consumed_at'   => '',
		);

		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return $result;
		}

		$row = self::row( hash( 'sha256', $token ) );

		if ( null === $row ) {
			$result['status'] = 'absent';
			return $result;
		}

		$result['form_id']       = (string) $row['form_id'];
		$result['form_version']  = (int) $row['form_version'];
		$result['submission_id'] = (int) $row['submission_id'];
		$result['created_at']    = (string) $row['created_at'];
		$result['consumed_at']   = null === $row['consumed_at'] ? '' : (string) $row['consumed_at'];

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( '' !== (string) $row['expires_at'] && (string) $row['expires_at'] <= $now ) {
			$result['status'] = 'expired';
			return $result;
		}

		if ( null !== $row['consumed_at'] ) {
			$result['status'] = (int) $row['submission_id'] > 0 ? 'replay' : 'pending';
			return $result;
		}

		$result['status'] = 'unclaimed';

		return $result;
	}

	/**
	 * Link a claimed challenge to the submission it produced.
	 *
	 * This is what makes a retry recognisable as a retry rather than as a
	 * replayed token, so the rules are about the *first* link being the only
	 * one that may happen:
	 *
	 *   - an **unclaimed** challenge cannot be linked — a token nobody consumed
	 *     has no attempt to record a submission for, and linking one would let a
	 *     caller claim and use it without ever going through `claim()`;
	 *   - an **expired** challenge cannot be linked, because an expired
	 *     challenge is not a valid one (the claim statement re-checks expiry for
	 *     the same reason);
	 *   - an existing submission id is **never replaced**: two different
	 *     submissions for one challenge is exactly the duplicate the challenge
	 *     exists to prevent, and which of the two is "the" submission would then
	 *     be a matter of arrival order;
	 *   - linking the **same** submission again is not an error — a retry that
	 *     got as far as writing the submission but failed to record it must be
	 *     able to finish. Nothing is written in that case.
	 *
	 * The write is one guarded statement, so two callers racing to link cannot
	 * both succeed. The classification below runs only when that statement
	 * changed nothing, which is why it can be a plain read.
	 *
	 * @param string $token         The token.
	 * @param int    $submission_id The entry post id.
	 * @return array{status:string,linked:bool,changed:bool,form_id:string,submission_id:int}
	 *         `status` is one of `linked`, `already_linked`, `unclaimed`,
	 *         `expired`, `conflict`, `absent`, `invalid`, `error`. `linked` is
	 *         true for the two states in which the challenge holds this
	 *         submission; `changed` is true only when this call wrote it.
	 */
	public static function link( $token, $submission_id ) {
		global $wpdb;

		$submission_id = (int) $submission_id;

		$result = array(
			'status'        => 'error',
			'linked'        => false,
			'changed'       => false,
			'form_id'       => '',
			'submission_id' => $submission_id,
		);

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return $result;
		}

		// A token that this plugin could never have minted is refused without a
		// lookup: hashing arbitrary input to discover that it is not a token is
		// a query that can only ever answer "no".
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$result['status'] = 'invalid';
			return $result;
		}

		if ( $submission_id < 1 ) {
			$result['status'] = 'invalid';
			return $result;
		}

		$hash  = hash( 'sha256', $token );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = self::table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET submission_id = %d
				  WHERE token_hash = %s
				    AND consumed_at IS NOT NULL
				    AND submission_id = 0
				    AND expires_at > %s",
				$submission_id,
				$hash,
				$now
			)
		);

		if ( 1 === (int) $affected ) {
			$row = self::row( $hash );

			$result['status']  = 'linked';
			$result['linked']  = true;
			$result['changed'] = true;
			$result['form_id'] = isset( $row['form_id'] ) ? (string) $row['form_id'] : '';

			return $result;
		}

		// Nothing was written: say which rule applied, rather than only that it
		// did not work.
		$row = self::row( $hash );

		if ( null === $row ) {
			$result['status'] = 'absent';
			return $result;
		}

		$result['form_id'] = (string) $row['form_id'];
		$existing          = (int) $row['submission_id'];

		$expires = (string) $row['expires_at'];
		if ( '' !== $expires && $expires <= $now ) {
			$result['status']        = 'expired';
			$result['submission_id'] = $existing;
			return $result;
		}

		if ( null === $row['consumed_at'] ) {
			$result['status'] = 'unclaimed';
			return $result;
		}

		if ( $existing === $submission_id ) {
			$result['status'] = 'already_linked';
			$result['linked'] = true;
			return $result;
		}

		if ( $existing > 0 ) {
			$result['status']        = 'conflict';
			$result['submission_id'] = $existing;
			return $result;
		}

		return $result;
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
