<?php
/**
 * Security headers — defence-in-depth for the front-end.
 *
 * The audit identified that AgentShell ships no explicit security
 * response headers. WordPress itself sends X-Frame-Options: SAMEORIGIN
 * by default since 3.1, but does not send X-Content-Type-Options or
 * Referrer-Policy.
 *
 * Post-fix: the theme emits a small set of compatible baseline headers
 * on the front-end. CSP enforcement is deliberately out of scope for this
 * commit — see the final report for the full rationale.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "security headers tests\n";

Agentshell_Test::run(
    'X-Content-Type-Options: nosniff is sent',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_captured_headers'] = array();
        agentshell_test_send_security_headers();

        $found = false;
        foreach ( $GLOBALS['_agentshell_test_captured_headers'] as $h ) {
            if ( stripos( $h, 'X-Content-Type-Options' ) !== false && stripos( $h, 'nosniff' ) !== false ) {
                $found = true;
                break;
            }
        }
        Agentshell_Test::assert_true( $found, 'X-Content-Type-Options: nosniff must be sent' );
    }
);

Agentshell_Test::run(
    'Referrer-Policy is sent',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_captured_headers'] = array();
        agentshell_test_send_security_headers();

        $found = false;
        foreach ( $GLOBALS['_agentshell_test_captured_headers'] as $h ) {
            if ( stripos( $h, 'Referrer-Policy' ) !== false ) {
                $found = true;
                break;
            }
        }
        Agentshell_Test::assert_true( $found, 'Referrer-Policy must be sent' );
    }
);

Agentshell_Test::run(
    'X-Frame-Options: SAMEORIGIN is sent',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_captured_headers'] = array();
        agentshell_test_send_security_headers();

        $found = false;
        foreach ( $GLOBALS['_agentshell_test_captured_headers'] as $h ) {
            if ( stripos( $h, 'X-Frame-Options' ) !== false && stripos( $h, 'SAMEORIGIN' ) !== false ) {
                $found = true;
                break;
            }
        }
        Agentshell_Test::assert_true( $found, 'X-Frame-Options: SAMEORIGIN must be sent' );
    }
);

Agentshell_Test::run(
    'send is a no-op once headers are already sent',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_captured_headers'] = array();
        $GLOBALS['_agentshell_test_headers_already_sent'] = true;
        agentshell_test_send_security_headers();

        Agentshell_Test::assert_equals(
            0,
            count( $GLOBALS['_agentshell_test_captured_headers'] )
        );
    }
);

Agentshell_Test::run(
    'headers are emitted together on a fresh response',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_captured_headers'] = array();
        agentshell_test_send_security_headers();

        $headers = $GLOBALS['_agentshell_test_captured_headers'];
        Agentshell_Test::assert_true( count( $headers ) >= 3 );

        $names = array_map( function( $h ) {
            return strtolower( explode( ':', $h, 2 )[0] );
        }, $headers );

        Agentshell_Test::assert_true( in_array( 'x-content-type-options', $names, true ) );
        Agentshell_Test::assert_true( in_array( 'referrer-policy',        $names, true ) );
        Agentshell_Test::assert_true( in_array( 'x-frame-options',        $names, true ) );
    }
);