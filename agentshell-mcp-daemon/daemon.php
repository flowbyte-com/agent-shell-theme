<?php
namespace AgentShellMCPDaemon;

require_once __DIR__ . '/src/JsonRpc.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Transport.php';

class Daemon {
    private $config;
    private $client;
    private $transport;
    private $verbose = false;

    private $server_version = null;

    const DAEMON_VERSION = '1.0.0';
    const MIN_COMPATIBLE_PROTOCOL_VERSION = '2025-03-26';
    const EXPECTED_SERVER_VERSION = '1.1.0';

    public function __construct( array $config, bool $verbose = false ) {
        $this->config    = $config;
        $this->verbose   = $verbose;
        $this->client    = new Client( $config['url'], $config['user'], $config['pass'], $config['timeout'] ?? 30 );
        $this->transport = new Transport();
    }

    public function run() {
        $this->send_initialize();
        $this->check_version_compatibility();

        while ( true ) {
            $line = $this->transport->read_line();
            if ( $line === null ) { break; } // EOF

            $line = trim( $line );
            if ( $line === '' ) { continue; }

            if ( $this->verbose ) { fwrite( STDERR, "IN: $line\n" ); }

            $messages = $this->parse_messages( $line );
            foreach ( $messages as $message ) {
                $this->handle_client_message( $message );
            }
        }
    }

    private function send_initialize() {
        $request = JsonRpc::build_request( 'initialize', array(
            'protocolVersion' => self::MIN_COMPATIBLE_PROTOCOL_VERSION,
            'clientInfo'     => array( 'name' => 'agentshell-mcp-daemon', 'version' => self::DAEMON_VERSION ),
            'capabilities'   => array(),
        ) );

        if ( $this->verbose ) { fwrite( STDERR, "OUT: $request\n" ); }

        $response = $this->client->send( $request );
        $this->transport->write_line( $response );

        $decoded = json_decode( $response, true );
        if ( isset( $decoded['result']['serverInfo']['version'] ) ) {
            $this->server_version = $decoded['result']['serverInfo']['version'];
            if ( $this->verbose ) { fwrite( STDERR, "Server version: " . $this->server_version . "\n" ); }
        }
    }

    private function check_version_compatibility() {
        if ( $this->server_version === null ) {
            return;
        }
        if ( $this->server_version !== self::EXPECTED_SERVER_VERSION ) {
            fwrite( STDERR, "VERSION MISMATCH: Daemon v" . self::DAEMON_VERSION . " / Server v$this->server_version (expected " . self::EXPECTED_SERVER_VERSION . "). Protocol issues possible.\n" );
        }
    }

    private function handle_client_message( array $message ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;

        // Notifications: forward directly, no response expected
        if ( $id === null ) {
            $request = JsonRpc::build_request( $method, $message['params'] ?? array() );
            if ( $this->verbose ) { fwrite( STDERR, "OUT(notification): $request\n" ); }
            $this->client->send( $request );
            return;
        }

        $request = JsonRpc::build_request( $method, $message['params'] ?? array(), $id );

        if ( $this->verbose ) { fwrite( STDERR, "OUT: $request\n" ); }

        try {
            $response = $this->client->send( $request );
            if ( $this->verbose ) { fwrite( STDERR, "IN: $response\n" ); }
            $this->transport->write_line( $response );
        } catch ( \Exception $e ) {
            $error_response = json_encode( array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => array( 'code' => -32000, 'message' => $e->getMessage() ),
            ) );
            $this->transport->write_line( $error_response );
        }
    }

    private function parse_messages( string $line ) {
        $decoded = json_decode( $line, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            if ( is_array( $decoded ) && isset( $decoded['jsonrpc'] ) ) {
                return isset( $decoded[0] ) ? $decoded : array( $decoded );
            }
        }
        fwrite( STDERR, "Skipping malformed JSON-RPC message\n" );
        return array();
    }
}

// CLI entry point
$options = getopt( '', array( 'config:', 'verbose' ) );
$config_path = $options['config'] ?? ( getenv( 'HOME' ) . '/.agentshell-mcp.json' );

if ( ! file_exists( $config_path ) ) {
    fwrite( STDERR, "Config file not found: $config_path\n" );
    exit( 1 );
}

$config = json_decode( file_get_contents( $config_path ), true );
if ( ! $config || empty( $config['url'] ) || empty( $config['user'] ) || empty( $config['pass'] ) ) {
    fwrite( STDERR, "Invalid config: must contain url, user, and pass\n" );
    exit( 1 );
}

$verbose = isset( $options['verbose'] );
$daemon = new Daemon( $config, $verbose );
$daemon->run();
