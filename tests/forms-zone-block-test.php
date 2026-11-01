<?php
/**
 * The `agentshell_form` zone block, across every entry point that can store one.
 *
 * What this suite defends
 * -----------------------
 * 1. **One vocabulary, six types.** The block type is accepted by the
 *    configuration validator, the doctor, and both zone tools — and refused
 *    everywhere the identifier is missing or malformed. A block one path
 *    accepts and another refuses is a configuration that saves and then
 *    cannot be committed.
 * 2. **The block delegates.** The renderer calls the forms plugin's public
 *    function and returns its HTML unchanged; it does not build a second
 *    form. The stub renderer here is a marker, so a theme that quietly grew
 *    its own form markup would return the marker's absence rather than pass.
 * 3. **Absence is not an error.** A site without the plugin renders no form
 *    and no fatal: the `function_exists()` guard is the contract.
 * 4. **Placement is placement.** Composition and all nine header/footer
 *    areas accept the block; the three-slot aliases still normalise; and the
 *    five historical block types keep working unchanged.
 * 5. **The reference is the immutable id.** A post id, a name, a slug or a
 *    stale field beside the id is refused by name rather than stored.
 *
 * The live end-to-end proof (a real published form, rendered on a real page,
 * through a real save) is `tests/forms-zone-block-live-test.php`.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-update-zone-slots.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-update-zone-composition.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-doctor.php';

echo "forms zone block tests\n";

// WordPress surface the renderer and the validator touch. Guarded so a later
// suite cannot redeclare them.
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $key = '' ) { return ( 'name' === $key ) ? 'AgentShell Test' : ''; }
}
if ( ! function_exists( 'get_search_form' ) ) {
    function get_search_form( $args = array() ) { return '<form class="search-form"></form>'; }
}
if ( ! function_exists( 'wp_registered_sidebars' ) ) {
    function wp_registered_sidebars() { return array(); }
}
if ( ! function_exists( 'dynamic_sidebar' ) ) {
    function dynamic_sidebar( $id ) { return false; }
}
require_once __DIR__ . '/agentshell-nav-stubs.php';

/** A real shape: 32 lowercase hex characters. */
const FZ_FORM_A = '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a';
const FZ_FORM_B = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

/**
 * Render one block through the real switch.
 *
 * @param array $block Block.
 * @return string
 */
function fz_render( array $block ) {
    return agentshell_render_block( $block );
}

/**
 * A configuration with one zone of the given shape.
 *
 * @param string $zone  Zone id.
 * @param array  $blocks Blocks.
 * @param string $where `composition` or `slots`.
 * @return array
 */
function fz_config( $zone, array $blocks, $where = 'composition' ) {
    $z = array( 'id' => $zone );

    if ( 'slots' === $where ) {
        $z['slots'] = array( 'top-left' => $blocks );
    } else {
        $z['composition'] = $blocks;
    }

    return array( 'zones' => array( $z ) );
}

/* ── 1. The vocabulary grew to six, and only by one ─────────────────────── */

Agentshell_Test::run(
    'the block vocabulary is six types and the form type is one of them',
    function () {
        $expected = array( 'wp_loop', 'wp_core', 'widget', 'json_block', 'wp_widget_area', 'agentshell_form' );

        Agentshell_Test::assert_equals( 6, count( $expected ), 'the vocabulary has six types' );

        // The doctor's constant is the one an agent reads.
        $reflection = new ReflectionClass( 'AgentShell_MCP\\Doctor' );
        $allowed    = $reflection->getConstant( 'ALLOWED_BLOCKS' );

        sort( $allowed );
        sort( $expected );

        Agentshell_Test::assert_equals( $expected, $allowed, 'the doctor allows exactly the six documented types' );
    }
);

