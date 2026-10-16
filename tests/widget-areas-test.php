<?php
/**
 * Widget areas registration + doctor warning tests.
 *
 * The three default areas (header-extra, footer-extra, primary-sidebar)
 * are registered through a widgets_init callback. The callback exposes
 * the `agentshell_widget_areas` filter for child themes / plugins and
 * validates every entry before forwarding to register_sidebar. The
 * doctor validator flags genuinely-unregistered ids through the
 * WIDGET_AREA_UNREGISTERED warning.
 *
 * Post-fix invariants under test:
 *   1. The default three areas register when no filter is attached.
 *   2. The filter is honoured: a child theme can add, remove, or
 *      reorder areas. A non-array return falls back to the defaults;
 *      a valid empty array is respected (zero areas register).
 *   3. Defensive validation: each entry must be an array with scalar
 *      id and name; malformed entries are silently skipped. Duplicate
 *      ids keep the first.
 *   4. The doctor: registered-and-empty areas do NOT warn; populated
 *      areas do NOT warn; genuinely-unregistered ids DO warn.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-doctor.php';

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
}

use AgentShell_MCP\Doctor;
use AgentShell_MCP\Store;

echo "widget areas registration + doctor warning tests\n";

/**
 * Invoke every callback registered against the widgets_init action.
 * The test bootstrap's do_action() is a no-op for unknown side-effects,
 * so we walk the registered filter list directly. Filters and actions
 * share the same registry in the bootstrap.
 *
 * After firing, clears the agentshell_widget_areas filter list so the
 * next test starts from a known state — the bootstrap's filter
 * registry is deliberately NOT cleared by agentshell_test_reset()
 * (callbacks are registered once when functions.php is required),
 * so tests that exercise the filter rely on this helper for cleanup.
 * Sidebar state is left to the next test's reset() call.
 */
function agentshell_test_fire_widgets_init() {
    $callbacks = $GLOBALS['_agentshell_test_filters']['widgets_init'] ?? array();
    foreach ( $callbacks as $cb ) {
        if ( is_callable( $cb ) ) {
            call_user_func( $cb );
        }
    }
    $GLOBALS['_agentshell_test_filters']['agentshell_widget_areas'] = array();
}

Agentshell_Test::run(
    'default three areas register when no filter is attached',
    function () {
        agentshell_test_reset();
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_true( is_registered_sidebar( 'header-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'footer-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'primary-sidebar' ) );
    }
);

Agentshell_Test::run(
    'filter can add a fourth area',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function ( $areas ) {
            $areas[] = array( 'id' => 'shop', 'name' => 'Shop' );
            return $areas;
        } );
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_true( is_registered_sidebar( 'header-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'shop' ) );
    }
);

Agentshell_Test::run(
    'filter can remove a default area',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function ( $areas ) {
            return array_values( array_filter( $areas, function ( $a ) {
                return ( $a['id'] ?? '' ) !== 'primary-sidebar';
            } ) );
        } );
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_true( is_registered_sidebar( 'header-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'footer-extra' ) );
        Agentshell_Test::assert_false( is_registered_sidebar( 'primary-sidebar' ) );
    }
);

Agentshell_Test::run(
    'filter returning a non-array falls back to the defaults (no silent disable)',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function () {
            return 'not-an-array'; // typical typo
        } );
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_true( is_registered_sidebar( 'header-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'footer-extra' ) );
        Agentshell_Test::assert_true( is_registered_sidebar( 'primary-sidebar' ) );
    }
);

Agentshell_Test::run(
    'filter returning a valid empty array registers zero areas (intentional)',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function () {
            return array();
        } );
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_false( is_registered_sidebar( 'header-extra' ) );
        Agentshell_Test::assert_false( is_registered_sidebar( 'footer-extra' ) );
        Agentshell_Test::assert_false( is_registered_sidebar( 'primary-sidebar' ) );
    }
);

