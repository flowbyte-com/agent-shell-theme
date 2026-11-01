<?php
/**
 * The public submission endpoint and the pipeline behind it.
 *
 * ## What a successful request proves
 *
 * A submission is accepted only when **all** of the following hold: the form
 * has a published definition, the request names the version the page was
 * rendered from and it is still the current one, the challenge is genuine and
 * unspent, the honeypot is empty, Turnstile's policy is satisfied (where the
 * form declares one), the request is inside the rate limits, and every
 * declared field validates. Anything else is a refusal that does not say which
 * control fired.
 *
 * ## The order, and why it is not the order in the design sketch
 *
 * §11.6 lists the pipeline as claim → Turnstile → honeypot → rate limit →
 * validate. Taken literally, a visitor who mistypes an address would spend
 * their single-use challenge, spend a rate-limit slot, and receive a field
 * error they cannot correct without a fresh token. That is not an abuse
 * control; it is a form that punishes typing.
 *
 * This implementation therefore:
 *
 *   1. **inspects** the challenge without claiming it (`Form_Challenges::inspect()`);
 *   2. checks the honeypot, then Turnstile (the cheap control first, so a bot
 *      that fills the trap costs no Cloudflare round trip);
 *   3. validates every declared field **before** the token is spent;
 *   4. reserves the rate-limit slots;
 *   5. **claims** the challenge atomically — the only step that decides
 *      ownership — and replays or refuses according to the claim;
 *   6. writes the submission, links the challenge and attempts the notification.
 *
 * Validation cannot be abused to skip the rate limit, because every attempt
 * needs a challenge token and minting one is itself rate-limited (per source
 * and per form, plus a site-wide backstop). The single-use property is
 * unchanged: exactly one request can win the claim, and a duplicate that loses
 * it replays the winner's success rather than writing a second submission.
 *
 * ## The stale-page contract
 *
 * A page may be cached, so a visitor can hold version 3's HTML after version 4
 * is published. The request carries the version the page was rendered from;
 * the server compares it with the published version **before anything is
 * written**, and a mismatch is `409 form_stale` with no claim and no
 * validation. A request that omits the version is treated as stale, because
 * the server cannot verify a page it cannot identify. The browser additionally
 * compares the version in the challenge response with the version in the page
 * and refuses to submit a stale form at all.
 *
 * ## Refusals
 *
 * A refusal that is not a field error is one shape —
 * `{"ok":false,"code":"form_rejected","message":"This submission was not accepted."}`
 * — whatever fired. The deliberate exceptions are the three states a visitor
 * must be able to act on: `form_stale` (reload), `form_pending` (wait) and
 * `form_retry` (a fresh challenge is supplied). None of them names an
 * anti-spam control.
 *
 * The honeypot is the one place a refusal is not a refusal: a filled honeypot
 * receives the form's ordinary success response, and nothing is stored or
 * mailed. Telling a bot it was caught teaches the bot what to change.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp/v2/agentshell-forms/v1/submit`.
 */
class Submission_Handler {

	/**
	 * The REST namespace, shared with the challenge route.
	 */
	const REST_NAMESPACE = 'wp/v2/agentshell-forms/v1';

	/**
	 * The route, relative to the namespace.
	 */
	const ROUTE = '/submit';

	/**
	 * The largest request body, in bytes.
	 */
	const MAX_BODY_BYTES = 65536;

	/**
	 * The largest number of keys a request may carry.
	 */
	const MAX_FIELDS = 100;

	/**
	 * The largest one field's raw value may be, in bytes.
	 */
	const MAX_FIELD_BYTES = 20000;

	/**
	 * The site-wide submission backstop: requests per window, all sources.
	 */
	const SITE_RATE_LIMIT = 600;

	/**
	 * The site-wide window, in seconds.
	 */
	const SITE_RATE_WINDOW = 300;

	/**
	 * How long a claimed-but-unlinked challenge may be considered in flight
	 * before a retry is offered a fresh challenge instead of asked to wait.
	 */
	const PENDING_GRACE = 30;

	/**
	 * The honeypot field's name.
	 */
	const HONEYPOT_FIELD = 'agentshell_forms_hp';

	/**
	 * The site-wide request limit, filterable for a site with legitimate volume
	 * (or a test bed that deliberately exercises a flood).
	 *
	 * @return int
	 */
	public static function site_limit() {
		$limit = (int) apply_filters( 'agentshell_forms_site_rate_limit', self::SITE_RATE_LIMIT );

		return $limit > 0 ? $limit : self::SITE_RATE_LIMIT;
	}

