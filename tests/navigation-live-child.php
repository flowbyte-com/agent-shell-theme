<?php
/**
 * Child half of the live navigation suite. Runs inside a real WordPress
 * install and reports facts as JSON; the parent asserts them.
 *
 * Everything it creates is disposable and named with a per-run token, and it
 * deletes all of it — plus restores the nav_menu_locations map — before it
 * exits. It never creates content: page_id targets are read from posts the
 * site already had.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

// Fail-closed identity guard — refuses the script if the wp_root
// does not match the test-bed profile. The guard itself checks
// wp-load.php existence plus the full identity chain (DB name,
// server_uuid, sentinel, siteurl, grants, open-transaction guard),
// so the legacy `is_file(wp-load.php)` check is no longer needed.
require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-register-navigation.php';

use function AgentShell_MCP\Tools\agentshell_normalise_navigation_items;

// Precondition: close any stale `live *` transaction left on the
// site by a prior crashed run. The Stage 1b "refuses inside a
// transaction" guard on `register_navigation` would otherwise fire
// on every test call. See `tests/agentshell-live-precondition.php`
// for the full rationale. Refusals (non-test transaction,
// applied-work orphan) are recorded in the report and the suite
// skips its destructive work.
require_once __DIR__ . '/agentshell-live-precondition.php';
$pre = agentshell_live_precondition();
if ( ! empty( $pre['ok'] ) && 'none' !== $pre['action'] ) {
    fwrite( STDERR, "precondition: {$pre['action']} (tx={$pre['tx_id']} label={$pre['tx_label']})\n" );
} elseif ( empty( $pre['ok'] ) ) {
    fwrite( STDERR, "precondition: REFUSED {$pre['action']} (tx={$pre['tx_id']} label={$pre['tx_label']})\n" );
    $facts['precondition_refused'] = $pre;
    file_put_contents( $report, json_encode( $facts ) );
    echo "reported\n";
    exit( 0 );
}

$tool = new \AgentShell_MCP\Tools\Register_Navigation();

$token      = 'ASNav' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$locations0 = get_nav_menu_locations();
$menus0     = wp_get_nav_menus();

$made      = array();   // every menu term this run created
$facts     = array();
$failures  = array();
$facts['precondition'] = $pre;

// Run-scoped fixture tracker — exact-id registry with try/finally
// teardown and parent-process recovery sweep. Every menu id this
// run creates is registered against the tracker's per-run
// registry so a child that crashes mid-script still releases its
// holdings when the parent sweeps the registry file. The
// existing $made foreach remains the primary cleanup path; the
// tracker is a redundant safety net.
require_once __DIR__ . '/agentshell-test-fixtures.php';
$fixtures = new Agentshell_Test_Fixtures();

/**
 * Remove every probe menu and restore the location map, whatever happens.
 *
 * A fatal part-way through this script used to leave its menus behind on the
 * developer's site, which then leaked into the next run's baseline and made a
 * correct compensation look like a failure. Cleanup has to survive a crash,
 * so it runs on shutdown and sweeps by name prefix rather than relying on the
 * orderly path at the bottom of the file.
 */
register_shutdown_function( function () use ( $token, $locations0 ) {
    foreach ( (array) wp_get_nav_menus() as $menu ) {
        if ( 0 === strpos( (string) $menu->name, 'AS Nav Probe ' ) ) {
            wp_delete_nav_menu( $menu->term_id );
        }
    }
    set_theme_mod( 'nav_menu_locations', $locations0 );
} );

/**
 * Record a fact for the parent to assert on.
 */
function fact( $key, $value ) {
    global $facts;
    $facts[ $key ] = $value;
}

function note_failure( $message ) {
    global $failures;
    $failures[] = $message;
}

/**
 * Titles of a menu's items, in WordPress order.
 */
function menu_titles( $menu_id ) {
    $out = array();
    foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
        $out[] = $item->post_title;
    }
    return $out;
}

function menu_ids( $menu_id ) {
    $out = array();
    foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
        $out[] = (int) $item->ID;
    }
    return $out;
}

function menu_exists( $name ) {
    $found = wp_get_nav_menu_object( $name );
    return $found ? (int) $found->term_id : 0;
}

/**
 * Run the tool and capture either its result or the exception message.
 */
function call_tool( array $args ) {
    global $tool;
    try {
        return array( 'ok' => true, 'result' => $tool->execute( $args ) );
    } catch ( \Throwable $e ) {
        return array( 'ok' => false, 'error' => get_class( $e ) . ': ' . $e->getMessage() );
    }
}

