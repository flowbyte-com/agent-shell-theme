<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_screenshot — read back a stored capture by id.
 *
 * The counterpart to agentshell_screenshot(inline=false). It exists so that a
 * durable capture is reachable over MCP, which is the channel that is already
 * authenticated, rather than over a URL the caller would have to fetch with
 * credentials it may not have.
 *
 * The owning actor is recorded at capture time and re-checked here.
 * Authentication is not authorisation: the MCP transport accepts any user
 * holding manage_options, so without this check any administrator could read a
 * capture taken by another — including one of a draft they cannot edit.
 */
class Get_Screenshot extends Base_Tool {
    public function get_name() { return 'agentshell_get_screenshot'; }
    public function get_description() {
        return 'Fetch a screenshot previously stored with agentshell_screenshot(inline=false). '
            . 'Returns the image inline. Only the actor that took the capture can retrieve it, '
            . 'and the capture expires after the retention window (default 24h).';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id' => array(
                    'type'        => 'string',
                    'description' => 'Capture id returned by agentshell_screenshot when inline=false.',
                ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $id = isset( $arguments['id'] ) ? (string) $arguments['id'] : '';

        // Distinguish "malformed" from "not yours" in the message the agent
        // sees: a caller who fat-fingered the id should be told to re-check it,
        // not told they are unauthorised.
        if ( ! \AgentShell_MCP\Screenshot_Store::is_valid_id( $id ) ) {
            throw new \InvalidArgumentException(
                'id must be 32 lowercase hexadecimal characters, as returned by agentshell_screenshot(inline=false).'
            );
        }

        $authorized = \AgentShell_MCP\Screenshot_Store::authorize_read( $id, \AgentShell_MCP\Store::actor() );
        if ( is_wp_error( $authorized ) ) {
            return array(
                'retrieved' => false,
                'reason'    => $authorized->get_error_code(),
                'message'   => $authorized->get_error_message(),
                'id'        => $id,
            );
        }

        $bytes = @file_get_contents( $authorized['path'] );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            return array(
                'retrieved' => false,
                'reason'    => 'unreadable',
                'message'   => 'The screenshot record exists but its file could not be read.',
                'id'        => $id,
            );
        }

        $summary = array(
            'retrieved' => true,
            'id'        => $id,
            'bytes'     => strlen( $bytes ),
            'created_at'=> $authorized['created_at'],
            'expires_at'=> $authorized['expires_at'],
        );

        // Same fallback as the capture tool: never hand back a text blob
        // pretending to be an image if this server cannot render one.
        if ( ! method_exists( '\AgentShell_MCP\Server', 'normalize_content' ) ) {
            $summary['retrieved'] = false;
            $summary['reason']    = 'inline_unsupported';
            $summary['message']   = 'This MCP server does not support inline image blocks. '
                . 'Fetch the capture over the authenticated REST route instead: '
                . 'GET ' . rest_url( \AgentShell_MCP\Transport::NAMESPACE . '/screenshot/' . $id );
            return $summary;
        }

        $summary['__mcp_content'] = array(
            array(
                'type'     => 'image',
                'data'     => base64_encode( $bytes ),
                'mimeType' => $authorized['mimeType'],
            ),
        );

        return $summary;
    }
}