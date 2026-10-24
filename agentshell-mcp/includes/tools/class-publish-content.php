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
                'include_content' => array( 'type' => 'boolean', 'description' => 'Include the content body in the response. Defaults to true (the existing contract); pass false to get the metadata and the length only, which keeps a large body out of the transcript.' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_publish refuses inside a transaction. No journal-aware status change exists yet; '
                . 'publish is Stage 2. The one content tool that may be called inside an open transaction is '
                . 'agentshell_create_draft. Either close the transaction (commit or rollback) and call '
                . 'agentshell_publish outside.'
            );
        }
        return \AgentShell_MCP\Content_Primitives::set_status(
            (int) $arguments['id'],
            $arguments['type'] ?? 'page',
            'publish',
            $arguments['include_content'] ?? true
        );
    }
}