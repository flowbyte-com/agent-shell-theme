<?php
/**
 * Navigation management — the parts that need no WordPress.
 *
 * Two layers of the tool are pure and can be pinned here: the
 * `wp_update_nav_menu_item()` row builder, and the whole-list validation that
 * runs *before* anything is written. The second matters more than the first:
 * it is what stops an invalid request from creating a menu and then dying half
 * way through it, which is exactly how the previous implementation lost data.
 *
 * Everything that touches the database — creating the menu, writing items,
 * ordering them, assigning the location, and compensating when a write fails
 * mid-way — lives in `navigation-live-test.php` and runs against a real
 * WordPress install. It used to have no coverage at all, which is why a call
 * to a function that has never existed in WordPress went unnoticed.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-register-navigation.php';

echo "navigation management tests\n";

// --------------------------------------------------------------- item rows

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
        // The object type is a database lookup, so it is left for the caller
        // to resolve and pass in.
        Agentshell_Test::assert_false( isset( $row['menu-item-object'] ) );
    }
);

Agentshell_Test::run(
    'item row: a resolved object type is carried into the row',
    function () {
        $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row(
            array( 'label' => 'Docs', 'page_id' => 42 ),
            'page'
        );
        Agentshell_Test::assert_equals( 'page', $row['menu-item-object'] );
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

// ------------------------------------------------------------ normalisation

Agentshell_Test::run(
    'normalise: keeps input order and trims labels',
    function () {
        $out = \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
            array( 'label' => '  One  ' ),
            array( 'label' => 'Two', 'url' => ' https://two.test/ ' ),
            array( 'label' => 'Three', 'page_id' => '9' ),
        ) );
        Agentshell_Test::assert_equals( array( 'One', 'Two', 'Three' ), array_column( $out, 'label' ) );
        Agentshell_Test::assert_equals( 'https://two.test/', $out[1]['url'] );
        Agentshell_Test::assert_equals( 9, $out[2]['page_id'], 'page_id is cast to int' );
        Agentshell_Test::assert_equals( 0, $out[0]['page_id'] );
    }
);

Agentshell_Test::run(
    'normalise: a parentless item is null, not index 0',
    function () {
        // null and 0 are different things here. Reading "no parent" as index 0
        // nests every flat item under the first entry, which silently turns a
        // five-link menu into a menu with one top-level link and four children.
        $out = \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
            array( 'label' => 'One' ),
            array( 'label' => 'Two' ),
        ) );
        Agentshell_Test::assert_true( null === $out[0]['_agentshell_parent_index'] );
        Agentshell_Test::assert_true( null === $out[1]['_agentshell_parent_index'] );
    }
);

Agentshell_Test::run(
    'normalise: a string parent resolves to the index of the earlier item',
    function () {
        $out = \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
            array( 'label' => 'Top' ),
            array( 'label' => 'Child' ),
            array( 'label' => 'Grandchild', 'parent' => 'Child' ),
        ) );
        Agentshell_Test::assert_equals( 1, $out[2]['_agentshell_parent_index'] );
    }
);

Agentshell_Test::run(
    'normalise: an integer parent is taken as an index',
    function () {
        $out = \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
            array( 'label' => 'Top' ),
            array( 'label' => 'Child', 'parent' => 0 ),
        ) );
        Agentshell_Test::assert_equals( 0, $out[1]['_agentshell_parent_index'] );
    }
);

Agentshell_Test::run(
    'normalise: a parent must name an earlier item',
    function () {
        // Forward references are refused: every parent is resolved in the same
        // pass that inserts the items, so a parent that has not been written
        // yet has no database id to point at.
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
                array( 'label' => 'Child', 'parent' => 'Parent' ),
                array( 'label' => 'Parent' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
            $message = $e->getMessage();
        }
        Agentshell_Test::assert_true( $threw );
        Agentshell_Test::assert_true(
            isset( $message ) && false !== strpos( $message, 'earlier' ),
            'error should explain the ordering rule'
        );
    }
);

Agentshell_Test::run(
    'normalise: an unknown parent label is refused',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
                array( 'label' => 'One', 'parent' => 'Nowhere' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'normalise: duplicate labels are refused',
    function () {
        // Labels resolve parent references, so a duplicate makes them
        // ambiguous. Better to refuse than to pick one.
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
                array( 'label' => 'Same' ),
                array( 'label' => 'Other' ),
                array( 'label' => 'Same' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
            $message = $e->getMessage();
        }
        Agentshell_Test::assert_true( $threw );
        Agentshell_Test::assert_true(
            isset( $message ) && false !== strpos( $message, 'items[2]' ),
            'error should name the offending index'
        );
    }
);

Agentshell_Test::run(
    'normalise: a missing label is refused before anything is written',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
                array( 'label' => 'Fine' ),
                array( 'url' => 'https://x.test/' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
            $message = $e->getMessage();
        }
        Agentshell_Test::assert_true( $threw );
        Agentshell_Test::assert_true(
            isset( $message ) && false !== strpos( $message, 'items[1]' ),
            'error should name the offending index, not just "an item"'
        );
    }
);

Agentshell_Test::run(
    'normalise: a non-numeric page_id is refused',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array(
                array( 'label' => 'Bad', 'page_id' => 'not-a-number' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'normalise: a non-array item is refused',
    function () {
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_normalise_navigation_items( array( 'oops' ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

// ------------------------------------------------------------------ schema

Agentshell_Test::run(
    'schema: tool name and required arguments are declared',
    function () {
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

Agentshell_Test::run(
    'the tool does not claim to be transaction-staged',
    function () {
        // The old description promised the tool was "safe to re-run inside the
        // same transaction", which was false: it wrote straight through to the
        // database. If that promise ever comes back, this fails.
        $tool = new \AgentShell_MCP\Tools\Register_Navigation();
        $text = strtolower( $tool->get_description() );
        Agentshell_Test::assert_false(
            false !== strpos( $text, 'atomic' ),
            'the tool no longer performs an atomic replace'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $text, 'not transaction-staged' ),
            'the description should state that writes are immediate'
        );
    }
);