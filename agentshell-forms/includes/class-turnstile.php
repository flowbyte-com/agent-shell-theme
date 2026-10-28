<?php
/**
 * Cloudflare Turnstile: configuration, verification and the failure policy.
 *
 * ## One outbound request, to one hard-coded place
 *
 * The verification endpoint is a constant. There is no field, no option and no
 * filter that can point it at another host, which is the direct answer to
 * "form configuration must not create arbitrary outbound web requests"
 * (design §11.1). The **browser** loads Cloudflare's widget script, and that is
 * the only other external thing a form with Turnstile does.
 *
 * ## The secret is never in a page, a result or a log
 *
 * The secret is read from `AGENTSHELL_FORMS_TURNSTILE_SECRET` when that constant
 * is defined and **non-empty**, and otherwise from a non-autoloaded option. A
 * constant that is defined but empty is treated as absent — the same
 * empty-constant rule `Transaction_Manager::preview_secret()` applies, and for
 * the same reason: a credential checked against an empty secret is one anybody
 * can compute.
 *
 * ## Test credentials are refused in production
 *
 * Cloudflare publishes credentials that always pass and always fail. They
 * validate nothing, so a production site that shipped them would have a form
 * that looks protected and is not. This class recognises them by their
 * published prefix (`1x…`, `2x…`, `3x…` followed by zeros) rather than by
 * hard-coding a credential, and refuses them when
 * `wp_get_environment_type() === 'production'` unless the
 * `agentshell_forms_allow_test_keys` filter says otherwise. Nothing in this
 * repository ships a usable real credential.
 *
 * ## Fail closed where the operator asked for certainty
 *
 * `disabled` does nothing; `optional` records spam/unverified and lets the
 * visitor through; `required` refuses when a token is missing, when
 * verification fails, **and when verification could not be performed**. A
 * Cloudflare outage does not silently downgrade a form the operator protected.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turnstile configuration, verification and policy.
 */
class Form_Turnstile {

	/**
	 * The verification endpoint. Hard-coded on purpose (§11.1).
	 */
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * The widget script.
	 */
	const WIDGET_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/**
	 * The option holding the publishable site key.
	 */
	const OPTION_SITE_KEY = 'agentshell_forms_turnstile_site_key';

	/**
	 * The option holding the secret, when no constant is defined.
	 */
	const OPTION_SECRET = 'agentshell_forms_turnstile_secret';

	/**
	 * How long one verification request may take, in seconds (§11.1).
	 */
	const TIMEOUT = 5;

	/**
	 * How many times a transport-level failure is retried. One, not a loop.
	 */
	const RETRIES = 1;

	/**
	 * The policies a form may declare.
	 *
	 * @return array<int,string>
	 */
	public static function policies() {
		return array( 'disabled', 'optional', 'required' );
	}

	/**
	 * The publishable site key, or ''.
	 *
	 * @return string
	 */
	public static function site_key() {
		$constant = self::constant( 'AGENTSHELL_FORMS_TURNSTILE_SITE_KEY' );

		if ( '' !== $constant ) {
			return $constant;
		}

		$option = get_option( self::OPTION_SITE_KEY, '' );

		return is_string( $option ) ? trim( $option ) : '';
	}

	/**
	 * The secret, or ''.
	 *
	 * @return string
	 */
	public static function secret() {
		$constant = self::constant( 'AGENTSHELL_FORMS_TURNSTILE_SECRET' );

		if ( '' !== $constant ) {
			return $constant;
		}

		$option = get_option( self::OPTION_SECRET, '' );

		return is_string( $option ) ? trim( $option ) : '';
	}

	/**
	 * The hostname a token must have been minted for.
	 *
	 * Defaults to the site's own host. An operator whose public host differs
	 * from `home_url()` — a proxy, a CDN with its own host — can name it with
	 * the `AGENTSHELL_FORMS_TURNSTILE_HOSTNAME` constant or the
	 * `agentshell_forms_turnstile_hostname` filter.
	 *
	 * @return string
	 */
	public static function expected_hostname() {
		$constant = self::constant( 'AGENTSHELL_FORMS_TURNSTILE_HOSTNAME' );
		$host     = '' !== $constant ? $constant : '';

		if ( '' === $host && function_exists( 'wp_parse_url' ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'agentshell_forms_turnstile_hostname', $host );
			$host     = is_string( $filtered ) ? $filtered : $host;
		}

		return strtolower( trim( $host ) );
	}

