<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_list_revisions — configuration history.
 */
class List_Revisions extends Base_Tool {
    public function get_name() { return 'agentshell_list_revisions'; }
    public function get_description() { return 'List configuration revisions (newest first): id, parent, parent_deleted, timestamp, actor, label, description, operation. Each config mutation creates one revision.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $data       = \AgentShell_MCP\Store::revisions();
        $items      = $data['items'] ?? array();
        $tombstones = \AgentShell_MCP\Store::revision_tombstones();
        $out        = array();
        foreach ( $items as $item ) {
            $parent = $item['parent'] ?? null;
            $out[] = array(
                'id'             => $item['id'],
                'parent'         => $parent,
                'parent_deleted' => null !== $parent && isset( $tombstones[ $parent ] ),
                'timestamp'      => $item['timestamp'],
                'actor'          => $item['actor'],
                'label'          => $item['label'],
                'description'    => \AgentShell_MCP\Store::describe_revision( $item, $items ),
                'operation'      => $item['operation'] ?? null,
                'transaction_id' => $item['transaction_id'] ?? null,
            );
        }
        return array(
            'revisions' => $out,
            'total'     => count( $out ),
            'tip'       => 'Use agentshell_diff_revisions with two ids to compare, agentshell_restore_revision to roll back.',
        );
    }
}