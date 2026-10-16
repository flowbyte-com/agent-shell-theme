<?php
/**
 * Live navigation regressions, run against a real WordPress install.
 *
 * The previous navigation tests passed while the tool was fatally broken,
 * because they only ever exercised a pure row-builder and a schema literal —
 * `execute()` was never called, so the call to a function that does not exist
 * was invisible. These tests call `execute()` against real WordPress and then
 * read the result back with WordPress's own APIs, which is the only way to
 * prove the menu, its items, their order and its location are what the tool
 * claimed.
 *
 * They also pin the failure behaviour that the old implementation lacked
 * entirely: a request that fails part-way must leave no orphan menu, must not
 * damage a menu that already existed, and must leave the nav_menu_locations
 * map exactly as it found it.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is safe
 * to run anywhere; `tests/wp-live.php` finds the install and picks a PHP that
 * can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp-live.php';

echo "live navigation registration (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live navigation suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-nav-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/navigation-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live navigation child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['nav_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['nav_f'] = $f;

function nav_f( $key, $default = null ) {
    $f = $GLOBALS['nav_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

/**
 * json_encode for a failure message. Avoids depending on wp_json_encode,
 * which does not exist in the bootstrap.
 */
function wp_json_encode_compat( $value ) {
    return (string) json_encode( $value );
}

// --------------------------------------------------------------- creation
Agentshell_Test::run(
    'creates a menu with several items in the requested order',
    function () {
        Agentshell_Test::assert_equals( true, nav_f( 'create_ok' ), 'create error: ' . nav_f( 'create_error' ) );
        Agentshell_Test::assert_equals(
            array( 'Home page', 'Blog post', 'GitHub', 'Resources' ),
            nav_f( 'order_after_create' )
        );
    }
);

Agentshell_Test::run(
    'reports the real WordPress menu and item ids',
    function () {
        $menu_id = (int) nav_f( 'menu_id' );
        Agentshell_Test::assert_true( $menu_id > 0 );
        Agentshell_Test::assert_equals( true, nav_f( 'reported_created' ) );

        $reported = nav_f( 'reported_items' );
        Agentshell_Test::assert_equals( 4, count( $reported ) );
        Agentshell_Test::assert_equals(
            nav_f( 'ids_after_create' ),
            array_map( function ( $r ) { return (int) $r['menu_item_id']; }, $reported )
        );
        foreach ( $reported as $r ) {
            Agentshell_Test::assert_true( (int) $r['menu_item_id'] > 0, 'reported id must be real' );
        }
    }
);

Agentshell_Test::run(
    'a page_id item is a post_type item pointing at that post',
    function () {
        Agentshell_Test::assert_equals( 'Home page', nav_f( 'item0_title' ) );
        Agentshell_Test::assert_equals( 'post_type', nav_f( 'item0_type' ) );
        Agentshell_Test::assert_equals( (int) nav_f( 'page_a' ), (int) nav_f( 'item0_object_id' ) );
        // The post type must be resolved, not left blank: WordPress writes
        // _menu_item_object straight from the row it is given.
        Agentshell_Test::assert_equals( nav_f( 'page_a_type' ), nav_f( 'item0_object' ) );
    }
);

Agentshell_Test::run(
    'url and label-only items become custom items with the right URL',
    function () {
        Agentshell_Test::assert_equals( 'https://github.com/example', nav_f( 'item2_url' ) );
        Agentshell_Test::assert_equals( '#', nav_f( 'item3_url' ) );
    }
);

Agentshell_Test::run(
    'a flat item list produces a flat menu',
    function () {
        // Four items, none of which named a parent, must all sit at the top
        // level. Ordering alone cannot catch this.
        Agentshell_Test::assert_equals(
            array( 0, 0, 0, 0 ),
            array_map( 'intval', nav_f( 'item_parents_flat', array() ) )
        );
    }
);

