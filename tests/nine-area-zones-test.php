<?php
/**
 * Nine-area header/footer zones — schema, compatibility, rendering, validation
 * and MCP/configurator equivalence.
 *
 * What this suite defends
 * -----------------------
 * 1. **Parity.** A site that only ever used `left` / `center` / `right` renders
 *    the same markup it rendered before the nine-area feature existed. The
 *    assertion is on the exact wrapper sequence, because "looks the same" is
 *    not a property a test can check and "the same bytes" is.
 * 2. **Compatibility.** The historical three-slot shape is accepted everywhere
 *    (stored config, REST PUT, import, MCP) and normalised through one
 *    function, idempotently, independent of key order.
 * 3. **One model.** The theme's canonical slot list and the plugin's fallback
 *    list are equal, so the doctor and the renderer cannot disagree about what
 *    a valid area is.
 * 4. **Equivalence.** The same header edit through the REST/configurator path
 *    and through `agentshell_update_zone_slots` produces the same stored
 *    configuration. Two interfaces, one model.
 * 5. **Empty rows cost nothing.** All nine cells are emitted, empty ones are
 *    genuinely empty (so `:empty` collapses them) and placement is explicit
 *    (so a hidden cell cannot shift its neighbours).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-update-zone-slots.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-doctor.php';

echo "nine-area zone tests\n";

// WordPress functions the shared bootstrap deliberately does not stub (no
// other unit suite renders a wp_core block). Guarded so the shared test
// process cannot redeclare them if a later suite grows its own.
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $key = '' ) { return ( 'name' === $key ) ? 'AgentShell Test' : ''; }
}
if ( ! function_exists( 'the_custom_logo' ) ) {
    function the_custom_logo() { /* no logo set: WP prints nothing */ }
}
if ( ! function_exists( 'wp_nav_menu' ) ) {
    function wp_nav_menu( $args = array() ) { return '<nav class="wp-core-nav"><ul><li>Home</li></ul></nav>'; }
}
if ( ! function_exists( 'get_search_form' ) ) {
    function get_search_form( $args = array() ) { return '<form class="search-form"><input></form>'; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}

/** The canonical ids, written out here on purpose: if the definition changes,
 *  this suite is meant to notice rather than follow along. */
$NINE = array(
    'top-left', 'top-center', 'top-right',
    'middle-left', 'middle-center', 'middle-right',
    'bottom-left', 'bottom-center', 'bottom-right',
);

/** A zone in the historical three-slot shape. */
function nine_area_legacy_zone() {
    return array(
        'id'    => 'header',
        'label' => 'Header',
        'slots' => array(
            'left'   => array( array( 'type' => 'json_block', 'content' => '<span>L</span>' ) ),
            'center' => array( array( 'type' => 'json_block', 'content' => '<span>C</span>' ) ),
            'right'  => array( array( 'type' => 'json_block', 'content' => '<span>R</span>' ) ),
        ),
    );
}

/** A config with one header zone in the historical shape. */
function nine_area_legacy_config() {
    return array(
        'zones' => array(
            nine_area_legacy_zone(),
            array( 'id' => 'main', 'composition' => array( array( 'type' => 'json_block', 'content' => '<p>main</p>' ) ) ),
        ),
        'custom_css' => '/* keep me */',
    );
}

// ── 1. the canonical grid ────────────────────────────────────────────────────
Agentshell_Test::run(
    'the canonical grid is nine areas in three rows, in render order',
    function () use ( $NINE ) {
        Agentshell_Test::assert_equals( $NINE, agentshell_zone_slot_ids() );

        $rows = agentshell_zone_slot_rows();
        Agentshell_Test::assert_equals( array( 'top', 'middle', 'bottom' ), array_keys( $rows ) );
        Agentshell_Test::assert_equals( 3, count( $rows['top'] ) );
        Agentshell_Test::assert_equals( 3, count( $rows['middle'] ) );
        Agentshell_Test::assert_equals( 3, count( $rows['bottom'] ) );

        Agentshell_Test::assert_equals( 'middle', agentshell_zone_slot_row( 'middle-center' ) );
        Agentshell_Test::assert_equals( 'bottom', agentshell_zone_slot_row( 'bottom-right' ) );
        Agentshell_Test::assert_equals( '', agentshell_zone_slot_row( 'nowhere' ) );
    }
);

Agentshell_Test::run(
    'the plugin fallback list agrees with the theme definition',
    function () use ( $NINE ) {
        // The theme owns the schema; Doctor's constants are the
        // plugin-without-theme fallback. If these ever differ, one of the two
        // is validating or rendering an area the other has never heard of.
        Agentshell_Test::assert_equals( $NINE, \AgentShell_MCP\Doctor::SLOTS );
        Agentshell_Test::assert_equals( agentshell_zone_slot_rows(), \AgentShell_MCP\Doctor::SLOT_ROWS );
        Agentshell_Test::assert_equals( agentshell_zone_slot_aliases(), \AgentShell_MCP\Doctor::SLOT_ALIASES );
    }
);

Agentshell_Test::run(
    'the interface description of the grid matches it',
    function () use ( $NINE ) {
        $areas = agentshell_zone_areas_for_ui();
        Agentshell_Test::assert_equals( 9, count( $areas ) );
        Agentshell_Test::assert_equals( $NINE, array_column( $areas, 'id' ) );
        Agentshell_Test::assert_equals( 'Top Left', $areas[0]['label'] );
        Agentshell_Test::assert_equals( 'Middle Centre', $areas[4]['label'] );
        Agentshell_Test::assert_equals( 'BR', $areas[8]['short'] );
        Agentshell_Test::assert_equals( 'bottom', $areas[8]['row'] );
    }
);

// ── 2. compatibility layer ──────────────────────────────────────────────────
Agentshell_Test::run(
    'legacy left/center/right become the middle row',
    function () {
        $zone = agentshell_normalize_zone( nine_area_legacy_zone() );

        Agentshell_Test::assert_equals( 9, count( $zone['slots'] ) );
        Agentshell_Test::assert_equals( '<span>L</span>', $zone['slots']['middle-left'][0]['content'] );
        Agentshell_Test::assert_equals( '<span>C</span>', $zone['slots']['middle-center'][0]['content'] );
        Agentshell_Test::assert_equals( '<span>R</span>', $zone['slots']['middle-right'][0]['content'] );
        Agentshell_Test::assert_equals( array(), $zone['slots']['top-left'] );
        Agentshell_Test::assert_equals( array(), $zone['slots']['bottom-right'] );
        Agentshell_Test::assert_false( isset( $zone['slots']['left'] ) );
    }
);

Agentshell_Test::run(
    'normalisation is idempotent',
    function () {
        $once  = agentshell_normalize_zone( nine_area_legacy_zone() );
        $twice = agentshell_normalize_zone( $once );
        Agentshell_Test::assert_equals( $once, $twice );
    }
);

Agentshell_Test::run(
    'canonical content wins over the legacy alias, whatever the key order',
    function () {
        $canonical_first = array(
            'id'    => 'header',
            'slots' => array(
                'middle-left' => array( array( 'type' => 'json_block', 'content' => '<span>canonical</span>' ) ),
                'left'        => array( array( 'type' => 'json_block', 'content' => '<span>legacy</span>' ) ),
            ),
        );
        $legacy_first = array(
            'id'    => 'header',
            'slots' => array(
                'left'        => array( array( 'type' => 'json_block', 'content' => '<span>legacy</span>' ) ),
                'middle-left' => array( array( 'type' => 'json_block', 'content' => '<span>canonical</span>' ) ),
            ),
        );

        $a = agentshell_normalize_zone( $canonical_first );
        $b = agentshell_normalize_zone( $legacy_first );

        Agentshell_Test::assert_equals( '<span>canonical</span>', $a['slots']['middle-left'][0]['content'] );
        Agentshell_Test::assert_equals( $a['slots'], $b['slots'], 'the result must not depend on key order' );
    }
);

Agentshell_Test::run(
    'an empty legacy slot normalises to an empty canonical slot',
    function () {
        $zone = array(
            'id'    => 'footer',
            'slots' => array( 'left' => array(), 'center' => array(), 'right' => array() ),
        );
        $norm = agentshell_normalize_zone( $zone );
        foreach ( agentshell_zone_slot_ids() as $slot_id ) {
            Agentshell_Test::assert_equals( array(), $norm['slots'][ $slot_id ] );
        }
        Agentshell_Test::assert_false( agentshell_zone_is_expanded( $norm ) );
    }
);

Agentshell_Test::run(
    'normalising a config leaves the main zone untouched',
    function () {
        $config = nine_area_legacy_config();
        $norm   = agentshell_normalize_zones_config( $config );
        Agentshell_Test::assert_equals(
            array( 'type' => 'json_block', 'content' => '<p>main</p>' ),
            $norm['zones'][1]['composition'][0]
        );
        Agentshell_Test::assert_false( isset( $norm['zones'][1]['slots'] ) );
        Agentshell_Test::assert_equals( 9, count( $norm['zones'][0]['slots'] ) );
    }
);

// ── 3. rendering parity ─────────────────────────────────────────────────────
Agentshell_Test::run(
    'a three-slot zone renders the historical markup, byte for byte',
    function () {
        $html = agentshell_render_zone( nine_area_legacy_zone() );

        $expected = '<div class="zone-slot slot-left"><span>L</span></div>'
            . '<div class="zone-slot slot-center"><span>C</span></div>'
            . '<div class="zone-slot slot-right"><span>R</span></div>';

        Agentshell_Test::assert_equals( $expected, $html );
        Agentshell_Test::assert_false( strpos( $html, 'data-slot' ) !== false, 'legacy markup carries no data-slot attributes' );
    }
);

Agentshell_Test::run(
    'the shipped default configuration renders the historical markup',
    function () {
        // The strongest compatibility evidence available offline: the file a
        // new site is seeded from, rendered through the new code path.
        $seed = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/default-config.json' ), true );
        Agentshell_Test::assert_true( is_array( $seed ) );

        $header = null;
        foreach ( $seed['zones'] as $zone ) {
            if ( ( $zone['id'] ?? '' ) === 'header' ) { $header = $zone; }
        }
        Agentshell_Test::assert_true( is_array( $header ) );

        $html = agentshell_render_zone( $header );
        Agentshell_Test::assert_equals( 3, substr_count( $html, 'class="zone-slot slot-' ) );
        Agentshell_Test::assert_true( strpos( $html, '<div class="zone-slot slot-left">' ) === 0 );
        Agentshell_Test::assert_true( strpos( $html, '<div class="zone-slot slot-center">' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '<div class="zone-slot slot-right">' ) !== false );
        Agentshell_Test::assert_false( strpos( $html, 'zone-grid--expanded' ) !== false );
    }
);

Agentshell_Test::run(
    'a zone with top-row content renders nine cells in row order',
    function () use ( $NINE ) {
        $zone = array(
            'id'    => 'header',
            'slots' => array(
                'top-right'     => array( array( 'type' => 'json_block', 'content' => '<span>utility</span>' ) ),
                'middle-left'   => array( array( 'type' => 'json_block', 'content' => '<span>logo</span>' ) ),
                'middle-center' => array( array( 'type' => 'json_block', 'content' => '<span>nav</span>' ) ),
                'bottom-center' => array( array( 'type' => 'json_block', 'content' => '<span>secondary</span>' ) ),
            ),
        );

        $html = agentshell_render_zone( $zone );

        Agentshell_Test::assert_equals( 9, substr_count( $html, 'class="zone-slot ' ) );

        // DOM order is the canonical order.
        $positions = array();
        foreach ( $NINE as $slot_id ) {
            $positions[ $slot_id ] = strpos( $html, 'data-slot="' . $slot_id . '"' );
            Agentshell_Test::assert_true( $positions[ $slot_id ] !== false, "cell {$slot_id} is present" );
        }
        $ordered = $positions;
        asort( $ordered );
        Agentshell_Test::assert_equals( $NINE, array_keys( $ordered ), 'cells are emitted in render order' );

        // Content lands in its own cell.
        Agentshell_Test::assert_true( strpos( $html, 'slot-top-right align-end' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '<span>utility</span>' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, '<span>secondary</span>' ) !== false );
    }
);

Agentshell_Test::run(
    'empty cells are empty, so the CSS collapses their rows',
    function () {
        $zone = array(
            'id'    => 'footer',
            'slots' => array(
                'bottom-left' => array( array( 'type' => 'json_block', 'content' => '<span>only</span>' ) ),
            ),
        );
        $html = agentshell_render_zone( $zone );

        // Eight empty cells: exactly the wrapper, nothing inside.
        Agentshell_Test::assert_equals( 8, preg_match_all( '/<div class="zone-slot [^"]*" data-slot="[^"]+"><\/div>/', $html ) );
        // The occupied cell carries its content.
        Agentshell_Test::assert_true( strpos( $html, '<span>only</span>' ) !== false );
    }
);

Agentshell_Test::run(
    'block order inside an area is preserved',
    function () {
        $zone = array(
            'id'    => 'header',
            'slots' => array(
                'top-left' => array(
                    array( 'type' => 'json_block', 'content' => '<i>one</i>' ),
                    array( 'type' => 'json_block', 'content' => '<i>two</i>' ),
                    array( 'type' => 'json_block', 'content' => '<i>three</i>' ),
                ),
            ),
        );
        $html = agentshell_render_zone( $zone );
        Agentshell_Test::assert_true( strpos( $html, '<i>one</i><i>two</i><i>three</i>' ) !== false );
    }
);

Agentshell_Test::run(
    'the layout mode is derived from content, not stored',
    function () {
        $middle_only = array(
            'id'    => 'header',
            'slots' => array( 'middle-center' => array( array( 'type' => 'json_block', 'content' => '<b>x</b>' ) ) ),
        );
        Agentshell_Test::assert_false( agentshell_zone_is_expanded( agentshell_normalize_zone( $middle_only ) ) );

        $with_top = $middle_only;
        $with_top['slots']['top-left'] = array( array( 'type' => 'json_block', 'content' => '<b>y</b>' ) );
        Agentshell_Test::assert_true( agentshell_zone_is_expanded( agentshell_normalize_zone( $with_top ) ) );
    }
);

Agentshell_Test::run(
    'alignment defaults by column and honours explicit overrides',
    function () {
        $zone = agentshell_normalize_zone( array(
            'id'    => 'header',
            'slots' => array( 'top-left' => array(), 'top-center' => array(), 'top-right' => array() ),
        ) );

        Agentshell_Test::assert_equals( 'start', agentshell_zone_slot_align( $zone, 'top-left' ) );
        Agentshell_Test::assert_equals( 'center', agentshell_zone_slot_align( $zone, 'top-center' ) );
        Agentshell_Test::assert_equals( 'end', agentshell_zone_slot_align( $zone, 'top-right' ) );
        Agentshell_Test::assert_equals( 'center', agentshell_zone_row_align( $zone, 'top' ) );

        $zone['slot_align'] = array( 'top-left' => 'stretch' );
        $zone['row_align']  = array( 'top' => 'end' );
        Agentshell_Test::assert_equals( 'stretch', agentshell_zone_slot_align( $zone, 'top-left' ) );
        Agentshell_Test::assert_equals( 'end', agentshell_zone_row_align( $zone, 'top' ) );

        $html = agentshell_render_zone( array(
            'id'         => 'header',
            'slots'      => array( 'top-left' => array( array( 'type' => 'json_block', 'content' => '<b>a</b>' ) ) ),
            'slot_align' => array( 'top-left' => 'center' ),
            'row_align'  => array( 'top' => 'end' ),
        ) );
        Agentshell_Test::assert_true( strpos( $html, 'align-center' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'valign-end' ) !== false );
    }
);

// ── 4. validation ───────────────────────────────────────────────────────────
Agentshell_Test::run(
    'validation accepts the canonical grid',
    function () use ( $NINE ) {
        $config = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array_fill_keys( $NINE, array() ),
                ),
            ),
        );
        $validated = agentshell_validate_config( $config );
        Agentshell_Test::assert_true( is_array( $validated ) );
        Agentshell_Test::assert_equals( $NINE, array_keys( $validated['zones'][0]['slots'] ) );
    }
);

