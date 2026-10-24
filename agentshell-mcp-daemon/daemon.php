<?php
namespace AgentShellMCPDaemon;

require_once __DIR__ . '/src/JsonRpc.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Transport.php';
require_once __DIR__ . '/daemon-version.php';

class Daemon {
    private $config;
    private $client;
    private $transport;
    private $verbose = false;

    private $server_version = null;
    private $server_protocol = null;

    // The daemon and the WordPress plugin are released together: they are
    // one product with two halves, so they carry the same product version.
    // The number below is kept equal to the plugin's by
    // tests/daemon-version-test.php, so the two cannot drift apart
    // unnoticed — the same "a number that is written down can only be
    // kept right by remembering to change it" problem the tool count had.
    const DAEMON_VERSION = '1.3.0';

    // The MCP wire format this daemon speaks. The server answers the
    // initialize handshake with the protocol version it will use; if that
    // is not this one, the daemon refuses (see daemon-version.php).
    const PROTOCOL_VERSION = '2025-03-26';

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
            'protocolVersion' => self::PROTOCOL_VERSION,
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
        if ( isset( $decoded['result']['protocolVersion'] ) ) {
            $this->server_protocol = (string) $decoded['result']['protocolVersion'];
            if ( $this->verbose ) { fwrite( STDERR, "Server protocol: " . $this->server_protocol . "\n" ); }
        }
    }

    /**
     * Check the two ends against each other and refuse to serve when they
     * are incompatible.
     *
     * Two independent axes are checked: the protocol version (the wire
     * format) and the product version (the tool surface). A refusal is
     * written to stderr — never to stdout, which carries the JSON-RPC
     * stream and must not be polluted — and the daemon exits with a
     * distinct code so a supervisor can tell the reason.
     *
     * An operator who knows the pairing works can override the refusal
     * for one run with AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1.
     */
    private function check_version_compatibility() {
        $checks = array(
            agentshell_daemon_protocol_status( $this->server_protocol, self::PROTOCOL_VERSION ),
            agentshell_daemon_version_status( $this->server_version, self::DAEMON_VERSION ),
        );

        $incompatible = array();
        foreach ( $checks as $status ) {
            // Only the loudest level writes to stderr. info is silent;
            // notice is verbose-only — it is a compatibility remark about
            // two version numbers that are both working, and the daemon is
            // started afresh for every connection, so an unconditional
            // notice is a line of noise on every single connection;
            // warning is reserved for cases that warrant operator
            // attention; error is a refusal.
            if ( $status['level'] === 'info' ) {
                continue;
            }
            if ( $status['level'] === 'notice' && ! $this->verbose ) {
                continue;
            }
            if ( $status['level'] === 'error' ) {
                $incompatible[] = $status;
                continue;
            }
            $prefix = ( $status['level'] === 'warning' ) ? 'WARNING' : strtoupper( $status['level'] );
            fwrite(
                STDERR,
                sprintf(
                    "%s: %s (daemon v%s, server v%s).\n",
                    $prefix,
                    $status['message'],
                    self::DAEMON_VERSION,
                    (string) $this->server_version
                )
            );
        }

        if ( empty( $incompatible ) ) {
            return;
        }

        foreach ( $incompatible as $status ) {
            fwrite( STDERR, "INCOMPATIBLE: " . $status['message'] . "\n" );
        }

        if ( agentshell_daemon_incompatible_override() ) {
            fwrite(
                STDERR,
                "WARNING: proceeding anyway because AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER is set.\n"
            );
            return;
        }

        exit( agentshell_daemon_incompatible_exit_code() );
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
//
// stdout carries the JSON-RPC stream and nothing else. PHP's own notices
// and warnings would be printed there by default on a CLI configuration
// with display_errors on, and a strict MCP client parses every line it
// reads: a stray "Deprecated: ..." line is a malformed message. Every
// diagnostic therefore goes to stderr.
ini_set( 'display_errors', 'stderr' );
ini_set( 'log_errors', '0' );

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
