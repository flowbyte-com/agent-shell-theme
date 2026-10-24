<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_restore_content — bring a post or page back from the Trash.
 *
 * The content returns to the status it had before it was trashed, which is
 * what WordPress records when the post is moved to the Trash.
 *
 * Restoring a post that is not in the Trash changes nothing and says so,
 * so a retried call is safe.
 *
 * The same checks as the trash operation apply: the `delete_post`
 * capability for that post, no open transaction, and no uncommitted work
 * attached to the content.
 */
class Restore_Content extends Base_Tool {
    public function get_name() { return 'agentshell_restore_content'; }
    public function get_description() { return 'Restore a post or page from the Trash. By default WordPress restores it as a draft; pass status="previous" to put it back exactly as it was before it was trashed. Requires the delete_post capability for that post, and refuses while a transaction is open or while the content is uncommitted work.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'     => array( 'type' => 'integer', 'description' => 'The post or page id.' ),
                'type'   => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Defaults to page.' ),
                'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'previous' ), 'description' => 'The status to restore it to. "draft" (the default) is WordPress\'s own behaviour; "previous" is the status it had before it was trashed.' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Trash::restore(
            (int) $arguments['id'],
            (string) ( $arguments['type'] ?? 'page' ),
            (string) ( $arguments['status'] ?? 'draft' )
        );
    }
}
