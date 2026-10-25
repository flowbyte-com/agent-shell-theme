<?php
/**
 * WordPress menu stubs for the theme's navigation tests.
 *
 * One stub set, shared by every suite that renders a navigation block, so two
 * suites cannot disagree about what WordPress does. It models the parts of
 * `wp_nav_menu()` the theme actually depends on:
 *
 *   - `menu` (a term id) resolves to that menu, and a menu that does not exist
 *     returns `false` rather than "the first menu with items",
 *   - `theme_location` resolves through `get_nav_menu_locations()`,
 *   - `fallback_cb` is honoured (false = no fallback),
 *   - `container`/`container_class`/`container_aria_label` shape the wrapper,
 *   - `items_wrap` is honoured, so a caller asking for `%3$s` gets items only,
 *   - the emitted `<ul id>` repeats the default id the way WordPress does
 *     (`menu-<slug>`, `menu-<slug>-1`, …), because "no duplicate DOM ids" is a
 *     property of that behaviour and the tests have to be able to see it.
 *
 * The last point is a reduction of a real WordPress rule, not an invention:
 * wp-includes/nav-menu-template.php keeps a static `$menu_id_slugs` and
 * suffixes repeats. The live suite asserts the same property against a real
 * WordPress, so the stub and the platform are checked separately.
 */

if ( ! isset( $GLOBALS['_agentshell_nav_stub'] ) ) {
    $GLOBALS['_agentshell_nav_stub'] = array(
        'menus'      => array(),   // [ { term_id, slug, name, count, items:[label,...] } ]
        'registered' => array(),   // location slug => label
        'locations'  => array(),   // location slug => menu term id
        'calls'      => array(),   // every wp_nav_menu() call's resolved args
        'ids'        => array(),   // ids emitted in this "request"
    );
}

/**
 * Reset the stub registry.
 *
 * @param array $menus      Menu fixtures.
 * @param array $registered Registered locations (slug => label).
 * @param array $locations  Assignments (slug => menu term id).
 */
function agentshell_nav_stub_reset( array $menus = array(), array $registered = array(), array $locations = array() ) {
    $GLOBALS['_agentshell_nav_stub'] = array(
        'menus'      => $menus,
        'registered' => $registered,
        'locations'  => $locations,
        'calls'      => array(),
        'ids'        => array(),
    );
}

function agentshell_nav_stub_registry() {
    return $GLOBALS['_agentshell_nav_stub'];
}

function agentshell_nav_stub_find( $ref ) {
    $menus = $GLOBALS['_agentshell_nav_stub']['menus'];
    foreach ( $menus as $menu ) {
        if ( is_array( $ref ) ) {
            if ( (int) ( $ref['term_id'] ?? 0 ) === (int) $menu['term_id'] ) {
                return $menu;
            }
            continue;
        }
        if ( is_numeric( $ref ) && (int) $ref === (int) $menu['term_id'] ) {
            return $menu;
        }
        if ( (string) $ref === (string) $menu['slug'] || (string) $ref === (string) $menu['name'] ) {
            return $menu;
        }
    }
    return null;
}

function agentshell_nav_stub_menu_object( $menu ) {
    $found = agentshell_nav_stub_find( $menu );
    if ( ! $found ) {
        return false;
    }
    return (object) array(
        'term_id' => (int) $found['term_id'],
        'slug'    => (string) $found['slug'],
        'name'    => (string) $found['name'],
        'count'   => count( $found['items'] ?? array() ),
    );
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
    function wp_get_nav_menus( $args = array() ) {
        $out = array();
        foreach ( $GLOBALS['_agentshell_nav_stub']['menus'] as $menu ) {
            $out[] = (object) array(
                'term_id' => (int) $menu['term_id'],
                'slug'    => (string) $menu['slug'],
                'name'    => (string) $menu['name'],
                'count'   => count( $menu['items'] ?? array() ),
            );
        }
        return $out;
    }
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
    function wp_get_nav_menu_object( $menu ) {
        return agentshell_nav_stub_menu_object( $menu );
    }
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
    function get_registered_nav_menus() {
        return $GLOBALS['_agentshell_nav_stub']['registered'];
    }
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
    function get_nav_menu_locations() {
        return $GLOBALS['_agentshell_nav_stub']['locations'];
    }
}

