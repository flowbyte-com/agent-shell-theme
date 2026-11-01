<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class JSON_RPC {
    /**
     * Maximum accepted request body size. Anything larger is rejected with a
     * structured error before json_decode runs so a hostile client cannot
     * force the plugin to allocate gigabytes per request.
     */
    const MAX_BODY_BYTES = 1048576; // 1 MiB

    public static function parse_request( $body ) {
        if ( empty( $body ) ) {
            return new \WP_Error( 'empty_body', 'Request body is empty' );
        }

        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return new \WP_Error(
                'request_too_large',
                sprintf( 'Request body exceeds %d bytes.', self::MAX_BODY_BYTES )
            );
        }

        $decoded = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'parse_error', 'Invalid JSON: ' . json_last_error_msg() );
        }

        return $decoded;
    }

    public static function success_response( $id, $result ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    public static function error_response( $id, $code, $message ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array(
                'code'    => $code,
                'message' => $message,
            ),
        );
    }

    public static function is_notification( $message ) {
        return isset( $message['id'] ) && $message['id'] === null;
    }
}
