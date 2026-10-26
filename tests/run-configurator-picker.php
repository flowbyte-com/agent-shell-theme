<?php
/**
 * Run the configurator component-picker tests in Node.
 *
 * Mirror of run-configurator-editor.php for tests/configurator-picker-test.js:
 * the picker is JavaScript, so the suite is JavaScript, and this shim is
 * what lets the single PHP runner execute it and count its assertions.
 * Node's absence is a skip here and a hard failure in CI, which runs the
 * file directly.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    if ( PHP_SAPI === 'cli' && ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        fwrite( STDERR, "node not found on PATH; skipping configurator picker tests\n" );
        exit( 0 );
    }
    return;
}
$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/configurator-picker-test.js' ) . ' 2>&1';
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