Agentshell_Test::run(
    'the configuration validator accepts every documented type',
    function () {
        foreach ( array( 'wp_loop', 'json_block', 'agentshell_form' ) as $type ) {
            $block = array( 'type' => $type );
            if ( 'agentshell_form' === $type ) {
                $block['id'] = FZ_FORM_A;
            }

            $out = agentshell_validate_config( fz_config( 'main', array( $block ) ) );

            Agentshell_Test::assert_false( is_wp_error( $out ), 'the validator accepts ' . $type . ': ' . ( is_wp_error( $out ) ? $out->get_error_message() : '' ) );
        }

        foreach ( array( 'wp_core', 'widget', 'wp_widget_area' ) as $type ) {
            $block = array( 'type' => $type, 'id' => 'nav_menu' === $type ? 'nav_menu' : 'x' );
            if ( 'wp_core' === $type ) {
                $block['id'] = 'site_title';
            }

            $out = agentshell_validate_config( fz_config( 'main', array( $block ) ) );

            Agentshell_Test::assert_false( is_wp_error( $out ), 'the validator still accepts ' . $type );
        }
    }
);

/* ── 2. The identifier is the immutable internal id ─────────────────────── */

Agentshell_Test::run(
    'a form block without an id is refused by name',
    function () {
        $out = agentshell_validate_config( fz_config( 'main', array( array( 'type' => 'agentshell_form' ) ) ) );

        Agentshell_Test::assert_instance_of( 'WP_Error', $out, 'a form block needs an id' );
        Agentshell_Test::assert_equals( 'form_block_missing_id', $out->get_error_code(), 'the refusal names the missing id' );
    }
);

Agentshell_Test::run(
    'a post id, a name and a malformed id are all refused',
    function () {
        $cases = array(
            'a WordPress post id'   => '42',
            'a display name'        => 'Contact us',
            'uppercase hex'        => '7C1D2E3A4B5C6D7E8F9A0B1C2D3E4F5A',
            'the wrong length'     => '7c1d2e',
            'a slug that looks ok' => 'contact-us',
        );

        foreach ( $cases as $label => $id ) {
            $out = agentshell_validate_config( fz_config( 'main', array( array( 'type' => 'agentshell_form', 'id' => $id ) ) ) );

            Agentshell_Test::assert_instance_of( 'WP_Error', $out, $label . ' is refused' );
            Agentshell_Test::assert_equals( 'form_block_id_invalid', $out->get_error_code(), $label . ' is refused as an invalid id' );
        }

        $out = agentshell_validate_config( fz_config( 'main', array( array( 'type' => 'agentshell_form', 'id' => array( FZ_FORM_A ) ) ) ) );
        Agentshell_Test::assert_equals( 'form_block_id_not_string', $out->get_error_code(), 'a non-string id is refused as such' );
    }
);

Agentshell_Test::run(
    'a stale field beside the id is refused rather than stored',
    function () {
        foreach ( array( 'post_id', 'name', 'slug' ) as $field ) {
            $block = array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A, $field => 'stale' );
            $out   = agentshell_validate_config( fz_config( 'main', array( $block ) ) );

            Agentshell_Test::assert_instance_of( 'WP_Error', $out, 'a form block may not carry ' . $field );
            Agentshell_Test::assert_equals( 'form_block_stale_field', $out->get_error_code(), 'the refusal names the stale field' );
        }
    }
);

/* ── 3. Placement: composition and the nine areas ───────────────────────── */

Agentshell_Test::run(
    'the block is accepted in the main composition and in every header and footer area',
    function () {
        $block = array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A );

        $main = agentshell_validate_config( fz_config( 'main', array( $block ) ) );
        Agentshell_Test::assert_false( is_wp_error( $main ), 'the main composition accepts the block' );

        foreach ( array( 'header', 'footer' ) as $zone ) {
            $slot_ids = agentshell_zone_slot_ids();

            Agentshell_Test::assert_equals( 9, count( $slot_ids ), $zone . ' has nine areas' );

            foreach ( $slot_ids as $slot ) {
                $config = array(
                    'zones' => array(
                        array(
                            'id'    => $zone,
                            'slots' => array( $slot => array( $block ) ),
                        ),
                    ),
                );

                $out = agentshell_validate_config( $config );
                Agentshell_Test::assert_false(
                    is_wp_error( $out ),
                    $zone . ' area ' . $slot . ' accepts the block: ' . ( is_wp_error( $out ) ? $out->get_error_message() : '' )
                );
            }
        }
    }
);

