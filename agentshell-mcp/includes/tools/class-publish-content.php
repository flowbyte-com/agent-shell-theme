<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_publish — publish a draft/pending post or page.
 */
class Publish_Content extends Base_Tool {
    public function get_name() { return 'agentshell_publish'; }
    public function get_description() { return 'Publish a post or page (status draft/pending/private/future -> publish). Returns the updated record.'; }
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
            'publish'
        );
    }
}