/**
 * Block insertion of nav menu items whose title matches $title, so a failure
 * can be provoked part-way through a write. Uses a real WordPress filter, so
 * the code under test hits a genuine WP_Error rather than a test double.
 */
function block_title( $title ) {
    add_filter(
        'wp_insert_post_empty_content',
        function ( $maybe_empty, $postarr ) use ( $title ) {
            return ( $postarr['post_title'] ?? '' ) === $title ? true : $maybe_empty;
        },
        10,
        2
    );
}

function unblock_title() {
    remove_all_filters( 'wp_insert_post_empty_content' );
}

// ---------------------------------------------------------------- fixtures
$pages = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 2, 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( count( $pages ) < 2 ) {
    fwrite( STDERR, "need two published posts/pages on the site\n" );
    exit( 2 );
}
$page_a = (int) $pages[0]->ID;
$page_b = (int) $pages[1]->ID;
fact( 'page_a', $page_a );
fact( 'page_b', $page_b );
fact( 'page_a_type', $pages[0]->post_type );
fact( 'page_b_type', $pages[1]->post_type );
fact( 'locations_before', $locations0 );

// ------------------------------------------------- 1. create, order, types
$name_main = 'AS Nav Probe ' . $token . ' Main';
$created   = call_tool( array(
    'name'     => $name_main,
    'location' => 'primary',
    'items'    => array(
        array( 'label' => 'Home page', 'page_id' => $page_a ),
        array( 'label' => 'Blog post', 'page_id' => $page_b ),
        array( 'label' => 'GitHub', 'url' => 'https://github.com/example' ),
        array( 'label' => 'Resources' ),
    ),
) );
if ( ! $created['ok'] ) {
    note_failure( 'create failed: ' . $created['error'] );
} else {
    $made[] = $created['result']['menu_id'];
    $fixtures->register_menu( (int) $created['result']['menu_id'], $created['result']['name'] ?? '' );
}
$menu_id = $created['ok'] ? (int) $created['result']['menu_id'] : 0;
fact( 'create_ok', $created['ok'] );
fact( 'menu_id', $menu_id );
fact( 'create_error', $created['ok'] ? '' : $created['error'] );
fact( 'order_after_create', $menu_id ? menu_titles( $menu_id ) : array() );
fact( 'ids_after_create', $menu_id ? menu_ids( $menu_id ) : array() );
fact( 'reported_items', $created['ok'] ? $created['result']['items'] : array() );
fact( 'reported_created', $created['ok'] ? $created['result']['created'] : null );
fact( 'reported_staged', $created['ok'] ? $created['result']['staged'] : null );
fact( 'reported_note', $created['ok'] ? $created['result']['transaction_note'] : '' );
// The tool call above asked for the 'primary' location; read the map back by
// that slug, which is how nav_menu_locations is keyed.
$location_slug = 'primary';
fact( 'location_after_create', $menu_id && isset( get_nav_menu_locations()[ $location_slug ] )
    ? (int) get_nav_menu_locations()[ $location_slug ] : null );
// has_nav_menu() is what wp_nav_menu() consults. If the location map is
// written in the shape get_nav_menu_locations()'s docblock describes — menu id
// keyed, location valued — nothing finds the assignment and the frontend
// silently falls back to the automatic page menu.
fact( 'has_nav_menu', $menu_id ? has_nav_menu( 'primary' ) : false );
fact( 'rendered_nav', $menu_id ? wp_nav_menu( array( 'echo' => false, 'theme_location' => 'primary' ) ) : '' );

if ( $menu_id ) {
    // There is no wp_get_nav_menu_item() in WordPress core — only the list
    // getter — so items are addressed by their position in that list.
    $posts = array_values( (array) wp_get_nav_menu_items( $menu_id ) );
    $first = $posts[0] ?? null;
    fact( 'item0_title', $first ? $first->post_title : null );
    fact( 'item0_type', $first ? get_post_meta( $first->ID, '_menu_item_type', true ) : null );
    fact( 'item0_object_id', $first ? (int) get_post_meta( $first->ID, '_menu_item_object_id', true ) : null );
    fact( 'item0_object', $first ? get_post_meta( $first->ID, '_menu_item_object', true ) : null );
    fact( 'item2_url', isset( $posts[2] ) ? get_post_meta( $posts[2]->ID, '_menu_item_url', true ) : null );
    fact( 'item3_url', isset( $posts[3] ) ? get_post_meta( $posts[3]->ID, '_menu_item_url', true ) : null );
    // A flat menu must actually be flat. Reading "no parent" as index 0 makes
    // every item after the first a child of the first, which still *orders*
    // correctly and so passes a naive order-only assertion.
    $parents = array();
    foreach ( $posts as $post ) {
        $parents[] = (int) get_post_meta( $post->ID, '_menu_item_menu_item_parent', true );
    }
    fact( 'item_parents_flat', $parents );
}

