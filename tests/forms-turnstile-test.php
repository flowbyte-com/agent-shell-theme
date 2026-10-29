<?php
/**
 * Turnstile: the policies, every verification branch, and the credential rules.
 *
 * There is no network in this suite. The client is constructed with an
 * **injectable transport** (the `agentshell_forms_turnstile_transport` filter),
 * so every branch of the response handling is driven from a scripted answer —
 * the same technique the screenshot suites use with a fake browser. What the
 * suite cannot prove is that Cloudflare is reachable; that is the acceptance
 * run's job, and it uses a disposable site.
 *
 * The properties worth reading the assertions for:
 *
 *   - `required` **fails closed**: a missing token, a failed verification and
 *     an unreachable verification are all refusals;
 *   - `optional` stores the submission and says how sure it is (`spam` for a
 *     failed token, `unverified` for no signal);
 *   - a test credential in production is a **misconfiguration**, never a
 *     working protection;
 *   - the secret never appears in a result.
 */

require_once __DIR__ . '/bootstrap.php';

// ── the WordPress surface the client calls ───────────────────────────────────

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
    function wp_get_environment_type() {
        return isset( $GLOBALS['asf_env_type'] ) ? (string) $GLOBALS['asf_env_type'] : 'production';
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? (string) $response['body'] : '';
    }
}

if ( ! defined( 'AGENTSHELL_FORMS_TURNSTILE_SECRET' ) ) {
    // Defined but **empty**, which the client must treat as absent. This is the
    // empty-constant rule, and it is asserted below rather than assumed.
    define( 'AGENTSHELL_FORMS_TURNSTILE_SECRET', '' );
}

$GLOBALS['asf_env_type']    = 'local';
$GLOBALS['asf_transport']   = null;
$GLOBALS['asf_calls']       = array();
$GLOBALS['asf_verify_seen'] = array();

add_filter(
    'agentshell_forms_turnstile_transport',
    function ( $transport ) {
        unset( $transport );

        return function ( $url, $args ) {
            $GLOBALS['asf_calls'][]       = array( 'url' => $url, 'args' => $args );
            $GLOBALS['asf_verify_seen'][] = $args['body'];

            $scripted = $GLOBALS['asf_transport'];

            if ( is_callable( $scripted ) ) {
                return call_user_func( $scripted, $url, $args );
            }

            return $scripted;
        };
    }
);

require_once dirname( __DIR__ ) . '/agentshell-forms/includes/class-turnstile.php';

use AgentShell_Forms\Form_Turnstile;

echo "forms turnstile (unit)\n";

/**
 * A scripted successful Cloudflare answer.
 *
 * @param string $form_id Form id, for the action.
 * @param array  $overrides Overrides.
 * @return array
 */
function ft_ok( $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a', array $overrides = array() ) {
    $body = array_merge(
        array(
            'success'  => true,
            'hostname' => 'example.test',
            'action'   => $form_id,
        ),
        $overrides
    );

    return array(
        'response' => array( 'code' => 200 ),
        'body'     => (string) wp_json_encode( $body ),
    );
}

/**
 * A scripted failure answer.
 *
 * @param array $codes Cloudflare error codes.
 * @param int   $code  HTTP status.
 * @return array
 */
function ft_fail( array $codes, $code = 200 ) {
    return array(
        'response' => array( 'code' => $code ),
        'body'     => (string) wp_json_encode(
            array(
                'success'     => false,
                'error-codes' => $codes,
            )
        ),
    );
}

/**
 * A definition with one Turnstile policy.
 *
 * @param string $policy Policy.
 * @return array
 */
function ft_definition( $policy ) {
    return array(
        'form_id'  => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
        'name'     => 'Contact',
        'version'  => 3,
        'fields'   => array(),
        'settings' => array(
            'spam' => array(
                'turnstile' => $policy,
                'honeypot'  => true,
            ),
        ),
    );
}

function ft_reset() {
    $GLOBALS['asf_transport'] = null;
    $GLOBALS['asf_calls']     = array();
    $GLOBALS['asf_env_type']  = 'local';
    update_option( Form_Turnstile::OPTION_SECRET, '' );
    update_option( Form_Turnstile::OPTION_SITE_KEY, '' );
}

