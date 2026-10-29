<?php
/**
 * Administrator notifications: plain text, claimed, retried, bounded.
 *
 * ## The distinct facts this file keeps distinct
 *
 * **A submission exists** and **a notification was delivered** are different
 * facts. The submission is written once, before any mail is attempted
 * (§11.6), so a mail failure can never roll it back or create a second one.
 * `wp_mail()` returning true means the message was **accepted by the sending
 * mechanism** — not delivered, not read, not even necessarily not rejected
 * later. The vocabulary here is `accepted`, never "sent" and never
 * "delivered".
 *
 * ## The atomic claim
 *
 * Two workers must not send the same message at once. Before sending, a worker
 * takes the record with the `meta_id`-scoped, value-guarded compare-and-swap
 * of `Form_DB::cas_update()`: the new value says `claimed`, the expected value
 * is the record exactly as read. One affected row means this worker owns the
 * notification; zero means somebody else does and this worker moves on.
 *
 * ## Two clocks, and the invariant between them
 *
 * The claim TTL (900 seconds) is how long a claim may sit before the
 * reconciler treats it as `uncertain` and lets another worker try. The attempt
 * budget (60 seconds) is how long one send may take before its outcome stops
 * being trusted. The budget is **strictly shorter** than the TTL, and
 * `budget()` clamps it if a filter tries to break that: a transport still
 * running when the claim expires is precisely the double-send the claim
 * exists to prevent.
 *
 * ## Delivery is at-least-once, and this file says so
 *
 * If the transport accepted a message and the acknowledgement was lost, a
 * retry can produce a second email. No bookkeeping on top of `wp_mail()` can
 * fix that. What is bounded is the exposure: at most five attempts, a long
 * backoff, one sender at a time, and a `Message-ID` on every message so a
 * duplicate is recognisable.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compose, claim, send and retry notifications.
 */
class Form_Notifier {

	/**
	 * Not attempted yet, or due for another attempt.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * A worker owns the record and may be sending right now.
	 */
	const STATUS_CLAIMED = 'claimed';

	/**
	 * `wp_mail()` returned true within the attempt budget.
	 */
	const STATUS_ACCEPTED = 'accepted';

	/**
	 * The attempt was made and failed.
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * The outcome could not be established.
	 */
	const STATUS_UNCERTAIN = 'uncertain';

	/**
	 * The attempt limit was reached.
	 */
	const STATUS_EXHAUSTED = 'exhausted';

	/**
	 * How many sends are attempted before the record is exhausted.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * How long a claim may sit before the reconciler may take it over.
	 */
	const CLAIM_TTL = 900;

	/**
	 * How long one send may take before its outcome is not trusted.
	 */
	const ATTEMPT_BUDGET = 60;

	/**
	 * How many recipients one notification may name, at send time.
	 */
	const MAX_RECIPIENTS = 10;

	/**
	 * How many submissions one worker run may examine.
	 *
	 * The retry worker is a cron job, so its work must be bounded: an
	 * installation with a very large history must not turn one tick into an
	 * unbounded scan. A record beyond this window is reached by the next run —
	 * because the window **rotates** (see `due_candidates()`), not because the
	 * window happens to be empty.
	 */
	const MAX_SCAN = 2000;

	/**
	 * How many entries one page of the scan reads.
	 */
	const PAGE_SIZE = 200;

	/**
	 * The option holding the scan cursor, per kind.
	 */
	const CURSOR_OPTION = 'agentshell_forms_notification_cursor';

	/**
	 * The longest subject, in characters, after substitution.
	 */
	const MAX_SUBJECT = 200;

	/**
	 * The longest body, in characters, after substitution.
	 */
	const MAX_BODY = 8000;

	/**
	 * The delay, in seconds, before the attempt after number N.
	 *
	 * @return array<int,int>
	 */
	public static function backoff() {
		return array(
			1 => 60,
			2 => 300,
			3 => 1800,
			4 => 7200,
			5 => 43200,
		);
	}

	/**
	 * The attempt budget in seconds, clamped below the claim TTL.
	 *
	 * @return int
	 */
	public static function budget() {
		$budget = (int) apply_filters( 'agentshell_forms_notification_attempt_budget', self::ATTEMPT_BUDGET );

		$ttl = (int) apply_filters( 'agentshell_forms_notification_claim_ttl', self::CLAIM_TTL );
		if ( $ttl < 2 ) {
			$ttl = 2;
		}

		if ( $budget < 1 ) {
			$budget = 1;
		}

		// 900 > 60 is the invariant (§11.6a). A filter that tries to break it is
		// clamped rather than trusted: the consequence of a budget longer than
		// the TTL is two workers sending at once.
		return $budget >= $ttl ? $ttl - 1 : $budget;
	}

	/**
	 * The claim TTL in seconds.
	 *
	 * @return int
	 */
	public static function claim_ttl() {
		$ttl = (int) apply_filters( 'agentshell_forms_notification_claim_ttl', self::CLAIM_TTL );

		return $ttl > 1 ? $ttl : self::CLAIM_TTL;
	}

	/**
	 * A fresh notification record.
	 *
	 * @param string $kind `admin` or `confirmation`.
	 * @return array
	 */
	public static function initial_record( $kind = 'admin' ) {
		return array(
			'kind'            => (string) $kind,
			'status'          => self::STATUS_PENDING,
			'attempts'        => 0,
			'next_attempt_at' => gmdate( 'c' ),
			'claimed_at'      => null,
			'claimed_by'      => null,
			'last_error'      => null,
			'accepted_at'     => null,
			'message_id'      => null,
		);
	}

