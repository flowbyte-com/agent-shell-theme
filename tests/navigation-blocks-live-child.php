<?php
/**
 * Child half of the live navigation-block suite. Runs inside a real WordPress
 * and reports facts as JSON; the parent asserts them.
 *
 * What only a real WordPress can prove
 * ------------------------------------
 *   - `wp_nav_menu()` with a menu term actually emits that menu's items, at
 *     every level, with WordPress's own `menu-item-has-children` / `sub-menu`
 *     markup — the shape the theme's CSS and script depend on.
 *   - Placing the same menu twice gives two different `<ul id>`s, because
 *     WordPress deduplicates them (`menu-<slug>`, `menu-<slug>-1`). That is a
 *     platform rule; a stub could assert it, but only WordPress can confirm it.
 *   - A missing menu renders nothing for a visitor and a diagnostic for an
 *     editor, and never falls back to another menu.
 *   - A location that is registered but unassigned is diagnosed, not guessed.
 *
 * Everything it creates is named with a per-run token and deleted before it
 * exits; the nav_menu_locations theme mod is restored.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
require_once $theme_root . '/functions.php';
require_once $theme_root . '/template-parts/shell-render.php';

$token  = 'ASNavB' . substr( md5( (string) microtime( true ) . getmypid() ), 0, 8 );
$facts  = array();
$errors = array();

function nb_fact( $key, $value ) {
    $GLOBALS['facts'][ $key ] = $value;
}

/** Build a menu with a nested branch and return its term id. */
function nb_make_menu( $name, array $tree ) {
    $menu_id = wp_create_nav_menu( $name );
    if ( is_wp_error( $menu_id ) ) {
        throw new RuntimeException( 'create menu: ' . $menu_id->get_error_message() );
    }
    $by_key = array();
    foreach ( $tree as $node ) {
        $args = array(
            'menu-item-title'  => $node['title'],
            'menu-item-status' => 'publish',
            'menu-item-url'    => $node['url'] ?? '#',
        );
        if ( ! empty( $node['parent'] ) && isset( $by_key[ $node['parent'] ] ) ) {
            $args['menu-item-parent-id'] = $by_key[ $node['parent'] ];
        }
        $item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
        if ( is_wp_error( $item_id ) ) {
            throw new RuntimeException( 'create item: ' . $item_id->get_error_message() );
        }
        $by_key[ $node['key'] ] = $item_id;
    }
    return array( 'term_id' => (int) $menu_id, 'slug' => (string) get_term( $menu_id, 'nav_menu' )->slug );
}

$locations_before = get_theme_mod( 'nav_menu_locations', array() );
$made_menus       = array();

// Cleanup has to survive a fatal, or the fixture menus leak into the next
// run's baseline and a correct compensation looks like a failure.
register_shutdown_function( function () use ( $token, $locations_before ) {
    foreach ( (array) wp_get_nav_menus() as $menu ) {
        if ( false !== strpos( (string) $menu->name, $token ) ) {
            wp_delete_nav_menu( $menu->term_id );
        }
    }
    set_theme_mod( 'nav_menu_locations', is_array( $locations_before ) ? $locations_before : array() );
} );