function ft_secret( $value ) {
    update_option( Form_Turnstile::OPTION_SECRET, $value );
    $GLOBALS['asf_env_type'] = 'local';
}

// ══ configuration ════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the policy vocabulary is the approved three',
    function () {
        Agentshell_Test::assert_equals( array( 'disabled', 'optional', 'required' ), Form_Turnstile::policies() );
    }
);

Agentshell_Test::run(
    'a defined-but-empty constant is not a secret',
    function () {
        ft_reset();

        Agentshell_Test::assert_true( defined( 'AGENTSHELL_FORMS_TURNSTILE_SECRET' ), 'the suite defines the constant empty' );
        Agentshell_Test::assert_equals( '', Form_Turnstile::secret(), 'an empty constant must fall through to the option' );

        update_option( Form_Turnstile::OPTION_SECRET, 'live-secret-value' );
        Agentshell_Test::assert_equals( 'live-secret-value', Form_Turnstile::secret(), 'the option supplies the secret' );
        ft_reset();
    }
);

Agentshell_Test::run(
    'test credentials are recognised by shape and refused in production',
    function () {
        Agentshell_Test::assert_true( Form_Turnstile::is_test_credential( '1x0000000000000000000000000000000AA' ) );
        Agentshell_Test::assert_true( Form_Turnstile::is_test_credential( '2x0000000000000000000000000000000AA' ) );
        Agentshell_Test::assert_true( Form_Turnstile::is_test_credential( '3x0000000000000000000000000000000AA' ) );
        Agentshell_Test::assert_false( Form_Turnstile::is_test_credential( '0x4AAAAAAABkMYinukE8nzY0' ) );
        Agentshell_Test::assert_false( Form_Turnstile::is_test_credential( '' ) );

        ft_reset();
        ft_secret( '1x0000000000000000000000000000000AA' );

        $GLOBALS['asf_env_type'] = 'production';
        Agentshell_Test::assert_equals( 'misconfigured', Form_Turnstile::credential_kind(), 'a test secret in production is a misconfiguration' );

        $GLOBALS['asf_env_type'] = 'local';
        Agentshell_Test::assert_equals( 'test', Form_Turnstile::credential_kind(), 'a test secret is usable outside production' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'the widget is emitted only for a policy that needs it, and carries no secret',
    function () {
        ft_reset();

        Agentshell_Test::assert_equals( '', Form_Turnstile::widget( ft_definition( 'disabled' ) ), 'a disabled form renders no widget' );

        update_option( Form_Turnstile::OPTION_SITE_KEY, '0x4AAAAAAABkMYinukE8nzY0' );
        update_option( Form_Turnstile::OPTION_SECRET, '0x4AAAAAAABkMYinukE8nzY0secret' );

        $html = Form_Turnstile::widget( ft_definition( 'required' ) );

        Agentshell_Test::assert_true( false !== strpos( $html, 'class="cf-turnstile' ), 'a required form renders the container' );
        Agentshell_Test::assert_true( false !== strpos( $html, 'data-sitekey="0x4AAAAAAABkMYinukE8nzY0"' ), 'the site key is emitted' );
        Agentshell_Test::assert_true( false !== strpos( $html, 'data-action="7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a"' ), 'the action binds the token to the form' );
        Agentshell_Test::assert_false( false !== strpos( $html, 'secret' ), 'the widget never carries the secret' );

        ft_reset();
    }
);

// ══ verification ═════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a valid token verifies for the site host and the form action',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';
        $GLOBALS['asf_transport'] = ft_ok( $form_id );

        $result = Form_Turnstile::verify( 'a-token', $form_id, '203.0.113.5' );

        Agentshell_Test::assert_equals( 'ok', $result['status'], 'a valid token must verify' );
        Agentshell_Test::assert_equals( 200, $result['http'], 'the HTTP status is reported' );
        Agentshell_Test::assert_equals( 1, count( $GLOBALS['asf_calls'] ), 'one request, no retry' );
        Agentshell_Test::assert_equals( Form_Turnstile::VERIFY_URL, $GLOBALS['asf_calls'][0]['url'], 'the endpoint is the fixed one' );

        $sent = $GLOBALS['asf_calls'][0]['args']['body'];
        Agentshell_Test::assert_equals( 'live-secret-value', $sent['secret'], 'the secret is sent server-side' );
        Agentshell_Test::assert_equals( 'a-token', $sent['response'], 'the token is sent' );
        Agentshell_Test::assert_equals( $form_id, $sent['action'], 'the action is the form id' );
        Agentshell_Test::assert_equals( '203.0.113.5', $sent['remoteip'], 'the address is sent when present' );
        Agentshell_Test::assert_equals( 5, $GLOBALS['asf_calls'][0]['args']['timeout'], 'the timeout is bounded at five seconds' );

        Agentshell_Test::assert_false(
            false !== strpos( (string) wp_json_encode( $result ), 'live-secret-value' ),
            'the secret must never appear in a result'
        );

        ft_reset();
    }
);