Agentshell_Test::run(
    'a parent label resolves to the earlier item it names',
    function () {
        Agentshell_Test::assert_equals( array( 'Top', 'Nested' ), nav_f( 'parent_child_order' ) );
        Agentshell_Test::assert_equals(
            (int) nav_f( 'parent_child_expected_parent_id' ),
            (int) nav_f( 'parent_child_parent_id' )
        );
    }
);

Agentshell_Test::run(
    'the theme location is assigned to the new menu',
    function () {
        // nav_menu_locations is keyed by location slug. Asserting the menu id
        // landed there is the only way to catch the map being written the
        // other way round.
        Agentshell_Test::assert_equals( (int) nav_f( 'menu_id' ), (int) nav_f( 'location_after_create' ) );
        Agentshell_Test::assert_equals( true, nav_f( 'has_nav_menu' ), 'has_nav_menu() gates whether wp_nav_menu() renders the menu' );
    }
);

Agentshell_Test::run(
    'the frontend renders the menu in the requested order',
    function () {
        $html = (string) nav_f( 'rendered_nav' );
        Agentshell_Test::assert_true(
            false !== strpos( $html, 'Home page' ),
            'rendered nav should contain the first item: ' . $html
        );
        // wp_nav_menu() falls back to the automatic page menu when the
        // location assignment is not found, so the fallback must not be what
        // is being asserted here.
        Agentshell_Test::assert_false(
            false !== strpos( $html, 'page-item' ),
            'nav fell back to the automatic page menu: ' . $html
        );

        $positions = array();
        foreach ( array( 'Home page', 'Blog post', 'GitHub', 'Resources' ) as $label ) {
            $at = strpos( $html, $label );
            Agentshell_Test::assert_true( false !== $at, "rendered nav should contain {$label}" );
            $positions[] = false === $at ? PHP_INT_MAX : $at;
        }
        $sorted = $positions;
        sort( $sorted );
        Agentshell_Test::assert_equals( $sorted, $positions, 'rendered order should match the requested order' );
    }
);

// ------------------------------------------------------------ idempotency
Agentshell_Test::run(
    're-registering the same inputs keeps the menu and the item ids',
    function () {
        Agentshell_Test::assert_equals( (int) nav_f( 'menu_id' ), (int) nav_f( 'idempotent_menu_id' ) );
        Agentshell_Test::assert_equals( false, nav_f( 'idempotent_created_flag' ), 'second run must not claim to have created it' );
        Agentshell_Test::assert_equals( nav_f( 'ids_after_create' ), nav_f( 'idempotent_ids' ) );
        Agentshell_Test::assert_equals( nav_f( 'order_after_create' ), nav_f( 'idempotent_order' ) );
    }
);

Agentshell_Test::run(
    'reordering rewrites the menu without creating or losing items',
    function () {
        Agentshell_Test::assert_equals( true, nav_f( 'reorder_ok' ) );
        Agentshell_Test::assert_equals(
            array( 'Resources', 'GitHub', 'Blog post', 'Home page' ),
            nav_f( 'reorder_order' )
        );
        Agentshell_Test::assert_equals( nav_f( 'idempotent_ids' ), nav_f( 'reorder_ids' ) );
    }
);

Agentshell_Test::run(
    'shrinking the item list removes the surplus items',
    function () {
        Agentshell_Test::assert_equals( true, nav_f( 'shrink_ok' ) );
        Agentshell_Test::assert_equals( array( 'Only one now' ), nav_f( 'shrink_order' ) );
        Agentshell_Test::assert_equals( 1, (int) nav_f( 'shrink_count' ) );
    }
);

// --------------------------------------------------------------- failure
Agentshell_Test::run(
    'a failure part-way through creating a menu leaves no orphan behind',
    function () {
        Agentshell_Test::assert_equals( false, nav_f( 'orphan_call_ok' ), 'the blocked insert must fail the call' );
        Agentshell_Test::assert_equals( 0, (int) nav_f( 'orphan_menu_exists' ), 'the menu must not survive a failed create' );
    }
);

