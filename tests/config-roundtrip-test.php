<?php
/**
 * custom_css / custom_js GET response + PUT round-trip tests.
 *
 * The layout-versatility assessment surfaced that the GET
 * /wp/v2/agentshell/config response omitted custom_css and
 * custom_js. The PUT path stored them correctly (via
 * agentshell_unflatten_config at functions.php:1808), so a round
 * trip did not actually lose data — but the agent reading the GET
 * had no way to see what was stored, and had no way to verify a
 * later partial PUT (e.g. just changing zones) had not silently
 * wiped the custom_css.
 *
 * The fix is in agentshell_build_config_get_response: the GET
 * payload now includes custom_css, custom_js, the byte counters,
 * and config['layout']. These tests pin that surface.
 *
 * The round-trip test exercises the unflatten merge directly: a
 * partial PUT body containing only 'zones' must leave custom_css
 * intact. The existing flatten-test.php already covers the
 * unflatten merge for the simple case; this file focuses on the
 * user-facing "I can read what I wrote" contract.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "config-roundtrip regression tests\n";

Agentshell_Test::run(
    'GET response includes custom_css when it is stored',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'custom_css' => 'body { background: #fafaf9; }',
        ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( 'body { background: #fafaf9; }', $response['custom_css'] );
        Agentshell_Test::assert_equals( strlen( 'body { background: #fafaf9; }' ), $response['custom_css_bytes'] );
    }
);

Agentshell_Test::run(
    'GET response includes custom_js when it is stored',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'custom_js'  => 'console.log("agent-loaded");',
        ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( 'console.log("agent-loaded");', $response['custom_js'] );
        Agentshell_Test::assert_equals( strlen( 'console.log("agent-loaded");' ), $response['custom_js_bytes'] );
    }
);

Agentshell_Test::run(
    'GET response includes layout when it is stored',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'breakpoints' => array( 'mobile' => '0px', 'tablet' => '900px' ),
                'grid_areas'  => array( 'mobile' => array( 'header', 'main', 'footer' ) ),
            ),
        ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals(
            array(
                'breakpoints' => array( 'mobile' => '0px', 'tablet' => '900px' ),
                'grid_areas'  => array( 'mobile' => array( 'header', 'main', 'footer' ) ),
            ),
            $response['layout']
        );
    }
);

Agentshell_Test::run(
    'GET response has empty custom_css and custom_js when none stored',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( '', $response['custom_css'] );
        Agentshell_Test::assert_equals( '', $response['custom_js'] );
        Agentshell_Test::assert_equals( 0, $response['custom_css_bytes'] );
        Agentshell_Test::assert_equals( 0, $response['custom_js_bytes'] );
    }
);

Agentshell_Test::run(
    'GET response has empty layout when none stored',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( array(), $response['layout'] );
    }
);

Agentshell_Test::run(
    'round-trip: partial PUT with only zones does not drop stored custom_css',
    function () {
        agentshell_test_reset();
        $existing = array(
            'custom_css' => '/* agent-set rule */ .x { color: red; }',
            'custom_js'  => '/* agent-set JS */',
            'zones'      => array(
                array(
                    'id'   => 'header',
                    'slots' => array( 'left' => array(), 'center' => array(), 'right' => array() ),
                ),
            ),
        );
        update_option( 'agentshell_config', $existing );

        // Simulate a PUT that updates only the header zone — custom_css
        // and custom_js must survive the merge.
        $merged = agentshell_unflatten_config(
            array( 'zones' => array(
                array(
                    'id'   => 'header',
                    'slots' => array(
                        'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                        'center' => array(),
                        'right'  => array(),
                    ),
                ),
            ) ),
            $existing
        );

        Agentshell_Test::assert_equals(
            '/* agent-set rule */ .x { color: red; }',
            $merged['custom_css'],
            'custom_css must survive a partial PUT that only sets zones'
        );
        Agentshell_Test::assert_equals(
            '/* agent-set JS */',
            $merged['custom_js'],
            'custom_js must survive a partial PUT that only sets zones'
        );
    }
);

Agentshell_Test::run(
    'round-trip: explicit empty custom_css in PUT clears the stored value',
    function () {
        agentshell_test_reset();
        $existing = array(
            'custom_css' => '/* this should be cleared */',
        );
        update_option( 'agentshell_config', $existing );

        // An agent that wants to clear custom_css sends an empty string.
        // The merge must accept that and replace, not preserve.
        $merged = agentshell_unflatten_config(
            array( 'custom_css' => '' ),
            $existing
        );

        Agentshell_Test::assert_equals( '', $merged['custom_css'] );
    }
);

Agentshell_Test::run(
    'round-trip: explicit custom_css in PUT overwrites the stored value',
    function () {
        agentshell_test_reset();
        $existing = array(
            'custom_css' => '/* old rule */',
        );
        update_option( 'agentshell_config', $existing );

        $merged = agentshell_unflatten_config(
            array( 'custom_css' => '/* new rule */' ),
            $existing
        );

        Agentshell_Test::assert_equals( '/* new rule */', $merged['custom_css'] );
    }
);

Agentshell_Test::run(
    'GET response: byte counter matches the actual custom_css length',
    function () {
        agentshell_test_reset();
        $css = str_repeat( '.x { color: red; } ', 100 ); // ~1800 chars
        update_option( 'agentshell_config', array( 'custom_css' => $css ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( strlen( $css ), $response['custom_css_bytes'] );
        Agentshell_Test::assert_equals( $css, $response['custom_css'] );
    }
);

Agentshell_Test::run(
    'GET response: byte counter helps the agent know proximity to 10KB cap',
    function () {
        agentshell_test_reset();
        $css = str_repeat( 'a', 9999 ); // one byte under the cap
        update_option( 'agentshell_config', array( 'custom_css' => $css ) );

        $response = agentshell_build_config_get_response();

        Agentshell_Test::assert_equals( 9999, $response['custom_css_bytes'] );
    }
);

Agentshell_Test::run(
    'GET response: 10KB cap on custom_css is enforced on the validator side',
    function () {
        agentshell_test_reset();
        $css = str_repeat( 'a', 10001 ); // one byte over the cap

        $result = agentshell_validate_config( array( 'custom_css' => $css ) );

        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'custom_css_too_large', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'GET response: 10KB cap on custom_js is enforced on the validator side',
    function () {
        agentshell_test_reset();
        $js = str_repeat( 'a', 10001 ); // one byte over the cap

        $result = agentshell_validate_config( array( 'custom_js' => $js ) );

        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'custom_js_too_large', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'GET response: existing custom_css survives a layout-only PUT',
    function () {
        agentshell_test_reset();
        $existing = array(
            'custom_css' => '/* important rule */',
            'layout'     => array(),
        );
        update_option( 'agentshell_config', $existing );

        $merged = agentshell_unflatten_config(
            array( 'layout' => array(
                'grid_areas' => array( 'mobile' => array( 'header', 'main', 'footer' ) ),
            ) ),
            $existing
        );

        Agentshell_Test::assert_equals( '/* important rule */', $merged['custom_css'] );
        Agentshell_Test::assert_equals(
            array( 'grid_areas' => array( 'mobile' => array( 'header', 'main', 'footer' ) ) ),
            $merged['layout']
        );
    }
);