	/**
	 * Attempt every notification a fresh submission needs.
	 *
	 * Never throws and never rejects: a mail failure must not fail a
	 * submission. The caller records the outcome; this method returns what
	 * happened so the caller can say so.
	 *
	 * @param int   $submission_id The entry id.
	 * @param array $definition    The published definition it was validated against.
	 * @param array $fields        The validated values.
	 * @return array<string,array>
	 */
	public static function dispatch( $submission_id, array $definition, array $fields ) {
		$out = array();

		if ( self::admin_enabled( $definition ) ) {
			$out['admin'] = self::attempt( $submission_id, 'admin', $definition, $fields );
		}

		$confirmation = isset( $definition['settings']['notification']['confirmation'] )
			? $definition['settings']['notification']['confirmation']
			: array();

		if ( ! empty( $confirmation['enabled'] ) ) {
			$recipient = self::confirmation_recipient( $definition, $fields );

			if ( '' === $recipient ) {
				// The form asked for a confirmation but names no email field to
				// send it to. Sending it nowhere and calling that success would
				// be a lie; the operator sees the skip in the audit log.
				Form_Audit::record(
					'form_notification_skipped',
					array(
						'submission' => (int) $submission_id,
						'kind'       => 'confirmation',
						'reason'     => 'no_recipient',
					),
					0
				);
				$out['confirmation'] = array( 'kind' => 'confirmation', 'outcome' => 'skipped', 'reason' => 'no_recipient' );
			} else {
				$out['confirmation'] = self::attempt( $submission_id, 'confirmation', $definition, $fields );
			}
		}

		return $out;
	}

	/**
	 * Attempt one notification.
	 *
	 * @param int    $submission_id The entry id.
	 * @param string $kind          `admin` or `confirmation`.
	 * @param array  $definition    The published definition.
	 * @param array  $fields        The validated values.
	 * @return array
	 */
	public static function attempt( $submission_id, $kind = 'admin', array $definition = array(), array $fields = array() ) {
		$kind = 'confirmation' === (string) $kind ? 'confirmation' : 'admin';

		$state = Submission_Store::notification_state( $submission_id, $kind );

		if ( null === $state['record'] ) {
			return array( 'kind' => $kind, 'outcome' => 'absent', 'status' => null );
		}

		$record = self::normalise_record( $state['record'], $kind );

		if ( in_array( $record['status'], array( self::STATUS_ACCEPTED, self::STATUS_EXHAUSTED ), true ) ) {
			return array( 'kind' => $kind, 'outcome' => $record['status'], 'status' => $record['status'], 'attempts' => $record['attempts'] );
		}

		// The operator may have turned notifications off after the submission
		// was written (or between the write and the first attempt). Retrying a
		// message the form no longer asks for would be the plugin overriding a
		// deliberate decision, so the record is closed with the reason visible
		// instead of being retried five times.
		if ( 'admin' === $kind && ! self::admin_enabled( $definition ) ) {
			return self::exhaust( $submission_id, $kind, $record, $state, 'notifications_disabled' );
		}

		$now = time();

		if ( self::STATUS_CLAIMED === $record['status'] ) {
			$claimed_at = self::timestamp( $record['claimed_at'] );

			if ( $claimed_at > 0 && ( $claimed_at + self::claim_ttl() ) > $now ) {
				return array( 'kind' => $kind, 'outcome' => 'busy', 'status' => $record['status'], 'attempts' => $record['attempts'] );
			}

			// The claim outlived its TTL: the worker that held it is gone or
			// hung, and nobody can say whether the message was accepted. It
			// becomes `uncertain` — the one status that admits not knowing —
			// and the next attempt time is now, so it can make progress.
			$record['status']         = self::STATUS_UNCERTAIN;
			$record['last_error']     = 'claim_expired';
			$record['next_attempt_at'] = gmdate( 'c', $now );
			$record['claimed_at']     = null;
			$record['claimed_by']     = null;

			if ( ! Submission_Store::cas_notification( $submission_id, $kind, $record, $state ) ) {
				return array( 'kind' => $kind, 'outcome' => 'busy', 'status' => self::STATUS_CLAIMED, 'attempts' => $record['attempts'] );
			}

			$state  = Submission_Store::notification_state( $submission_id, $kind );
			$record = self::normalise_record( (array) $state['record'], $kind );
		}

		if ( $record['attempts'] >= self::MAX_ATTEMPTS ) {
			return self::exhaust( $submission_id, $kind, $record, $state, 'attempt_limit' );
		}

		$due = self::timestamp( $record['next_attempt_at'] );

		if ( $due > $now ) {
			return array(
				'kind'            => $kind,
				'outcome'         => 'not_due',
				'status'          => $record['status'],
				'attempts'        => $record['attempts'],
				'next_attempt_at' => $record['next_attempt_at'],
			);
		}

		$message = self::compose( $kind, $definition, $submission_id, $fields );

		if ( is_wp_error( $message ) ) {
			// A composition failure is a real failure of this attempt — the
			// form has no usable recipient or template. It is recorded, not
			// silently swallowed, and it is still bounded by the attempt limit.
			$claimed = self::claim( $submission_id, $kind, $record, $state );

			if ( null === $claimed ) {
				return array( 'kind' => $kind, 'outcome' => 'busy', 'status' => $record['status'], 'attempts' => $record['attempts'] );
			}

			return self::settle(
				$submission_id,
				$kind,
				$claimed,
				self::STATUS_FAILED,
				$message->get_error_code() . ': ' . $message->get_error_message(),
				''
			);
		}

		$claimed = self::claim( $submission_id, $kind, $record, $state );

		if ( null === $claimed ) {
			return array( 'kind' => $kind, 'outcome' => 'busy', 'status' => $record['status'], 'attempts' => $record['attempts'] + 1 );
		}

		$started = microtime( true );
		$result  = self::send( $message );
		$elapsed = microtime( true ) - $started;

		if ( $result['sent'] ) {
			if ( $elapsed > self::budget() ) {
				// `wp_mail()` said yes after running longer than the operator's
				// configuration allows. Treating that as settled would hide a
				// transport that is misbehaving, so it is `uncertain` — back in
				// front of the reconciler.
				return self::settle(
					$submission_id,
					$kind,
					$claimed,
					self::STATUS_UNCERTAIN,
					sprintf( 'over_budget: %.3fs', $elapsed ),
					$message['message_id']
				);
			}

			return self::settle(
				$submission_id,
				$kind,
				$claimed,
				self::STATUS_ACCEPTED,
				'',
				$message['message_id']
			);
		}

		return self::settle(
			$submission_id,
			$kind,
			$claimed,
			self::STATUS_FAILED,
			'' !== $result['error'] ? $result['error'] : 'wp_mail_failed',
			$message['message_id']
		);
	}

