<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Server {
    const PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME      = 'agentshell-mcp';

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
                return null;
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

    /**
     * Gather tools from all registered sources via filter.
     * Each registered tool must implement get_name(), get_description(),
     * get_input_schema(), and execute().
     */
    private function gather_tools() {
        /**
         * Filter: agentshell_mcp_register_tools
         * Theme and plugins hook here to register their MCP tools.
         * Each entry must be an object with:
         *   - get_name(): string
         *   - get_description(): string
         *   - get_input_schema(): array
         *   - execute(array $arguments): mixed
         *
         * @param array $tools Empty array — start of chain
         * @return array Accumulated tools
         */
        return apply_filters( 'agentshell_mcp_register_tools', array() );
    }

    private function handle_tools_list( $id ) {
        $tools = $this->gather_tools();
        $defs  = array_map( function( $tool ) {
            return $tool->get_definition();
        }, $tools );
        return JSON_RPC::success_response( $id, array(
            'tools' => array_values( $defs ),
        ) );
    }

    private function handle_tools_call( $id, $params ) {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? array();

        if ( empty( $tool_name ) ) {
            return JSON_RPC::error_response( $id, Error_Codes::INVALID_PARAMS, 'Missing tool name' );
        }

        $tools = $this->gather_tools();
        $tool  = null;
        foreach ( $tools as $t ) {
            if ( $t->get_name() === $tool_name ) {
                $tool = $t;
                break;
            }
        }

        if ( null === $tool ) {
            // Tool not registered — ask plugins if they want to handle it
            $response = apply_filters( 'agentshell_mcp_execute_tool', null, $tool_name, $arguments );
            if ( null === $response ) {
                return JSON_RPC::error_response( $id, Error_Codes::METHOD_NOT_FOUND, "Unknown tool: $tool_name" );
            }
            return $response;
        }

        try {
            $result = $tool->execute( $arguments );
            $text   = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
