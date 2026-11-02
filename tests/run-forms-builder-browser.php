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

// The child's own count, when it reported one. A child that reported
// 0 passed and 0 failed asserted nothing, and the marker check below
// would still pass — "the page ran 0 builder assertions" contains the
// marker. The emptiness is judged on the child's numbers, so it is
// recorded as a failure of this suite. The counts are not added: this
// shim asserts about the child rather than counting it.
$counts = preg_match( '/(\d+)\s+passed,\s+(\d+)\s+failed/', (string) $output, $m )
    ? array( (int) $m[1], (int) $m[2] )
    : array( 0, 0 );
agentshell_absorb_child_counts( $counts[0], $counts[1], (string) $output, false );

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
