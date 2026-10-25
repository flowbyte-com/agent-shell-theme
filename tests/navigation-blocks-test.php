<?php
/**
 * Navigation blocks — selection, resolution, rendering, compatibility.
 *
 * What this suite defends
 * -----------------------
 * 1. **Legacy parity.** A `wp_core` block with id `nav_menu` and no navigation
 *    fields is still the primary-location menu, rendered by the original code,
 *    including its "No menu assigned to Primary Location" message.
 * 2. **Selection is explicit.** A block names a menu (slug, unique name, or
 *    numeric id) or a registered location. Nothing else. A missing menu, an
 *    unregistered location, an unassigned location or two menus with the same
 *    name produces a diagnostic — never a different menu.
 * 3. **No silent substitution.** `wp_nav_menu()`'s "first menu that has items"
 *    fallback and its `wp_page_menu()` fallback are both disabled by the
 *    renderer; the stub keeps both rules so removing the defence fails here.
 * 4. **Independent menus, unique ids.** Different menus render side by side,
 *    and the same menu twice is deduplicated by WordPress's own id rule.
 * 5. **One validation authority.** The configurator, the MCP tool and the
 *    doctor accept and reject the same blocks, because they call the same
 *    helper.
 *
 * The WordPress menu functions are stubbed by tests/agentshell-nav-stubs.php,
 * which models the container/id/fallback rules from nav-menu-template.php. The
 * live suite (tests/navigation-blocks-live-test.php) asserts the same
 * properties against a real WordPress.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-nav-stubs.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-update-zone-slots.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-doctor.php';

echo "navigation block tests\n";

/** A site with four usable menus and one empty one. */
function nav_site( array $overrides = array() ) {
    $menus = array(
        array( 'term_id' => 11, 'slug' => 'primary',   'name' => 'Main Menu', 'items' => array( 'Home', 'About', 'Contact' ) ),
        array( 'term_id' => 12, 'slug' => 'utility',   'name' => 'Utility',   'items' => array( 'Log in', 'Register' ) ),
        array( 'term_id' => 13, 'slug' => 'secondary', 'name' => 'Secondary', 'items' => array( 'Docs', 'Blog' ) ),
        array( 'term_id' => 14, 'slug' => 'legal',     'name' => 'Legal',     'items' => array( 'Privacy', 'Terms' ) ),
        array( 'term_id' => 15, 'slug' => 'empty',     'name' => 'Empty Menu', 'items' => array() ),
        array( 'term_id' => 16, 'slug' => 'dup-a',     'name' => 'Duplicate', 'items' => array( 'A' ) ),
        array( 'term_id' => 17, 'slug' => 'dup-b',     'name' => 'Duplicate', 'items' => array( 'B' ) ),
    );
    $registered = array(
        'primary'      => 'Primary',
        'footer-legal' => 'Footer Legal',
        'unassigned'   => 'Unassigned Location',
    );
    $locations = array(
        'primary'      => 11,
        'footer-legal' => 14,
    );

    agentshell_nav_stub_reset(
        $overrides['menus'] ?? $menus,
        $overrides['registered'] ?? $registered,
        $overrides['locations'] ?? $locations
    );
}

/** A navigation block with sensible defaults. */
function nav_block( array $overrides = array() ) {
    return array_merge(
        array( 'type' => 'wp_core', 'id' => 'nav_menu' ),
        $overrides
    );
}

// ── 1. resolution ───────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a menu reference resolves by slug',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'menu' => 'utility' ) ) );
        Agentshell_Test::assert_equals( true, $r['ok'] );
        Agentshell_Test::assert_equals( 12, $r['menu_id'] );
        Agentshell_Test::assert_equals( 'Utility', $r['label'], 'the accessible name defaults to the menu name' );
    }
);

Agentshell_Test::run(
    'a menu reference resolves by a name that is unique',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'menu' => 'Secondary' ) ) );
        Agentshell_Test::assert_equals( true, $r['ok'] );
        Agentshell_Test::assert_equals( 13, $r['menu_id'] );
    }
);

Agentshell_Test::run(
    'a numeric menu reference resolves by term id',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'menu' => '14' ) ) );
        Agentshell_Test::assert_equals( true, $r['ok'] );
        Agentshell_Test::assert_equals( 14, $r['menu_id'] );
    }
);

