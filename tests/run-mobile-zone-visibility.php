<?php
/**
 * Run the mobile zone-visibility test.
 *
 * The test measures the theme's real stylesheet and the theme's real
 * renderer in a headless browser, so it needs Node and a browser. Node's
 * absence is a skip here; a missing browser is a skip too, and CI runs
 * the file directly with AGENTSHELL_REQUIRE_BROWSER=1, where it is a
 * failure.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    if ( PHP_SAPI === 'cli' && ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        fwrite( STDERR, "node not found on PATH; skipping the mobile zone visibility test\n" );
        exit( 0 );
    }
    return;
}
$cmd    = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/mobile-zone-visibility-test.js' ) . ' 2>&1';
$output = shell_exec( $cmd );
echo $output;

if ( preg_match( '/SKIP:/', $output ) ) {
    if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
        exit( 0 );
    }
    return;
}

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
