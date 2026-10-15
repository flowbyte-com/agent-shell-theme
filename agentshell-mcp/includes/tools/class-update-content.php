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
        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_update_content refuses inside a transaction. The journal-aware update path '
                . 'is agentshell_update_draft (Stage 2a). The other journal-aware content tool is '
                . 'agentshell_create_draft. Either close the transaction (commit or rollback) and call '
                . 'agentshell_update_content outside, or call agentshell_update_draft inside the same '
                . 'transaction to edit a draft that agentshell_create_draft created there. Note that '
                . 'agentshell_update_draft only edits drafts and only those owned by the current '
                . 'transaction; status transitions and edits to non-draft content must wait until the '
                . 'transaction is closed.'
            );
        }
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