// Nested parents are not in the fixture, but a child item must round-trip.
$name_parent = 'AS Nav Probe ' . $token . ' Parent';
$parented    = call_tool( array(
    'name'  => $name_parent,
    'items' => array(
        array( 'label' => 'Top' ),
        array( 'label' => 'Nested', 'parent' => 'Top' ),
    ),
) );
if ( $parented['ok'] ) {
    $made[] = $parented['result']['menu_id'];
    $fixtures->register_menu( (int) $parented['result']['menu_id'], $parented['result']['name'] ?? '' );
    $pid    = (int) $parented['result']['menu_id'];
    $pids   = menu_ids( $pid );
    fact( 'parent_child_order', menu_titles( $pid ) );
    fact( 'parent_child_parent_id', (int) get_post_meta( $pids[1], '_menu_item_menu_item_parent', true ) );
    fact( 'parent_child_expected_parent_id', $pids[0] );
} else {
    note_failure( 'parented create failed: ' . $parented['error'] );
    fact( 'parent_child_order', array() );
    fact( 'parent_child_parent_id', 0 );
    fact( 'parent_child_expected_parent_id', 0 );
}

// -------------------------------------------------------- 2. idempotency
$again = call_tool( array(
    'name'     => $name_main,
    'location' => 'primary',
    'items'    => array(
        array( 'label' => 'Home page', 'page_id' => $page_a ),
        array( 'label' => 'Blog post', 'page_id' => $page_b ),
        array( 'label' => 'GitHub', 'url' => 'https://github.com/example' ),
        array( 'label' => 'Resources' ),
    ),
) );
fact( 'idempotent_menu_id', $again['ok'] ? (int) $again['result']['menu_id'] : 0 );
fact( 'idempotent_created_flag', $again['ok'] ? $again['result']['created'] : null );
fact( 'idempotent_ids', $menu_id ? menu_ids( $menu_id ) : array() );
fact( 'idempotent_order', $menu_id ? menu_titles( $menu_id ) : array() );

// -------------------------------------------------------------- 3. reorder
$reversed = call_tool( array(
    'name'  => $name_main,
    'items' => array(
        array( 'label' => 'Resources' ),
        array( 'label' => 'GitHub', 'url' => 'https://github.com/example' ),
        array( 'label' => 'Blog post', 'page_id' => $page_b ),
        array( 'label' => 'Home page', 'page_id' => $page_a ),
    ),
) );
fact( 'reorder_ok', $reversed['ok'] );
fact( 'reorder_order', $menu_id ? menu_titles( $menu_id ) : array() );
fact( 'reorder_ids', $menu_id ? menu_ids( $menu_id ) : array() );

// --------------------------------------------------------------- 4. shrink
$shrunk = call_tool( array(
    'name'  => $name_main,
    'items' => array(
        array( 'label' => 'Only one now' ),
    ),
) );
fact( 'shrink_ok', $shrunk['ok'] );
fact( 'shrink_order', $menu_id ? menu_titles( $menu_id ) : array() );
fact( 'shrink_count', $menu_id ? count( menu_ids( $menu_id ) ) : 0 );

// ------------------------------------------- 5. mid-write failure, no orphan
$name_fail = 'AS Nav Probe ' . $token . ' Orphan';
// Baseline taken here, not at process start: earlier steps legitimately
// changed the location map, and the question is whether *this* failure
// disturbed anything.
$locations_before_orphan = get_nav_menu_locations();
block_title( 'Never created' );
$failed = call_tool( array(
    'name'     => $name_fail,
    'location' => 'primary',
    'items'    => array(
        array( 'label' => 'First' ),
        array( 'label' => 'Never created' ),
        array( 'label' => 'Third' ),
    ),
) );
unblock_title();
fact( 'orphan_call_ok', $failed['ok'] );
fact( 'orphan_error', $failed['ok'] ? '' : $failed['error'] );
fact( 'orphan_menu_exists', menu_exists( $name_fail ) );
fact( 'locations_before_orphan', $locations_before_orphan );
fact( 'locations_after_orphan', get_nav_menu_locations() );