Agentshell_Test::run(
    'the WP_Error from the failing item reaches the caller',
    function () {
        $error = (string) nav_f( 'orphan_error' );
        Agentshell_Test::assert_true(
            false !== strpos( $error, 'Blocked' ) || false !== strpos( $error, 'items[1]' ),
            'error should name the failing item: ' . $error
        );
        // WordPress's own message, propagated rather than swallowed.
        Agentshell_Test::assert_true(
            false !== stripos( $error, 'empty' ),
            'WordPress error text should be propagated: ' . $error
        );
    }
);

Agentshell_Test::run(
    'a failed create leaves the location map untouched',
    function () {
        Agentshell_Test::assert_equals(
            nav_f( 'locations_before_orphan' ),
            nav_f( 'locations_after_orphan' ),
            'before: ' . wp_json_encode_compat( nav_f( 'locations_before_orphan' ) )
            . ' after: ' . wp_json_encode_compat( nav_f( 'locations_after_orphan' ) )
        );
    }
);

Agentshell_Test::run(
    'a failure part-way through an existing menu does not damage it',
    function () {
        Agentshell_Test::assert_equals( false, nav_f( 'existing_call_ok' ) );
        Agentshell_Test::assert_equals( true, nav_f( 'existing_menu_still_there' ), 'the pre-existing menu must survive' );
        Agentshell_Test::assert_equals(
            nav_f( 'existing_before' ),
            nav_f( 'existing_after' ),
            'the pre-existing items must be restored exactly'
        );
        Agentshell_Test::assert_equals(
            nav_f( 'existing_ids_before' ),
            nav_f( 'existing_ids_after' ),
            'the pre-existing item ids must be restored exactly'
        );
    }
);

Agentshell_Test::run(
    'a failure restores the location map it was going to change',
    function () {
        Agentshell_Test::assert_equals(
            true,
            nav_f( 'locations_restored' ),
            'locations before: ' . wp_json_encode_compat( nav_f( 'locations_before_fail' ) )
            . ' after: ' . wp_json_encode_compat( nav_f( 'locations_after_fail' ) )
        );
    }
);

Agentshell_Test::run(
    'invalid input is refused before anything is written',
    function () {
        Agentshell_Test::assert_equals( false, nav_f( 'invalid_created_any' ), 'no menu may be created by a refused request' );
        $errors = nav_f( 'invalid_errors' );
        Agentshell_Test::assert_equals( 4, count( $errors ) );
        foreach ( $errors as $i => $error ) {
            Agentshell_Test::assert_true( '' !== $error, "case {$i} should have failed" );
        }
        // The first case is the bad location; it must name the setting.
        Agentshell_Test::assert_true(
            false !== strpos( $errors[0], 'no-such-location' ),
            'bad location should be named: ' . $errors[0]
        );
    }
);

// ---------------------------------------------------- transaction semantics
Agentshell_Test::run(
    'the result reports that navigation writes are not staged',
    function () {
        Agentshell_Test::assert_equals( false, nav_f( 'reported_staged' ) );
        Agentshell_Test::assert_true(
            false !== stripos( (string) nav_f( 'reported_note' ), 'rollback_transaction' ),
            'the note should say plainly that rollback will not undo this'
        );
    }
);

// --------------------------------------------------------------- cleanup
Agentshell_Test::run(
    'the suite cleans up every menu it created',
    function () {
        Agentshell_Test::assert_equals( 0, (int) nav_f( 'leftover_probe_menus' ) );
        Agentshell_Test::assert_equals(
            nav_f( 'menus_before' ),
            nav_f( 'menus_after' ),
            'pre-existing menus must be preserved'
        );
        Agentshell_Test::assert_equals( nav_f( 'locations_original' ), nav_f( 'locations_after_cleanup' ) );
    }
);

Agentshell_Test::run(
    'the child reported no internal errors',
    function () {
        Agentshell_Test::assert_equals( array(), nav_f( 'notes', array() ) );
    }
);