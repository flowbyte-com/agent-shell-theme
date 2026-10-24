<?php
namespace AgentShellMCPDaemon;

class Client {
    private $url;
    private $user;
    private $pass;
    private $timeout;

    public function __construct( string $url, string $user, string $pass, int $timeout = 30 ) {
        $this->url     = $url;
        $this->user    = $user;
        $this->pass    = $pass;
        $this->timeout = $timeout;
    }

    public function send( string $json_rpc_request ) : string {
        $auth   = base64_encode( "$this->user:$this->pass" );
        $context = stream_context_create( array(
            'http' => array(
                'method'        => 'POST',
                'header'        => array(
                    "Content-Type: application/json",
                    "Authorization: Basic $auth",
                    "Accept: application/json",
                ),
                'content'       => $json_rpc_request,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ),
        ) );

        $response = @file_get_contents( $this->url, false, $context );

        if ( $response === false ) {
            $error = error_get_last();
            throw new \RuntimeException( 'HTTP request failed: ' . ( $error['message'] ?? 'Unknown error' ) );
        }

        // Check for auth errors (401).
        //
        // The response headers are read through http_get_last_response_headers()
        // where it exists. The predefined `$http_response_header` is only
        // global: inside a method, PHP 8.5 raises a deprecation notice for
        // the implicitly local variable — and a deprecation notice printed
        // to stdout lands in the middle of the JSON-RPC stream, where a
        // strict client reads it as a malformed message.
        $headers = array();
        if ( function_exists( 'http_get_last_response_headers' ) ) {
            $headers = (array) http_get_last_response_headers();
        } elseif ( isset( $GLOBALS['http_response_header'] ) && is_array( $GLOBALS['http_response_header'] ) ) {
            $headers = $GLOBALS['http_response_header'];
        }
        if ( ! empty( $headers ) && strpos( (string) $headers[0], '401' ) !== false ) {
            throw new \RuntimeException( 'Authentication failed — check user and application password' );
        }

        return $response;
    }
}
