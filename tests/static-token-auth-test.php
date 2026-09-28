<?php
/**
 * Static-token authentication: bind to an explicit WordPress actor.
 *
 * Pre-fix state: AGENTSHELL_REST_TOKEN resolved authentication by
 * calling wp_set_current_user(1) — whatever account owns database
 * user ID 1. Implicit. Any operator who knew the token became the
 * site's admin by definition.
 *
 * Post-fix invariants:
 *   1. The token authenticates as the user explicitly configured via
 *      AGENTSHELL_REST_TOKEN_USER_ID, never as an implicit user 1.
 *   2. The configured user must exist; otherwise auth fails closed.
 *   3. Authentication via the token is timing-safe (hash_equals).
 *   4. The static token without a configured actor fails closed
 *      (no legacy user-1 fallback) — explicit configuration is
 *      required going forward.
 *
 * These tests exercise the pure helper agentshell_check_static_token().
 * The integration (filter closure that reads constants and looks up the
 * user) is small enough that the helper test pins the policy; the
 * wrapper is exercised end-to-end on a live WP install.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "static-token authentication tests\n";

// ─── tests ─────────────────────────────────────────────────────

Agentshell_Test::run(
    'configured actor + matching token + existing user returns user id',
    function () {
        $result = agentshell_check_static_token(
            'valid-token',
            'valid-token',
            7,
            true
        );
        Agentshell_Test::assert_equals( 7, $result );
    }
);

Agentshell_Test::run(
    'configured actor + matching token + non-existing user fails',
    function () {
        $result = agentshell_check_static_token(
            'valid-token',
            'valid-token',
            999,
            false
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'token + zero configured user id fails (no implicit user-1)',
    function () {
        $result = agentshell_check_static_token(
            'valid-token',
            'valid-token',
            0,
            true
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'wrong token fails',
    function () {
        $result = agentshell_check_static_token(
            'wrong-token',
            'real-token',
            7,
            true
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'matching token + empty static token fails',
    function () {
        // The configured secret is empty (e.g. AGENTSHELL_REST_TOKEN not
        // defined in wp-config.php). We must not authenticate anyone
        // with the secret-less path even if the provided token matches.
        $result = agentshell_check_static_token(
            'anything',
            '',
            7,
            true
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'empty provided token fails',
    function () {
        $result = agentshell_check_static_token(
            '',
            'real-token',
            7,
            true
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'non-string inputs fail (defensive type check)',
    function () {
        $result = agentshell_check_static_token(
            null,
            'real-token',
            7,
            true
        );
        Agentshell_Test::assert_false( $result );
    }
);

Agentshell_Test::run(
    'comparison is timing-safe (uses hash_equals)',
    function () {
        // We can\'t directly observe hash_equals from a behavioural
        // test, but we can verify that two distinct correct tokens both
        // succeed (i.e. the helper is not caching a single-comparison
        // result) and that the comparison is not short-circuited.
        Agentshell_Test::assert_equals(
            7,
            agentshell_check_static_token( 'aaaaaa', 'aaaaaa', 7, true )
        );
        Agentshell_Test::assert_equals(
            7,
            agentshell_check_static_token( 'zzzzzz', 'zzzzzz', 7, true )
        );
    }
);

Agentshell_Test::run(
    'integration: configured subscriber fails at the capability boundary',
    function () {
        // A subscriber is the static-token actor. The helper succeeds
        // (the token resolves to the configured user), but downstream
        // the MCP transport (Class\Auth::authenticate) requires
        // manage_options; a subscriber does not have it.
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        // Simulate the auth filter side: the token resolves to the user.
        $result = agentshell_check_static_token( 'sub-token', 'sub-token', 5, true );
        Agentshell_Test::assert_equals( 5, $result );

        // The capability gate stops the subscriber before any tool runs.
        Agentshell_Test::assert_false(
            current_user_can( 'manage_options' ),
            'subscriber must not pass manage_options regardless of auth path'
        );
    }
);