<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_update_content — update title/content/status/slug of a post or page.
 */
class Update_Content extends Base_Tool {
    public function get_name() { return 'agentshell_update_content'; }
    public function get_description() { return 'Update an existing post or page (title, raw HTML content, status, slug, or page parent). Returns the updated record.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'      => array( 'type' => 'integer', 'description' => 'Post/page ID' ),
                'type'    => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Defaults to page' ),
                'title'   => array( 'type' => 'string', 'description' => 'New title' ),
                'content' => array( 'type' => 'string', 'description' => 'New raw HTML content (replaces the whole body)' ),
                'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ), 'description' => 'New status' ),
                'slug'    => array( 'type' => 'string', 'description' => 'New URL slug' ),
                'parent'  => array( 'type' => 'integer', 'description' => 'New parent page ID (pages only)' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $changes = array();
        foreach ( array( 'title', 'content', 'status', 'slug', 'parent' ) as $key ) {
            if ( array_key_exists( $key, $arguments ) ) {
                $changes[ $key ] = $arguments[ $key ];
            }
        }
        if ( empty( $changes ) ) {
            throw new \InvalidArgumentException( 'Nothing to update — pass at least one of title, content, status, slug, parent.' );
        }
        return \AgentShell_MCP\Content_Primitives::update(
            (int) $arguments['id'],
            $arguments['type'] ?? 'page',
            $changes
        );
    }
}