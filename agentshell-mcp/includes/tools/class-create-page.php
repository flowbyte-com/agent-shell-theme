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
                'include_content' => array( 'type' => 'boolean', 'description' => 'Include the content body in the response. Defaults to true (the existing contract); pass false to get the metadata and the length only, which keeps a large body out of the transcript.' ),
            ),
            'required'   => array( 'title', 'content' ),
        );
    }

    public function execute( array $arguments ) {
        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_create_page refuses inside a transaction. No journal-aware implementation exists '
                . 'for this operation. The one content tool that may be called inside an open transaction is '
                . 'agentshell_create_draft. Either close the transaction (commit or rollback) and call '
                . 'agentshell_create_page outside, or call agentshell_create_draft if you want this change to be '
                . 'journal-aware.'
            );
        }
        // Presence, not emptiness. A missing argument is not an empty
        // argument: reading it directly raised "Undefined array key"
        // and carried on with an empty value, so a caller who forgot the
        // body was warned at and then told the page had been created. A
        // missing title and a missing body together are refused by the
        // content primitives below ("a title and content are both empty"),
        // which is the rule AGENTS.md 6.3 states.
        $title   = array_key_exists( 'title', $arguments ) ? (string) $arguments['title'] : '';
        $content = array_key_exists( 'content', $arguments ) ? (string) $arguments['content'] : '';
        return \AgentShell_MCP\Content_Primitives::create(
            'page',
            $title,
            $content,
            $arguments['status'] ?? 'draft',
            array(
                'post_name' => $arguments['slug'] ?? null,
                'parent'    => $arguments['parent'] ?? null,
                'include_content' => $arguments['include_content'] ?? true,
            )
        );
    }
}