	/**
	 * The `action` value for a form.
	 *
	 * Cloudflare limits `action` to 32 characters of `[A-Za-z0-9_-]`, and the
	 * form's internal identifier is exactly 32 lowercase hex characters — so
	 * the identifier *is* the action. A token minted for one form therefore
	 * cannot verify against another, because the actions differ.
	 *
	 * @param string $form_id Internal identifier.
	 * @return string
	 */
	public static function action_for( $form_id ) {
		return (string) $form_id;
	}

	/**
	 * Is this secret one of Cloudflare's published test credentials?
	 *
	 * The test secrets all begin with `1x`, `2x` or `3x` and continue with
	 * zeros; real secrets do not. Recognising the shape rather than embedding
	 * the values keeps a usable credential out of the repository entirely.
	 *
	 * @param string $secret Secret.
	 * @return bool
	 */
	public static function is_test_credential( $secret ) {
		$secret = (string) $secret;

		if ( '' === $secret ) {
			return false;
		}

		if ( preg_match( '/^[123]x0{10,}/i', $secret ) ) {
			return true;
		}

		/**
		 * Filters whether a credential is treated as a test credential.
		 *
		 * A test harness can name its own synthetic value here; an operator
		 * should not need it.
		 *
		 * @param bool   $is_test Whether it is a test credential.
		 * @param string $secret  The credential.
		 */
		return (bool) apply_filters( 'agentshell_forms_turnstile_is_test_credential', false, $secret );
	}

	/**
	 * Is this a production environment?
	 *
	 * Fails closed: a site that cannot say what it is, is treated as
	 * production, because that is the answer that refuses a test credential.
	 *
	 * @return bool
	 */
	public static function is_production() {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return true;
		}