	/**
	 * The site-wide window, in seconds.
	 *
	 * @return int
	 */
	public static function site_window() {
		$window = (int) apply_filters( 'agentshell_forms_site_rate_window', self::SITE_RATE_WINDOW );

		return $window > 0 ? $window : self::SITE_RATE_WINDOW;
	}

	/**
	 * Register the route and the header filter that keeps it uncacheable.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: the caller is a visitor who has not logged in.
				'permission_callback' => '__return_true',
			)
		);

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );

			// The size of the request is decided before anything decodes it.
			// `rest_pre_dispatch` runs before WordPress parses the JSON body
			// (the parse happens when the route's parameters are validated,
			// which is later), so an oversized request is refused here —
			// before the decoder is invoked, and before the body is parsed
			// into memory.
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 10, 3 );
		}
	}

	/**
	 * Refuse an oversized request before the body is decoded.
	 *
	 * @param mixed $result  The pre-dispatch result, or null.
	 * @param mixed $server  The server (unused).
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public static function pre_dispatch( $result, $server = null, $request = null ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_body' ) ) {
			return $result;
		}

		if ( ! self::is_submit_request( $request ) ) {
			return $result;
		}

		$raw = (string) $request->get_body();

		if ( strlen( $raw ) <= self::MAX_BODY_BYTES ) {
			return $result;
		}

		self::log( '', 'too_large', array( 'body' ) );

		return self::refuse(
			'form_rejected',
			__( 'This submission was not accepted.', 'agentshell-forms' ),
			413
		);
	}

	/**
	 * Is this request for the submission route?
	 *
	 * The route is not matched yet when `rest_pre_dispatch` runs, so the path
	 * is what the request is identified by.
	 *
	 * @param mixed $request The request.
	 * @return bool
	 */
	private static function is_submit_request( $request ) {
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		if ( '' === $route && method_exists( $request, 'get_uri' ) ) {
			$uri  = (string) $request->get_uri();
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

			$route = '' !== $path ? $path : $uri;
		}

		$needle = self::REST_NAMESPACE . self::ROUTE;

		if ( '' === $route ) {
			return false;
		}

		if ( $route === '/' . $needle ) {
			return true;
		}

		// The tail, for a request whose path carries a subdirectory.
		$length = strlen( $needle );

		return strlen( $route ) > $length && substr( $route, -$length ) === $needle;
	}