Agentshell_Test::run(
    'a duplicate menu name is a diagnostic, not a coin flip',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'menu' => 'Duplicate' ) ) );
        Agentshell_Test::assert_equals( false, $r['ok'] );
        Agentshell_Test::assert_equals( 'navigation_menu_ambiguous', $r['code'] );
        Agentshell_Test::assert_true( strpos( $r['message'], 'slug' ) !== false, 'the message says what to do' );
    }
);

Agentshell_Test::run(
    'a missing menu is reported with the reference the block asked for',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'menu' => 'legal-old' ) ) );
        Agentshell_Test::assert_equals( false, $r['ok'] );
        Agentshell_Test::assert_equals( 'navigation_menu_missing', $r['code'] );
        Agentshell_Test::assert_true( strpos( $r['message'], 'legal-old' ) !== false );
        Agentshell_Test::assert_equals( 'legal-old', $r['reference'] );
    }
);

Agentshell_Test::run(
    'a registered location resolves through the assignment map',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'location' => 'footer-legal' ) ) );
        Agentshell_Test::assert_equals( true, $r['ok'] );
        Agentshell_Test::assert_equals( 14, $r['menu_id'] );
        Agentshell_Test::assert_equals( 'Footer Legal', $r['label'], 'the accessible name defaults to the location label' );
    }
);

Agentshell_Test::run(
    'an unregistered location is a diagnostic',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'location' => 'sidebar' ) ) );
        Agentshell_Test::assert_equals( false, $r['ok'] );
        Agentshell_Test::assert_equals( 'navigation_location_unregistered', $r['code'] );
    }
);

Agentshell_Test::run(
    'a registered location with no menu assigned is a diagnostic',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block( array( 'location' => 'unassigned' ) ) );
        Agentshell_Test::assert_equals( false, $r['ok'] );
        Agentshell_Test::assert_equals( 'navigation_location_unassigned', $r['code'] );
        Agentshell_Test::assert_true( strpos( $r['message'], 'Appearance' ) !== false, 'the message says where to fix it' );
    }
);

Agentshell_Test::run(
    'no navigation fields means the historical primary location',
    function () {
        nav_site();
        $r = agentshell_resolve_nav_block( nav_block() );
        Agentshell_Test::assert_equals( true, $r['ok'] );
        Agentshell_Test::assert_equals( 'primary', $r['location'] );
        Agentshell_Test::assert_equals( 0, $r['menu_id'], 'the legacy path does not pre-resolve a menu' );
    }
);

// ── 2. rendering ────────────────────────────────────────────────────────────
Agentshell_Test::run(
    'the historical block renders the primary menu with the historical markup',
    function () {
        nav_site();
        $html = agentshell_render_nav_block( nav_block() );

        Agentshell_Test::assert_true( strpos( $html, '<nav class="wp-core-nav" aria-label="Primary">' ) === 0 );
        Agentshell_Test::assert_true( strpos( $html, 'Home' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'wp-core-nav--' ) === false, 'no mode class on the legacy path' );
        Agentshell_Test::assert_true( strpos( $html, 'menu-primary' ) !== false );
    }
);

Agentshell_Test::run(
    'the historical block keeps WordPress\'s own fallback when primary is unassigned',
    function () {
        // WordPress's `fallback_cb` default is wp_page_menu(), and it runs when
        // no menu is assigned to the location. The historical path does not opt
        // out of that, and this PR does not change it: an existing site whose
        // primary location is empty keeps whatever it renders today.
        nav_site( array( 'locations' => array() ) );
        $html = agentshell_render_nav_block( nav_block() );
        Agentshell_Test::assert_true( strpos( $html, 'agentshell-test-page-menu' ) !== false, 'the platform fallback ran' );
        Agentshell_Test::assert_true( strpos( $html, 'wp-core-nav--' ) === false, 'still no mode class' );
    }
);