Agentshell_Test::run(
    'the historical three-slot aliases still normalise with a form block in them',
    function () {
        $block = array( 'type' => 'agentshell_form', 'id' => FZ_FORM_B );

        $out = agentshell_validate_config(
            array(
                'zones' => array(
                    array(
                        'id'    => 'header',
                        'slots' => array( 'left' => array( $block ) ),
                    ),
                ),
            )
        );

        Agentshell_Test::assert_false( is_wp_error( $out ), 'the alias is accepted' );

        $normalised = agentshell_normalize_zone(
            array(
                'id'    => 'header',
                'slots' => array( 'left' => array( $block ) ),
            )
        );

        Agentshell_Test::assert_true( isset( $normalised['slots']['middle-left'] ), 'left means middle-left' );
        Agentshell_Test::assert_equals(
            $block,
            $normalised['slots']['middle-left'][0],
            'the form block survives normalisation unchanged'
        );
        Agentshell_Test::assert_false( isset( $normalised['slots']['left'] ), 'the alias key is gone' );
    }
);

Agentshell_Test::run(
    'the five historical block types are untouched',
    function () {
        $out = agentshell_validate_config(
            array(
                'zones' => array(
                    array(
                        'id'          => 'main',
                        'composition' => array(
                            array( 'type' => 'wp_loop' ),
                            array( 'type' => 'wp_core', 'id' => 'site_title' ),
                            array( 'type' => 'widget', 'id' => 'chart' ),
                            array( 'type' => 'json_block', 'content' => '<p>Hi</p>' ),
                            array( 'type' => 'wp_widget_area', 'id' => 'primary-sidebar' ),
                        ),
                    ),
                ),
            )
        );

        Agentshell_Test::assert_false( is_wp_error( $out ), 'a site using the five original types is unaffected' );

        // Adding the sixth must not have loosened the first five.
        $unknown = agentshell_validate_config( fz_config( 'main', array( array( 'type' => 'agentshell_gallery' ) ) ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $unknown, 'an unknown type is still refused' );
        Agentshell_Test::assert_equals( 'invalid_block_type', $unknown->get_error_code(), 'the refusal names the type' );
    }
);

/* ── 4. The doctor agrees with the validator ─────────────────────────────── */

/**
 * Run the doctor over a configuration.
 *
 * @param array $config Configuration.
 * @return array{ok:bool,errors:array,warnings:array}
 */
function fz_doctor( array $config ) {
    $reflection = new ReflectionClass( 'AgentShell_MCP\\Doctor' );
    $method     = $reflection->getMethod( 'check_zones' );
    $method->setAccessible( true );

    $errors   = array();
    $warnings = array();

    $method->invokeArgs( null, array( $config, &$errors, &$warnings ) );

    return array( 'ok' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings );
}

Agentshell_Test::run(
    'the doctor accepts a valid form block',
    function () {
        $result = fz_doctor( fz_config( 'main', array( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A ) ) ) );

        Agentshell_Test::assert_true( $result['ok'], 'the doctor accepts the block: ' . wp_json_encode( $result['errors'] ) );
    }
);

Agentshell_Test::run(
    'the doctor refuses a form block with no id and with a malformed id',
    function () {
        $missing = fz_doctor( fz_config( 'main', array( array( 'type' => 'agentshell_form' ) ) ) );
        Agentshell_Test::assert_false( $missing['ok'], 'an id-less form block is an error, not a warning' );

        $codes = array();
        foreach ( $missing['errors'] as $error ) {
            $codes[] = $error['code'] ?? '';
        }
        Agentshell_Test::assert_true( in_array( 'FORM_BLOCK_MISSING_ID', $codes, true ), 'the doctor names the missing id: ' . wp_json_encode( $codes ) );

        $bad = fz_doctor( fz_config( 'main', array( array( 'type' => 'agentshell_form', 'id' => '42' ) ) ) );
        Agentshell_Test::assert_false( $bad['ok'], 'a post id is an error' );

        $bad_codes = array();
        foreach ( $bad['errors'] as $error ) {
            $bad_codes[] = $error['code'] ?? '';
        }
        Agentshell_Test::assert_true( in_array( 'FORM_BLOCK_ID_INVALID', $bad_codes, true ), 'the doctor names the invalid id: ' . wp_json_encode( $bad_codes ) );
    }
);