	/**
	 * The submission URL.
	 *
	 * @return string
	 */
	public static function submit_url() {
		return esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE ) );
	}

	/**
	 * Mark every response from this route uncacheable.
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The server (unused).
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public static function no_store( $response, $server = null, $request = null ) {
		unset( $server );

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::REST_NAMESPACE . self::ROUTE ) ) {
			return $response;
		}

		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}

		return $response;
	}

	/**
	 * Handle one submission.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function handle( $request ) {
		// The size of the request is decided **before** the body is decoded.
		//
		// Decoding first meant that an oversized body was parsed in full —
		// allocated, decoded, and only then measured — so the limit was a
		// statement about what the server was willing to keep, not about what
		// it was willing to spend. A body that is both oversized and malformed
		// was answered `400` (it failed to parse) instead of `413` (it was
		// too large), which is the wrong order of questions.
		$raw = self::raw_body( $request );

		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			self::log( '', 'too_large', array( 'body' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 413 );
		}

		$body = self::json_body( $request, $raw );

		if ( null === $body ) {
			self::log( '', 'malformed', array( 'not_json' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 400 );
		}

		$form_id = isset( $body['form_id'] ) && is_string( $body['form_id'] ) ? $body['form_id'] : '';

		if ( ! preg_match( Form_Schema::FORM_ID_PATTERN, $form_id ) ) {
			self::log( '', 'malformed', array( 'form_id' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 400 );
		}

		$fields = self::fields_from( $body );

		if ( null === $fields ) {
			self::log( $form_id, 'malformed', array( 'fields' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 400 );
		}

		if ( count( $fields ) > self::MAX_FIELDS ) {
			self::log( $form_id, 'too_large', array( 'field_count' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 413 );
		}

		foreach ( $fields as $value ) {
			if ( self::raw_length( $value ) > self::MAX_FIELD_BYTES ) {
				self::log( $form_id, 'too_large', array( 'field' ) );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 413 );
			}
		}

		// The form.
		$post_id = Form_Definition::resolve_post_id( $form_id );

		if ( $post_id < 1 ) {
			self::log( $form_id, 'malformed', array( 'unknown_form' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}

		if ( 'trash' === (string) get_post_status( $post_id ) ) {
			self::log( $form_id, 'malformed', array( 'trashed_form' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) || ! is_array( $state['published'] ) ) {
			self::log( $form_id, 'malformed', array( 'unpublished' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}

		$definition = $state['published'];
		$current    = (int) $state['version'];

		$errors = Form_Schema::validate(
			$definition,
			array(
				'site_host'          => Form_Definition::site_host(),
				'slug_taken'         => function () {
					return false;
				},
				'has_been_published' => true,
				'previous_slug'      => (string) $state['slug'],
			)
		);

		if ( ! empty( $errors ) ) {
			// A broken published definition is the operator's problem, not the
			// visitor's. Nothing is stored against a schema nobody can trust.
			self::log( $form_id, 'write_failed', array( 'invalid_definition' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 503 );
		}

		// The version the page was rendered from.
		$stale = self::stale_response( $body, $current, $form_id );

		if ( null !== $stale ) {
			return $stale;
		}

		$version = (int) $body['form_version'];

		$challenge = isset( $body['challenge'] ) && is_string( $body['challenge'] ) ? trim( $body['challenge'] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $challenge ) ) {
			self::log( $form_id, 'challenge_invalid', array( 'format' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}

		$inspection = Form_Challenges::inspect( $challenge );

		$early = self::early_challenge_outcome( $inspection, $form_id, $current, $challenge, $definition );

		if ( null !== $early ) {
			return $early;
		}

		// A weak timing signal, enforced only when the form does not carry a
		// stronger control. §11.4: it is off by default for a form with a
		// honeypot or Turnstile, because it penalises a fast typist and an
		// assistive-technology user who pastes.
		if ( self::min_seconds_enforced( $definition ) ) {
			$minted    = self::timestamp( (string) $inspection['created_at'] );
			$min       = (int) $definition['settings']['spam']['min_seconds'];
			$elapsed   = $minted > 0 ? ( time() - $minted ) : $min;

			if ( $elapsed < $min ) {
				self::log( $form_id, 'challenge_invalid', array( 'too_fast' ) );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
			}
		}

		// The honeypot. A filled trap is answered with the ordinary success
		// response and nothing is stored or mailed.
		$honeypot = isset( $body[ self::HONEYPOT_FIELD ] ) ? trim( (string) $body[ self::HONEYPOT_FIELD ] ) : '';

		if ( '' === $honeypot && isset( $_POST[ self::HONEYPOT_FIELD ] ) ) {
			$honeypot = trim( (string) $_POST[ self::HONEYPOT_FIELD ] );
		}

		if ( '' !== $honeypot ) {
			self::log( $form_id, 'honeypot', array() );

			return self::success_response( $definition, true );
		}

		$turnstile_token = isset( $body['turnstile_token'] ) && is_string( $body['turnstile_token'] )
			? $body['turnstile_token']
			: '';

		$policy = Form_Turnstile::apply_policy( $definition, $turnstile_token, $form_id, self::address() );

		if ( 'refuse' === $policy['decision'] ) {
			$reason = 'turnstile_failed';

			if ( 'unavailable' === $policy['reason'] ) {
				$reason = 'turnstile_unavailable';
			} elseif ( 'misconfigured' === $policy['reason'] ) {
				$reason = 'turnstile_misconfigured';
			}

			$codes = array();

			if ( is_array( $policy['verification'] ) ) {
				if ( ! empty( $policy['verification']['codes'] ) && is_array( $policy['verification']['codes'] ) ) {
					$codes = $policy['verification']['codes'];
				} elseif ( ! empty( $policy['verification']['detail'] ) ) {
					$codes = array( (string) $policy['verification']['detail'] );
				}
			}

			self::log( $form_id, $reason, $codes );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}

		// Validate before spending the challenge.
		$values  = array();
		$invalid = self::validate_fields( $definition, $fields, $values );

		if ( is_wp_error( $invalid ) ) {
			self::log( $form_id, 'write_failed', array( $invalid->get_error_code() ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 503 );
		}

		if ( ! empty( $invalid ) ) {
			return self::field_errors_response( $invalid );
		}

		// The rate limits, reserved once the request is otherwise admissible.
		$limits = self::reserve_rate_limits( $definition, $form_id );

		if ( is_wp_error( $limits ) ) {
			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 503 );
		}

		if ( ! $limits['admitted'] ) {
			self::log( $form_id, 'rate_limited', array() );

			$response = self::refuse(
				'form_rejected',
				__( 'This submission was not accepted. Please try again later.', 'agentshell-forms' ),
				429
			);

			if ( $limits['retry_after'] > 0 ) {
				$response->header( 'Retry-After', (string) $limits['retry_after'] );
			}

			return $response;
		}

		// The claim is the only decision that matters. The inspection above may
		// be stale by now; this one cannot be.
		$claim = Form_Challenges::claim( $challenge );

		$outcome = self::claim_outcome( $claim, $form_id, $current, $definition );

		if ( null !== $outcome ) {
			return $outcome;
		}

		// Belt and braces: the claim succeeded, so the token was unspent, but a
		// submission for it may still exist from an earlier attempt whose link
		// failed. One challenge may produce one submission, so an existing one
		// is reconciled rather than duplicated.
		$existing = self::reconcile_submission( $challenge );

		if ( $existing > 0 ) {
			return self::success_response( $definition, false );
		}

		$notification = null;
		if ( ! empty( $definition['settings']['notification']['enabled'] ) ) {
			$notification = Form_Notifier::initial_record( 'admin' );
		}

		$confirmation = null;
		if ( ! empty( $definition['settings']['notification']['confirmation']['enabled'] ) && '' !== Form_Notifier::confirmation_recipient( $definition, $values ) ) {
			$confirmation = Form_Notifier::initial_record( 'confirmation' );
		}

		$meta = array(
			'received_at' => gmdate( 'c' ),
			'source'      => 'web',
		);

		$storage = isset( $definition['settings']['storage'] ) && is_array( $definition['settings']['storage'] )
			? $definition['settings']['storage']
			: array();

		if ( ! empty( $storage['store_ip'] ) ) {
			// A truncated HMAC, never the address and never a plain hash.
			$meta['subject_hash'] = substr( Form_Challenge_Endpoint::subject_hash(), 0, 32 );
		}

		if ( ! empty( $storage['store_user_agent'] ) ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
			$meta['user_agent'] = substr( $agent, 0, 300 );
		}

		$created = Submission_Store::create(
			array(
				'form_id'      => $form_id,
				'form_name'    => isset( $definition['name'] ) ? (string) $definition['name'] : '',
				'form_version' => $version,
				'fields'       => $values,
				'spam_status'  => (string) $policy['spam_status'],
				'meta'         => $meta,
				'challenge'    => hash( 'sha256', $challenge ),
				'notification' => $notification,
				'confirmation' => $confirmation,
				// What this submission was accepted under. A retry composes
				// the message from this, so a form republished in the meantime
				// cannot redirect it to a different recipient.
				'notification_snapshot' => Form_Notifier::snapshot( $definition ),
			)
		);

		if ( is_wp_error( $created ) ) {
			self::log( $form_id, 'write_failed', array( $created->get_error_code() ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 503 );
		}

		$link = Form_Challenges::link( $challenge, (int) $created['post_id'] );

		if ( ! $link['linked'] && 'already_linked' !== $link['status'] ) {
			// The submission exists and is the visitor's. A link failure means a
			// retry will not replay this success, so it is recorded for the
			// operator — but it must not turn a stored submission into a refusal.
			Form_Audit::record(
				'form_submission_link_failed',
				array(
					'submission' => (int) $created['post_id'],
					'form_id'    => $form_id,
					'status'     => (string) $link['status'],
				),
				0
			);
		}

		// Notification is attempted after the submission is durable. It cannot
		// roll it back and it cannot create a second one.
		try {
			Form_Notifier::dispatch( (int) $created['post_id'], $definition, $values );
		} catch ( \Throwable $e ) {
			Form_Audit::record(
				'form_notification_failed',
				array(
					'submission' => (int) $created['post_id'],
					'error'      => substr( $e->getMessage(), 0, 200 ),
				),
				0
			);
		}

		return self::success_response( $definition, false );
	}

	/**
	 * The decoded JSON body, or null.
	 *
	 * @param mixed $request The request.
	 * @return array|null
	 */
	private static function json_body( $request, $raw = null ) {
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$params = $request->get_json_params();

			return is_array( $params ) ? $params : null;
		}

		if ( null === $raw ) {
			$raw = self::raw_body( $request );
		}

		if ( '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The raw request body.
	 *
	 * @param mixed $request The request.
	 * @return string
	 */
	private static function raw_body( $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_body' ) ) {
			return (string) $request->get_body();
		}

		$raw = file_get_contents( 'php://input' );

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * The `fields` map, or null when it is not a map.
	 *
	 * A JSON list is not a map: field keys match `^[a-z][a-z0-9_]{0,39}$`, so
	 * integer keys can only come from a client that sent something else.
	 *
	 * @param array $body Body.
	 * @return array|null
	 */
	private static function fields_from( array $body ) {
		if ( ! isset( $body['fields'] ) || ! is_array( $body['fields'] ) ) {
			return null;
		}

		$fields = $body['fields'];

		if ( array() !== $fields && array_keys( $fields ) === range( 0, count( $fields ) - 1 ) ) {
			return null;
		}

		return $fields;
	}

	/**
	 * The byte length of a raw value.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function raw_length( $value ) {
		if ( is_array( $value ) ) {
			$length = 0;

			foreach ( $value as $item ) {
				$length += self::raw_length( $item );
			}

			return $length;
		}

		if ( is_scalar( $value ) ) {
			return strlen( (string) $value );
		}

		return 0;
	}

	/**
	 * The stale-page refusal, or null when the version matches.
	 *
	 * @param array  $body    Body.
	 * @param int    $current The published version.
	 * @param string $form_id Form id.
	 * @return \WP_REST_Response|null
	 */
	private static function stale_response( array $body, $current, $form_id ) {
		$supplied = isset( $body['form_version'] ) ? $body['form_version'] : null;

		if ( null === $supplied || '' === $supplied ) {
			self::log( $form_id, 'stale', array( 'version_missing' ) );

			return self::stale( $current );
		}

		if ( is_string( $supplied ) && ctype_digit( $supplied ) ) {
			$supplied = (int) $supplied;
		}

		if ( ! is_int( $supplied ) ) {
			self::log( $form_id, 'malformed', array( 'version' ) );

			return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 400 );
		}

		if ( (int) $supplied !== (int) $current ) {
			self::log( $form_id, 'stale', array( 'version_mismatch' ) );

			return self::stale( $current );
		}

		return null;
	}

	/**
	 * The outcome an inspection alone can decide, or null to continue.
	 *
	 * @param array  $inspection The challenge snapshot.
	 * @param string $form_id    Form id.
	 * @param int    $current    Published version.
	 * @param string $challenge  The token.
	 * @param array  $definition The definition.
	 * @return \WP_REST_Response|null
	 */
	private static function early_challenge_outcome( array $inspection, $form_id, $current, $challenge, array $definition ) {
		switch ( $inspection['status'] ) {
			case 'unclaimed':
				if ( (string) $inspection['form_id'] !== (string) $form_id ) {
					self::log( $form_id, 'challenge_invalid', array( 'mismatch' ) );

					return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
				}

				// The token belongs to this form but to an older published
				// version: the page it came from is out of date. Saying so is
				// what gives the visitor a way forward (reload), and it is the
				// same answer the version check above gives. It discloses
				// nothing an attacker could not read from the page itself.
				if ( (int) $inspection['form_version'] !== (int) $current ) {
					self::log( $form_id, 'stale', array( 'challenge_version' ) );

					return self::stale( $current );
				}

				return null;

			case 'replay':
				if ( (string) $inspection['form_id'] !== (string) $form_id ) {
					self::log( $form_id, 'challenge_invalid', array( 'mismatch' ) );

					return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
				}

				return self::success_response( $definition, false );

			case 'pending':
				// The challenge is claimed but the row does not point at a
				// submission. That is what a **failed link** leaves behind:
				// the submission was written, and the bookkeeping that ties it
				// to the token was not.
				//
				// The submission is found by the hash of the token that
				// produced it, linked, and the visitor is answered with the
				// success they are owed. Without this, the retry would be told
				// to wait, then handed a fresh token, and the fresh token
				// would create a *second* copy of a message the site already
				// holds.
				$existing = self::reconcile_submission( $challenge );

				if ( $existing > 0 ) {
					return self::success_response( $definition, false );
				}

				return self::pending_response( $inspection, $form_id, $current, $definition );

			case 'expired':
				self::log( $form_id, 'challenge_expired', array() );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );

			case 'absent':
			case 'invalid':
			default:
				self::log( $form_id, 'challenge_invalid', array( (string) $inspection['status'] ) );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}
	}

	/**
	 * The outcome an authoritative claim decides, or null to continue.
	 *
	 * @param array  $claim      The claim result.
	 * @param string $form_id    Form id.
	 * @param int    $current    Version.
	 * @param array  $definition Definition.
	 * @return \WP_REST_Response|null
	 */
	private static function claim_outcome( array $claim, $form_id, $current, array $definition ) {
		switch ( $claim['status'] ) {
			case 'claimed':
				if ( (string) $claim['form_id'] !== (string) $form_id ) {
					self::log( $form_id, 'challenge_invalid', array( 'claim_mismatch' ) );

					return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
				}

				if ( (int) $claim['form_version'] !== (int) $current ) {
					self::log( $form_id, 'stale', array( 'claim_version' ) );

					return self::stale( $current );
				}

				return null;

			case 'replay':
				if ( (string) $claim['form_id'] !== (string) $form_id ) {
					self::log( $form_id, 'challenge_invalid', array( 'claim_mismatch' ) );

					return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
				}

				return self::success_response( $definition, false );

			case 'pending':
				return self::pending_response( $claim, $form_id, $current, $definition );

			case 'expired':
				self::log( $form_id, 'challenge_expired', array() );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );

			default:
				self::log( $form_id, 'challenge_invalid', array( (string) $claim['status'] ) );

				return self::refuse( 'form_rejected', __( 'This submission was not accepted.', 'agentshell-forms' ), 422 );
		}
	}

	/**
	 * A claimed challenge that produced no submission yet.
	 *
	 * @param array  $inspection Inspection or claim.
	 * @param string $form_id    Form id.
	 * @param int    $current    Version.
	 * @param array  $definition Definition.
	 * @return \WP_REST_Response
	 */
	private static function reconcile_submission( $challenge ) {
		$challenge = (string) $challenge;

		if ( '' === $challenge ) {
			return 0;
		}

		$entry = Submission_Store::find_by_challenge( hash( 'sha256', $challenge ) );

		if ( $entry < 1 ) {
			return 0;
		}

		// The link is the repairable part: the submission is the part that
		// matters, and it is already stored.
		Form_Challenges::link( $challenge, $entry );

		return $entry;
	}

	/**
	 * Answer a submission that is already stored.
	 *
	 * @param array $inspection The challenge inspection.
	 * @param string $form_id   The form identifier.
	 * @param int    $current   The current published version.
	 * @param array $definition The published definition.
	 * @return \WP_REST_Response
	 */
	private static function pending_response( array $inspection, $form_id, $current, array $definition ) {
		self::log( $form_id, 'challenge_pending', array() );

		$consumed = self::timestamp( (string) $inspection['consumed_at'] );

		// A recent claim is another request genuinely in flight. Asking the
		// browser to wait and retry the same request is what lets a lost
		// response become a replay rather than a second submission.
		if ( $consumed > 0 && ( $consumed + self::PENDING_GRACE ) > time() ) {
			$response = self::refuse(
				'form_pending',
				__( 'This submission is still being processed. Please wait a moment and try again.', 'agentshell-forms' ),
				409,
				array( 'retry_after' => 2 )
			);

			$response->header( 'Retry-After', '2' );

			return $response;
		}

		// An old claim belongs to an attempt that died. A fresh challenge lets
		// the visitor make progress without reloading the page — but minting is
		// still rate-limited, and a caller that has exhausted the mint budget
		// gets a retryable answer with no token rather than an unbounded supply
		// of them.
		$bucket = Form_Rate_Limits::reserve(
			Form_Rate_Limits::SCOPE_MINT,
			Form_Challenge_Endpoint::subject_hash(),
			(string) $form_id,
			Form_Challenge_Endpoint::RATE_LIMIT,
			Form_Challenge_Endpoint::RATE_WINDOW
		);

		$extra = array( 'retry' => true );

		if ( empty( $bucket['error'] ) && ! empty( $bucket['admitted'] ) ) {
			$fresh = Form_Challenges::mint( (string) $form_id, (int) $current, Form_Challenge_Endpoint::subject_hash() );

			if ( is_string( $fresh ) && '' !== $fresh ) {
				$extra['challenge']    = $fresh;
				$extra['form_version'] = (int) $current;
			}
		}

		return self::refuse(
			'form_retry',
			__( 'This submission could not be completed. Please try again.', 'agentshell-forms' ),
			409,
			$extra
		);
	}

	/**
	 * Validate every declared field, filling `$values`.
	 *
	 * @param array $definition The published definition.
	 * @param array $raw        The request's field map.
	 * @param array $values     Filled with the validated values.
	 * @return array<string,string>|\WP_Error Field key => message, or an error
	 *                                       when the definition cannot be
	 *                                       validated at all.
	 */
	public static function validate_fields( array $definition, array $raw, array &$values ) {
		$values = array();
		$errors = array();

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
				continue;
			}

			$key  = (string) $field['key'];
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			$declaration = Form_Field_Types::get( $type );

			if ( null === $declaration ) {
				return new \WP_Error(
					'form_field_type_unknown',
					sprintf(
						/* translators: %s: field type */
						__( 'The field type "%s" is not registered.', 'agentshell-forms' ),
						$type
					)
				);
			}

			$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();
			$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
			$required = ! empty( $field['required'] );

			// A definition-owned value is read from the definition and the
			// request's value for it is never looked at (§5.4).
			if ( ! empty( $declaration['definition_value'] ) ) {
				$values[ $key ] = isset( $settings['value'] ) ? (string) $settings['value'] : '';
				continue;
			}

			$present = array_key_exists( $key, $raw );
			$value   = $present ? $raw[ $key ] : ( ! empty( $declaration['multiple'] ) ? array() : '' );

			if ( ! empty( $declaration['multiple'] ) && ! is_array( $value ) ) {
				$value = array();
			}

			if ( ! empty( $declaration['multiple'] ) && is_array( $value ) ) {
				$clean = array();

				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$clean[] = (string) $item;
					}
				}

				$value = $clean;
			}

			if ( ! empty( $declaration['boolean'] ) ) {
				$value = self::to_bool( $value );
			} elseif ( ! is_array( $value ) && ! is_scalar( $value ) ) {
				$value = '';
			}

			$sanitised = call_user_func( $declaration['sanitize'], $value );

			if ( $required && self::is_empty_value( $sanitised ) ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: field label */
					__( '%s is required.', 'agentshell-forms' ),
					self::label( $field, $key )
				);
				continue;
			}

			if ( is_string( $sanitised ) && strlen( $sanitised ) > self::MAX_FIELD_BYTES ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: field label */
					__( '%s is too long.', 'agentshell-forms' ),
					self::label( $field, $key )
				);
				continue;
			}

			$check = call_user_func( $declaration['validate'], $sanitised, $settings, $options );

			if ( is_wp_error( $check ) ) {
				$errors[ $key ] = $check->get_error_message();
				continue;
			}

			$values[ $key ] = $sanitised;
		}

		return $errors;
	}

	/**
	 * Reserve both rate-limit slots.
	 *
	 * @param array  $definition The definition.
	 * @param string $form_id    Form id.
	 * @return array{admitted:bool,retry_after:int}|\WP_Error
	 */
	private static function reserve_rate_limits( array $definition, $form_id ) {
		$spam   = isset( $definition['settings']['spam'] ) && is_array( $definition['settings']['spam'] ) ? $definition['settings']['spam'] : array();
		$rate   = isset( $spam['rate_limit'] ) && is_array( $spam['rate_limit'] ) ? $spam['rate_limit'] : array();
		$max    = isset( $rate['max'] ) ? (int) $rate['max'] : 5;
		$window = isset( $rate['window'] ) ? (int) $rate['window'] : 300;

		$subject = Form_Challenge_Endpoint::subject_hash();

		$form_bucket = Form_Rate_Limits::reserve( Form_Rate_Limits::SCOPE_FORM, $subject, $form_id, $max, $window );

		if ( ! empty( $form_bucket['error'] ) ) {
			return $form_bucket['error'];
		}

		$site_bucket = Form_Rate_Limits::reserve(
			Form_Rate_Limits::SCOPE_SITE,
			Form_Rate_Limits::SITE_SUBJECT,
			'',
			self::site_limit(),
			self::site_window()
		);

		if ( ! empty( $site_bucket['error'] ) ) {
			return $site_bucket['error'];
		}

		$retry = 0;

		foreach ( array( $form_bucket, $site_bucket ) as $bucket ) {
			if ( ! empty( $bucket['admitted'] ) ) {
				continue;
			}

			$start  = isset( $bucket['window_start'] ) ? (int) $bucket['window_start'] : 0;
			$length = isset( $bucket['window'] ) ? (int) $bucket['window'] : 300;
			$wait   = $start > 0 ? ( $start + $length ) - time() : $length;

			$retry = max( $retry, $wait > 0 ? $wait : $length );
		}

		return array(
			'admitted'    => ! empty( $form_bucket['admitted'] ) && ! empty( $site_bucket['admitted'] ),
			'retry_after' => $retry,
		);
	}

	/**
	 * Is the minimum-fill-time rule in force for this form?
	 *
	 * Off when the form has a honeypot or a Turnstile policy — the stronger
	 * controls — unless a filter forces it. The schema always carries a
	 * non-zero default, so the rule has to be about the combination, not about
	 * the number alone.
	 *
	 * @param array $definition The definition.
	 * @return bool
	 */
	private static function min_seconds_enforced( array $definition ) {
		$spam     = isset( $definition['settings']['spam'] ) && is_array( $definition['settings']['spam'] ) ? $definition['settings']['spam'] : array();
		$min      = isset( $spam['min_seconds'] ) ? (int) $spam['min_seconds'] : 0;
		$honeypot = ! empty( $spam['honeypot'] );
		$policy   = Form_Turnstile::policy_for( $definition );

		if ( $min < 1 ) {
			return false;
		}

		if ( ! $honeypot && 'disabled' === $policy ) {
			return true;
		}

		return (bool) apply_filters( 'agentshell_forms_enforce_min_seconds', false, $definition );
	}

	/**
	 * The success response.
	 *
	 * @param array $definition The definition.
	 * @param bool  $fake       Whether this is the honeypot's fake success.
	 * @return \WP_REST_Response
	 */
	private static function success_response( array $definition, $fake ) {
		unset( $fake );

		$message = isset( $definition['settings']['success_message'] ) && '' !== (string) $definition['settings']['success_message']
			? (string) $definition['settings']['success_message']
			: __( 'Thank you — your message has been sent.', 'agentshell-forms' );

		$redirect = isset( $definition['settings']['redirect_url'] ) ? (string) $definition['settings']['redirect_url'] : '';

		$body = array(
			'ok'      => true,
			'message' => $message,
		);

		if ( '' !== $redirect ) {
			$body['redirect'] = $redirect;
		}

		return self::json( $body, 200 );
	}

	/**
	 * The field-error response.
	 *
	 * @param array $errors Field key => message.
	 * @return \WP_REST_Response
	 */
	private static function field_errors_response( array $errors ) {
		$clean = array();

		foreach ( $errors as $key => $message ) {
			$clean[ (string) $key ] = (string) $message;
		}

		return self::json(
			array(
				'ok'      => false,
				'code'    => 'form_invalid',
				'message' => __( 'Please check the highlighted fields and try again.', 'agentshell-forms' ),
				'errors'  => $clean,
			),
			422
		);
	}

	/**
	 * The stale-page response.
	 *
	 * @param int $current The current published version.
	 * @return \WP_REST_Response
	 */
	private static function stale( $current ) {
		return self::json(
			array(
				'ok'      => false,
				'code'    => 'form_stale',
				'message' => __( 'This form has changed since this page was loaded. Please reload the page to see the current version.', 'agentshell-forms' ),
				'reload'  => true,
				'version' => (int) $current,
			),
			409
		);
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 * @param array  $extra   Extra body keys.
	 * @return \WP_REST_Response
	 */
	private static function refuse( $code, $message, $status, array $extra = array() ) {
		$body = array_merge(
			array(
				'ok'      => false,
				'code'    => (string) $code,
				'message' => (string) $message,
			),
			$extra
		);

		return self::json( $body, (int) $status );
	}

	/**
	 * A JSON response with the no-store headers.
	 *
	 * @param array $body   Body.
	 * @param int   $status Status.
	 * @return \WP_REST_Response
	 */
	private static function json( array $body, $status ) {
		$response = new \WP_REST_Response( $body, (int) $status );

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * Record a refusal without any submitted value.
	 *
	 * @param string $form_id Form id.
	 * @param string $reason  Reason code.
	 * @param array  $codes   Extra machine-readable facts.
	 * @return void
	 */
	private static function log( $form_id, $reason, array $codes = array() ) {
		Form_Rejections::record( (string) $form_id, (string) $reason, $codes, Form_Challenge_Endpoint::subject_hash() );
	}

	/**
	 * The visitor's address, or ''.
	 *
	 * @return string
	 */
	private static function address() {
		return isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? trim( $_SERVER['REMOTE_ADDR'] )
			: '';
	}

	/**
	 * A field's label, or its key.
	 *
	 * @param array  $field Field.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function label( array $field, $key ) {
		return isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : (string) $key;
	}

	/**
	 * Is a sanitised value absent?
	 *
	 * `empty()` would call the string `"0"` empty, and a number field whose
	 * value is zero is a value the visitor supplied.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( $value ) {
		if ( null === $value || false === $value ) {
			return true;
		}

		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return '' === (string) $value;
	}

	/**
	 * Interpret a request value as a boolean without `(bool) "false"`.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0.0 !== (float) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	/**
	 * Parse an ISO timestamp or a MySQL datetime.
	 *
	 * @param string $value Value.
	 * @return int Unix seconds, or 0.
	 */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}

		$parsed = strtotime( $value );

		return false === $parsed ? 0 : (int) $parsed;
	}
}
