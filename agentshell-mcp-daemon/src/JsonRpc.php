<?php
namespace AgentShellMCPDaemon;

class JsonRpc {
    public static function build_request( string $method, array $params = array(), $id = null ) {
        if ( $id !== null ) {
            return json_encode( array(
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params,
                'id'      => $id,
            ) );
        }
        // Auto-generate id only when not provided (notifications)
        return json_encode( array(
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => mt_rand( 1, PHP_INT_MAX ),
        ) );
    }

    public static function parse_response( string $json ) {
        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'Invalid JSON from server: ' . json_last_error_msg() );
        }
        return $decoded;
    }

    public static function is_error( array $response ) {
        return isset( $response['error'] );
    }
}
