<?php
/**
 * Run the configurator state-model tests in Node.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node === '' ) {
    fwrite( STDERR, "node not found on PATH; skipping configurator-state tests\n" );
    exit( 0 );
}
$cmd = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/configurator-state-test.js' );
passthru( $cmd, $rc );
exit( $rc );