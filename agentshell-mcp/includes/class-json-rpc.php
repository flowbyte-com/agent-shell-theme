<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class JSON_RPC {
    public static function parse_request( $body ) {
        if ( empty( $body ) ) {
            return new \WP_Error( 'empty_body', 'Request body is empty' );
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
