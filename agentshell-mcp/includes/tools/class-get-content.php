<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_content — fetch one post or page incl. raw + rendered HTML.
 */
class Get_Content extends Base_Tool {
    public function get_name() { return 'agentshell_get_content'; }
    public function get_description() { return 'Fetch a single post or page: title, status, slug, raw HTML content and the rendered HTML as the theme outputs it, plus edit URL and link.'; }
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
        return \AgentShell_MCP\Content_Primitives::get(
            (int) $arguments['id'],
            $arguments['type'] ?? 'page'
        );
    }
}