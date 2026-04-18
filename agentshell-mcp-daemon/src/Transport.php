<?php
namespace AgentShellMCPDaemon;

class Transport {
    public function read_line() : ?string {
        $line = fgets( STDIN );
        if ( $line === false ) { return null; }
        return $line;
    }

    public function write_line( string $line ) {
        fwrite( STDOUT, $line . "\n" );
        fflush( STDOUT );
    }
}