	/**
	 * Take the notification, atomically.
	 *
	 * The return value is an **ownership bundle**, not just the record: it
	 * carries the exact bytes this worker wrote, so that the settlement that
	 * follows is guarded on *this* claim rather than on whatever the row
	 * happens to say by then.
	 *
	 * @param int    $submission_id Entry id.
	 * @param string $kind          Kind.
	 * @param array  $record        The record as read.
	 * @param array  $state         The row the record was read from.
	 * @return array|null The ownership bundle, or null when somebody else owns it.
	 */
	private static function claim( $submission_id, $kind, array $record, array $state ) {
		$record['status']          = self::STATUS_CLAIMED;
		$record['attempts']        = (int) $record['attempts'] + 1;
		$record['claimed_at']      = gmdate( 'c' );
		$record['claimed_by']      = self::worker_id();
		$record['next_attempt_at'] = null;

		if ( ! Submission_Store::cas_notification( $submission_id, $kind, $record, $state ) ) {
			return null;
		}

		// The bytes this worker put in the row. `cas_notification()` wrote
		// exactly this string, so it is the value the settlement must expect.
		return array(
			'record'  => $record,
			'meta_id' => (int) ( $state['meta_id'] ?? 0 ),
			'raw'     => (string) wp_json_encode( $record ),
		);
	}

	/**
	 * Write the outcome of an attempt.
	 *
	 * @param int    $submission_id Entry id.
	 * @param string $kind          Kind.
	 * @param array  $claimed       The claimed record.
	 * @param string $status        Outcome status.
	 * @param string $error         Last error, or ''.
	 * @param string $message_id    Message id, or ''.
	 * @return array
	 */
	private static function settle( $submission_id, $kind, array $claim, $status, $error, $message_id ) {
		// The record this worker claimed, and the bytes it claimed them with.
		// Everything below settles **that** claim.
		$claimed = isset( $claim['record'] ) && is_array( $claim['record'] ) ? $claim['record'] : array();
		$attempts = (int) $claimed['attempts'];
		$backoff  = self::backoff();

		$claimed['status']      = $status;
		$claimed['last_error']  = '' !== (string) $error ? self::truncate( $error, 500 ) : null;
		$claimed['message_id']  = '' !== (string) $message_id ? (string) $message_id : null;
		$claimed['claimed_at']  = null;
		$claimed['claimed_by']  = null;

		if ( self::STATUS_ACCEPTED === $status ) {
			$claimed['accepted_at']     = gmdate( 'c' );
			$claimed['next_attempt_at'] = null;
		} elseif ( $attempts >= self::MAX_ATTEMPTS ) {
			$claimed['status'] = self::STATUS_EXHAUSTED;

			Form_Audit::record(
				'form_notification_exhausted',
				array(
					'submission' => (int) $submission_id,
					'kind'       => (string) $kind,
					'attempts'   => $attempts,
					'last_error' => (string) $claimed['last_error'],
				),
				0
			);
		} else {
			$delay = isset( $backoff[ $attempts ] ) ? (int) $backoff[ $attempts ] : (int) end( $backoff );
			$claimed['next_attempt_at'] = gmdate( 'c', time() + $delay );
		}

		// The guard is the **claim's own bytes**, not a fresh read of the row.
		//
		// A fresh read would be a fresh expectation: if the claim expired and a
		// second worker took the record over, the first worker's settlement
		// would be guarded on the *second* worker's bytes, would therefore
		// succeed, and would overwrite that worker's claim and settlement with
		// its own — an older worker winning a race it had already lost.
		//
		// Guarding on the bytes this worker wrote is what makes the settlement
		// belong to the claim: if anything moved the row since, the write does
		// not land, and the outcome is `contended`.
		$expected = array(
			'meta_id' => (int) ( $claim['meta_id'] ?? 0 ),
			'raw'     => (string) ( $claim['raw'] ?? '' ),
		);

		$written = Submission_Store::cas_notification( $submission_id, $kind, $claimed, $expected );

		return array(
			'kind'            => $kind,
			'outcome'         => $written ? $claimed['status'] : 'contended',
			'status'          => $claimed['status'],
			'attempts'        => $attempts,
			'next_attempt_at' => $claimed['next_attempt_at'],
			'error'           => $claimed['last_error'],
		);
	}