try {
    // A second registered location, so location-based selection has something
    // other than 'primary' to point at.
    register_nav_menus( array( 'nb-extra' => 'Nav Blocks Extra' ) );

    $menu_a = nb_make_menu(
        'AS Nav Blocks A ' . $token,
        array(
            array( 'key' => 'home', 'title' => 'A Home' ),
            array( 'key' => 'about', 'title' => 'A About' ),
            array( 'key' => 'team', 'title' => 'A Team', 'parent' => 'about' ),
            array( 'key' => 'history', 'title' => 'A History', 'parent' => 'team' ),
            array( 'key' => 'contact', 'title' => 'A Contact' ),
        )
    );
    $made_menus[] = $menu_a['term_id'];

    $menu_b = nb_make_menu(
        'AS Nav Blocks B ' . $token,
        array(
            array( 'key' => 'docs', 'title' => 'B Docs' ),
            array( 'key' => 'legal', 'title' => 'B Legal' ),
            array( 'key' => 'privacy', 'title' => 'B Privacy', 'parent' => 'legal' ),
        )
    );
    $made_menus[] = $menu_b['term_id'];

    $assigned                        = is_array( $locations_before ) ? $locations_before : array();
    $assigned['primary']             = $menu_a['term_id'];
    $assigned['nb-extra']            = $menu_b['term_id'];
    set_theme_mod( 'nav_menu_locations', $assigned );

    nb_fact( 'menu_a', $menu_a );
    nb_fact( 'menu_b', $menu_b );
    nb_fact( 'registered', array_keys( (array) get_registered_nav_menus() ) );

    // ── 1. legacy block, primary assigned ──
    wp_set_current_user( 0 );
    $legacy = agentshell_render_core_component( 'nav_menu', array( 'type' => 'wp_core', 'id' => 'nav_menu' ) );
    nb_fact( 'legacy_html', $legacy );
    nb_fact( 'legacy_has_primary_label', false !== strpos( $legacy, 'aria-label="Primary"' ) );
    nb_fact( 'legacy_has_a_home', false !== strpos( $legacy, 'A Home' ) );
    nb_fact( 'legacy_has_b_docs', false !== strpos( $legacy, 'B Docs' ) );
    nb_fact( 'legacy_has_mode_class', false !== strpos( $legacy, 'wp-core-nav--' ) );
    nb_fact( 'legacy_nested_depth', substr_count( $legacy, '<ul class="sub-menu">' ) );

    // ── 2. named menu B, horizontal ──
    $named = agentshell_render_nav_block( array(
        'type'  => 'wp_core',
        'id'    => 'nav_menu',
        'menu'  => $menu_b['slug'],
        'mode'  => 'horizontal',
        'label' => 'Legal links',
    ) );
    nb_fact( 'named_html', $named );
    nb_fact( 'named_has_b_docs', false !== strpos( $named, 'B Docs' ) );
    nb_fact( 'named_has_a_home', false !== strpos( $named, 'A Home' ) );
    nb_fact( 'named_label', false !== strpos( $named, 'aria-label="Legal links"' ) );
    nb_fact( 'named_mode_class', false !== strpos( $named, 'wp-core-nav--horizontal' ) );
    nb_fact( 'named_nested', false !== strpos( $named, 'menu-item-has-children' ) && false !== strpos( $named, '<ul class="sub-menu">' ) );

    // ── 3. the same menu twice: distinct list ids ──
    $twice  = agentshell_render_nav_block( array( 'type' => 'wp_core', 'id' => 'nav_menu', 'menu' => $menu_a['slug'] ) )
        . agentshell_render_nav_block( array( 'type' => 'wp_core', 'id' => 'nav_menu', 'menu' => $menu_a['slug'] ) );
    preg_match_all( '/<ul id="([^"]+)"/', $twice, $m );
    nb_fact( 'twice_ids', $m[1] );
    nb_fact( 'twice_id_count', count( $m[1] ) );
    nb_fact( 'twice_ids_unique', count( array_unique( $m[1] ) ) === count( $m[1] ) );
    nb_fact( 'twice_occurrences', substr_count( $twice, 'A About' ) );

    // ── 4. location selection ──
    $by_location = agentshell_render_nav_block( array(
        'type'     => 'wp_core',
        'id'       => 'nav_menu',
        'location' => 'nb-extra',
        'mode'     => 'vertical',
    ) );
    nb_fact( 'location_has_b_docs', false !== strpos( $by_location, 'B Docs' ) );
    nb_fact( 'location_mode_class', false !== strpos( $by_location, 'wp-core-nav--vertical' ) );
    nb_fact( 'location_has_extra_label', false !== strpos( $by_location, 'aria-label="Nav Blocks Extra"' ) );

    // ── 5. missing menu ──
    wp_set_current_user( 0 );
    nb_fact( 'missing_visitor', agentshell_render_nav_block( array(
        'type' => 'wp_core',
        'id'   => 'nav_menu',
        'menu' => 'no-such-menu-' . $token,
    ) ) );
    wp_set_current_user( 1 );
    $missing_editor = agentshell_render_nav_block( array(
        'type' => 'wp_core',
        'id'   => 'nav_menu',
        'menu' => 'no-such-menu-' . $token,
    ) );
    nb_fact( 'missing_editor_has_code', false !== strpos( $missing_editor, 'navigation_menu_missing' ) );

    // ── 6. unassigned location ──
    register_nav_menus( array( 'nb-unassigned' => 'Nav Blocks Unassigned' ) );
    $unassigned = agentshell_resolve_nav_block( array(
        'type'     => 'wp_core',
        'id'       => 'nav_menu',
        'location' => 'nb-unassigned',
    ) );
    nb_fact( 'unassigned_code', $unassigned['code'] ?? '' );

    // ── 7. legacy block with nothing assigned to primary ──
    $stripped = $assigned;
    unset( $stripped['primary'] );
    set_theme_mod( 'nav_menu_locations', $stripped );
    wp_set_current_user( 0 );
    nb_fact( 'legacy_unassigned', agentshell_render_core_component( 'nav_menu', array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ) );
} catch ( Throwable $e ) {
    $errors[] = $e->getMessage();
} finally {
    set_theme_mod( 'nav_menu_locations', is_array( $locations_before ) ? $locations_before : array() );
    foreach ( $made_menus as $id ) {
        wp_delete_nav_menu( $id );
    }
    $left = array();
    foreach ( wp_get_nav_menus() as $menu ) {
        if ( false !== strpos( (string) $menu->name, $token ) ) {
            $left[] = (int) $menu->term_id;
        }
    }
    nb_fact( 'leftover_menus', $left );
    nb_fact( 'locations_restored', get_theme_mod( 'nav_menu_locations', array() ) === $locations_before );
    nb_fact( 'errors', $errors );
}

file_put_contents( $report, wp_json_encode( $facts ) );
echo "ok\n";
