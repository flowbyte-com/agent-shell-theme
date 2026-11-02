<?php
/**
 * Run the configurator's browser test.
 *
 * The test is a real browser against the theme's real stylesheet, modules and
 * markup: tests/configurator-browser-test.html is driven by
 * tests/configurator-browser-test.js, which finds a browser, runs it once and
 * reads the assertions back out of the document title.
 *
 * A missing browser is a skip, never a pass — so this shim reports the skip
 * and counts nothing. CI runs the Node runner directly with
 * AGENTSHELL_REQUIRE_BROWSER=1, where a missing browser is a failure.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    // A missing Node is a skip, declared as one. It used to be an exit,
    // which — when this file was `require`d into the runner's own
    // process — ended the whole run.
    Agentshell_Test::skip( 'node not found on PATH; the configurator browser suite cannot run' );
    return;
}
$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/configurator-browser-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );
echo $output;

if ( preg_match( '/SKIP:/', $output ) ) {
    // No browser on this machine. That is a skip, not a result, and it is
    // reported as one so nobody reads it as a green run.
    Agentshell_Test::skip( 'no browser found; the configurator browser suite cannot run' );
    return;
}

if ( ! preg_match( '/(\d+)\s+passed,\s+(\d+)\s+failed/', $output, $m ) ) {
    if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        exit( 1 );
    }
    // The Node runner ran but did not report a result. Returning quietly
    // would make this suite look like it passed with nothing to say,
    // which is the failure mode the runner was fixed to end.
    Agentshell_Test::run(
        'the Node suite reported a result',
        function () use ( $output ) {
            Agentshell_Test::assert_true(
                false,
                'the Node suite did not report a pass/fail count: ' . substr( (string) $output, -400 )
            );
        }
    );
    return;
}
// A child that reported 0 passed and 0 failed asserted nothing. Adding
// its zeroes to the aggregate would read as a pass, so the emptiness is
// recorded as a failure of this suite instead.
$failed = agentshell_absorb_child_counts( (int) $m[1], (int) $m[2], (string) $output );

if ( defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    return;
}
exit( $failed === 0 ? 0 : 1 );
