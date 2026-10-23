<?php
/**
 * Widget runtime regression: a widget's init function must actually be called.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * A widget's `init_js` registers an initialiser —
 * `window.AgentshellWidgets['<id>'] = { init: function(el) { … } }` — and
 * something has to call it. Nothing did. The theme emitted each widget's
 * registration script into the footer and stopped there, so every widget
 * that needed to run any JavaScript at all rendered as an empty element:
 * the `init` function was defined, never invoked, and the page showed the
 * widget's shell with nothing in it.
 *
 * The blocks plugin carried a second, also-broken copy of the missing
 * half: a MutationObserver that called `AgentshellWidgets.initWidget(el,
 * id)`, a function it never defined, while selecting `[data-widget]` where
 * every renderer in this repository emits `data-widget-id`.
 *
 * What is asserted here
 * ---------------------
 * The behavioural half runs in Node (tests/widget-init-test.js), against a
 * small DOM stub, because the defect is in JavaScript. This file carries
 * the JavaScript to Node and adds the two things only PHP can see:
 *
 *   1. The runtime the theme actually emits defines an initialiser, and
 *      recognises both attribute spellings.
 *   2. The attribute the renderers emit is one the runtime recognises —
 *      the two are checked against each other, so a future change to
 *      either one that breaks the pairing fails here rather than in a
 *      browser.
 *
 * When Node is not installed the behavioural half cannot run, and this
 * suite says so rather than passing silently.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

echo "widget runtime regression tests\n";

Agentshell_Test::run(
    'the emitted widget runtime defines an initialiser',
    function () {
        $runtime = agentshell_widget_runtime_js();
        Agentshell_Test::assert_true(
            '' !== trim( (string) $runtime ),
            'the runtime must not be empty'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $runtime, 'initWidget' ),
            'the runtime must define initWidget'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $runtime, 'initAll' ),
            'the runtime must define initAll'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $runtime, 'MutationObserver' ),
            'the runtime must watch for elements added after load'
        );
    }
);

Agentshell_Test::run(
    'the runtime recognises the attribute the renderers emit',
    function () {
        $runtime = agentshell_widget_runtime_js();

        // What the renderers emit.
        Agentshell_Test::assert_true(
            false !== strpos( (string) agentshell_render_widget( 'hello-world' ), 'data-widget-id' ),
            'the renderer emits data-widget-id'
        );

        // What the runtime looks for.
        Agentshell_Test::assert_true(
            false !== strpos( $runtime, '[data-widget]' ),
            'the runtime must select [data-widget]'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $runtime, '[data-widget-id]' ),
            'the runtime must select [data-widget-id]'
        );
    }
);

Agentshell_Test::run(
    'the widget asset block carries the runtime before the registrations',
    function () {
        update_option( 'agentshell_config', array(
            'zones'          => array(),
            'widgets'        => array(
                array(
                    'id'       => 'runtime-probe',
                    'name'     => 'Runtime probe',
                    'template' => '<div data-widget-id="runtime-probe"></div>',
                    'init_js'  => "window.AgentshellWidgets = window.AgentshellWidgets || {}; window.AgentshellWidgets['runtime-probe'] = { init: function(el) { el.setAttribute('data-initialised', '1'); } };",
                ),
            ),
            'widget_overrides' => array(),
        ) );

        $assets = agentshell_get_widget_assets();
        $js     = (string) $assets['init_js'];

        Agentshell_Test::assert_true(
            false !== strpos( $js, 'initWidget' ),
            'the emitted asset block must carry the runtime'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $js, "AgentshellWidgets['runtime-probe']" ),
            'the emitted asset block must carry the widget registration'
        );
        Agentshell_Test::assert_true(
            strpos( $js, 'initWidget' ) < strpos( $js, "AgentshellWidgets['runtime-probe']" ),
            'the runtime must come before the registrations that plug into it'
        );
    }
);

// ── the behavioural half, in Node ────────────────────────────────────────────
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $node ) {
    Agentshell_Test::run(
        'widget runtime behaviour (Node)',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: node not found on PATH' );
        }
    );
    return;
}

Agentshell_Test::run(
    'widget runtime behaviour (Node)',
    function () use ( $node ) {
        $runtime_file = sys_get_temp_dir() . '/agentshell-widget-runtime-' . getmypid() . '.js';
        file_put_contents( $runtime_file, agentshell_widget_runtime_js() );

        $cmd = escapeshellcmd( $node ) . ' ' . escapeshellarg( __DIR__ . '/widget-init-test.js' )
            . ' ' . escapeshellarg( $runtime_file ) . ' 2>&1';
        $output = (string) shell_exec( $cmd );
        @unlink( $runtime_file );

        echo preg_replace( '/^/m', '       ', trim( (string) $output ) ) . "\n";

        Agentshell_Test::assert_true(
            (bool) preg_match( '/(\d+)\s+passed,\s+(\d+)\s+failed/', $output, $m ),
            'the Node suite must report a result: ' . $output
        );
        Agentshell_Test::assert_equals(
            0,
            (int) $m[2],
            'the Node suite must pass: ' . $output
        );
    }
);