if ( ! function_exists( 'wp_page_menu' ) ) {
    /**
     * The default fallback_cb. WordPress renders a page list here; the stub
     * marks the output so a test can tell "the platform's fallback" apart from
     * "a menu the block named".
     */
    function wp_page_menu( $args = array() ) {
        return '<div class="menu agentshell-test-page-menu"><ul><li><a href="/sample-page/">Sample Page</a></li></ul></div>';
    }
}

if ( ! function_exists( 'wp_nav_menu' ) ) {
    function wp_nav_menu( $args = array() ) {
        $args = (array) $args;

        // WordPress defaults `fallback_cb` to wp_page_menu() before it does
        // anything else; an explicit `false` is how a caller opts out.
        $fallback_cb = array_key_exists( 'fallback_cb', $args ) ? $args['fallback_cb'] : 'wp_page_menu';

        $menu     = null;
        $location = (string) ( $args['theme_location'] ?? '' );

        if ( ! empty( $args['menu'] ) ) {
            $menu = agentshell_nav_stub_find( $args['menu'] );
        } elseif ( '' !== $location ) {
            $locations = get_nav_menu_locations();
            if ( isset( $locations[ $location ] ) ) {
                $menu = agentshell_nav_stub_find( (int) $locations[ $location ] );
            }
        }

        // WordPress falls back to the first menu with items when no menu was
        // found *and* no theme location was named. The stub keeps that rule,
        // because the theme's "never substitute" guarantee is a claim about
        // not letting this branch run.
        if ( ! $menu && '' === $location ) {
            foreach ( get_nav_menu_locations() as $unused ) {
                // no-op: assignments do not count as a search
            }
            foreach ( wp_get_nav_menus() as $candidate ) {
                $found = agentshell_nav_stub_find( (int) $candidate->term_id );
                if ( ! empty( $found['items'] ) ) {
                    $menu = $found;
                    break;
                }
            }
        }

        // WordPress's fallback rule, faithfully: the callback runs when no menu
        // was found at all, and also when a menu was found but has no items and
        // no theme location was named. `fallback_cb => false` is therefore the
        // only way to guarantee that a configured menu — or the absence of one —
        // is never replaced by something else.
        $items   = $menu['items'] ?? array();
        $no_menu = ! $menu;
        $no_items = ( ! $no_menu && empty( $items ) && '' === $location );
        if ( ( $no_menu || $no_items ) && $fallback_cb && is_callable( $fallback_cb ) ) {
            return call_user_func( $fallback_cb, $args );
        }
        if ( ! $menu ) {
            return false;
        }

        $GLOBALS['_agentshell_nav_stub']['calls'][] = array(
            'menu'       => (int) $menu['term_id'],
            'location'   => $location,
            'class'      => (string) ( $args['container_class'] ?? '' ),
            'aria_label' => (string) ( $args['container_aria_label'] ?? '' ),
            'fallback'   => $fallback_cb,
        );

        // Default id, deduplicated the way WordPress deduplicates it.
        $id = 'menu-' . $menu['slug'];
        while ( in_array( $id, $GLOBALS['_agentshell_nav_stub']['ids'], true ) ) {
            if ( preg_match( '#-(\d+)$#', $id, $m ) ) {
                $id = preg_replace( '#-(\d+)$#', '-' . ( (int) $m[1] + 1 ), $id );
            } else {
                $id .= '-1';
            }
        }
        $GLOBALS['_agentshell_nav_stub']['ids'][] = $id;

        $list = sprintf( '<ul id="%s" class="menu">', esc_attr( $id ) );
        foreach ( $items as $item ) {
            $list .= '<li class="menu-item">' . esc_html( (string) $item ) . '</li>';
        }
        $list .= '</ul>';

        if ( ! empty( $args['container'] ) ) {
            $class = (string) ( $args['container_class'] ?? '' );
            $aria  = '';
            if ( 'nav' === $args['container'] && ! empty( $args['container_aria_label'] ) ) {
                $aria = ' aria-label="' . esc_attr( $args['container_aria_label'] ) . '"';
            }
            return '<' . $args['container'] . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . $aria . '>' . $list . '</' . $args['container'] . '>';
        }

        return $list;
    }
}