Agentshell_Test::run(
    'the doctor warns about a form that is not published here, without failing the configuration',
    function () {
        // No forms plugin in this process: the catalog does not exist, so the
        // doctor has nothing to verify the reference against and says so
        // rather than inventing a verdict.
        $result = fz_doctor( fz_config( 'main', array( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_B ) ) ) );

        Agentshell_Test::assert_true( $result['ok'], 'an unverifiable reference is not an error: a manifest describes a site that may not have the form yet' );
    }
);

/* ── 5. Both zone tools ─────────────────────────────────────────────────── */

/**
 * Each zone tool with persistence captured instead of staged.
 *
 * The real tools stage through Transaction_Manager; this replaces that one
 * boundary so the tool's own validation is what is under test. Everything
 * else — reading the config, validating the payload, normalising it — is the
 * production code, and the capture proves the block reached storage intact.
 */
class FZ_Update_Zone_Composition extends \AgentShell_MCP\Tools\Update_Zone_Composition {
    public $stored = null;
    protected function update_agentshell_config( array $config ) {
        $this->stored = $config;
        return true;
    }
}

class FZ_Update_Zone_Slots extends \AgentShell_MCP\Tools\Update_Zone_Slots {
    public $stored = null;
    protected function update_agentshell_config( array $config ) {
        $this->stored = $config;
        return true;
    }
}

Agentshell_Test::run(
    'agentshell_update_zone_composition accepts a valid form block and stores it intact',
    function () {
        $tool = new FZ_Update_Zone_Composition();

        $schema = $tool->get_input_schema();
        $enum   = $schema['properties']['composition']['items']['properties']['type']['enum'];

        Agentshell_Test::assert_true( in_array( 'agentshell_form', $enum, true ), 'the tool advertises the type in its schema' );

        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'main', 'composition' => array() ) ) ) );

        $block  = array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A );
        $result = $tool->execute( array( 'zone_id' => 'main', 'composition' => array( $block ) ) );

        Agentshell_Test::assert_true( is_array( $result ), 'the tool returns a result' );

        $main = null;
        foreach ( (array) ( $tool->stored['zones'] ?? array() ) as $zone ) {
            if ( 'main' === ( $zone['id'] ?? '' ) ) {
                $main = $zone;
            }
        }

        Agentshell_Test::assert_true( is_array( $main ), 'the main zone was written' );
        Agentshell_Test::assert_equals( array( $block ), $main['composition'] ?? null, 'the block is stored exactly as sent' );
    }
);

Agentshell_Test::run(
    'agentshell_update_zone_composition refuses a malformed form block',
    function () {
        $tool = new \AgentShell_MCP\Tools\Update_Zone_Composition();

        foreach ( array(
            'no id'      => array( 'type' => 'agentshell_form' ),
            'post id'    => array( 'type' => 'agentshell_form', 'id' => '42' ),
            'name'       => array( 'type' => 'agentshell_form', 'id' => 'Contact us' ),
            'stale slug' => array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A, 'slug' => 'contact' ),
        ) as $label => $block ) {
            $threw = false;
            try {
                $tool->execute( array( 'zone_id' => 'main', 'composition' => array( $block ) ) );
            } catch ( \InvalidArgumentException $e ) {
                $threw = 0 === strpos( $e->getMessage(), 'composition[0]:' );
            }

            Agentshell_Test::assert_true( $threw, 'composition refuses ' . $label );
        }
    }
);

Agentshell_Test::run(
    'agentshell_update_zone_slots accepts a valid form block in an area and stores it intact',
    function () {
        $tool = new FZ_Update_Zone_Slots();

        update_option( 'agentshell_config', array( 'zones' => array( array( 'id' => 'header' ) ) ) );

        $block  = array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A );
        $result = $tool->execute( array( 'zone_id' => 'header', 'slots' => array( 'bottom-right' => array( $block ) ) ) );

        Agentshell_Test::assert_true( is_array( $result ), 'the tool returns a result' );

        $header = null;
        foreach ( (array) ( $tool->stored['zones'] ?? array() ) as $zone ) {
            if ( 'header' === ( $zone['id'] ?? '' ) ) {
                $header = $zone;
            }
        }

        Agentshell_Test::assert_true( is_array( $header ), 'the header zone was written' );
        Agentshell_Test::assert_equals( array( $block ), $header['slots']['bottom-right'] ?? null, 'the block is stored in the area it was sent to' );
    }
);