	/**
	 * Mark a record exhausted under a CAS.
	 *
	 * @param int    $submission_id Entry id.
	 * @param string $kind          Kind.
	 * @param array  $record        Record.
	 * @param array  $state         Its row.
	 * @param string $reason        Reason.
	 * @return array
	 */
	private static function exhaust( $submission_id, $kind, array $record, array $state, $reason ) {
		$record['status']          = self::STATUS_EXHAUSTED;
		$record['last_error']      = (string) $reason;
		$record['next_attempt_at'] = null;

		$written = Submission_Store::cas_notification( $submission_id, $kind, $record, $state );

		return array(
			'kind'     => $kind,
			'outcome'  => $written ? 'exhausted' : 'contended',
			'status'   => self::STATUS_EXHAUSTED,
			'attempts' => (int) $record['attempts'],
			'error'    => (string) $reason,
		);
	}

	/**
	 * Send one composed message.
	 *
	 * The transport timeout is set on the PHPMailer instance for this send
	 * only, through the supported `phpmailer_init` hook, so the bound holds for
	 * whatever SMTP plugin the operator uses (§11.6a).
	 *
	 * @param array $message The composed message.
	 * @return array{sent:bool,error:string}
	 */
	private static function send( array $message ) {
		$error = '';

		$failed = function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		$timeout = function ( $phpmailer ) {
			if ( is_object( $phpmailer ) ) {
				$phpmailer->Timeout = self::budget();
			}
		};

		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_mail_failed', $failed );
			add_action( 'phpmailer_init', $timeout );
		}

		try {
			$sent = (bool) wp_mail( $message['to'], $message['subject'], $message['body'], $message['headers'] );
		} catch ( \Throwable $e ) {
			$sent  = false;
			$error = $e->getMessage();
		} finally {
			if ( function_exists( 'remove_action' ) ) {
				remove_action( 'wp_mail_failed', $failed );
				remove_action( 'phpmailer_init', $timeout );
			}
		}

