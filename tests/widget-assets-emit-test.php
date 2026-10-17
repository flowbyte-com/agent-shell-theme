<?php
/**
 * Widget asset emission regression tests.
 *
 * The layout-versatility assessment surfaced that `template-parts/widgets.php`
 * is never loaded. The function it was supposed to call,
 * agentshell_get_widget_assets(), worked correctly — but nothing called
 * it, so any widget registered with `css` or `init_js` was silently
 * inert. The fix lives in functions.php: two new wp_head and wp_footer
 * hooks (registered without touching the protected header.php,
 * footer.php, or template-parts/widgets.php) emit the widget CSS into
 * <head> and the init JS into <footer>.
 *
 * The pure function agentshell_widget_assets_head_css() is the unit
 * under test. The hook-registration check verifies the production
 * dispatch path is wired (the test bootstrap's do_action() is a
 * no-op, so we read the recorded callbacks directly from the filter
 * registry). Live verification on wp-modernize confirms the actual
 * <style id='agentshell-widget-css'> tag appears in the rendered HTML.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "widget-assets-emit regression tests\n";

Agentshell_Test::run(
    'bilateral merge: file-based hello-world widget CSS is in the output',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'widgets' => array() ) );

        $css = agentshell_widget_assets_head_css();

        // The widgets/.index.json ships hello-world, which the bilateral
        // merge (functions.php agentshell_get_widget_registry) brings in
        // even when the config has no widgets of its own. The pre-existing
        // bug was that none of this CSS ever reached the page; this test
        // proves the file-based path is now plumbed through the emission
        // function.
        Agentshell_Test::assert_true( false !== strpos( $css, '/* Widget: hello-world */' ) );
        Agentshell_Test::assert_true( false !== strpos( $css, '.hello-widget { font-weight: bold;' ) );
    }
);

Agentshell_Test::run(
    'config-registered widget with css: head CSS contains the widget rules',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'widgets' => array(
                array(
                    'id'   => 'my-test-widget',
                    'name' => 'My Test Widget',
                    'css'  => '.my-test-widget { color: red; padding: 0.5rem; }',
                ),
            ),
        ) );

        $css = agentshell_widget_assets_head_css();

        Agentshell_Test::assert_true( false !== strpos( $css, '/* Widget: my-test-widget */' ), 'expected widget comment marker' );
        Agentshell_Test::assert_true( false !== strpos( $css, '.my-test-widget { color: red; padding: 0.5rem; }' ), 'expected widget CSS body' );
    }
);

Agentshell_Test::run(
    'multiple widgets: all CSS rules appear in the returned string',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'widgets' => array(
                array( 'id' => 'widget-a', 'name' => 'A', 'css' => '.widget-a { color: blue; }' ),
                array( 'id' => 'widget-b', 'name' => 'B', 'css' => '.widget-b { color: green; }' ),
            ),
        ) );

        $css = agentshell_widget_assets_head_css();

        Agentshell_Test::assert_true( false !== strpos( $css, '.widget-a { color: blue; }' ) );
        Agentshell_Test::assert_true( false !== strpos( $css, '.widget-b { color: green; }' ) );
    }
);

Agentshell_Test::run(
    'init_js is wrapped in a try/catch and tagged with the widget id',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'widgets' => array(
                array(
                    'id'      => 'js-widget',
                    'name'    => 'JS Widget',
                    'init_js' => 'document.body.classList.add("js-widget-ran");',
                ),
            ),
        ) );

        $js = agentshell_widget_assets_footer_js();

        Agentshell_Test::assert_true( false !== strpos( $js, '/* Widget: js-widget */' ), 'expected widget comment marker' );
        Agentshell_Test::assert_true( false !== strpos( $js, '(function() {' ), 'expected IIFE wrapper' );
        Agentshell_Test::assert_true( false !== strpos( $js, 'try' ), 'expected try/catch error isolation' );
        Agentshell_Test::assert_true( false !== strpos( $js, 'document.body.classList.add("js-widget-ran");' ) );
    }
);

Agentshell_Test::run(
    'wp_head action has agentshell_emit_widget_assets_head registered',
    function () {
        $callbacks = $GLOBALS['_agentshell_test_filters']['wp_head'] ?? array();
        Agentshell_Test::assert_true(
            in_array( 'agentshell_emit_widget_assets_head', $callbacks, true ),
            'wp_head must dispatch to agentshell_emit_widget_assets_head'
        );
    }
);

Agentshell_Test::run(
    'wp_footer action has agentshell_emit_widget_assets_footer registered',
    function () {
        $callbacks = $GLOBALS['_agentshell_test_filters']['wp_footer'] ?? array();
        Agentshell_Test::assert_true(
            in_array( 'agentshell_emit_widget_assets_footer', $callbacks, true ),
            'wp_footer must dispatch to agentshell_emit_widget_assets_footer'
        );
    }
);

Agentshell_Test::run(
    'config-registered widget without css does not duplicate the head block',
    function () {
        agentshell_test_reset();
        // The file-based hello-world still has CSS, so the head block is
        // non-empty; this test only asserts that the config-registered
        // widget that lacks CSS does not introduce a second empty wrapper.
        update_option( 'agentshell_config', array(
            'widgets' => array(
                array( 'id' => 'no-css', 'name' => 'No CSS', 'init_js' => 'console.log(1);' ),
            ),
        ) );

        ob_start();
        agentshell_emit_widget_assets_head();
        $output = ob_get_clean();

        // Exactly one <style id='agentshell-widget-css'> wrapper, not two.
        $count = substr_count( $output, "<style id='agentshell-widget-css'>" );
        Agentshell_Test::assert_equals( 1, $count );
    }
);

Agentshell_Test::run(
    'head emission wraps the CSS in <style id="agentshell-widget-css">',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'widgets' => array(
                array(
                    'id'   => 'wrap-test',
                    'name' => 'Wrap Test',
                    'css'  => '.wrap-test { margin: 1rem; }',
                ),
            ),
        ) );

        ob_start();
        agentshell_emit_widget_assets_head();
        $output = ob_get_clean();

        Agentshell_Test::assert_true( false !== strpos( $output, "<style id='agentshell-widget-css'>" ) );
        Agentshell_Test::assert_true( false !== strpos( $output, '.wrap-test { margin: 1rem; }' ) );
    }
);
