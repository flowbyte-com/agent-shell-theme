<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_trash_content — move a post or page to the Trash.
 *
 * Reversible by design: the content goes to the Trash, where it can be
 * restored with `agentshell_restore_content`. There is no permanent
 * deletion here and no argument that would turn this into one.
 *
 * Trashing a post that is already in the Trash changes nothing and says
 * so, so a retried call is safe.
 *
 * The operation is refused — and the content is left exactly as it was —
 * when the caller does not hold the `delete_post` capability for that
 * post, when a transaction is open, or when the content is uncommitted work
 * that a journal or a published edit is holding.
 */
class Trash_Content extends Base_Tool {
    public function get_name() { return 'agentshell_trash_content'; }
    public function get_description() { return 'Move a post or page to the Trash. Reversible: restore it with agentshell_restore_content. There is no permanent deletion. Requires the delete_post capability for that post, and refuses while a transaction is open or while the content is uncommitted work.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'   => array( 'type' => 'integer', 'description' => 'The post or page id.' ),
                'type' => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Defaults to page.' ),
            ),
            'required'   => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Trash::trash(
            (int) $arguments['id'],
            (string) ( $arguments['type'] ?? 'page' )
        );
    }
}
