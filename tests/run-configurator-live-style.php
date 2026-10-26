<?php
/**
 * Run the live-style layer tests in Node.
 *
 * When invoked directly (CLI), exits with the Node return code.
 * When required from tests/run-all.php, returns the count without
 * exiting so the parent runner can keep going.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    // A missing Node is a skip, declared as one. It used to be an exit,
    // which — when this file was `require`d into the runner's own
    // process — ended the whole run.
    Agentshell_Test::skip( 'node not found on PATH; the live-style layer suite cannot run' );
    return;
}
$cmd = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/configurator-live-style-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );
echo $output;

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
$passed = (int) $m[1];
$failed = (int) $m[2];

if ( defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    Agentshell_Test::$passed += $passed;
    Agentshell_Test::$failed += $failed;
    return;
}
exit( $failed === 0 ? 0 : 1 );