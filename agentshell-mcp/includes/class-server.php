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
        $unique = array();
        foreach ( apply_filters( 'agentshell_mcp_register_tools', array() ) as $tool ) {
            $name = $tool->get_name();
            // Keep the first registration, matching tools/call dispatch.
            if ( ! isset( $unique[ $name ] ) ) {
                $unique[ $name ] = $tool;
            }
        }
        return array_values( $unique );
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

            // A tool may return a raw MCP content array under the reserved
            // __mcp_content key, for results that are not text — an image
            // block, for instance. This is strictly additive: a tool that does
            // not use the key is unaffected, and because the key is reserved
            // and stripped here, no tool can leak it into a text payload.
            if ( is_array( $result ) && isset( $result['__mcp_content'] ) ) {
                $content = self::normalize_content( $result['__mcp_content'] );
                unset( $result['__mcp_content'] );

                // Any remaining keys ride alongside as a trailing text block,
                // so an image result can still carry its structured summary
                // without a second round trip.
                if ( ! empty( $result ) ) {
                    $content[] = array(
                        'type' => 'text',
                        'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
                    );
                }

                return JSON_RPC::success_response( $id, array( 'content' => $content ) );
            }

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

    /**
     * Validate a tool-supplied MCP content array.
     *
     * Only the block shapes this server understands are allowed through, and
     * anything unrecognised is dropped rather than forwarded. The point is that
     * __mcp_content is a channel into the protocol: without validation a tool
     * could emit arbitrary block types — a `resource` pointing at a local file,
     * or an image carrying a non-image mime type — and a client would act on
     * it. Text and image are the two this server supports.
     *
     * @param mixed $content
     * @return array valid content blocks
     */
    public static function normalize_content( $content ) {
        if ( ! is_array( $content ) ) {
            return array();
        }

        $out = array();
        foreach ( $content as $block ) {
            if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
                continue;
            }
            if ( 'text' === $block['type'] ) {
                $text = isset( $block['text'] ) && is_scalar( $block['text'] ) ? (string) $block['text'] : '';
                $out[] = array( 'type' => 'text', 'text' => $text );
                continue;
            }
            if ( 'image' === $block['type'] ) {
                $data = isset( $block['data'] ) ? $block['data'] : '';
                // A malformed payload would reach the client as an undecodable
                // image and fail there, far from the cause. Validate here so
                // the tool gets an error it can act on.
                if ( ! is_string( $data ) || '' === $data || ! base64_decode( $data, true ) ) {
                    continue;
                }
                $mime = isset( $block['mimeType'] ) && is_string( $block['mimeType'] ) ? $block['mimeType'] : '';
                if ( ! preg_match( '#^image/[a-z0-9.+-]+$#i', $mime ) ) {
                    continue;
                }
                $out[] = array(
                    'type'     => 'image',
                    'data'     => $data,
                    'mimeType' => $mime,
                );
            }
        }

        return $out;
    }
}
