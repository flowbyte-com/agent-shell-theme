<?php
/**
 * Run the forms rendering browser test.
 *
 * tests/forms-render-browser-test.js generates a page with the real renderer,
 * loads it in a real browser, and reads the assertions back out of the document
 * title. The page proves what a visitor would get: the markup, the contrast,
 * the focus behaviour, per-instance challenge provisioning and the bfcache
 * refresh.
 *
 * A missing Node or browser is a skip, never a pass. CI runs the Node runner
 * directly with AGENTSHELL_REQUIRE_BROWSER=1, where a missing browser is a
 * failure.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    Agentshell_Test::skip( 'node not found on PATH; the forms browser suite cannot run' );
    return;
}
$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/forms-render-browser-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );
echo $output;

if ( preg_match( '/SKIP:/', $output ) ) {
    Agentshell_Test::skip( 'no browser found; the forms browser suite cannot run' );
    return;
}

if ( ! preg_match( '/(\d+)\s+passed,\s+(\d+)\s+failed/', $output, $m ) ) {
    if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        exit( 1 );
    }
    // The Node runner ran but did not report a result. Returning quietly would
    // make this suite look like it passed with nothing to say.
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
$passed = (int) $m[1];
$failed = (int) $m[2];

if ( defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    Agentshell_Test::$passed += $passed;
    Agentshell_Test::$failed += $failed;
    return;
}
exit( $failed === 0 ? 0 : 1 );