Agentshell_Test::run(
    'agentshell_update_zone_slots refuses a malformed form block',
    function () {
        $tool = new \AgentShell_MCP\Tools\Update_Zone_Slots();

        foreach ( array(
            'no id'   => array( 'type' => 'agentshell_form' ),
            'post id' => array( 'type' => 'agentshell_form', 'id' => '42' ),
        ) as $label => $block ) {
            $threw = false;
            try {
                $tool->execute( array( 'zone_id' => 'header', 'slots' => array( 'top-left' => array( $block ) ) ) );
            } catch ( \InvalidArgumentException $e ) {
                $threw = 0 === strpos( $e->getMessage(), 'slots[top-left][0]:' );
            }

            Agentshell_Test::assert_true( $threw, 'the slots tool refuses ' . $label );
        }
    }
);

/* ── 6. The renderer delegates, and absence is not an error ─────────────── */

Agentshell_Test::run(
    'the renderer returns nothing when the forms plugin is absent, without a fatal',
    function () {
        // No agentshell_forms_render() in this process: that is the site the
        // plugin is not active on.
        Agentshell_Test::assert_false( function_exists( 'agentshell_forms_render' ), 'the plugin is absent in this process' );

        $html = fz_render( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A ) );

        Agentshell_Test::assert_equals( '', (string) $html, 'a form block renders nothing rather than failing' );
    }
);

Agentshell_Test::run(
    'the renderer renders nothing for a block with no usable id',
    function () {
        Agentshell_Test::assert_equals( '', (string) fz_render( array( 'type' => 'agentshell_form' ) ), 'no id renders nothing' );
        Agentshell_Test::assert_equals( '', (string) fz_render( array( 'type' => 'agentshell_form', 'id' => '' ) ), 'an empty id renders nothing' );
        Agentshell_Test::assert_equals( '', (string) fz_render( array( 'type' => 'agentshell_form', 'id' => array( FZ_FORM_A ) ) ), 'a non-string id renders nothing' );
    }
);

/**
 * The forms plugin's public function, stubbed once.
 *
 * Declared after the assertions above that require it to be *absent*: PHP
 * cannot undeclare a function, and the absent case is the one a site without
 * the plugin is in. The mode global selects the plugin's two real behaviours
 * — HTML for a published form, an empty string for one that is unknown,
 * unpublished, trashed or invalid.
 */
if ( ! function_exists( 'agentshell_forms_render' ) ) {
    function agentshell_forms_render( $form_id, array $args = array() ) {
        $GLOBALS['fz_calls'][] = array( 'form_id' => $form_id, 'args' => $args );

        if ( 'empty' === ( $GLOBALS['fz_mode'] ?? 'marker' ) ) {
            return '';
        }

        return '<form class="agentshell-form" data-marker="delegated"><input name="a"></form>';
    }
}

Agentshell_Test::run(
    'the renderer calls the plugin positionally and returns its HTML unchanged',
    function () {
        $GLOBALS['fz_calls'] = array();
        $GLOBALS['fz_mode']  = 'marker';

        $html = fz_render( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A ) );

        Agentshell_Test::assert_true( false !== strpos( (string) $html, 'data-marker="delegated"' ), 'the plugin\'s HTML is what is rendered' );
        Agentshell_Test::assert_equals( 1, count( $GLOBALS['fz_calls'] ), 'the plugin is called exactly once' );
        Agentshell_Test::assert_equals( FZ_FORM_A, $GLOBALS['fz_calls'][0]['form_id'], 'the immutable id is what the plugin is given' );
        Agentshell_Test::assert_equals( 'zone', $GLOBALS['fz_calls'][0]['args']['context'] ?? '', 'the renderer is told this is a zone placement' );
        Agentshell_Test::assert_equals( 'hide', $GLOBALS['fz_calls'][0]['args']['title'] ?? '', 'a placement does not force the form name as a heading' );

        // Not double-escaped, and not passed through the json_block
        // sanitiser: the form's own markup survives exactly as the plugin
        // produced it.
        Agentshell_Test::assert_equals(
            '<form class="agentshell-form" data-marker="delegated"><input name="a"></form>',
            (string) $html,
            'the HTML is returned byte for byte'
        );
    }
);

