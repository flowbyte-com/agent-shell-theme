<?php
/**
 * The one public route Phase 2 needs: issuing a submission challenge.
 *
 * ## Why a challenge cannot be in the page
 *
 * A challenge is single-use, and a page is not. The first visitor to receive a
 * cached page would consume the challenge embedded in it, and every visitor
 * after them would present a token that was already claimed — so either the
 * page cannot be cached, or the challenge cannot be in the page. Page caching
 * is not negotiable on a real site (and a CDN sits in front of many of them, so
 * a server that "does not cache" may still be cached), and a form that only
 * works for one visitor per cache entry is broken. The challenge is therefore
 * provisioned **per visitor, per form instance, after the page has been
 * delivered**.
 *
 * `Form_Renderer` consequently emits no per-visitor value at all: the challenge
 * input is rendered empty and the browser fills it. Two visitors, one cached
 * page, two independent challenges — which is the property this endpoint
 * exists to provide.
 *
 * ## What this route does, and what it refuses to do
 *
 * It **mints a token and returns it**. It does not accept a submission, does
 * not read field values, does not write anything except a challenge row and a
 * rate-limit counter, and does not send an email. Submission processing is
 * Phase 3 and is not present in this build; a route that half-accepted a
 * submission would be worse than one that does not exist.
 *
 * ## The limits, stated plainly
 *
 * - **It is public.** It has to be: a visitor is not logged in. It is
 *   `__return_true` and that is deliberate, not an oversight.
 * - **It mints for the current published version and nothing else.** A
 *   client-supplied `form_version` is not read at all. A version the client
 *   cannot choose cannot be used to ask for a challenge against a definition
 *   the visitor was never shown.
 * - **It accepts the internal identifier only.** The identifier is 128 bits of
 *   randomness, so it cannot be enumerated; a slug would be guessable and would
 *   let anybody mint challenges for a form they cannot see.
 * - **It answers 404 for both "no such form" and "no published version".** The
 *   distinction would disclose the existence of a draft, and a caller that
 *   cannot render the form has no business learning about it.
 * - **It is rate-limited per source and per form** (~30 mints per 5 minutes).
 *   The counter is keyed on an HMAC of the source address, never on the address
 *   itself (§9.1: no personal data in the protocol tables without a declared
 *   purpose). A refusal is a 429 with `Retry-After`, not a silent failure.
 * - **Every response is `no-store`**, including the refusals — a cached refusal
 *   would be served to the next visitor, who might well be entitled to a
 *   challenge. The headers are applied in one place, by a route filter, so no
 *   branch of this class can forget them.
 * - **It is not a cross-site resource.** Nothing here is authenticated, so a
 *   cross-site caller could mint challenges; it could also simply load the
 *   page. No credential is minted, no session is touched and no state is
 *   readable, so there is nothing to protect with a same-origin check beyond
 *   the rate limit that is already applied.
 *
 * ## What Phase 3 inherits
 *
 * The minted token is stored as a SHA-256 in the challenge table with the form
 * identifier, the published version, the source HMAC and an expiry
 * (`Form_Challenges::DEFAULT_TTL`, two hours). The claim, the submission write
 * and the replay decision are Phase 3's, and they read that row — this route
 * does not pre-empt them.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp/v2/agentshell-forms/v1/challenge`.
 */
class Form_Challenge_Endpoint {

	/**
	 * The REST namespace.
	 *
	 * `NAMESPACE` cannot be a class constant — it is a reserved word in PHP —
	 * so the constant carries the `REST_` prefix and means the same thing.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'wp/v2/agentshell-forms/v1';

	/**
	 * The route, relative to the namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/challenge';

	/**
	 * Mints allowed per source per form, per window.
	 *
	 * A page with three forms mints three tokens; thirty is room for ten
	 * reloads, which is more than a person needs and far less than a
	 * challenge-table flood.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 30;

	/**
	 * The rate-limit window, in seconds.
	 *
	 * @var int
	 */
	const RATE_WINDOW = 300;

	/**
	 * Register the route, and the header filter that keeps it uncacheable.
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
				// Public by design: the caller is a visitor who has not logged
				// in, and the response carries no privileged information.
				'permission_callback' => '__return_true',
				'args'                => array(
					'form_id' => array(
						'description'       => __( 'The form’s internal identifier: 32 lowercase hexadecimal characters.', 'agentshell-forms' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_form_id' ),
					),
				),
			)
		);

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
		}
	}

	/**
	 * Mark every response from this route uncacheable.
	 *
	 * One place, applied to successes, refusals and argument-validation errors
	 * alike, so a branch that returns early cannot lose the header that makes
	 * the whole design safe.
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

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE . self::ROUTE ) ) {
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
	 * Validate the `form_id` argument.
	 *
	 * @param mixed $value The supplied value.
	 * @return true|\WP_Error
	 */
	public static function validate_form_id( $value ) {
		if ( ! is_string( $value ) || ! preg_match( Form_Schema::FORM_ID_PATTERN, $value ) ) {
			return new \WP_Error(
				'form_id_invalid',
				__( 'A form id is 32 lowercase hexadecimal characters.', 'agentshell-forms' )
			);
		}

		return true;
	}

