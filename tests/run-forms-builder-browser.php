<?php
/**
 * Run the forms builder browser test.
 *
 * tests/forms-builder-browser-test.js generates a page with the real modules
 * and drives it in a real browser. A missing Node or browser is a skip here
 * and a failure in CI.
 */

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( '' === $node ) {
    Agentshell_Test::skip( 'node not found on PATH; the forms builder browser suite cannot run' );
    return;
}

$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/forms-builder-browser-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );

echo (string) $output;

$failed = preg_match( '/\b([1-9][0-9]*) failed\b/', (string) $output, $matches ) === 1;

Agentshell_Test::run(
    'forms builder browser: the page behaves as a visitor experiences it',
    function () use ( $failed, $output ) {
        Agentshell_Test::assert_false(
            $failed,
            'the builder browser suite reported failures: ' . trim( (string) $output )
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) $output, 'builder assertions' ),
            'the suite must report that the page ran its assertions'
        );
    }
);
