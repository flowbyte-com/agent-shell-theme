<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_list_revisions — configuration history.
 */
class List_Revisions extends Base_Tool {
    public function get_name() { return 'agentshell_list_revisions'; }
    public function get_description() { return 'List configuration revisions (newest first): id, parent, timestamp, actor, label. Each config mutation creates one revision.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $data = \AgentShell_MCP\Store::revisions();
        $items = array();
        foreach ( $data['items'] ?? array() as $item ) {
            $items[] = array(
                'id'             => $item['id'],
                'parent'         => $item['parent'],
                'timestamp'      => $item['timestamp'],
                'actor'          => $item['actor'],
                'label'          => $item['label'],
                'transaction_id' => $item['transaction_id'] ?? null,
            );
        }
        return array(
            'revisions' => $items,
            'total'     => count( $items ),
            'tip'       => 'Use agentshell_diff_revisions with two ids to compare, agentshell_restore_revision to roll back.',
        );
    }
}