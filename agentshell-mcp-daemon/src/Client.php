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

        // Check for auth errors (401)
        if ( isset( $http_response_header[0] ) && strpos( $http_response_header[0], '401' ) !== false ) {
            throw new \RuntimeException( 'Authentication failed — check user and application password' );
        }

        return $response;
    }
}