Agentshell_Test::run(
    'the historical block reports an empty primary menu rather than a page list',
    function () {
        // A menu exists and is assigned but has no items. `wp_nav_menu()` does
        // not fall back here when a fallback_cb is absent? It does — unless the
        // theme_location is set, which it is. So: no fallback, an empty list,
        // and no substitution either way.
        nav_site( array( 'menus' => array(
            array( 'term_id' => 11, 'slug' => 'primary', 'name' => 'Main Menu', 'items' => array() ),
        ) ) );
        $html = agentshell_render_nav_block( nav_block() );
        Agentshell_Test::assert_false( strpos( $html, 'agentshell-test-page-menu' ) !== false, 'no page-list substitution' );
        Agentshell_Test::assert_false( strpos( $html, 'Home' ) !== false, 'no other menu' );
    }
);

Agentshell_Test::run(
    'an extended block renders its own menu in its own mode',
    function () {
        nav_site();
        $html = agentshell_render_nav_block( nav_block( array( 'menu' => 'utility', 'mode' => 'vertical', 'label' => 'Account' ) ) );

        Agentshell_Test::assert_true( strpos( $html, 'class="wp-core-nav wp-core-nav--vertical"' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'aria-label="Account"' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'Log in' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'Home' ) === false, 'it must not render the primary menu' );
    }
);

Agentshell_Test::run(
    'two different menus render side by side',
    function () {
        nav_site();
        $a = agentshell_render_nav_block( nav_block( array( 'menu' => 'secondary', 'label' => 'Docs' ) ) );
        $b = agentshell_render_nav_block( nav_block( array( 'menu' => 'legal', 'label' => 'Legal' ) ) );

        Agentshell_Test::assert_true( strpos( $a, 'Blog' ) !== false );
        Agentshell_Test::assert_true( strpos( $b, 'Privacy' ) !== false );
        Agentshell_Test::assert_true( strpos( $a, 'Privacy' ) === false );
        Agentshell_Test::assert_true( strpos( $b, 'Blog' ) === false );
    }
);

Agentshell_Test::run(
    'the same menu twice gets two different DOM ids',
    function () {
        nav_site();
        $first  = agentshell_render_nav_block( nav_block( array( 'menu' => 'legal' ) ) );
        $second = agentshell_render_nav_block( nav_block( array( 'menu' => 'legal' ) ) );

        preg_match( '/<ul id="([^"]+)"/', $first, $m1 );
        preg_match( '/<ul id="([^"]+)"/', $second, $m2 );

        Agentshell_Test::assert_true( ! empty( $m1[1] ) && ! empty( $m2[1] ) );
        Agentshell_Test::assert_true(
            $m1[1] !== $m2[1],
            'WordPress suffixes the repeated id: ' . ( $m1[1] ?? '?' ) . ' vs ' . ( $m2[1] ?? '?' )
        );
    }
);

Agentshell_Test::run(
    'the renderer disables both WordPress fallbacks',
    function () {
        nav_site();
        agentshell_render_nav_block( nav_block( array( 'menu' => 'legal' ) ) );
        $calls = agentshell_nav_stub_registry()['calls'];
        Agentshell_Test::assert_equals( 1, count( $calls ) );
        Agentshell_Test::assert_equals( false, $calls[0]['fallback'], 'fallback_cb must be false' );
        Agentshell_Test::assert_equals( 14, $calls[0]['menu'], 'the block renders the menu it named' );
    }
);

// ── 3. diagnostics ──────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a missing menu renders nothing for visitors',
    function () {
        nav_site();
        wp_set_current_user( 0 );
        $html = agentshell_render_nav_block( nav_block( array( 'menu' => 'gone' ) ) );
        Agentshell_Test::assert_equals( '', $html, 'a visitor must not be shown an empty or substituted menu' );
    }
);

Agentshell_Test::run(
    'a missing menu tells an editor what is wrong',
    function () {
        nav_site();
        agentshell_test_reset();
        wp_set_current_user( 7 );
        $GLOBALS['_agentshell_test_user_caps']['edit_theme_options'] = true;

        $html = agentshell_render_nav_block( nav_block( array( 'menu' => 'gone' ) ) );
        Agentshell_Test::assert_true( strpos( $html, 'data-nav-diagnostic="navigation_menu_missing"' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'gone' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'Shell Config' ) !== false, 'the notice says where to fix it' );
    }
);

