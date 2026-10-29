<?php
/**
 * Run the forms submission browser test.
 *
 * tests/forms-submit-browser-test.js generates a page with the real renderer,
 * serves it from a real origin, drives it in a real browser and reads the
 * assertions back out of the document title. The page proves what a visitor
 * gets: a successful submission, field errors with focus, a stale page that
 * keeps the answers, a pending attempt, a retry, a lost response, a double
 * click and a provisioning failure that recovers.
 *
 * A missing Node or browser is a skip, never a pass. CI runs the Node runner
 * directly with AGENTSHELL_REQUIRE_BROWSER=1, where a missing browser is a
 * failure.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( '' === $node ) {
    Agentshell_Test::skip( 'node not found on PATH; the forms submission browser suite cannot run' );
    return;
}

$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/forms-submit-browser-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );

echo (string) $output;

$failed = preg_match( '/\b([1-9][0-9]*) failed\b/', (string) $output, $matches ) === 1;

Agentshell_Test::run(
    'forms submission browser: the page behaves as a visitor experiences it',
    function () use ( $failed, $output ) {
        Agentshell_Test::assert_false(
            $failed,
            'the submission browser suite reported failures: ' . trim( (string) $output )
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) $output, 'browser assertions' ),
            'the suite must report that the page ran its assertions'
        );
    }
);