		return 'production' === (string) wp_get_environment_type();
	}

	/**
	 * May a test credential be used here?
	 *
	 * @return bool
	 */
	public static function test_credentials_allowed() {
		if ( ! self::is_production() ) {
			return true;
		}

		/**
		 * Filters whether test credentials may be used in production.
		 *
		 * Leave this alone unless the site is a staging copy that is labelled
		 * production. A test credential validates nothing.
		 *
		 * @param bool $allow Whether to allow them. Default false.
		 */
		return (bool) apply_filters( 'agentshell_forms_allow_test_keys', false );
	}

	/**
	 * What kind of credential is configured, for the operator's screen.
	 *
	 * @return string `none`, `test`, `live` or `misconfigured`.
	 */
	public static function credential_kind() {
		$secret = self::secret();

		if ( '' === $secret ) {
			return 'none';
		}

		if ( self::is_test_credential( $secret ) ) {
			return self::test_credentials_allowed() ? 'test' : 'misconfigured';
		}

		return 'live';
	}

	/**
	 * Does this form need the widget rendered?
	 *
	 * @param array $definition The published definition.
	 * @return bool
	 */
	public static function needs_widget( array $definition ) {
		$policy = self::policy_for( $definition );

		return 'disabled' !== $policy && '' !== self::site_key();
	}

	/**
	 * The declared policy for a form.
	 *
	 * @param array $definition The definition.
	 * @return string
	 */
	public static function policy_for( array $definition ) {
		$policy = isset( $definition['settings']['spam']['turnstile'] )
			? (string) $definition['settings']['spam']['turnstile']
			: 'disabled';

		return in_array( $policy, self::policies(), true ) ? $policy : 'disabled';
	}

	/**
	 * The widget container, or ''.
	 *
	 * Cloudflare's script replaces the container with an iframe and inserts a
	 * hidden `cf-turnstile-response` input into the enclosing form, which is
	 * what the submission reads. `data-action` binds the token to this form.
	 *
	 * @param array $definition The published definition.
	 * @param bool  $enqueue    Whether to enqueue the widget script.
	 * @return string
	 */
	public static function widget( array $definition, $enqueue = true ) {
		if ( ! self::needs_widget( $definition ) ) {
			return '';
		}

		$form_id = isset( $definition['form_id'] ) ? (string) $definition['form_id'] : '';
		$site    = self::site_key();

		if ( '' === $form_id || '' === $site ) {
			return '';
		}

		if ( $enqueue && function_exists( 'wp_enqueue_script' ) ) {
			// `render=explicit` is not used: the implicit renderer finds every
			// `.cf-turnstile` container, which is what a shortcode can emit
			// without the theme having to run a callback.
			wp_enqueue_script(
				'agentshell-forms-turnstile',
				self::WIDGET_SCRIPT,
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare versions the script itself.
				true
			);
		}

		return '<div class="cf-turnstile agentshell-forms__turnstile"'
			. ' data-sitekey="' . esc_attr( $site ) . '"'
			. ' data-action="' . esc_attr( self::action_for( $form_id ) ) . '"'
			. ' data-theme="auto"></div>';
	}

	/**
	 * Verify one token.
	 *
	 * The result's `status` is one of:
	 *
	 *   - `ok`            — verified for this site, this action;
	 *   - `failed`        — the token was bad, expired or already used;
	 *   - `unavailable`   — the verification could not be performed;
	 *   - `misconfigured` — the secret is missing, empty, a test credential in
	 *                       production, or the server refused it. This is the
	 *                       operator's problem, never the visitor's.
	 *
	 * @param string $token    The token.
	 * @param string $form_id  The form's internal identifier.
	 * @param string $address  The visitor's address, or ''.
	 * @return array{status:string,codes:array<int,string>,detail:string,http:int}
	 */
	public static function verify( $token, $form_id, $address = '' ) {
		$result = array(
			'status' => 'misconfigured',
			'codes'  => array(),
			'detail' => '',
			'http'   => 0,
		);

		$secret = self::secret();

		if ( '' === $secret ) {
			$result['detail'] = 'missing_secret';
			return $result;
		}

		if ( self::is_test_credential( $secret ) && ! self::test_credentials_allowed() ) {
			$result['detail'] = 'test_credential_in_production';
			$result['codes']  = array( 'test-credential' );
			return $result;
		}

		$token = trim( (string) $token );

		if ( '' === $token ) {
			$result['status'] = 'failed';
			$result['detail'] = 'missing_token';
			$result['codes']  = array( 'missing-input-response' );
			return $result;
		}

		$body = array(
			'secret'   => $secret,
			'response' => $token,
			'action'   => self::action_for( $form_id ),
		);

		if ( '' !== (string) $address ) {
			$body['remoteip'] = (string) $address;
		}

		$response = self::request( $body );

		if ( null === $response ) {
			$result['status'] = 'unavailable';
			$result['detail'] = 'transport';
			return $result;
		}

		$result['http'] = (int) ( $response['http'] ?? 0 );

		$decoded = json_decode( (string) ( $response['body'] ?? '' ), true );

		if ( ! is_array( $decoded ) ) {
			// A 5xx with an HTML or empty body is Cloudflare's own failure, not
			// the visitor's. A 2xx with an unreadable body is a protocol
			// surprise; both are "could not be performed".
			$result['status'] = ( $result['http'] >= 500 || 0 === $result['http'] ) ? 'unavailable' : 'unavailable';
			$result['detail'] = 'unreadable_response';
			return $result;
		}

		$codes = isset( $decoded['error-codes'] ) && is_array( $decoded['error-codes'] )
			? array_values( array_map( 'strval', $decoded['error-codes'] ) )
			: array();

		$result['codes'] = $codes;

		if ( ! empty( $decoded['success'] ) ) {
			$expected_host = self::expected_hostname();
			$hostname      = isset( $decoded['hostname'] ) ? strtolower( (string) $decoded['hostname'] ) : '';
			$action        = isset( $decoded['action'] ) ? (string) $decoded['action'] : '';

			if ( '' !== $expected_host && '' === $hostname ) {
				$result['status'] = 'failed';
				$result['detail'] = 'hostname_missing';
				return $result;
			}

			if ( '' !== $expected_host && $hostname !== $expected_host ) {
				$result['status'] = 'failed';
				$result['detail'] = 'hostname_mismatch';
				return $result;
			}

			if ( $action !== self::action_for( $form_id ) ) {
				$result['status'] = 'failed';
				$result['detail'] = 'action_mismatch';
				return $result;
			}

			$result['status'] = 'ok';
			$result['detail'] = 'verified';
			return $result;
		}

		// Cloudflare's own configuration errors are not the visitor's fault.
		foreach ( array( 'missing-input-secret', 'invalid-input-secret' ) as $fatal ) {
			if ( in_array( $fatal, $codes, true ) ) {
				$result['status'] = 'misconfigured';
				$result['detail'] = $fatal;
				return $result;
			}
		}

		foreach ( array( 'internal-error', 'bad-request' ) as $transient ) {
			if ( in_array( $transient, $codes, true ) ) {
				$result['status'] = 'unavailable';
				$result['detail'] = $transient;
				return $result;
			}
		}

		$result['status'] = 'failed';
		$result['detail'] = '' !== $codes ? (string) $codes[0] : 'not_successful';

		return $result;
	}

	/**
	 * Apply the form's policy to one submission attempt.
	 *
	 * @param array  $definition The published definition.
	 * @param string $token      The token the request supplied, or ''.
	 * @param string $form_id    The form's internal identifier.
	 * @param string $address    The visitor's address, or ''.
	 * @return array{decision:string,spam_status:string,reason:string,verification:array|null}
	 *         `decision` is `proceed` or `refuse`.
	 */
	public static function apply_policy( array $definition, $token, $form_id, $address = '' ) {
		$policy = self::policy_for( $definition );

		if ( 'disabled' === $policy ) {
			return array(
				'decision'     => 'proceed',
				'spam_status'  => 'clean',
				'reason'       => 'disabled',
				'verification' => null,
			);
		}

		$token = trim( (string) $token );

		// A form that says "required" but has no site key or secret cannot be
		// protected at all. Refusing is the only honest answer: accepting
		// would be an unprotected form wearing a protective label.
		$secret = self::secret();
		if ( '' === $secret || ( self::is_test_credential( $secret ) && ! self::test_credentials_allowed() ) ) {
			return array(
				'decision'     => 'required' === $policy ? 'refuse' : 'proceed',
				'spam_status'  => 'required' === $policy ? 'spam' : 'unverified',
				'reason'       => 'misconfigured',
				'verification' => null,
			);
		}

		if ( 'optional' === $policy && '' === $token ) {
			return array(
				'decision'     => 'proceed',
				'spam_status'  => 'unverified',
				'reason'       => 'no_token',
				'verification' => null,
			);
		}

		$verification = self::verify( $token, $form_id, $address );

		switch ( $verification['status'] ) {
			case 'ok':
				return array(
					'decision'     => 'proceed',
					'spam_status'  => 'clean',
					'reason'       => 'verified',
					'verification' => $verification,
				);

			case 'failed':
				return array(
					'decision'     => 'required' === $policy ? 'refuse' : 'proceed',
					'spam_status'  => 'required' === $policy ? 'spam' : 'spam',
					'reason'       => 'failed',
					'verification' => $verification,
				);

			case 'misconfigured':
				return array(
					'decision'     => 'required' === $policy ? 'refuse' : 'proceed',
					'spam_status'  => 'required' === $policy ? 'spam' : 'unverified',
					'reason'       => 'misconfigured',
					'verification' => $verification,
				);

			case 'unavailable':
			default:
				return array(
					'decision'     => 'required' === $policy ? 'refuse' : 'proceed',
					'spam_status'  => 'required' === $policy ? 'spam' : 'unverified',
					'reason'       => 'unavailable',
					'verification' => $verification,
				);
		}
	}

	/**
	 * The transport, injectable for tests.
	 *
	 * A filter rather than a constructor argument because verification happens
	 * inside a static pipeline; a test that wants a scripted Cloudflare answers
	 * `agentshell_forms_turnstile_transport` and never touches the network.
	 *
	 * @return callable|null
	 */
	public static function transport() {
		$transport = apply_filters( 'agentshell_forms_turnstile_transport', null );

		return is_callable( $transport ) ? $transport : null;
	}

	/**
	 * Make one verification request, with a single retry.
	 *
	 * @param array $body Request body.
	 * @return array{body:string,http:int}|null
	 */
	private static function request( array $body ) {
		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'        => $body,
		);

		$last = null;

		for ( $attempt = 0; $attempt <= self::RETRIES; $attempt++ ) {
			$response = self::call_transport( self::VERIFY_URL, $args );

			if ( null === $response ) {
				continue;
			}

			$http = (int) $response['http'];

			if ( $http >= 500 ) {
				$last = $response;
				continue;
			}

			return $response;
		}

		// The last attempt may have produced a readable 5xx; report it rather
		// than pretending nothing came back, so the rejection log names the
		// transport failure accurately. A pure transport failure returns null.
		return $last;
	}

	/**
	 * Call the configured transport.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args Arguments.
	 * @return array{body:string,http:int}|null
	 */
	private static function call_transport( $url, array $args ) {
		$transport = self::transport();

		if ( null !== $transport ) {
			$response = call_user_func( $transport, $url, $args );
		} elseif ( function_exists( 'wp_remote_post' ) ) {
			$response = wp_remote_post( $url, $args );
		} else {
			return null;
		}

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return null;
		}

		$http = 0;

		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			$http = (int) wp_remote_retrieve_response_code( $response );
		} elseif ( isset( $response['response']['code'] ) ) {
			$http = (int) $response['response']['code'];
		}

		$body = '';

		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			$body = (string) wp_remote_retrieve_body( $response );
		} elseif ( isset( $response['body'] ) ) {
			$body = (string) $response['body'];
		}

		return array( 'body' => $body, 'http' => $http );
	}

	/**
	 * A constant's value, or '' when undefined or empty.
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private static function constant( $name ) {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}
}