Agentshell_Test::run(
    'malformed entries are silently skipped; valid ones still register',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function ( $areas ) {
            $areas[] = array( 'name' => 'no-id' );                       // missing id
            $areas[] = array( 'id' => 'no-name' );                       // missing name
            $areas[] = array( 'id' => array( 'nested' ), 'name' => 'X' ); // non-scalar id
            $areas[] = array( 'id' => 'ok', 'name' => 'OK' );            // valid
            return $areas;
        } );
        agentshell_test_fire_widgets_init();
        Agentshell_Test::assert_true( is_registered_sidebar( 'ok' ) );
        Agentshell_Test::assert_false( is_registered_sidebar( 'no-name' ) );
    }
);

Agentshell_Test::run(
    'duplicate ids keep the first; second is skipped silently',
    function () {
        agentshell_test_reset();
        add_filter( 'agentshell_widget_areas', function ( $areas ) {
            $areas[] = array( 'id' => 'header-extra', 'name' => 'Duplicate Header' );
            return $areas;
        } );
        agentshell_test_fire_widgets_init();
        // The default "Header — Extra" wins over the duplicate "Duplicate Header".
        $registered = wp_registered_sidebars();
        Agentshell_Test::assert_equals( 'Header — Extra', $registered['header-extra']['name'] );
    }
);

Agentshell_Test::run(
    'wp_registered_sidebars snapshot reflects the registered set',
    function () {
        agentshell_test_reset();
        agentshell_test_fire_widgets_init();
        $registered = wp_registered_sidebars();
        Agentshell_Test::assert_true( is_array( $registered ) );
        Agentshell_Test::assert_true( isset( $registered['header-extra'] ) );
        Agentshell_Test::assert_true( isset( $registered['footer-extra'] ) );
        Agentshell_Test::assert_true( isset( $registered['primary-sidebar'] ) );
    }
);

Agentshell_Test::run(
    'doctor: registered-and-empty wp_widget_area block does NOT warn',
    function () {
        agentshell_test_reset();
        agentshell_test_fire_widgets_init();
        // The default area is registered but has no widgets assigned.
        $config = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'left'   => array( array( 'type' => 'wp_widget_area', 'id' => 'header-extra' ) ),
                        'center' => array(),
                        'right'  => array(),
                    ),
                ),
            ),
        );
        $out = Doctor::check( $config );
        $codes = array_column( $out['warnings'], 'code' );
        Agentshell_Test::assert_false(
            in_array( 'WIDGET_AREA_UNREGISTERED', $codes, true ),
            'registered-and-empty widget area must not warn; is_registered_sidebar() is the right primitive'
        );
    }
);

Agentshell_Test::run(
    'doctor: registered-and-populated wp_widget_area block does NOT warn',
    function () {
        agentshell_test_reset();
        agentshell_test_fire_widgets_init();
        // Pretend the area has a widget assigned.
        $GLOBALS['_agentshell_test_sidebar_widgets']['primary-sidebar'] = array( 'recent-posts' => true );
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_widget_area', 'id' => 'primary-sidebar' ) ),
                ),
            ),
        );
        $out = Doctor::check( $config );
        $codes = array_column( $out['warnings'], 'code' );
        Agentshell_Test::assert_false(
            in_array( 'WIDGET_AREA_UNREGISTERED', $codes, true ),
            'populated widget area must not warn'
        );
    }
);

Agentshell_Test::run(
    'doctor: genuinely-unregistered wp_widget_area block DOES warn',
    function () {
        agentshell_test_reset();
        agentshell_test_fire_widgets_init();
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_widget_area', 'id' => 'shop-sidebar' ) ),
                ),
            ),
        );
        $out = Doctor::check( $config );
        $codes = array_column( $out['warnings'], 'code' );
        Agentshell_Test::assert_true(
            in_array( 'WIDGET_AREA_UNREGISTERED', $codes, true ),
            'a wp_widget_area block pointing at an unregistered id must warn'
        );
    }
);

Agentshell_Test::run(
    'doctor: wp_widget_area with empty id does not warn (defensive)',
    function () {
        agentshell_test_reset();
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_widget_area', 'id' => '' ) ),
                ),
            ),
        );
        $out = Doctor::check( $config );
        $codes = array_column( $out['warnings'], 'code' );
        Agentshell_Test::assert_false(
            in_array( 'WIDGET_AREA_UNREGISTERED', $codes, true ),
            'an empty wp_widget_area id is a no-op, not an unregistered area'
        );
    }
);
