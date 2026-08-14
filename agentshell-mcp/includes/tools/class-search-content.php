<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_search_content — search posts and pages.
 */
class Search_Content extends Base_Tool {
    public function get_name() { return 'agentshell_search_content'; }
    public function get_description() { return 'Search posts/pages by title or body text (empty query lists most recently modified). Returns compact records: id, title, type, status, date, modified, excerpt.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'query'  => array( 'type' => 'string', 'description' => 'Search term; empty lists recent content' ),
                'type'   => array( 'type' => 'string', 'enum' => array( 'both', 'post', 'page' ), 'description' => 'Default both' ),
                'status' => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'publish', 'pending', 'private', 'future' ), 'description' => 'Default any' ),
                'limit'  => array( 'type' => 'integer', 'description' => '1-50, default 10' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Primitives::search(
            $arguments['query'] ?? '',
            $arguments['type'] ?? 'both',
            $arguments['status'] ?? 'any',
            (int) ( $arguments['limit'] ?? 10 )
        );
    }
}