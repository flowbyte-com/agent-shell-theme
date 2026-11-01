<?php
namespace AgentShell_Blocks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class JSON_RPC {
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
}