// ----------------------- 6. mid-write failure must not damage an existing menu
$name_existing = 'AS Nav Probe ' . $token . ' Existing';
$seed          = call_tool( array(
    'name'     => $name_existing,
    'location' => 'primary',
    'items'    => array(
        array( 'label' => 'Keep one' ),
        array( 'label' => 'Keep two' ),
        array( 'label' => 'Keep three' ),
    ),
) );
if ( $seed['ok'] ) {
    $made[]     = $seed['result']['menu_id'];
    $fixtures->register_menu( (int) $seed['result']['menu_id'], $seed['result']['name'] ?? '' );
    $existing_id = (int) $seed['result']['menu_id'];
} else {
    note_failure( 'seed failed: ' . $seed['error'] );
    $existing_id = 0;
}
$existing_before = $existing_id ? menu_titles( $existing_id ) : array();
$existing_ids_before = $existing_id ? menu_ids( $existing_id ) : array();
$locations_before_fail = get_nav_menu_locations();

block_title( 'Blocked third' );
$damaging = call_tool( array(
    'name'     => $name_existing,
    'location' => 'primary',
    'items'    => array(
        array( 'label' => 'Rewritten one' ),
        array( 'label' => 'Rewritten two' ),
        array( 'label' => 'Blocked third' ),
        array( 'label' => 'Never reached' ),
    ),
) );
unblock_title();

fact( 'existing_call_ok', $damaging['ok'] );
fact( 'existing_error', $damaging['ok'] ? '' : $damaging['error'] );
fact( 'existing_before', $existing_before );
fact( 'existing_ids_before', $existing_ids_before );
fact( 'existing_after', $existing_id ? menu_titles( $existing_id ) : array() );
fact( 'existing_ids_after', $existing_id ? menu_ids( $existing_id ) : array() );
fact( 'existing_menu_still_there', menu_exists( $name_existing ) === $existing_id );
fact( 'locations_restored', get_nav_menu_locations() == $locations_before_fail );
fact( 'locations_before_fail', $locations_before_fail );
fact( 'locations_after_fail', get_nav_menu_locations() );

// ------------------------------- 7. invalid input must create nothing at all
$menus_before_invalid = wp_get_nav_menus();
$invalid = array(
    call_tool( array( 'name' => 'AS Nav Probe ' . $token . ' Bad1', 'location' => 'no-such-location', 'items' => array( array( 'label' => 'x' ) ) ) ),
    call_tool( array( 'name' => 'AS Nav Probe ' . $token . ' Bad2', 'items' => array( array( 'url' => 'https://x.test/' ) ) ) ),
    call_tool( array( 'name' => 'AS Nav Probe ' . $token . ' Bad3', 'items' => array( array( 'label' => 'y', 'page_id' => 99999999 ) ) ) ),
    call_tool( array( 'name' => 'AS Nav Probe ' . $token . ' Bad4', 'items' => array( array( 'label' => 'z', 'parent' => 'nowhere' ) ) ) ),
);
fact( 'invalid_errors', array_map( function ( $r ) { return $r['ok'] ? '' : $r['error']; }, $invalid ) );
fact( 'invalid_created_any', count( wp_get_nav_menus() ) > count( $menus_before_invalid ) );

// ------------------------------------------------------------- 8. cleanup
//
// The fixture tracker's per-run registry is the safety net: every
// menu registered via `$fixtures->register_menu()` is removed by
// the teardown call below. The existing $made foreach is the
// primary path and remains in place. Both reach the same menus by
// exact id, so a double-delete is a no-op.
$fixtures->teardown();

foreach ( $made as $id ) {
    wp_delete_nav_menu( $id );
}
set_theme_mod( 'nav_menu_locations', $locations0 );

$menus_after = array();
foreach ( wp_get_nav_menus() as $menu ) {
    $menus_after[] = $menu->term_id;
}
$menus_before_ids = array();
foreach ( $menus0 as $menu ) {
    $menus_before_ids[] = (int) $menu->term_id;
}
fact( 'menus_before', $menus_before_ids );
fact( 'menus_after', $menus_after );
fact( 'locations_after_cleanup', get_nav_menu_locations() );
fact( 'locations_original', $locations0 );
fact( 'leftover_probe_menus', count( preg_grep( '/AS Nav Probe ' . preg_quote( $token, '/' ) . '/', array_map( function ( $m ) { return $m->name; }, wp_get_nav_menus() ) ) ) );
fact( 'notes', $failures );

file_put_contents( $report, wp_json_encode( $facts ) );
echo "ok\n";