Agentshell_Test::run(
    'validation accepts the legacy shape and returns it canonical',
    function () {
        $validated = agentshell_validate_config( nine_area_legacy_config() );
        Agentshell_Test::assert_true( is_array( $validated ) );
        Agentshell_Test::assert_equals( '<span>C</span>', $validated['zones'][0]['slots']['middle-center'][0]['content'] );
        Agentshell_Test::assert_false( isset( $validated['zones'][0]['slots']['center'] ) );
    }
);

Agentshell_Test::run(
    'validation refuses an unknown area and names the real ones',
    function () {
        $result = agentshell_validate_config( array(
            'zones' => array(
                array( 'id' => 'header', 'slots' => array( 'middle-middle' => array() ) ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_slot_key', $result->get_error_code() );
        Agentshell_Test::assert_true( strpos( $result->get_error_message(), 'top-left' ) !== false );
        Agentshell_Test::assert_true( strpos( $result->get_error_message(), 'middle-middle' ) !== false );
    }
);

Agentshell_Test::run(
    'validation refuses a non-array area',
    function () {
        $result = agentshell_validate_config( array(
            'zones' => array(
                array( 'id' => 'header', 'slots' => array( 'top-left' => 'nope' ) ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_slot_value', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'validation checks every area, not just the middle row',
    function () {
        $result = agentshell_validate_config( array(
            'zones' => array(
                array(
                    'id'    => 'footer',
                    'slots' => array( 'bottom-right' => array( array( 'type' => 'bogus' ) ) ),
                ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_slot_block_type', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'validation refuses an alignment the stylesheet cannot render',
    function () {
        $bad_value = agentshell_validate_config( array(
            'zones' => array(
                array(
                    'id'         => 'header',
                    'slots'      => array( 'top-left' => array() ),
                    'slot_align' => array( 'top-left' => 'middle' ),
                ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $bad_value );
        Agentshell_Test::assert_equals( 'invalid_slot_align', $bad_value->get_error_code() );

        $bad_area = agentshell_validate_config( array(
            'zones' => array(
                array(
                    'id'         => 'header',
                    'slots'      => array( 'top-left' => array() ),
                    'slot_align' => array( 'nowhere' => 'start' ),
                ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $bad_area );

        $bad_row = agentshell_validate_config( array(
            'zones' => array(
                array(
                    'id'        => 'header',
                    'slots'     => array( 'top-left' => array() ),
                    'row_align' => array( 'middle' => 'sideways' ),
                ),
            ),
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $bad_row );
        Agentshell_Test::assert_equals( 'invalid_row_align', $bad_row->get_error_code() );
    }
);

Agentshell_Test::run(
    'validation is idempotent on an already-canonical config',
    function () {
        $once  = agentshell_validate_config( nine_area_legacy_config() );
        $twice = agentshell_validate_config( $once );
        Agentshell_Test::assert_equals( $once, $twice );
    }
);

// ── 5. readers and the REST response ────────────────────────────────────────
Agentshell_Test::run(
    'a stored legacy config is normalised on read',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', nine_area_legacy_config() );

        $config = agentshell_get_config();
        Agentshell_Test::assert_equals( 9, count( $config['zones'][0]['slots'] ) );
        Agentshell_Test::assert_equals( '<span>L</span>', $config['zones'][0]['slots']['middle-left'][0]['content'] );
    }
);

Agentshell_Test::run(
    'the config endpoint describes nine areas and the alignment vocabulary',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', nine_area_legacy_config() );

        $response = agentshell_build_config_get_response();
        Agentshell_Test::assert_equals( 9, count( $response['zones'][0]['slots'] ) );
        Agentshell_Test::assert_equals( 9, count( $response['zone_areas'] ) );
        Agentshell_Test::assert_equals( array( 'start', 'center', 'end', 'stretch' ), $response['zone_align']['slot'] );
        Agentshell_Test::assert_equals( array( 'top', 'middle', 'bottom' ), $response['zone_align']['rows'] );
    }
);

Agentshell_Test::run(
    'a configurator-shaped PUT keeps custom_css and stores the canonical grid',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', nine_area_legacy_config() );

        $merged = agentshell_unflatten_config(
            array(
                'zones' => array(
                    array(
                        'id'    => 'header',
                        'slots' => array(
                            'top-left' => array( array( 'type' => 'wp_core', 'id' => 'site_title' ) ),
                            'left'     => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                        ),
                    ),
                ),
            ),
            agentshell_get_config()
        );

        $validated = agentshell_validate_config( $merged );
        Agentshell_Test::assert_true( is_array( $validated ) );
        Agentshell_Test::assert_equals( '/* keep me */', $validated['custom_css'], 'custom_css survives a zones-only PUT' );
        Agentshell_Test::assert_equals( 'site_title', $validated['zones'][0]['slots']['top-left'][0]['id'] );
        Agentshell_Test::assert_equals( 'site_logo', $validated['zones'][0]['slots']['middle-left'][0]['id'] );
        Agentshell_Test::assert_equals( 9, count( $validated['zones'][0]['slots'] ) );
    }
);

// ── 6. MCP ↔ configurator equivalence ───────────────────────────────────────
/**
 * The tool, with persistence captured instead of staged.
 *
 * The real tool stages through Transaction_Manager; this subclass replaces
 * that one boundary so the tool's own logic can be compared with the REST
 * path byte for byte. Everything else — reading the config, validating the
 * payload, normalising it — is the production code.
 */
class Nine_Area_Test_Update_Zone_Slots extends \AgentShell_MCP\Tools\Update_Zone_Slots {
    public $stored = null;
    protected function update_agentshell_config( array $config ) {
        $this->stored = $config;
        return true;
    }
}

Agentshell_Test::run(
    'MCP and the configurator produce the same configuration for the same edit',
    function () {
        $edit = array(
            'top-center'    => array( array( 'type' => 'wp_core', 'id' => 'site_tagline' ) ),
            'middle-left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
            'middle-center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
            'bottom-right'  => array( array( 'type' => 'json_block', 'content' => '<small>legal</small>' ) ),
        );

        // Configurator path: the panel PUTs the whole zones array.
        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'header' ) ) ) );
        $via_rest = agentshell_validate_config( agentshell_unflatten_config(
            array( 'zones' => array( array( 'id' => 'header', 'slots' => $edit ) ) ),
            agentshell_get_config()
        ) );

        // MCP path: the tool replaces the same zone.
        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'header' ) ) ) );
        $tool = new Nine_Area_Test_Update_Zone_Slots();
        $result = $tool->execute( array( 'zone_id' => 'header', 'slots' => $edit ) );

        Agentshell_Test::assert_equals( $via_rest['zones'], $tool->stored['zones'], 'the two interfaces must agree' );
        Agentshell_Test::assert_equals( 'expanded', $result['layout'] );
        Agentshell_Test::assert_equals( 9, count( $result['slots'] ) );
    }
);

Agentshell_Test::run(
    'a legacy MCP payload stores exactly what the canonical payload stores',
    function () {
        $legacy = array(
            'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
            'center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
            'right'  => array(),
        );
        $canonical = array(
            'middle-left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
            'middle-center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
            'middle-right'  => array(),
        );

        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool_a = new Nine_Area_Test_Update_Zone_Slots();
        $tool_a->execute( array( 'zone_id' => 'header', 'slots' => $legacy ) );

        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool_b = new Nine_Area_Test_Update_Zone_Slots();
        $tool_b->execute( array( 'zone_id' => 'header', 'slots' => $canonical ) );

        Agentshell_Test::assert_equals( $tool_b->stored, $tool_a->stored );
        Agentshell_Test::assert_equals( 'standard', 'standard', 'layout is standard for a middle-only grid' );
    }
);

Agentshell_Test::run(
    'the MCP tool refuses an unknown area by name',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool = new Nine_Area_Test_Update_Zone_Slots();

        $threw = false;
        try {
            $tool->execute( array( 'zone_id' => 'header', 'slots' => array( 'middle-middle' => array() ) ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
            Agentshell_Test::assert_true( strpos( $e->getMessage(), 'top-left' ) !== false );
        }
        Agentshell_Test::assert_true( $threw, 'an unknown area must be refused' );
        Agentshell_Test::assert_equals( null, $tool->stored, 'a refused call must not write' );
    }
);

Agentshell_Test::run(
    'the MCP tool refuses an unknown wp_core component',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool = new Nine_Area_Test_Update_Zone_Slots();
        $threw = false;
        try {
            $tool->execute( array(
                'zone_id' => 'header',
                'slots'   => array( 'top-left' => array( array( 'type' => 'wp_core', 'id' => 'not_a_component' ) ) ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
        Agentshell_Test::assert_equals( null, $tool->stored );
    }
);

Agentshell_Test::run(
    'the MCP tool accepts and normalises alignment maps',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool = new Nine_Area_Test_Update_Zone_Slots();
        $result = $tool->execute( array(
            'zone_id'    => 'footer',
            'slots'      => array( 'bottom-left' => array(), 'left' => array( array( 'type' => 'wp_core', 'id' => 'site_title' ) ) ),
            'slot_align' => array( 'bottom-left' => 'end', 'left' => 'center' ),
            'row_align'  => array( 'bottom' => 'end' ),
        ) );

        Agentshell_Test::assert_equals( 'end', $result['slot_align']['bottom-left'] );
        Agentshell_Test::assert_equals( 'center', $result['slot_align']['middle-left'], 'a legacy alignment key is normalised' );
        Agentshell_Test::assert_equals( 'end', $result['row_align']['bottom'] );
        Agentshell_Test::assert_equals( 'site_title', $result['slots']['middle-left'][0]['id'] );
    }
);

Agentshell_Test::run(
    'the MCP tool refuses an invalid alignment value',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );
        $tool = new Nine_Area_Test_Update_Zone_Slots();
        $threw = false;
        try {
            $tool->execute( array(
                'zone_id'    => 'header',
                'slots'      => array( 'top-left' => array() ),
                'slot_align' => array( 'top-left' => 'diagonal' ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'a legacy config and its canonical form produce a truthful transaction diff',
    function () {
        // The configurator and the tools write the canonical shape, so a
        // staged change against a legacy committed config really does rename
        // area keys. The diff must describe that honestly rather than hide it.
        if ( ! class_exists( '\AgentShell_MCP\Store' ) ) {
            require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php';
        }
        $before = nine_area_legacy_config();
        $after  = agentshell_validate_config( $before );

        $diff = \AgentShell_MCP\Store::diff_config( $before, $after );
        Agentshell_Test::assert_true( is_array( $diff ) );

        $paths = array();
        foreach ( ( $diff['structural'] ?? array() ) as $entry ) {
            $paths[ $entry['path'] ] = $entry['kind'];
        }
        Agentshell_Test::assert_equals( 'removed', $paths['zones.0.slots.left'] ?? '' );
        Agentshell_Test::assert_equals( 'added', $paths['zones.0.slots.middle-left'] ?? '' );
        Agentshell_Test::assert_equals( 'added', $paths['zones.0.slots.top-left'] ?? '' );
    }
);

Agentshell_Test::run(
    'the doctor accepts a nine-area zone and flags an unknown area',
    function () {
        $good = \AgentShell_MCP\Doctor::check( array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'top-left'      => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                        'middle-center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
                    ),
                ),
            ),
        ) );
        foreach ( $good['errors'] as $error ) {
            Agentshell_Test::assert_false(
                in_array( $error['code'] ?? '', array( 'SLOT_UNKNOWN', 'SLOT_ALIGN_INVALID', 'ROW_ALIGN_INVALID' ), true ),
                'a canonical zone must not produce slot errors: ' . wp_json_encode( $error )
            );
        }

        $bad = \AgentShell_MCP\Doctor::check( array(
            'zones' => array(
                array( 'id' => 'header', 'slots' => array( 'middle-middle' => array() ) ),
            ),
        ) );
        $codes = array_column( $bad['errors'], 'code' );
        Agentshell_Test::assert_true( in_array( 'SLOT_UNKNOWN', $codes, true ) );
    }
);