Agentshell_Test::run(
    'the renderer passes an empty string through: an unpublished form is not an error either',
    function () {
        $GLOBALS['fz_calls'] = array();
        $GLOBALS['fz_mode']  = 'empty';

        Agentshell_Test::assert_equals( '', (string) fz_render( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_B ) ), 'an empty rendering stays empty' );

        $GLOBALS['fz_mode'] = 'marker';
    }
);

/* ── 7. The block in the real shell markup ──────────────────────────────── */

Agentshell_Test::run(
    'a form block renders inside a nine-area header slot without disturbing the grid',
    function () {
        $markup = agentshell_render_zone(
            array(
                'id'    => 'header',
                'slots' => array( 'top-left' => array( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A ) ) ),
            )
        );

        // The zone is in its expanded mode — the thing that selects the grid
        // renderer — because an area outside the middle row holds a block.
        Agentshell_Test::assert_equals(
            'zone-grid--expanded',
            agentshell_zone_layout_class(
                array(
                    'id'    => 'header',
                    'slots' => array( 'top-left' => array( array( 'type' => 'agentshell_form', 'id' => FZ_FORM_A ) ) ),
                )
            ),
            'the expanded grid is selected because an area outside the middle row has content'
        );

        Agentshell_Test::assert_true( false !== strpos( $markup, 'data-marker="delegated"' ), 'the form is inside the grid' );

        // Every one of the nine cells is still emitted with its own slot id,
        // so a form in one cell cannot shift its neighbours: placement is by
        // data-slot and CSS, not by document order.
        Agentshell_Test::assert_equals( 9, substr_count( $markup, 'class="zone-slot' ), 'all nine cells are still emitted' );

        foreach ( agentshell_zone_slot_ids() as $slot ) {
            Agentshell_Test::assert_true(
                false !== strpos( $markup, 'data-slot="' . $slot . '"' ),
                'cell ' . $slot . ' carries its own id'
            );
        }

        // The form is in the cell it was placed in, and in no other.
        $cells = explode( '<div class="zone-slot', $markup );
        foreach ( $cells as $cell ) {
            if ( false === strpos( $cell, 'data-marker="delegated"' ) ) {
                continue;
            }
            Agentshell_Test::assert_true( false !== strpos( $cell, 'data-slot="top-left"' ), 'the form renders in the area it was placed in' );
        }
    }
);

/* ── 8. The catalog contract the theme consumes ─────────────────────────── */

Agentshell_Test::run(
    'the theme degrades to an empty forms catalog when the plugin is absent',
    function () {
        // agentshell_forms_published_catalog() is absent in this process, so
        // the config response must carry an empty list and a false flag
        // rather than omitting the keys or fataling.
        Agentshell_Test::assert_false( function_exists( 'agentshell_forms_published_catalog' ), 'the plugin is absent' );

        $response = agentshell_build_config_response( array( 'zones' => array() ) );

        Agentshell_Test::assert_true( array_key_exists( 'available_forms', $response ), 'the key is present' );
        Agentshell_Test::assert_equals( array(), $response['available_forms'], 'the catalog is empty' );
        Agentshell_Test::assert_equals( false, $response['forms_available'], 'the flag says the plugin is absent' );
        Agentshell_Test::assert_equals( '', $response['forms_builder_url'], 'no builder route is offered' );

        // Backward compatibility: every key the previous response carried is
        // still there.
        foreach ( array( 'zones', 'available_widgets', 'core_components', 'available_widget_areas', 'available_menus', 'custom_css', 'custom_js', 'layout', 'schema' ) as $key ) {
            Agentshell_Test::assert_true( array_key_exists( $key, $response ), 'the response still carries ' . $key );
        }
    }
);