Agentshell_Test::run(
    'a missing menu leaves the block configuration untouched',
    function () {
        nav_site();
        $block = nav_block( array( 'menu' => 'gone', 'mode' => 'compact', 'label' => 'Legal' ) );
        $before = $block;
        agentshell_render_nav_block( $block );
        Agentshell_Test::assert_equals( $before, $block, 'rendering must never rewrite the block' );
    }
);

Agentshell_Test::run(
    'an empty menu is diagnosed for editors and silent for visitors',
    function () {
        nav_site();
        wp_set_current_user( 0 );
        Agentshell_Test::assert_equals( '', agentshell_render_nav_block( nav_block( array( 'menu' => 'empty' ) ) ) );

        agentshell_test_reset();
        wp_set_current_user( 7 );
        $GLOBALS['_agentshell_test_user_caps']['edit_theme_options'] = true;
        $html = agentshell_render_nav_block( nav_block( array( 'menu' => 'empty' ) ) );
        Agentshell_Test::assert_true( strpos( $html, 'navigation_menu_empty' ) !== false );
    }
);

// ── 4. validation ───────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a well-formed navigation block has no issues',
    function () {
        Agentshell_Test::assert_equals( array(), agentshell_nav_block_issues( nav_block( array( 'menu' => 'legal', 'mode' => 'vertical', 'label' => 'Legal' ) ) ) );
        Agentshell_Test::assert_equals( array(), agentshell_nav_block_issues( nav_block() ) );
    }
);

Agentshell_Test::run(
    'menu and location together are refused as ambiguous',
    function () {
        $issues = agentshell_nav_block_issues( nav_block( array( 'menu' => 'legal', 'location' => 'footer-legal' ) ) );
        Agentshell_Test::assert_equals( 'navigation_selection_ambiguous', $issues[0]['code'] );
    }
);

Agentshell_Test::run(
    'an unknown mode is refused',
    function () {
        $issues = agentshell_nav_block_issues( nav_block( array( 'mode' => 'sidebar' ) ) );
        Agentshell_Test::assert_equals( 'navigation_mode_invalid', $issues[0]['code'] );
        Agentshell_Test::assert_true( strpos( $issues[0]['message'], 'horizontal' ) !== false );
    }
);

Agentshell_Test::run(
    'navigation fields on a non-navigation component are refused',
    function () {
        $issues = agentshell_nav_block_issues( array( 'type' => 'wp_core', 'id' => 'site_logo', 'menu' => 'legal' ) );
        Agentshell_Test::assert_equals( 'navigation_fields_on_other_component', $issues[0]['code'] );
    }
);

Agentshell_Test::run(
    'non-string, oversized and control-character fields are refused',
    function () {
        $issues = agentshell_nav_block_issues( nav_block( array( 'menu' => array( 'legal' ) ) ) );
        Agentshell_Test::assert_equals( 'navigation_field_not_string', $issues[0]['code'] );

        $issues = agentshell_nav_block_issues( nav_block( array( 'label' => str_repeat( 'a', 101 ) ) ) );
        Agentshell_Test::assert_equals( 'navigation_field_too_long', $issues[0]['code'] );

        $issues = agentshell_nav_block_issues( nav_block( array( 'menu' => "legal\x07" ) ) );
        Agentshell_Test::assert_equals( 'navigation_field_control_characters', $issues[0]['code'] );
    }
);

Agentshell_Test::run(
    'the config validator refuses the same blocks the helper does',
    function () {
        agentshell_test_reset();
        $bad = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'top-right' => array( nav_block( array( 'menu' => 'a', 'location' => 'b' ) ) ),
                    ),
                ),
            ),
        );
        $result = agentshell_validate_config( $bad );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'navigation_selection_ambiguous', $result->get_error_code() );

        $good = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'top-right'     => array( nav_block( array( 'menu' => 'utility', 'mode' => 'compact' ) ) ),
                        'middle-center' => array( nav_block( array( 'location' => 'primary' ) ) ),
                    ),
                ),
            ),
        );
        Agentshell_Test::assert_true( is_array( agentshell_validate_config( $good ) ) );
    }
);

