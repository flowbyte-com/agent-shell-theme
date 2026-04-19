<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Server {
    const PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME      = 'agentshell-mcp';

    private $registry;

    public function __construct( Tools\Registry $registry ) {
        $this->registry = $registry;
    }

    public function handle_message( $message, $user_id ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;
        $params = $message['params'] ?? array();

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $id, $params );
            case 'ping':
                return JSON_RPC::success_response( $id, (object) array() );
            case 'tools/list':
                return $this->handle_tools_list( $id );
            case 'tools/call':
                return $this->handle_tools_call( $id, $params );
            case 'notifications/initialized':
                return null; // No response for notifications
            default:
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, "Method not found: $method" );
        }
    }

    private function handle_initialize( $id, $params ) {
        return JSON_RPC::success_response( $id, array(
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'   => array(
                'tools'     => (object) array(),
                'resources' => (object) array(),
            ),
            'serverInfo'     => array(
                'name'    => self::SERVER_NAME,
                'version' => AGENTSHELL_MCP_VERSION,
            ),
            'instructions'   => 'AgentShell MCP Server. Use tools to manage the AgentShell theme configuration including zones, CSS variables, design, widgets, and layout.',
        ) );
    }

    private function handle_tools_list( $id ) {
        return JSON_RPC::success_response( $id, array(
            'tools' => $this->registry->get_all_definitions(),
        ) );
    }

    private function handle_tools_call( $id, $params ) {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? array();

        if ( empty( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing tool name' );
        }

        $tool = $this->registry->get_tool( $tool_name );
        if ( null === $tool ) {
            return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, "Unknown tool: $tool_name" );
        }

        try {
            $result = $tool->execute( $arguments );
            $text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            return JSON_RPC::success_response( $id, array(
                'content' => array( array( 'type' => 'text', 'text' => $text ) ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, $e->getMessage() );
        } catch ( \Exception $e ) {
            return JSON_RPC::error_response( $id, Error_Codes::INTERNAL_ERROR, $e->getMessage() );
        }
    }
}
