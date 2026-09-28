<?php
/**
 * Run the configurator state-model tests in Node.
 *
 * When invoked directly (CLI), exits with the Node return code.
 * When required from tests/run-all.php, returns the count without
 * exiting so the parent runner can keep going.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    if ( PHP_SAPI === 'cli' && ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        fwrite( STDERR, "node not found on PATH; skipping configurator-state tests\n" );
        exit( 0 );
    }
    return;
}
$cmd = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/configurator-state-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );
echo $output;

if ( ! preg_match( '/(\d+)\s+passed,\s+(\d+)\s+failed/', $output, $m ) ) {
    if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        exit( 1 );
    }
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