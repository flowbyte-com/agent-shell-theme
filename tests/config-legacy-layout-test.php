<?php
/**
 * Legacy geometry compatibility and the unrelated-edit regression.
 *
 * The real defect this pins: a site that had used agentshell_set_shape
 * carried design.layout.borderWidth and design.layout.borderStyle. Every
 * configurator PUT merges the flat tokens into the stored configuration and
 * then validates the whole merged document, so an Appearance edit — a
 * shadow preset, a colour, a font — was refused with
 * "Unknown layout key: borderWidth" even though it touched neither key.
 *
 * The fixture is a trimmed capture of the configuration stored on the
 * disposable agentshell-demo.local installation, not a synthetic flat token
 * map: the fields that caused the failure, the historical three-slot zone
 * shape and the custom properties the stylesheet reads are all present.
 *
 * The fix is an explicit allowlist entry, not a wildcard: the keys are the
 * ones the set_shape tool writes and the doctor already allows, their values
 * are checked with the same grammar the tool enforces, and an unknown key is
 * still refused in both places it could appear (design.layout and the
 * top-level layout).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "legacy geometry compatibility (real stored shape)\n";

function config_legacy_fixture() {
    static $fixture = null;
    if ( null === $fixture ) {
        $fixture = json_decode(
            (string) file_get_contents( __DIR__ . '/fixtures/agentshell-config-legacy-shape.json' ),
            true
        );
    }
    return $fixture;
}

Agentshell_Test::run(
    'a stored configuration with design.layout.borderWidth validates',
    function () {
        $legacy = config_legacy_fixture();

        $validated = agentshell_validate_config( $legacy );

        Agentshell_Test::assert_false( is_wp_error( $validated ), 'no validation error for a stored shape' );
        Agentshell_Test::assert_equals( '1px', $validated['design']['layout']['borderWidth'] );
        Agentshell_Test::assert_equals( 'solid', $validated['design']['layout']['borderStyle'] );
        Agentshell_Test::assert_equals( '10px', $validated['design']['layout']['radius'] );
    }
);

Agentshell_Test::run(
    'an unrelated shadow edit saves and preserves the legacy geometry',
    function () {
        $legacy = config_legacy_fixture();
        $soft   = '0 1px 2px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.06)';

        $merged    = agentshell_unflatten_config( array( '--theme-shadow' => $soft ), $legacy );
        $validated = agentshell_validate_config( $merged );

        // Validation stores zones in the canonical nine-area shape. That is
        // existing behaviour, not this fix: the same blocks must survive the
        // translation, in the same areas.
        $canonical = agentshell_normalize_zones_config( $legacy );

        Agentshell_Test::assert_false( is_wp_error( $validated ), 'the unrelated edit is not refused' );
        Agentshell_Test::assert_equals( $legacy['design']['layout'], $validated['design']['layout'], 'geometry is preserved' );
        Agentshell_Test::assert_equals( $canonical['zones'], $validated['zones'], 'zone composition is preserved' );
        Agentshell_Test::assert_equals( $legacy['custom_css'], $validated['custom_css'], 'custom CSS is preserved' );
        Agentshell_Test::assert_equals( $soft, $validated['design']['custom_css_vars']['--theme-shadow'], 'the edit is stored' );
    }
);

Agentshell_Test::run(
    'changing border width writes the geometry and the custom property',
    function () {
        $legacy = config_legacy_fixture();

        $merged = agentshell_unflatten_config( array( '--border-width' => '2px' ), $legacy );
        $validated = agentshell_validate_config( $merged );

        Agentshell_Test::assert_false( is_wp_error( $validated ), 'the width is accepted' );
        Agentshell_Test::assert_equals( '2px', $validated['design']['layout']['borderWidth'] );
        Agentshell_Test::assert_equals( '2px', $validated['design']['custom_css_vars']['--border-width'] );
        Agentshell_Test::assert_equals( 'solid', $validated['design']['layout']['borderStyle'], 'style is untouched' );
        Agentshell_Test::assert_equals( 'solid', $validated['design']['custom_css_vars']['--border-style'], 'style token is untouched' );
    }
);

Agentshell_Test::run(
    'changing border style writes the geometry and the custom property',
    function () {
        $legacy = config_legacy_fixture();

        $merged = agentshell_unflatten_config( array( '--border-style' => 'dashed' ), $legacy );
        $validated = agentshell_validate_config( $merged );

        Agentshell_Test::assert_false( is_wp_error( $validated ), 'the style is accepted' );
        Agentshell_Test::assert_equals( 'dashed', $validated['design']['layout']['borderStyle'] );
        Agentshell_Test::assert_equals( 'dashed', $validated['design']['custom_css_vars']['--border-style'] );
        Agentshell_Test::assert_equals( '1px', $validated['design']['layout']['borderWidth'], 'width is untouched' );
    }
);

Agentshell_Test::run(
    'a layout-only legacy configuration flattens to the border tokens',
    function () {
        $config = array(
            'design' => array(
                'layout' => array( 'borderWidth' => '3px', 'borderStyle' => 'dotted' ),
            ),
        );

        $flat = agentshell_flatten_config( $config );

        Agentshell_Test::assert_equals( '3px', $flat['--border-width'] );
        Agentshell_Test::assert_equals( 'dotted', $flat['--border-style'] );
    }
);

Agentshell_Test::run(
    'an explicit custom property still wins over the legacy geometry',
    function () {
        $config = array(
            'design' => array(
                'layout' => array( 'borderWidth' => '3px' ),
                'custom_css_vars' => array( '--border-width' => '5px' ),
            ),
        );

        $flat = agentshell_flatten_config( $config );

        Agentshell_Test::assert_equals( '5px', $flat['--border-width'], 'the value the stylesheet reads wins' );
    }
);

Agentshell_Test::run(
    'the GET response exposes the border tokens of a layout-only configuration',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'design' => array(
                'layout' => array( 'borderWidth' => '4px', 'borderStyle' => 'dashed' ),
            ),
        ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( '4px', $response['config']['--border-width'] );
        Agentshell_Test::assert_equals( 'dashed', $response['config']['--border-style'] );
    }
);

Agentshell_Test::run(
    'an unknown design.layout key is still refused',
    function () {
        $config = array(
            'design' => array(
                'layout' => array( 'borderWidth' => '1px', 'borderColour' => '#fff' ),
            ),
        );

        $result = agentshell_validate_config( $config );

        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_layout_key', $result->get_error_code() );
        Agentshell_Test::assert_equals( 'Unknown layout key: borderColour', $result->get_error_message() );
    }
);

Agentshell_Test::run(
    'borderWidth must still be a CSS length',
    function () {
        foreach ( array( 'red', '1px; } body { display: none', '2 px', '' ) as $bad ) {
            $result = agentshell_validate_config( array(
                'design' => array( 'layout' => array( 'borderWidth' => $bad ) ),
            ) );
            Agentshell_Test::assert_instance_of( 'WP_Error', $result, "refused: {$bad}" );
            Agentshell_Test::assert_equals( 'invalid_layout_value', $result->get_error_code() );
        }
    }
);

Agentshell_Test::run(
    'borderStyle must still be one of the four known values',
    function () {
        foreach ( array( 'wavy', 'solid; } body { display: none', '' ) as $bad ) {
            $result = agentshell_validate_config( array(
                'design' => array( 'layout' => array( 'borderStyle' => $bad ) ),
            ) );
            Agentshell_Test::assert_instance_of( 'WP_Error', $result, "refused: {$bad}" );
            Agentshell_Test::assert_equals( 'invalid_layout_value', $result->get_error_code() );
        }
    }
);

Agentshell_Test::run(
    'the top-level layout allowlist is a different place and still refuses borderWidth',
    function () {
        $result = agentshell_validate_config( array(
            'layout' => array( 'borderWidth' => '1px' ),
        ) );

        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_layout_key', $result->get_error_code() );
        Agentshell_Test::assert_equals( 'Unknown layout key: borderWidth', $result->get_error_message() );
    }
);

Agentshell_Test::run(
    'a realistic PUT round trip leaves the stored legacy fields byte-for-byte equal',
    function () {
        agentshell_test_reset();
        $legacy = config_legacy_fixture();
        update_option( 'agentshell_config', $legacy );

        // What the configurator sends for a shadow-preset change.
        $flat = array( '--theme-shadow' => '0 8px 20px -6px rgba(0,0,0,0.2)' );

        $existing  = agentshell_get_config();
        $merged    = agentshell_unflatten_config( $flat, $existing );
        $validated = agentshell_validate_config( $merged );
        Agentshell_Test::assert_false( is_wp_error( $validated ) );
        update_option( 'agentshell_config', $validated );

        $stored    = agentshell_get_config();
        $canonical = agentshell_normalize_zones_config( $legacy );

        Agentshell_Test::assert_equals( $legacy['design']['layout'], $stored['design']['layout'] );
        Agentshell_Test::assert_equals( $canonical['zones'], $stored['zones'] );
        Agentshell_Test::assert_equals( $legacy['custom_css'], $stored['custom_css'] );
        Agentshell_Test::assert_equals( $legacy['widget_overrides'], $stored['widget_overrides'] );
        Agentshell_Test::assert_equals( '0 8px 20px -6px rgba(0,0,0,0.2)', $stored['design']['custom_css_vars']['--theme-shadow'] );
    }
);