Agentshell_Test::run(
    'validated navigation fields survive validation unchanged',
    function () {
        agentshell_test_reset();
        $block = nav_block( array( 'menu' => 'legal', 'mode' => 'vertical', 'label' => 'Legal links' ) );
        $config = agentshell_validate_config( array(
            'zones' => array(
                array( 'id' => 'footer', 'slots' => array( 'bottom-right' => array( $block ) ) ),
            ),
        ) );
        Agentshell_Test::assert_equals( $block, $config['zones'][0]['slots']['bottom-right'][0] );
    }
);

// ── 5. escaping ─────────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a hostile label and slug cannot break out of their attributes',
    function () {
        nav_site();
        $html = agentshell_render_nav_block( nav_block( array(
            'menu'  => 'legal',
            'label' => 'Legal" onmouseover="alert(1)',
        ) ) );

        Agentshell_Test::assert_false( strpos( $html, 'onmouseover="alert' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '&quot;' ) !== false, 'the quote is escaped' );
        Agentshell_Test::assert_true( strpos( $html, 'aria-label="Legal&quot; onmouseover=&quot;alert(1)"' ) !== false );
    }
);

Agentshell_Test::run(
    'a menu slug that resolves to nothing cannot inject markup',
    function () {
        nav_site();
        agentshell_test_reset();
        wp_set_current_user( 7 );
        $GLOBALS['_agentshell_test_user_caps']['edit_theme_options'] = true;
        $html = agentshell_render_nav_block( nav_block( array( 'menu' => '<script>x</script>' ) ) );
        Agentshell_Test::assert_false( strpos( $html, '<script>' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '&lt;script&gt;' ) !== false );
    }
);

// ── 6. doctor ───────────────────────────────────────────────────────────────
Agentshell_Test::run(
    'the doctor warns about a missing menu without blocking the config',
    function () {
        nav_site();
        $issues = \AgentShell_MCP\Doctor::check( array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array( 'top-right' => array( nav_block( array( 'menu' => 'gone' ) ) ) ),
                ),
            ),
        ) );
        $codes = array_column( $issues['warnings'], 'code' );
        Agentshell_Test::assert_true( in_array( 'NAVIGATION_MENU_MISSING', $codes, true ), 'the dependency is reported' );
        $nav_errors = array_filter(
            array_column( $issues['errors'], 'code' ),
            function ( $code ) {
                return 0 === strpos( $code, 'NAVIGATION_' );
            }
        );
        Agentshell_Test::assert_equals( array(), array_values( $nav_errors ), 'a missing menu is not a config error' );
    }
);

Agentshell_Test::run(
    'the doctor rejects an ambiguous selection as a config error',
    function () {
        nav_site();
        $issues = \AgentShell_MCP\Doctor::check( array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array( 'top-right' => array( nav_block( array( 'menu' => 'legal', 'location' => 'primary' ) ) ) ),
                ),
            ),
        ) );
        Agentshell_Test::assert_true( in_array( 'NAVIGATION_SELECTION_AMBIGUOUS', array_column( $issues['errors'], 'code' ), true ) );
    }
);

// ── 7. configurator data ────────────────────────────────────────────────────
Agentshell_Test::run(
    'the config response lists the menus and locations an operator can choose',
    function () {
        nav_site();
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );

        $response = agentshell_build_config_get_response();
        Agentshell_Test::assert_equals( 7, count( $response['available_menus'] ) );
        Agentshell_Test::assert_equals( 'legal', $response['available_menus'][3]['slug'] );
        Agentshell_Test::assert_equals( 2, $response['available_menus'][3]['count'] );
        Agentshell_Test::assert_equals( 3, count( $response['available_menu_locations'] ) );

        $by_slug = array();
        foreach ( $response['available_menu_locations'] as $location ) {
            $by_slug[ $location['slug'] ] = $location;
        }
        Agentshell_Test::assert_equals( 14, $by_slug['footer-legal']['menu_id'] );
        Agentshell_Test::assert_equals( 0, $by_slug['unassigned']['menu_id'] );
    }
);

