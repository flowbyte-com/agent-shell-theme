<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Transport {
    const NAMESPACE = 'agentshell-mcp/v1';
    const ROUTE    = '/mcp';

    /** Capture id in the retrieval URL. Opaque; never a filesystem path. */
    const SCREENSHOT_ID_PATTERN = '(?P<id>[A-Za-z0-9_-]{8,64})';

    private $server;
    private $auth;

    /**
     * Body to emit verbatim for the current request, or null.
     *
     * WordPress JSON-encodes every REST response body. For binary image data
     * that is silent corruption: the response arrives as a quoted JSON string
     * of \u0000 escapes, with an image/png Content-Type on top. The bytes are
     * therefore emitted directly by serve_raw_bytes() instead.
     */
    private $raw_body = null;

    /**
     * @param Server    $server
     * @param Auth|null $auth Injectable so the retrieval route can be driven
     *                        with a known principal in tests, without having
     *                        to mint real application passwords.
     */
    public function __construct( Server $server, $auth = null ) {
        $this->server = $server;
        $this->auth  = $auth instanceof Auth ? $auth : new Auth();
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

        register_rest_route( self::NAMESPACE, '/screenshot/' . self::SCREENSHOT_ID_PATTERN, array(
            'methods'             => 'GET',
            'callback'           => array( $this, 'handle_get_screenshot' ),
            // Auth is performed inside the callback, not by a permission
            // callback: an authenticated-but-unauthorised request has to be
            // distinguishable from an unauthenticated one (403 vs 401), and
            // WordPress collapses a false permission_callback into a generic
            // rejection before the callback can answer.
            'permission_callback' => '__return_true',
        ) );

        // Required for the image body: without it WordPress JSON-encodes the
        // response and the PNG arrives as a string of \u0000 escapes.
        add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_bytes' ), 10, 4 );
    }

    /**
     * Serve a stored screenshot to an authenticated, authorised caller.
     *
     * This is a secondary route. Primary delivery is the MCP image block, which
     * needs no second request and therefore no credential handoff — see
     * docs/SCREENSHOT-PRIVACY-DESIGN.md. The route exists for clients that
     * genuinely need a URL.
     */
    public function handle_get_screenshot( \WP_REST_Request $request ) {
        $auth_result = $this->auth->authenticate( $request );
        if ( is_wp_error( $auth_result ) ) {
            return new \WP_REST_Response(
                array( 'code' => 'unauthenticated', 'message' => $auth_result->get_error_message() ),
                401
            );
        }

        $id = (string) $request['id'];
        Screenshot_Store::maybe_migrate_legacy();
        $authorized = Screenshot_Store::authorize_read( $id, Store::actor() );
        if ( is_wp_error( $authorized ) ) {
            return $this->screenshot_error( $authorized );
        }

        $bytes = @file_get_contents( $authorized['path'] );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            return new \WP_REST_Response(
                array( 'code' => 'unreadable', 'message' => 'The screenshot file could not be read.' ),
                500
            );
        }

        $response = new \WP_REST_Response( $bytes, 200 );
        $response->header( 'Content-Type', $authorized['mimeType'] );
        $response->header( 'Content-Length', (string) strlen( $bytes ) );
        // Screenshots are private and short-lived. Caching them anywhere —
        // browser, proxy, or CDN — would recreate exactly the exposure the
        // private storage exists to remove.
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        $response->header( 'Content-Disposition', 'inline' );

        // Opt out of JSON encoding for this response only.
        $this->raw_body = $bytes;

        return $response;
    }

    /**
     * Emit the pending body verbatim rather than as JSON.
     *
     * Hooked to rest_pre_serve_request. Returning true tells WordPress the
     * request is already served, so it must not encode the body again.
     *
     * @return bool True if this handled the response.
     */
    public function serve_raw_bytes( $served, $result = null, $request = null, $server = null ) {
        if ( null === $this->raw_body ) {
            return $served;
        }
        $body = $this->raw_body;
        // One-shot: a transport instance is per-request, but clearing it keeps
        // a later response on the same object from being served raw by
        // accident.
        $this->raw_body = null;

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PNG, not markup

        return true;
    }

    /**
     * Map a store error to a status the client can act on.
     *
     * 404 means "no such capture", 410 means "it existed and is gone", 403 means
     * "not yours". Collapsing these into one generic failure would leave a
     * client unable to distinguish a typo from a permissions problem.
     */
    private function screenshot_error( \WP_Error $error ) {
        switch ( $error->get_error_code() ) {
            case 'agentshell_screenshot_expired':
                $status = 410;
                break;
            case 'agentshell_screenshot_forbidden':
                $status = 403;
                break;
            default:
                $status = 404;
        }

        return new \WP_REST_Response(
            array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ),
            $status
        );
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

        // Now that there is a principal, the one-time legacy migration can run.
        // It must be attempted from an authenticated request: `init` fires
        // before this point, when there is no current user to attribute the
        // migrated captures to.
        Screenshot_Store::maybe_migrate_legacy();

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

        // Notification: spec says no response at all. WP_REST_Response with status 204
        // strips the body, giving us the correct HTTP 204 No Content.
        if ( JSON_RPC::is_notification( $parsed ) ) {
            $this->server->handle_message( $parsed, $auth_result['user_id'] );
            return new \WP_REST_Response( null, 204 );
        }

        // Handle single message
        $result = $this->server->handle_message( $parsed, $auth_result['user_id'] );

        // Log tool call if applicable. Status reflects the actual outcome: an error
        // response from Server::handle_tools_call means the tool threw or was rejected,
        // and the audit log must record that — a silent "always-success" log makes
        // incident triage impossible.
        if ( isset( $parsed['method'] ) && $parsed['method'] === 'tools/call' ) {
            $status = ( is_array( $result ) && isset( $result['error'] ) ) ? 'error' : 'success';
            $this->log_tool_call(
                $auth_result['user_id'],
                $parsed['params']['name'] ?? '',
                $parsed['params']['arguments'] ?? array(),
                $status
            );
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

        // Opportunistic bounded-retention prune. Counter-based throttle
        // (see Agentshell_Audit_Log::maybe_prune) so the cost is amortised;
        // on a small table the inner prune is a no-op.
        Agentshell_Audit_Log::maybe_prune();
    }

    private static function redact_sensitive_args( $args ) {
        if ( ! is_array( $args ) ) { return $args; }
        // base64 image payloads are redacted for the same reason secrets are:
        // the audit log is retained and readable by anyone with the table, and
        // a screenshot can contain draft content. Tool *arguments* should never
        // carry one, but the log is the wrong place to find out.
        $sensitive = '/^(password|pass|secret|token|api[_\-]?key|authorization|content_base64|base64|image_base64|screenshot_data)$/i';
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
