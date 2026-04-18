<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Transport {
    const NAMESPACE = 'agentshell-mcp/v1';
    const ROUTE    = '/mcp';

    private $server;
    private $auth;

    public function __construct( Server $server ) {
        $this->server = $server;
        $this->auth  = new Auth();
    }

    public function register_routes() {
        register_rest_route( self::NAMESPACE, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'           => array( $this, 'handle_post' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, self::ROUTE, array(
            'methods'             => 'OPTIONS',
            'callback'           => function() { return null; },
            'permission_callback' => '__return_true',
        ) );
    }

    public function handle_post( \WP_REST_Request $request ) {
        // Authenticate
        $auth_result = $this->auth->authenticate( $request );
        if ( is_wp_error( $auth_result ) ) {
            return new \WP_REST_Response(
                JSON_RPC::error_response( null, Error_Codes::AUTH_ERROR, $auth_result->get_error_message() ),
                401
            );
        }

        // Parse JSON-RPC body
        $parsed = JSON_RPC::parse_request( $request->get_body() );
        if ( is_wp_error( $parsed ) ) {
            return new \WP_REST_Response(
                JSON_RPC::error_response( null, Error_Codes::PARSE_ERROR, $parsed->get_error_message() ),
                400
            );
        }

        // Handle batch
        if ( is_array( $parsed ) && isset( $parsed[0] ) ) {
            return $this->handle_batch( $parsed, $auth_result['user_id'] );
        }

        // Handle single message
        $result = $this->server->handle_message( $parsed, $auth_result['user_id'] );

        // Log tool call if applicable
        if ( isset( $parsed['method'] ) && $parsed['method'] === 'tools/call' ) {
            $this->log_tool_call( $auth_result['user_id'], $parsed['params']['name'] ?? '', $parsed['params']['arguments'] ?? array(), 'success' );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    private function handle_batch( array $messages, $user_id ) {
        $responses = array();
        foreach ( $messages as $message ) {
            $result = $this->server->handle_message( $message, $user_id );
            if ( ! JSON_RPC::is_notification( $message ) ) {
                $responses[] = $result;
            }
        }
        return new \WP_REST_Response( $responses, 200 );
    }

    private function log_tool_call( $user_id, $tool_name, $arguments, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';

        $safe_args = self::redact_sensitive_args( $arguments );

        $wpdb->insert( $table, array(
            'wp_user_id'     => $user_id,
            'tool_name'      => $tool_name,
            'arguments'      => wp_json_encode( $safe_args ),
            'result_status'  => $status,
            'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            'created_at'    => current_time( 'mysql', true ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
    }

    private static function redact_sensitive_args( $args ) {
        if ( ! is_array( $args ) ) { return $args; }
        $sensitive = '/^(password|pass|secret|token|api[_\-]?key|authorization|content_base64)$/i';
        $result = array();
        foreach ( $args as $key => $value ) {
            if ( preg_match( $sensitive, $key ) ) {
                $result[ $key ] = '[REDACTED]';
            } elseif ( is_array( $value ) ) {
                $result[ $key ] = self::redact_sensitive_args( $value );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }
}
