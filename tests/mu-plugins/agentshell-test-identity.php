<?php
/**
 * Test-only identity REST endpoint for the disposable AgentShell test bed.
 *
 * This file is loaded as a must-use plugin on the *test* site only. It is
 * copied to wp-content/mu-plugins/ on the test site by
 * `agentshell_test_bed_install_mu_plugin()`. The development site
 * (agentshell.local) is never touched.
 *
 * The endpoint serves one purpose: the test runner fetches
 * `/wp-json/agentshell-test/v1/identity` with a per-run token in the
 * `X-AgentShell-Test-Token` header and compares the response to the
 * identity it just wrote to the test site's wp_options. The round-trip
 * proves that the HTTP service resolves to the same WordPress install
 * (and therefore the same database) that the CLI path validated.
 *
 * The endpoint requires the per-run token, which the test runner
 * provisions before any HTTP-dependent test runs. The token is stored
 * in `agentshell_test_bed_token` and changes every run; an old token
 * from a prior run is invalid.
 *
 * Without this endpoint, the only HTTP-side check is whether the
 * configured hostname responds with *some* WordPress HTML — which a
 * clone of the dev site on a different name would pass. The endpoint
 * forces HTTP and CLI to read the same value from the same database.
 *
 * The endpoint returns 503 when no token is configured, 401 on a
 * missing or wrong token, 200 with `{ identity, siteurl }` on success.
 *
 * This is test infrastructure. The development site has no copy.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'agentshell-test/v1', '/identity', array(
        'methods'             => 'GET',
        'permission_callback' => function ( $request ) {
            $expected = (string) get_option( 'agentshell_test_bed_token', '' );
            if ( '' === $expected ) {
                // No token has been provisioned. This is not a security
                // failure; it is a configuration gap the test runner
                // has to close before destructive tests can run. The
                // 503 tells the runner "no token, refuse" rather than
                // the misleading 401/403 a wrong/missing header would
                // produce.
                return new WP_Error(
                    'no_token',
                    'No test token configured for this run',
                    array( 'status' => 503 )
                );
            }
            $provided = (string) $request->get_header( 'x_agentshell_test_token' );
            if ( '' === $provided ) {
                return new WP_Error(
                    'no_token_header',
                    'Missing X-AgentShell-Test-Token header',
                    array( 'status' => 401 )
                );
            }
            if ( ! hash_equals( $expected, $provided ) ) {
                return new WP_Error(
                    'wrong_token',
                    'X-AgentShell-Test-Token does not match',
                    array( 'status' => 401 )
                );
            }
            return true;
        },
        'callback' => function () {
            $identity = (string) get_option( 'agentshell_test_bed_identity', '' );
            $siteurl  = (string) get_option( 'siteurl', '' );
            return array(
                'identity' => $identity,
                'siteurl'  => $siteurl,
            );
        },
    ) );
} );