	/**
	 * Mint a challenge for a published form.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function handle( $request ) {
		$form_id = '';

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$raw     = $request->get_param( 'form_id' );
			$form_id = is_string( $raw ) ? $raw : '';
		}

		if ( ! preg_match( Form_Schema::FORM_ID_PATTERN, $form_id ) ) {
			return self::refusal(
				'form_id_invalid',
				__( 'A form id is 32 lowercase hexadecimal characters.', 'agentshell-forms' ),
				400
			);
		}

		$post_id = Form_Definition::resolve_post_id( $form_id );

		if ( $post_id < 1 ) {
			return self::unavailable();
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		// A trashed form, a form with no published definition and a form whose
		// state cannot be read are one answer: no challenge is minted, and the
		// caller is not told which of them it was.
		if ( is_wp_error( $state ) || ! is_array( $state['published'] ) ) {
			return self::unavailable();
		}

		$version = (int) $state['version'];

		// The definition is validated before a token is minted for it, for the
		// same reason the renderer validates before rendering: a token that
		// names a version whose definition is broken would let Phase 3 accept a
		// submission against a form nobody could have seen correctly.
		$errors = Form_Schema::validate(
			$state['published'],
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
			return self::unavailable();
		}

		$subject = self::subject_hash();

		$reserved = Form_Rate_Limits::reserve(
			Form_Rate_Limits::SCOPE_FORM,
			$subject,
			$form_id,
			self::RATE_LIMIT,
			self::RATE_WINDOW
		);

		if ( ! empty( $reserved['error'] ) ) {
			return self::refusal(
				'form_challenge_unavailable',
				__( 'A submission challenge could not be issued right now. Please try again.', 'agentshell-forms' ),
				503
			);
		}

		if ( empty( $reserved['admitted'] ) ) {
			$retry_after = self::retry_after( $reserved );

			return self::refusal(
				'form_challenge_rate_limited',
				__( 'Too many attempts to start this form. Please wait a few minutes and try again.', 'agentshell-forms' ),
				429,
				$retry_after > 0 ? array( 'Retry-After' => (string) $retry_after ) : array()
			);
		}

		$token = Form_Challenges::mint( $form_id, $version, $subject );

		if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
			return self::refusal(
				'form_challenge_unavailable',
				__( 'A submission challenge could not be issued right now. Please try again.', 'agentshell-forms' ),
				503
			);
		}

		$response = new \WP_REST_Response(
			array(
				'challenge'    => $token,
				'form_version' => $version,
				'expires_in'   => (int) Form_Challenges::DEFAULT_TTL,
			),
			200
		);

		// Also applied by `no_store()`, and applied here as well so the header
		// does not depend on a filter that another plugin could remove.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * The 404 that does not distinguish its reasons.
	 *
	 * @return \WP_REST_Response
	 */
	private static function unavailable() {
		return self::refusal(
			'form_unavailable',
			__( 'That form is not available.', 'agentshell-forms' ),
			404
		);
	}

	/**
	 * A refusal, in WordPress's own REST error envelope.
	 *
	 * Returned as a response rather than a `WP_Error` so the status, the body and
	 * the headers are decided in one place: `WP_Error` carries a status but not
	 * a header, and the 429's `Retry-After` is a header.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $headers Extra headers.
	 * @return \WP_REST_Response
	 */
	private static function refusal( $code, $message, $status, array $headers = array() ) {
		$response = new \WP_REST_Response(
			array(
				'code'    => (string) $code,
				'message' => (string) $message,
				'data'    => array( 'status' => (int) $status ),
			),
			(int) $status
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		foreach ( $headers as $name => $value ) {
			$response->header( (string) $name, (string) $value );
		}

		return $response;
	}

	/**
	 * Seconds until the rate-limit window turns over.
	 *
	 * @param array $reserved The reservation result.
	 * @return int
	 */
	private static function retry_after( array $reserved ) {
		$start  = isset( $reserved['window_start'] ) ? (int) $reserved['window_start'] : 0;
		$window = isset( $reserved['window'] ) ? (int) $reserved['window'] : self::RATE_WINDOW;

		if ( $start < 1 || $window < 1 ) {
			return $window;
		}

		$remaining = ( $start + $window ) - time();

		return $remaining > 0 ? $remaining : $window;
	}

	/**
	 * The HMAC of the visitor's source address.
	 *
	 * The address itself is never stored: the protocol tables hold an HMAC so a
	 * database disclosure does not yield a list of visitors, and so the row can
	 * still answer "is this the same source as before".
	 *
	 * A request with no address — a local cron, a CLI run — hashes to the empty
	 * subject, which the challenge table treats as "unknown source" rather than
	 * as a shared identity.
	 *
	 * @return string 64 hexadecimal characters, or ''.
	 */
	public static function subject_hash() {
		$address = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			$address = trim( $_SERVER['REMOTE_ADDR'] );
		}

		if ( '' === $address ) {
			return '';
		}

		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : '';

		// A site with no salt is a site with a broken `wp-config.php`; hashing
		// without one is still better than storing the address, and the value
		// is only ever compared with itself.
		return hash_hmac( 'sha256', $address, $salt );
	}
}
