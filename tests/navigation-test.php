<?php
/**
 * Navigation management tests.
 *
 * The tool agentshell_register_navigation is bounded: it manages one
 * menu at a time, identified by name, replacing all items atomically
 * via wp_update_nav_menu_items. These tests pin the pure data-
 * transformation layer (item-row construction) and the schema-level
 * guarantees that the integration relies on.
 *
 * End-to-end coverage (create menu, persist items, assign location,
 * update existing menu) requires a live WordPress instance and is
 * out of scope for the bootstrap-based test runner; the helpers
 * below are the testable surface.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-register-navigation.php';

echo "navigation management tests\n";

Agentshell_Test::run(
    'item row: external url produces custom-object item',
    function () {
        $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
            array( 'label' => 'GitHub', 'url' => 'https://github.com/example' )
        );
        Agentshell_Test::assert_equals( 'GitHub', $row['menu-item-title'] );
        Agentshell_Test::assert_equals( 'https://github.com/example', $row['menu-item-url'] );
        Agentshell_Test::assert_equals( 'custom', $row['menu-item-object'] );
        Agentshell_Test::assert_equals( 'custom', $row['menu-item-type'] );
        Agentshell_Test::assert_equals( 'publish', $row['menu-item-status'] );
    }
);

Agentshell_Test::run(
    'item row: internal page_id produces post-type item',
    function () {
        $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
            array( 'label' => 'Docs', 'page_id' => 42 )
        );
        Agentshell_Test::assert_equals( 'Docs', $row['menu-item-title'] );
        Agentshell_Test::assert_equals( 42, $row['menu-item-object-id'] );
        Agentshell_Test::assert_equals( 'post_type', $row['menu-item-type'] );
        // object is resolved by the caller via get_post() and may vary
        // (page / post / cpt). The helper leaves it unset.
        Agentshell_Test::assert_false( isset( $row['menu-item-object'] ) );
    }
);

Agentshell_Test::run(
    'item row: label-only becomes a heading',
    function () {
        $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
            array( 'label' => 'Resources' )
        );
        Agentshell_Test::assert_equals( 'Resources', $row['menu-item-title'] );
        Agentshell_Test::assert_equals( '#', $row['menu-item-url'] );
        Agentshell_Test::assert_equals( 'custom', $row['menu-item-object'] );
        Agentshell_Test::assert_equals( 'custom', $row['menu-item-type'] );
    }
);

Agentshell_Test::run(
    'item row: page_id wins over url when both are set',
    function () {
        $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
            array( 'label' => 'Mixed', 'page_id' => 7, 'url' => 'https://ignored/' )
        );
        Agentshell_Test::assert_equals( 7, $row['menu-item-object-id'] );
        Agentshell_Test::assert_equals( 'post_type', $row['menu-item-type'] );
        Agentshell_Test::assert_false( isset( $row['menu-item-url'] ) );
    }
);

Agentshell_Test::run(
    'item row: empty label is rejected',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
                array( 'label' => '', 'url' => 'https://example.com' )
            );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'item row: missing label is rejected',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
                array( 'url' => 'https://example.com' )
            );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'schema: tool name and required arguments are declared',
    function () {
        // We can't easily instantiate the tool class without WP, but
        // the schema constants are static and discoverable.
        $schema = ( new \AgentShell_MCP\Tools\Register_Navigation() )->get_input_schema();
        Agentshell_Test::assert_equals( 'object', $schema['type'] );
        Agentshell_Test::assert_equals(
            array( 'name', 'items' ),
            $schema['required']
        );
        Agentshell_Test::assert_true( isset( $schema['properties']['name'] ) );
        Agentshell_Test::assert_true( isset( $schema['properties']['location'] ) );
        Agentshell_Test::assert_true( isset( $schema['properties']['items'] ) );
    }
);

Agentshell_Test::run(
    'schema: items entries declare label/url/page_id/parent',
    function () {
        $schema = ( new \AgentShell_MCP\Tools\Register_Navigation() )->get_input_schema();
        $item_props = $schema['properties']['items']['items']['properties'];
        Agentshell_Test::assert_true( isset( $item_props['label'] ) );
        Agentshell_Test::assert_true( isset( $item_props['url'] ) );
        Agentshell_Test::assert_true( isset( $item_props['page_id'] ) );
        Agentshell_Test::assert_true( isset( $item_props['parent'] ) );
    }
);