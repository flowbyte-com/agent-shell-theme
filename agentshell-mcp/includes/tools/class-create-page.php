<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_create_page — add a page with raw HTML content.
 */
class Create_Page extends Base_Tool {
    public function get_name() { return 'agentshell_create_page'; }
    public function get_description() { return 'Create a WordPress page with raw HTML content (no wpautop/kses — content is preserved exactly). Returns the created record incl. edit URL.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'title'    => array( 'type' => 'string', 'description' => 'Page title' ),
                'content'  => array( 'type' => 'string', 'description' => 'Raw HTML content. No script tags or inline event handlers — they are stripped by WordPress core for non-super-admin users.' ),
                'status'   => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ), 'description' => 'Default draft' ),
                'slug'     => array( 'type' => 'string', 'description' => 'Optional URL slug' ),
                'parent'   => array( 'type' => 'integer', 'description' => 'Optional parent page ID' ),
            ),
            'required'   => array( 'title', 'content' ),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Primitives::create(
            'page',
            $arguments['title'],
            $arguments['content'],
            $arguments['status'] ?? 'draft',
            array(
                'post_name' => $arguments['slug'] ?? null,
                'parent'    => $arguments['parent'] ?? null,
            )
        );
    }
}