<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_unpublish — move a published post or page back to draft.
 */
class Unpublish_Content extends Base_Tool {
    public function get_name() { return 'agentshell_unpublish'; }
    public function get_description() { return 'Unpublish a post or page (status publish -> draft). Returns the updated record.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'   => array( 'type' => 'integer', 'description' => 'Post/page ID' ),
                'type' => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Defaults to page' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Primitives::set_status(
            (int) $arguments['id'],
            $arguments['type'] ?? 'page',
            'draft'
        );
    }
}