Agentshell_Test::run(
    'a token for another host or another form is refused',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';

        $GLOBALS['asf_transport'] = ft_ok( $form_id, array( 'hostname' => 'evil.example' ) );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'failed', $result['status'], 'a token for another host must fail' );
        Agentshell_Test::assert_equals( 'hostname_mismatch', $result['detail'], 'the reason is named' );

        $GLOBALS['asf_transport'] = ft_ok( 'abcdef0123456789abcdef0123456789' );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'failed', $result['status'], 'a token minted for another form must fail' );
        Agentshell_Test::assert_equals( 'action_mismatch', $result['detail'], 'the action is what caught it' );

        $GLOBALS['asf_transport'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => (string) wp_json_encode( array( 'success' => true, 'action' => $form_id ) ),
        );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'failed', $result['status'], 'a success without a hostname is not a verification' );
        Agentshell_Test::assert_equals( 'hostname_missing', $result['detail'], 'the missing hostname is named' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'every failure class is classified, and none of them is reported as the visitor’s fault alone',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';

        $GLOBALS['asf_transport'] = ft_fail( array( 'timeout-or-duplicate' ) );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'failed', $result['status'], 'an expired or reused token fails' );
        Agentshell_Test::assert_true( in_array( 'timeout-or-duplicate', $result['codes'], true ), 'the code is kept for the operator' );

        $GLOBALS['asf_transport'] = ft_fail( array( 'invalid-input-response' ) );
        Agentshell_Test::assert_equals( 'failed', Form_Turnstile::verify( 'a-token', $form_id )['status'], 'a malformed token fails' );

        $GLOBALS['asf_transport'] = ft_fail( array( 'invalid-input-secret' ) );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'misconfigured', $result['status'], 'a bad secret is a misconfiguration' );

        $GLOBALS['asf_transport'] = ft_fail( array( 'internal-error' ) );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'unavailable', $result['status'], 'an internal error is transient' );

        $GLOBALS['asf_calls']    = array();
        $GLOBALS['asf_transport'] = array(
            'response' => array( 'code' => 503 ),
            'body'     => 'upstream unavailable',
        );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'unavailable', $result['status'], 'a 5xx is unavailable' );
        Agentshell_Test::assert_equals( 2, count( $GLOBALS['asf_calls'] ), 'a 5xx is retried once' );

        $GLOBALS['asf_calls']    = array();
        $GLOBALS['asf_transport'] = new WP_Error( 'http_request_failed', 'timeout' );
        $result = Form_Turnstile::verify( 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'unavailable', $result['status'], 'a network error is unavailable' );
        Agentshell_Test::assert_equals( 2, count( $GLOBALS['asf_calls'] ), 'a network error is retried once' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'a 5xx followed by a valid answer verifies on the retry',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';
        $calls   = 0;

        $GLOBALS['asf_transport'] = function () use ( &$calls, $form_id ) {
            $calls++;

            return 1 === $calls ? array( 'response' => array( 'code' => 502 ), 'body' => '' ) : ft_ok( $form_id );
        };

        Agentshell_Test::assert_equals( 'ok', Form_Turnstile::verify( 'a-token', $form_id )['status'], 'the retry must verify' );
        Agentshell_Test::assert_equals( 2, $calls, 'exactly one retry' );

        ft_reset();
    }
);