		return array(
			'sent'  => $sent,
			'error' => self::truncate( $error, 500 ),
		);
	}

	/**
	 * Does the form ask for administrator notifications?
	 *
	 * @param array $definition The definition.
	 * @return bool
	 */
	private static function admin_enabled( array $definition ) {
		return ! empty( $definition['settings']['notification']['enabled'] );
	}

	/**
	 * Compose a notification, or explain why it cannot be composed.
	 *
	 * @param string $kind          Kind.
	 * @param array  $definition    The published definition.
	 * @param int    $submission_id Entry id.
	 * @param array  $fields        Validated values.
	 * @return array{to:array<int,string>,subject:string,body:string,headers:array<int,string>,message_id:string}|\WP_Error
	 */
	public static function compose( $kind, array $definition, $submission_id, array $fields ) {
		$settings     = isset( $definition['settings']['notification'] ) && is_array( $definition['settings']['notification'] )
			? $definition['settings']['notification']
			: array();
		$confirmation = isset( $settings['confirmation'] ) && is_array( $settings['confirmation'] ) ? $settings['confirmation'] : array();

		if ( 'confirmation' === $kind ) {
			// A confirmation the form has switched off must not compose. The
			// dispatcher already skips it, but a composition that succeeds is a
			// message somebody could send: the refusal belongs here, where the
			// decision to mail anything is made.
			if ( empty( $confirmation['enabled'] ) ) {
				return new \WP_Error( 'form_notification_disabled', __( 'The confirmation is not enabled for this form.', 'agentshell-forms' ) );
			}

			$recipient = self::confirmation_recipient( $definition, $fields );

			if ( '' === $recipient ) {
				return new \WP_Error( 'form_notification_no_recipient', __( 'The confirmation has no valid recipient.', 'agentshell-forms' ) );
			}

			$to            = array( $recipient );
			$subject       = isset( $confirmation['subject'] ) ? (string) $confirmation['subject'] : '';
			$body_template = isset( $confirmation['body_template'] ) ? (string) $confirmation['body_template'] : '';
		} else {
			if ( empty( $settings['enabled'] ) ) {
				return new \WP_Error( 'form_notification_disabled', __( 'Notifications are not enabled for this form.', 'agentshell-forms' ) );
			}

			$to = self::recipients( $definition );

			if ( empty( $to ) ) {
				return new \WP_Error( 'form_notification_no_recipient', __( 'No valid notification recipient is configured.', 'agentshell-forms' ) );
			}

			$subject       = isset( $settings['subject'] ) ? (string) $settings['subject'] : '';
			$body_template = isset( $settings['body_template'] ) ? (string) $settings['body_template'] : '';
		}

		$context = array(
			'definition'    => $definition,
			'submission_id' => (int) $submission_id,
			'fields'        => $fields,
		);

		$subject = self::render( $subject, $context, 'subject' );
		$body    = self::render( $body_template, $context, 'text' );

		$message_id = sprintf(
			'<agentshell-forms-%d-%s-%s@%s>',
			(int) $submission_id,
			'confirmation' === $kind ? 'confirmation' : 'admin',
			substr( hash( 'sha256', $subject . "\n" . $body . "\n" . gmdate( 'c' ) ), 0, 16 ),
			self::mail_host()
		);

		$headers = array( 'Message-ID: ' . $message_id );

		$reply_to = self::reply_to( $definition, $fields );

		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return array(
			'to'         => $to,
			'subject'    => $subject,
			'body'       => $body,
			'headers'    => $headers,
			'message_id' => $message_id,
		);
	}

	/**
	 * The notification settings a submission was accepted under.
	 *
	 * ## Why a submission carries a snapshot
	 *
	 * A notification is retried against the form **as it was when the
	 * submission was accepted**, not as it is when the retry happens. If the
	 * form is republished with a different recipient list in between, a retry
	 * that read the current definition would mail an old submission to
	 * somebody who was never asked to receive it — a visitor's message
	 * delivered to an address the operator added afterwards, and never to the
	 * address that was promised when it was sent.
	 *
	 * The snapshot holds exactly what composing a message needs: the form's
	 * name, the field list (the labels a body template renders, and the types
	 * that decide which field may supply a `Reply-To`), and the notification
	 * settings. It is small, it is written once, and it is what the retry
	 * worker composes from.
	 *
	 * @param array $definition The published definition.
	 * @return array
	 */
	public static function snapshot( array $definition ) {
		$settings = isset( $definition['settings']['notification'] ) && is_array( $definition['settings']['notification'] )
			? $definition['settings']['notification']
			: array();

		$fields = array();

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
				continue;
			}

			$fields[] = array(
				'key'   => (string) $field['key'],
				'label' => isset( $field['label'] ) ? (string) $field['label'] : '',
				'type'  => isset( $field['type'] ) ? (string) $field['type'] : 'text',
			);
		}

		return array(
			'name'         => isset( $definition['name'] ) ? (string) $definition['name'] : '',
			'fields'       => $fields,
			'notification' => $settings,
		);
	}

	/**
	 * Rebuild a definition view from a snapshot.
	 *
	 * The shape is the one `compose()` reads: a form name, a field list and
	 * the notification settings.
	 *
	 * @param array $snapshot The snapshot.
	 * @return array
	 */
	public static function definition_from_snapshot( array $snapshot ) {
		$fields = array();

		foreach ( (array) ( $snapshot['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
				continue;
			}

			$fields[] = array(
				'key'      => (string) $field['key'],
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
				'type'     => isset( $field['type'] ) ? (string) $field['type'] : 'text',
				'required' => false,
				'settings' => array(),
				'options'  => array(),
			);
		}

		return array(
			'name'     => isset( $snapshot['name'] ) ? (string) $snapshot['name'] : '',
			'fields'   => $fields,
			'settings' => array(
				'notification' => isset( $snapshot['notification'] ) && is_array( $snapshot['notification'] )
					? $snapshot['notification']
					: array(),
			),
		);
	}

	/**
	 * Resolve the administrator recipients.
	 *
	 * `site-admin` is the site's administration address; any other address must
	 * be valid and on the site's own domain. Anything outside the allowlist is
	 * dropped and reported by `compose()` as "no valid recipient".
	 *
	 * @param array $definition The definition.
	 * @return array<int,string>
	 */
	public static function recipients( array $definition ) {
		$settings   = isset( $definition['settings']['notification'] ) && is_array( $definition['settings']['notification'] )
			? $definition['settings']['notification']
			: array();
		$declared   = isset( $settings['recipients'] ) && is_array( $settings['recipients'] ) ? $settings['recipients'] : array();
		$site_host  = self::site_host();
		$admin      = (string) get_option( 'admin_email', '' );
		$out        = array();

		foreach ( $declared as $recipient ) {
			$recipient = is_string( $recipient ) ? trim( $recipient ) : '';

			if ( '' === $recipient ) {
				continue;
			}

			if ( 'site-admin' === $recipient ) {
				if ( '' !== $admin && is_email( $admin ) ) {
					$out[] = $admin;
				}
				continue;
			}

			if ( is_email( $recipient ) && self::is_on_site( $recipient, $site_host ) ) {
				$out[] = $recipient;
			}
		}

		$out = array_values( array_unique( $out ) );

		return array_slice( $out, 0, self::MAX_RECIPIENTS );
	}

	/**
	 * The `Reply-To` address, or ''.
	 *
	 * Only an `email` field named by `reply_to_field` may supply it, and only
	 * when the submitted value is a valid address. `From:` is never set to a
	 * submitter's address — that would be a spoofing vector and would damage
	 * deliverability.
	 *
	 * @param array $definition The definition.
	 * @param array $fields     Validated values.
	 * @return string
	 */
	public static function reply_to( array $definition, array $fields ) {
		$key = isset( $definition['settings']['notification']['reply_to_field'] )
			? (string) $definition['settings']['notification']['reply_to_field']
			: '';

		if ( '' === $key ) {
			return '';
		}

		$field = self::field( $definition, $key );

		if ( null === $field || 'email' !== (string) $field['type'] ) {
			return '';
		}

		$value = isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? trim( (string) $fields[ $key ] ) : '';

		// A CR or LF here is the classic header-injection attempt. `is_email()`
		// rejects it, and the address is never used unless it passes.
		if ( '' === $value || ! is_email( $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * The address a confirmation goes to, or ''.
	 *
	 * The approved schema's confirmation block has no recipient field, so the
	 * rule is deterministic: `reply_to_field` when it names an email field,
	 * otherwise the first email field in the definition. No email field means
	 * no confirmation, and the skip is audited.
	 *
	 * @param array $definition The definition.
	 * @param array $fields     Validated values.
	 * @return string
	 */
	public static function confirmation_recipient( array $definition, array $fields ) {
		$key = isset( $definition['settings']['notification']['reply_to_field'] )
			? (string) $definition['settings']['notification']['reply_to_field']
			: '';

		$candidates = array();

		if ( '' !== $key ) {
			$candidates[] = $key;
		}

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['type'], $field['key'] ) && 'email' === (string) $field['type'] ) {
				$candidates[] = (string) $field['key'];
			}
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$field = self::field( $definition, $candidate );

			if ( null === $field || 'email' !== (string) $field['type'] ) {
				continue;
			}

			$value = isset( $fields[ $candidate ] ) && is_scalar( $fields[ $candidate ] ) ? trim( (string) $fields[ $candidate ] ) : '';

			if ( '' !== $value && is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Substitute the closed placeholder set.
	 *
	 * There is no placeholder that inserts raw HTML and none that inserts a
	 * header. A subject is stripped of every control character and length
	 * limited; a plain-text body keeps newlines and tabs and loses the rest.
	 *
	 * @param string $template The template.
	 * @param array  $context  Definition, submission id and values.
	 * @param string $mode     `subject` or `text`.
	 * @return string
	 */
	public static function render( $template, array $context, $mode = 'text' ) {
		$definition = isset( $context['definition'] ) && is_array( $context['definition'] ) ? $context['definition'] : array();
		$fields     = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();
		$id         = isset( $context['submission_id'] ) ? (int) $context['submission_id'] : 0;

		$replacements = array(
			'{form:name}'      => isset( $definition['name'] ) ? (string) $definition['name'] : '',
			'{form:id}'        => isset( $definition['form_id'] ) ? (string) $definition['form_id'] : '',
			'{submission:id}'  => (string) $id,
			'{fields}'         => self::fields_block( $definition, $fields ),
		);

		$text = strtr( (string) $template, $replacements );

		$text = preg_replace_callback(
			'/\{field:([a-z][a-z0-9_]{0,39})\}/',
			function ( $matches ) use ( $definition, $fields ) {
				$key = (string) $matches[1];

				if ( ! array_key_exists( $key, $fields ) ) {
					return '';
				}

				return self::value_text( $fields[ $key ] );
			},
			$text
		);

		if ( ! is_string( $text ) ) {
			$text = '';
		}

		if ( 'subject' === $mode ) {
			return self::sanitise_subject( $text );
		}

		return self::sanitise_body( $text );
	}

	/**
	 * `{fields}` — one `Label: value` line per declared field.
	 *
	 * @param array $definition The definition.
	 * @param array $fields     Values.
	 * @return string
	 */
	public static function fields_block( array $definition, array $fields ) {
		$lines = array();

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
				continue;
			}

			$key = (string) $field['key'];

			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $key;

			$lines[] = $label . ': ' . self::value_text( $fields[ $key ] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * One value as plain text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function value_text( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( __CLASS__, 'scalar_text' ), $value ) );
		}

		return self::scalar_text( $value );
	}

	/**
	 * A scalar as text, with control characters removed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar_text( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'agentshell-forms' ) : __( 'No', 'agentshell-forms' );
		}

		if ( null === $value || ! is_scalar( $value ) ) {
			return '';
		}

		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
	}

	/**
	 * Sanitise a subject.
	 *
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function sanitise_subject( $subject ) {
		$subject = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $subject );
		$subject = trim( (string) preg_replace( '/\s+/', ' ', $subject ) );

		return self::truncate( $subject, self::MAX_SUBJECT );
	}

	/**
	 * Sanitise a plain-text body.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	public static function sanitise_body( $body ) {
		$body = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $body );

		return self::truncate( $body, self::MAX_BODY );
	}

	/**
	 * Process due notifications. The retry worker.
	 *
	 * @param string $kind Kind.
	 * @param int    $max  Maximum submissions to inspect.
	 * @return array{attempted:int,outcomes:array<string,int>}
	 */
	public static function run_due( $kind = 'admin', $max = 100 ) {
		$kind      = 'confirmation' === (string) $kind ? 'confirmation' : 'admin';
		$attempted = 0;
		$outcomes  = array();

		foreach ( self::due_candidates( $kind, $max ) as $submission_id ) {
			$definition = self::definition_for_submission( $submission_id );

			if ( null === $definition ) {
				continue;
			}

			$record = Submission_Store::get( $submission_id, true );

			if ( is_wp_error( $record ) ) {
				continue;
			}

			$result = self::attempt( $submission_id, $kind, $definition, $record['values'] );

			$attempted++;
			$outcome = isset( $result['outcome'] ) ? (string) $result['outcome'] : 'unknown';
			$outcomes[ $outcome ] = isset( $outcomes[ $outcome ] ) ? $outcomes[ $outcome ] + 1 : 1;
		}

		return array( 'attempted' => $attempted, 'outcomes' => $outcomes );
	}

	/**
	 * Submissions whose record may need work.
	 *
	 * ## Why the window rotates
	 *
	 * The scan is bounded — a cron tick must not become unbounded work — but a
	 * fixed window of "the oldest N records" is a starvation bug. On an
	 * installation with a long history the oldest N records were settled long
	 * ago, they occupy the window forever, and a record that becomes due
	 * behind them is never examined: the worker keeps finding the same settled
	 * records and the pending one is never reached.
	 *
	 * The window therefore **rotates**. Each run continues from the entry id
	 * the previous run stopped at, wraps around to the beginning when it
	 * reaches the end of the table, and examines at most `MAX_SCAN` records
	 * either way. Every record is therefore examined within
	 * `ceil(total / MAX_SCAN)` runs, and no run is unbounded.
	 *
	 * The cursor is a plugin option, so it survives between cron ticks and
	 * between processes.
	 *
	 * @param string $kind Kind.
	 * @param int    $max  Cap on returned ids.
	 * @return array<int,int>
	 */
	private static function due_candidates( $kind, $max ) {
		$max      = max( 1, (int) $max );
		$key      = Submission_Store::notification_key( $kind );
		$statuses = array(
			self::STATUS_PENDING,
			self::STATUS_FAILED,
			self::STATUS_UNCERTAIN,
			self::STATUS_CLAIMED,
		);

		$found    = array();
		$examined = 0;
		$cursor   = self::cursor_value( $kind );
		$last     = $cursor;
		$wrapped  = false;
		$now      = time();

		while ( $examined < self::MAX_SCAN && count( $found ) < $max ) {
			$page = self::scan_page( $key, $last, self::PAGE_SIZE );

			$at_end = ( count( $page ) < self::PAGE_SIZE );

			foreach ( $page as $post_id ) {
				$examined++;
				$last = (int) $post_id;

				if ( $wrapped && $last >= $cursor ) {
					// The wrap has come back to where this run started: the whole
					// table has been covered.
					break 2;
				}

				$state = Submission_Store::notification_state( (int) $post_id, $kind );

				if ( null === $state['record'] ) {
					continue;
				}

				$record = self::normalise_record( $state['record'], $kind );

				if ( ! in_array( $record['status'], $statuses, true ) ) {
					continue;
				}

				// A claim is a candidate whatever its clock says: the reconciler
				// is the thing that decides whether it has expired.
				if ( self::STATUS_CLAIMED !== $record['status'] ) {
					$due = self::timestamp( $record['next_attempt_at'] );

					if ( $due > $now ) {
						continue;
					}
				}

				$found[] = (int) $post_id;

				if ( count( $found ) >= $max ) {
					break;
				}
			}

			if ( count( $found ) >= $max || $examined >= self::MAX_SCAN ) {
				break;
			}

			if ( $at_end ) {
				// The end of the table. If this run started in the middle, wrap
				// around to the beginning and finish the rotation.
				if ( ! $wrapped && $cursor > 0 ) {
					$wrapped = true;
					$last    = 0;
					continue;
				}

				break;
			}
		}

		self::set_cursor_value( $kind, $last );

		return $found;
	}

	/**
	 * One page of entries that carry a notification record.
	 *
	 * Ordered by id and filtered by id, so the scan can resume exactly where
	 * the previous page stopped. The meta query is an `EXISTS` on the record's
	 * own key, which is what keeps the page to the submissions that have one.
	 *
	 * @param string $key      The notification meta key.
	 * @param int    $after_id Exclusive lower bound on the entry id.
	 * @param int    $size     Page size.
	 * @return array<int,int>
	 */
	private static function scan_page( $key, $after_id, $size ) {
		global $wpdb;

		// `WP_Query` has no native `ID >` operator: its `date_query` reads
		// `after`/`before` as date strings, so handing it an entry id (as the
		// earlier version of this code did) silently ignored the bound and
		// returned the same first page on every run — the starvation bug this
		// rotation exists to fix.
		$bind = (int) $after_id;

		$filter = static function ( $where ) use ( $wpdb, $bind ) {
			if ( $bind > 0 ) {
				$where .= $wpdb->prepare( ' AND ' . $wpdb->posts . '.ID > %d', $bind );
			}

			return $where;
		};

		add_filter( 'posts_where', $filter, 10 );

		$args = array(
			'post_type'      => Form_Post_Types::ENTRY,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) $size,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'suppress_filters' => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => $key,
					'compare' => 'EXISTS',
				),
			),
		);

		$posts = get_posts( $args );

		remove_filter( 'posts_where', $filter, 10 );

		return array_map( 'intval', (array) $posts );
	}

	/**
	 * The id the next run of this kind continues from.
	 *
	 * @param string $kind Kind.
	 * @return int
	 */
	private static function cursor_value( $kind ) {
		$stored = get_option( self::CURSOR_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return isset( $stored[ $kind ] ) ? (int) $stored[ $kind ] : 0;
	}

	/**
	 * Remember where this kind's scan stopped.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   The last entry id examined.
	 * @return void
	 */
	private static function set_cursor_value( $kind, $id ) {
		$stored = get_option( self::CURSOR_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored[ $kind ] = (int) $id;

		update_option( self::CURSOR_OPTION, $stored, false );
	}

	/**
	 * The published definition a submission was validated against, or null.
	 *
	 * The **published** definition is used, not the working one: a retry sends
	 * what the form currently promises, and a template the operator has not
	 * published must not reach a notification.
	 *
	 * @param int $submission_id Entry id.
	 * @return array|null
	 */
	private static function definition_for_submission( $submission_id ) {
		$record = Submission_Store::get( $submission_id, false );

		if ( is_wp_error( $record ) ) {
			return null;
		}

		// What the submission was accepted under. A form that has been
		// republished since must not change who receives this message.
		$snapshot = Submission_Store::notification_snapshot( (int) $submission_id );

		if ( is_array( $snapshot ) && ! empty( $snapshot ) ) {
			return self::definition_from_snapshot( $snapshot );
		}

		$post_id = Form_Definition::resolve_post_id( (string) $record['form_id'] );

		if ( $post_id < 1 ) {
			return null;
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) || ! is_array( $state['published'] ) ) {
			return null;
		}

		return $state['published'];
	}

	/**
	 * Turn stale claims into `uncertain` records.
	 *
	 * A worker that died while holding a claim leaves the record at `claimed`
	 * with nobody sending. The reconciler is what lets that state make
	 * progress, and it is deliberately the *same* worker — there is no second
	 * scheduler to disagree with.
	 *
	 * @param int $max Cap.
	 * @return int Records reconciled.
	 */
	public static function reconcile( $max = 100 ) {
		$reconciled = 0;

		foreach ( array( 'admin', 'confirmation' ) as $kind ) {
			foreach ( self::due_candidates( $kind, $max ) as $submission_id ) {
				$state = Submission_Store::notification_state( $submission_id, $kind );

				if ( null === $state['record'] ) {
					continue;
				}

				$record = self::normalise_record( $state['record'], $kind );

				if ( self::STATUS_CLAIMED !== $record['status'] ) {
					continue;
				}

				$claimed_at = self::timestamp( $record['claimed_at'] );

				if ( $claimed_at > 0 && ( $claimed_at + self::claim_ttl() ) > time() ) {
					continue;
				}

				$record['status']          = self::STATUS_UNCERTAIN;
				$record['last_error']      = 'claim_expired';
				$record['claimed_at']      = null;
				$record['claimed_by']      = null;
				$record['next_attempt_at'] = gmdate( 'c' );

				if ( Submission_Store::cas_notification( $submission_id, $kind, $record, $state ) ) {
					$reconciled++;
				}
			}
		}

		return $reconciled;
	}

	/**
	 * Fill in missing keys on a record written by an older build.
	 *
	 * @param array  $record Record.
	 * @param string $kind   Kind.
	 * @return array
	 */
	private static function normalise_record( array $record, $kind ) {
		$defaults = self::initial_record( $kind );

		$record = array_merge( $defaults, $record );

		$record['kind']     = (string) $kind;
		$record['attempts'] = (int) $record['attempts'];

		if ( ! in_array( $record['status'], array( self::STATUS_PENDING, self::STATUS_CLAIMED, self::STATUS_ACCEPTED, self::STATUS_FAILED, self::STATUS_UNCERTAIN, self::STATUS_EXHAUSTED ), true ) ) {
			$record['status'] = self::STATUS_PENDING;
		}

		return $record;
	}

	/**
	 * The site host, for the recipient allowlist.
	 *
	 * @return string
	 */
	private static function site_host() {
		if ( ! function_exists( 'wp_parse_url' ) ) {
			return '';
		}

		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Is an address on the site's own domain?
	 *
	 * @param string $email Email.
	 * @param string $host  Site host.
	 * @return bool
	 */
	private static function is_on_site( $email, $host ) {
		if ( '' === $host ) {
			return false;
		}

		$at = strrpos( (string) $email, '@' );

		if ( false === $at ) {
			return false;
		}

		$domain = strtolower( substr( (string) $email, $at + 1 ) );

		// The site's own domain or a subdomain of it. `example.com` may send
		// to `mail.example.com`; `evil-example.com` may not.
		return $domain === $host || substr( $domain, -1 * ( strlen( $host ) + 1 ) ) === '.' . $host;
	}

	/**
	 * The host part of a generated Message-ID.
	 *
	 * @return string
	 */
	private static function mail_host() {
		$host = self::site_host();

		if ( '' === $host ) {
			return 'localhost';
		}

		return (string) preg_replace( '/[^a-z0-9.\-]/i', '', $host );
	}

	/**
	 * A definition's field by key.
	 *
	 * @param array  $definition Definition.
	 * @param string $key        Field key.
	 * @return array|null
	 */
	private static function field( array $definition, $key ) {
		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) && (string) $field['key'] === (string) $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * An identifier for this worker.
	 *
	 * @return string
	 */
	private static function worker_id() {
		$host = function_exists( 'gethostname' ) ? (string) gethostname() : 'host';

		return substr( $host . ':' . getmypid() . ':' . bin2hex( random_bytes( 4 ) ), 0, 100 );
	}

	/**
	 * Parse an ISO-8601 timestamp, or null/empty.
	 *
	 * @param mixed $value Value.
	 * @return int Unix seconds, or 0 when absent/unparseable.
	 */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}

		$parsed = strtotime( $value );

		return false === $parsed ? 0 : (int) $parsed;
	}

	/**
	 * Truncate a string to a maximum, multibyte-aware where possible.
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum characters.
	 * @return string
	 */
	private static function truncate( $value, $max ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, (int) $max, 'UTF-8' );
		}

		return substr( $value, 0, (int) $max );
	}
}
