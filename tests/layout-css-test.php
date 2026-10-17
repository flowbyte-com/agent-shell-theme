<?php
/**
 * Layout CSS regression tests.
 *
 * The layout-versatility assessment surfaced that
 * `agentshell_set_layout` reported success without producing an
 * effective layout change: the tool stored grid_areas / grid_gap /
 * grid_padding in config['layout'] but no renderer read them. The
 * fix is `agentshell_layout_css()`, a pure function called from
 * `agentshell_inject_saved_styles` that turns the stored values into
 * the CSS string the page needs.
 *
 * These tests pin the pure function's behaviour. They would not pass
 * before the fix existed (the function would be undefined); the
 * "empty config emits no CSS" case is the negative-control that
 * proves the function does not stomp on the flex-column default
 * from style.css Section 3 when no layout values are set.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "layout-css regression tests\n";

Agentshell_Test::run(
    'empty config produces no layout CSS — flex default in style.css stays in effect',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array() );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_equals( '', $css );
    }
);

Agentshell_Test::run(
    'breakpoints-only config still emits no layout CSS — backwards compatible',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'breakpoints' => array( 'mobile' => '0px', 'tablet' => '768px', 'desktop' => '1024px' ),
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_equals( '', $css );
    }
);

Agentshell_Test::run(
    'grid_areas.mobile emits display:grid + grid-template-areas + zone mapping',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'grid_areas' => array(
                    'mobile' => array( 'header', 'main', 'footer' ),
                ),
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_true( false !== strpos( $css, '#agentshell-root' ), 'expected #agentshell-root rule' );
        Agentshell_Test::assert_true( false !== strpos( $css, 'display: grid' ), 'expected display: grid' );
        Agentshell_Test::assert_true( false !== strpos( $css, 'grid-template-areas: "header" "main" "footer"' ), 'expected quoted-rows protocol' );
        Agentshell_Test::assert_true( false !== strpos( $css, '#zone-header { grid-area: header; }' ), 'expected zone-area mapping for header' );
        Agentshell_Test::assert_true( false !== strpos( $css, '#zone-main { grid-area: main; }' ), 'expected zone-area mapping for main' );
        Agentshell_Test::assert_true( false !== strpos( $css, '#zone-footer { grid-area: footer; }' ), 'expected zone-area mapping for footer' );
    }
);

Agentshell_Test::run(
    'grid_areas.tablet wraps in @media(min-width: breakpoint) using stored breakpoint value',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'breakpoints' => array( 'mobile' => '0px', 'tablet' => '900px', 'desktop' => '1200px' ),
                'grid_areas'  => array(
                    'mobile'  => array( 'header', 'main', 'footer' ),
                    'tablet'  => array( 'header header', 'main main', 'footer footer' ),
                ),
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_true( false !== strpos( $css, '@media (min-width: 900px)' ), 'expected tablet media query with stored breakpoint' );
        Agentshell_Test::assert_true( false !== strpos( $css, 'grid-template-areas: "header header" "main main" "footer footer"' ), 'expected tablet grid-template-areas' );
    }
);

Agentshell_Test::run(
    'grid_gap and grid_padding land inside the #agentshell-root rule',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'grid_areas'   => array( 'mobile' => array( 'header', 'main', 'footer' ) ),
                'grid_gap'     => '1.5rem',
                'grid_padding' => '1rem',
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_true( false !== strpos( $css, 'gap: 1.5rem;' ), 'expected gap inside #agentshell-root' );
        Agentshell_Test::assert_true( false !== strpos( $css, 'padding: 1rem;' ), 'expected padding inside #agentshell-root' );
    }
);

Agentshell_Test::run(
    'grid_gap-only (no grid_areas) emits only global rule, no display: grid override',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'grid_gap' => '2rem',
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_true( false !== strpos( $css, 'gap: 2rem;' ), 'expected global gap rule' );
        Agentshell_Test::assert_false( false !== strpos( $css, 'display: grid' ), 'should NOT force display:grid when no grid_areas is set' );
    }
);

Agentshell_Test::run(
    'desktop breakpoint uses 1024px default when no user override',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'grid_areas' => array(
                    'desktop' => array( 'header header header header', 'main main main main', 'footer footer footer footer' ),
                ),
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_true( false !== strpos( $css, '@media (min-width: 1024px)' ), 'expected desktop default 1024px' );
    }
);

Agentshell_Test::run(
    'grid_areas with empty array for a breakpoint does not emit its rule',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'grid_areas' => array(
                    'mobile'  => array( 'header', 'main', 'footer' ),
                    'tablet'  => array(),
                    'desktop' => array(),
                ),
            ),
        ) );

        $css = agentshell_layout_css();

        Agentshell_Test::assert_false( false !== strpos( $css, '@media' ), 'no @media queries when only mobile is set' );
        Agentshell_Test::assert_true( false !== strpos( $css, 'grid-template-areas: "header" "main" "footer"' ), 'mobile rule still emitted' );
    }
);

Agentshell_Test::run(
    'validator rejects unknown key in config[layout]',
    function () {
        $config = array(
            'layout' => array(
                'breakpoints'    => array( 'mobile' => '0px' ),
                'totally_fake'   => 'value',
            ),
        );

        $result = agentshell_validate_config( $config );

        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_layout_key', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'validator accepts all four known layout keys',
    function () {
        $config = array(
            'layout' => array(
                'breakpoints'   => array( 'mobile' => '0px', 'tablet' => '768px', 'desktop' => '1024px' ),
                'grid_areas'    => array( 'mobile' => array( 'a', 'b', 'c' ) ),
                'grid_gap'      => '1rem',
                'grid_padding'  => '0',
            ),
        );

        $result = agentshell_validate_config( $config );

        Agentshell_Test::assert_equals( $config, $result );
    }
);

Agentshell_Test::run(
    'agentshell_get_layout_config deep-merges user breakpoints over defaults',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'layout' => array(
                'breakpoints' => array( 'tablet' => '900px' ),
            ),
        ) );

        $layout = agentshell_get_layout_config();

        Agentshell_Test::assert_equals( '0px',    $layout['breakpoints']['mobile'] );
        Agentshell_Test::assert_equals( '900px',  $layout['breakpoints']['tablet'] );
        Agentshell_Test::assert_equals( '1024px', $layout['breakpoints']['desktop'] );
    }
);