// ══ the policy ═══════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'disabled performs no verification at all',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $result = Form_Turnstile::apply_policy( ft_definition( 'disabled' ), '', '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a' );

        Agentshell_Test::assert_equals( 'proceed', $result['decision'] );
        Agentshell_Test::assert_equals( 'clean', $result['spam_status'] );
        Agentshell_Test::assert_equals( 0, count( $GLOBALS['asf_calls'] ), 'a disabled form must not call Cloudflare' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'optional proceeds with an honest status for every outcome',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';

        $no_token = Form_Turnstile::apply_policy( ft_definition( 'optional' ), '', $form_id );
        Agentshell_Test::assert_equals( 'proceed', $no_token['decision'] );
        Agentshell_Test::assert_equals( 'unverified', $no_token['spam_status'], 'no token is no signal' );

        $GLOBALS['asf_transport'] = ft_fail( array( 'invalid-input-response' ) );
        $failed = Form_Turnstile::apply_policy( ft_definition( 'optional' ), 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'proceed', $failed['decision'], 'optional never blocks' );
        Agentshell_Test::assert_equals( 'spam', $failed['spam_status'], 'a failed token is recorded as spam' );

        $GLOBALS['asf_transport'] = new WP_Error( 'http_request_failed', 'timeout' );
        $down = Form_Turnstile::apply_policy( ft_definition( 'optional' ), 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'proceed', $down['decision'], 'an outage does not block an optional form' );
        Agentshell_Test::assert_equals( 'unverified', $down['spam_status'], 'an outage is no signal, not spam' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'required fails closed for a missing token, a failed verification and an outage',
    function () {
        ft_reset();
        ft_secret( 'live-secret-value' );

        $form_id = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';

        $missing = Form_Turnstile::apply_policy( ft_definition( 'required' ), '', $form_id );
        Agentshell_Test::assert_equals( 'refuse', $missing['decision'], 'a missing token is refused' );

        $GLOBALS['asf_transport'] = ft_fail( array( 'timeout-or-duplicate' ) );
        $failed = Form_Turnstile::apply_policy( ft_definition( 'required' ), 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'refuse', $failed['decision'], 'a failed token is refused' );

        $GLOBALS['asf_transport'] = new WP_Error( 'http_request_failed', 'timeout' );
        $down = Form_Turnstile::apply_policy( ft_definition( 'required' ), 'a-token', $form_id );
        Agentshell_Test::assert_equals( 'refuse', $down['decision'], 'an outage is refused by a required form' );

        ft_reset();
    }
);

Agentshell_Test::run(
    'required refuses when the server itself is misconfigured',
    function () {
        ft_reset();

        // No secret at all: the form says "required" but cannot be protected.
        $no_secret = Form_Turnstile::apply_policy( ft_definition( 'required' ), 'a-token', '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a' );
        Agentshell_Test::assert_equals( 'refuse', $no_secret['decision'], 'a required form with no secret must refuse' );
        Agentshell_Test::assert_equals( 'misconfigured', $no_secret['reason'], 'the reason is the operator’s configuration' );

        // A test credential in production is the same class of problem.
        ft_secret( '1x0000000000000000000000000000000AA' );
        $GLOBALS['asf_env_type'] = 'production';

        $test_key = Form_Turnstile::apply_policy( ft_definition( 'required' ), 'a-token', '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a' );
        Agentshell_Test::assert_equals( 'refuse', $test_key['decision'], 'a test credential in production must refuse' );

        // With the explicit escape hatch it is allowed, and it really verifies.
        add_filter( 'agentshell_forms_allow_test_keys', '__return_true_stub' );

        if ( ! function_exists( '__return_true_stub' ) ) {
            function __return_true_stub( $value ) {
                unset( $value );
                return true;
            }
        }

        $GLOBALS['asf_transport'] = ft_ok( '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a' );
        $allowed = Form_Turnstile::apply_policy( ft_definition( 'required' ), 'a-token', '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a' );
        Agentshell_Test::assert_equals( 'proceed', $allowed['decision'], 'the filter allows a test credential deliberately' );

        remove_filter( 'agentshell_forms_allow_test_keys', '__return_true_stub' );

        ft_reset();
    }
);