Agentshell_Test::run(
    'the config response names the navigation dependencies of the configuration',
    function () {
        nav_site();
        agentshell_test_reset();
        $config = agentshell_validate_config( array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'top-right'     => array( nav_block( array( 'menu' => 'utility' ) ) ),
                        'middle-center' => array( nav_block( array( 'location' => 'primary' ) ) ),
                        'bottom-center' => array( nav_block( array( 'menu' => 'secondary' ) ) ),
                        'middle-right'  => array( nav_block( array( 'menu' => 'utility' ) ) ),
                    ),
                ),
            ),
        ) );
        update_option( 'agentshell_config', $config );

        $response = agentshell_build_config_get_response();
        $deps = $response['navigation_dependencies'];
        // Three distinct references from four blocks: 'utility' is placed
        // twice and must be listed once.
        Agentshell_Test::assert_equals( 3, count( $deps['menus'] ) );
        $refs = array_map(
            function ( $entry ) {
                return $entry['kind'] . ':' . $entry['reference'];
            },
            $deps['menus']
        );
        sort( $refs );
        Agentshell_Test::assert_equals( array( 'location:primary', 'menu:secondary', 'menu:utility' ), $refs );
        Agentshell_Test::assert_equals( array( 'primary' ), $deps['locations'] );
    }
);

// ── 8. MCP ↔ configurator equivalence ───────────────────────────────────────
class Nav_Test_Update_Zone_Slots extends \AgentShell_MCP\Tools\Update_Zone_Slots {
    public $stored = null;
    protected function update_agentshell_config( array $config ) {
        $this->stored = $config;
        return true;
    }
}

Agentshell_Test::run(
    'the MCP tool and the configurator store the same navigation block',
    function () {
        nav_site();
        $block = nav_block( array( 'menu' => 'legal', 'mode' => 'compact', 'label' => 'Legal' ) );

        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'footer' ) ) ) );
        $via_rest = agentshell_validate_config( agentshell_unflatten_config(
            array( 'zones' => array( array( 'id' => 'footer', 'slots' => array( 'bottom-right' => array( $block ) ) ) ) ),
            agentshell_get_config()
        ) );

        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'footer' ) ) ) );
        $tool = new Nav_Test_Update_Zone_Slots();
        $tool->execute( array( 'zone_id' => 'footer', 'slots' => array( 'bottom-right' => array( $block ) ) ) );

        Agentshell_Test::assert_equals( $via_rest['zones'], $tool->stored['zones'] );
    }
);

Agentshell_Test::run(
    'the MCP tool refuses a navigation block the doctor would reject',
    function () {
        nav_site();
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool = new Nav_Test_Update_Zone_Slots();
        $threw = false;
        try {
            $tool->execute( array(
                'zone_id' => 'header',
                'slots'   => array( 'top-right' => array( nav_block( array( 'menu' => 'legal', 'location' => 'primary' ) ) ) ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
            Agentshell_Test::assert_true( strpos( $e->getMessage(), 'not both' ) !== false );
        }
        Agentshell_Test::assert_true( $threw, 'an ambiguous selection must be refused before it is stored' );
        Agentshell_Test::assert_equals( null, $tool->stored );
    }
);

// ── 9. inside a zone ────────────────────────────────────────────────────────
Agentshell_Test::run(
    'navigation blocks render in any of the nine areas',
    function () {
        nav_site();
        $zone = array(
            'id'    => 'header',
            'slots' => array(
                'top-right'     => array( nav_block( array( 'menu' => 'utility', 'label' => 'Utility' ) ) ),
                'middle-center' => array( nav_block( array( 'location' => 'primary' ) ) ),
                'bottom-center' => array( nav_block( array( 'menu' => 'secondary', 'label' => 'Secondary', 'mode' => 'vertical' ) ) ),
            ),
        );

        $html = agentshell_render_zone( $zone );

        Agentshell_Test::assert_true( strpos( $html, 'data-slot="top-right"' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'aria-label="Utility"' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'aria-label="Primary"' ) !== false, 'a location block takes the location label' );
        Agentshell_Test::assert_true( strpos( $html, 'wp-core-nav--vertical' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'Log in' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'Docs' ) !== false );
    }
);

Agentshell_Test::run(
    'a legacy zone with a plain nav_menu block renders exactly as before',
    function () {
        nav_site();
        $html = agentshell_render_zone( array(
            'id'    => 'footer',
            'slots' => array( 'center' => array( nav_block() ) ),
        ) );

        Agentshell_Test::assert_true( strpos( $html, '<div class="zone-slot slot-center">' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '<nav class="wp-core-nav" aria-label="Primary">' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'wp-core-nav--' ) === false );
    }
);
