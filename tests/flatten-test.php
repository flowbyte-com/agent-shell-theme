<?php
/**
 * Flatten / unflatten roundtrip and custom-variable precedence tests.
 *
 * The flatten map is the bridge between the nested config stored in
 * agentshell_config and the flat CSS-variable keyspace the configurator
 * panel edits. Bugs in this map silently break panel mutations or cause
 * round-trips to lose user changes — exactly the class of bug fixed by
 * commit 84aea40 (set_css_var precedence). This file pins that fix.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "flatten/unflatten regression tests\n";

Agentshell_Test::run(
    'flat-to-nested-to-flat roundtrip preserves every value',
    function () {
        $flat = array(
            '--theme-bg'    => '#ffffff',
            '--theme-text'  => '#000000',
            '--font-base'   => 'system-ui',
            '--spacing-base'=> '1rem',
            '--radius-base' => '8px',
        );

        $nested   = agentshell_unflatten_config( $flat, array() );
        $roundtrip = agentshell_flatten_config( $nested );

        foreach ( $flat as $key => $value ) {
            Agentshell_Test::assert_equals( $value, $roundtrip[ $key ] ?? null );
        }
    }
);

Agentshell_Test::run(
    'unflatten merges into existing config rather than replacing it',
    function () {
        $existing = array(
            'design'  => array(
                'colors' => array(
                    'background' => '#fff',
                    'text'       => '#111',
                ),
            ),
            'zones'   => array(
                array( 'id' => 'header' ),
            ),
        );

        $merged = agentshell_unflatten_config(
            array( '--theme-bg' => '#000' ),
            $existing
        );

        // Pre-existing keys must survive the merge.
        Agentshell_Test::assert_equals( '#111', $merged['design']['colors']['text'] );
        Agentshell_Test::assert_equals( array( array( 'id' => 'header' ) ), $merged['zones'] );
        // New key landed.
        Agentshell_Test::assert_equals( '#000', $merged['design']['colors']['background'] );
    }
);

Agentshell_Test::run(
    'explicit --var set via custom_css_vars sticks over legacy palette mapping',
    function () {
        // Regression for commit 84aea40: when an agent calls set_css_var
        // for a key that also exists in the legacy map (e.g. --theme-bg),
        // the explicit custom_css_vars value must win on flatten.
        $config = array(
            'design' => array(
                'colors' => array(
                    'background' => '#fff',       // legacy map path
                ),
                'custom_css_vars' => array(
                    '--theme-bg' => '#000',        // explicit override
                ),
            ),
        );

        $flat = agentshell_flatten_config( $config );

        Agentshell_Test::assert_equals( '#000', $flat['--theme-bg'] );
    }
);

Agentshell_Test::run(
    'a change is not shadowed by the custom variable that used to win',
    function () {
        // A configuration that carries a legacy custom variable for a
        // palette key: the flatten prefers it, so a change written to the
        // palette path alone would be stored and never rendered. The editor
        // would show a colour the site does not show.
        $config = array(
            'design' => array(
                'colors' => array(
                    'primary' => '#0e1013',
                ),
                'custom_css_vars' => array(
                    '--theme-header-bg' => '#0e1013',
                ),
            ),
        );

        $merged = agentshell_unflatten_config(
            array( '--theme-header-bg' => '#16a34a' ),
            $config
        );

        Agentshell_Test::assert_equals( '#16a34a', $merged['design']['colors']['primary'], 'the palette path is written' );
        Agentshell_Test::assert_equals( '#16a34a', $merged['design']['custom_css_vars']['--theme-header-bg'],
            'the custom variable that shadows it is written with the same value' );

        $flat = agentshell_flatten_config( $merged );
        Agentshell_Test::assert_equals( '#16a34a', $flat['--theme-header-bg'],
            'and the change is the one the site renders' );
    }
);

Agentshell_Test::run(
    'a change to a key with no custom variable leaves the custom variables alone',
    function () {
        $config = array(
            'design' => array(
                'colors' => array( 'primary' => '#0e1013' ),
                'custom_css_vars' => array( '--theme-footer-bg' => '#0e1013' ),
            ),
        );

        $merged = agentshell_unflatten_config( array( '--theme-header-bg' => '#16a34a' ), $config );

        Agentshell_Test::assert_equals( '#0e1013', $merged['design']['custom_css_vars']['--theme-footer-bg'],
            'an unrelated custom variable is untouched' );
        Agentshell_Test::assert_false(
            array_key_exists( '--theme-header-bg', $merged['design']['custom_css_vars'] ),
            'no custom variable is invented for a key that had none'
        );
    }
);

Agentshell_Test::run(
    'unset custom_css_vars preserves legacy map values',
    function () {
        $config = array(
            'design' => array(
                'colors' => array(
                    'background' => '#fff',
                ),
                // no custom_css_vars
            ),
        );

        $flat = agentshell_flatten_config( $config );

        Agentshell_Test::assert_equals( '#fff', $flat['--theme-bg'] );
    }
);

Agentshell_Test::run(
    'wildcard --* keys in custom_css_vars pass through flatten',
    function () {
        $config = array(
            'design' => array(
                'custom_css_vars' => array(
                    '--my-arbitrary-var' => '12px',
                    '--theme-gutter'     => '24px',
                ),
            ),
        );

        $flat = agentshell_flatten_config( $config );

        Agentshell_Test::assert_equals( '12px', $flat['--my-arbitrary-var'] );
        Agentshell_Test::assert_equals( '24px', $flat['--theme-gutter'] );
    }
);

Agentshell_Test::run(
    'custom_css and custom_js land in the unflattened config',
    function () {
        $flat = array(
            'custom_css' => 'body{color:red}',
            'custom_js'  => 'console.log(1)',
        );

        $merged = agentshell_unflatten_config( $flat, array() );

        Agentshell_Test::assert_equals( 'body{color:red}', $merged['custom_css'] );
        Agentshell_Test::assert_equals( 'console.log(1)', $merged['custom_js'] );
    }
);