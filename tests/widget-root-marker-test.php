<?php
/**
 * A rendered widget must have exactly one marked root.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The widget runtime finds widget elements by their marker attribute —
 * `data-widget` or `data-widget-id` — and calls the matching `init`
 * function on each, exactly once.
 *
 * The renderer that produces a widget WITHOUT a template emits
 * `<div data-widget-id="…">`, so it is marked. The renderer that
 * produces a widget WITH a template emitted the template verbatim, and
 * nothing required the author to put a marker on it.
 *
 * So a registered widget whose template lacked the marker rendered,
 * looked correct, and was never initialised: no `init(el)` call, no
 * error, no warning, and the tool reported the registration as
 * successful. The widget's JavaScript simply never ran.
 *
 * Measured live against the disposable test bed: such a widget produced
 * zero marked elements on the page and zero initialiser calls.
 *
 * What is asserted here
 * ---------------------
 *   1. A template with no marker gets one on its root element — and on
 *      the ROOT, so `init(el)` receives the element the author wrote.
 *   2. A template that already carries a marker is emitted verbatim:
 *      re-marking it would produce two marked elements and two
 *      initialiser calls for one widget.
 *   3. A template with no element at all is wrapped — the same shape the
 *      no-template path has always produced.
 *   4. The no-template path still produces a marked element.
 *   5. Exactly ONE marked element is produced in every case. Two
 *      marked elements means the widget initialises twice.
 *   6. The marker carries the widget's own id, so the runtime can find
 *      the matching `init` function.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

echo "widget root marker tests\n";

/**
 * The marked elements in a rendered widget.
 *
 * @param string $html
 * @return array
 */
function wrm_markers( $html ) {
    $found = array();
    if ( preg_match_all( '/\bdata-widget(?:-id)?\s*=\s*"([^"]*)"/i', (string) $html, $m ) ) {
        $found = $m[1];
    }
    return $found;
}

/**
 * Render a widget through the theme's renderer.
 *
 * The config is written directly so the fixture is deterministic; the
 * renderer reads the effective config, and a transaction is not open
 * in this suite.
 *
 * @param string $id
 * @param string $template
 * @return string
 */
function wrm_render( $id, $template ) {
    update_option( 'agentshell_config', array(
        'zones'           => array(),
        'widgets'         => array(
            array(
                'id'       => $id,
                'name'     => 'Marker probe',
                'template' => $template,
                'init_js'  => "window.AgentshellWidgets = window.AgentshellWidgets || {}; window.AgentshellWidgets['" . $id . "'] = { init: function(el) { el.setAttribute('data-initialised', '1'); } };",
            ),
        ),
        'widget_overrides' => array(),
    ) );
    return (string) agentshell_render_widget( $id );
}

// ── 1. A template with no marker gets one ───────────────────────────────────

Agentshell_Test::run( 'a template with no marker is marked at its root', function () {
    $html = wrm_render( 'probe-plain', '<div class="my-widget">hello</div>' );

    Agentshell_Test::assert_equals(
        array( 'probe-plain' ),
        wrm_markers( $html ),
        'the rendered widget must carry exactly one marker, naming this widget'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $html, 'class="my-widget"' ),
        "the author's markup must survive: $html"
    );
    Agentshell_Test::assert_true(
        false !== strpos( $html, 'hello' ),
        "the author's content must survive: $html"
    );
} );

Agentshell_Test::run( 'the marker lands on the template ROOT, not on a child', function () {
    $html = wrm_render( 'probe-root', '<section class="outer"><span class="inner">x</span></section>' );

    // The marked element must be the root: `init(el)` receives the
    // element the author wrote, not a descendant of it.
    Agentshell_Test::assert_true(
        (bool) preg_match( '/<section[^>]*data-widget-id="probe-root"/', $html ),
        'the root element must carry the marker: ' . $html
    );
    Agentshell_Test::assert_false(
        (bool) preg_match( '/<span[^>]*data-widget-id/', $html ),
        'no descendant may be marked as well: ' . $html
    );
} );

Agentshell_Test::run( 'a self-closing root element is marked correctly', function () {
    $html = wrm_render( 'probe-selfclose', '<div class="box" />' );
    Agentshell_Test::assert_equals(
        array( 'probe-selfclose' ),
        wrm_markers( $html ),
        'a self-closing root must be marked: ' . $html
    );
} );

Agentshell_Test::run( 'a root element whose attributes contain a > is marked correctly', function () {
    $html = wrm_render( 'probe-gt', '<div data-x="a>b" class="c">t</div>' );
    Agentshell_Test::assert_equals(
        array( 'probe-gt' ),
        wrm_markers( $html ),
        'the marker must land on the root, not be swallowed by a quoted >: ' . $html
    );
    Agentshell_Test::assert_true(
        false !== strpos( $html, 'data-x="a>b"' ),
        'the quoted attribute must survive intact: ' . $html
    );
} );

Agentshell_Test::run( 'a template that begins with a comment is marked at its root', function () {
    $html = wrm_render( 'probe-comment', "<!-- a comment -->\n<div class=\"after\">y</div>" );
    Agentshell_Test::assert_equals(
        array( 'probe-comment' ),
        wrm_markers( $html ),
        'the marker must land on the element, not inside the comment: ' . $html
    );
} );

// ── 2. A template that already carries a marker is left alone ───────────────

Agentshell_Test::run( 'a template that already marks itself is emitted verbatim', function () {
    $html = wrm_render( 'probe-marked', '<div class="m" data-widget="probe-marked">z</div>' );

    // Verbatim means verbatim: the author's attribute is unchanged and
    // no second marker is added.
    Agentshell_Test::assert_true(
        false !== strpos( $html, 'data-widget="probe-marked"' ),
        "the author's own marker must survive: $html"
    );
    Agentshell_Test::assert_false(
        false !== strpos( $html, 'data-widget-id' ),
        'no second marker may be added: ' . $html
    );
    Agentshell_Test::assert_equals(
        1,
        count( wrm_markers( $html ) ),
        'exactly one marked element: ' . $html
    );
} );

Agentshell_Test::run( 'a template marked with data-widget-id is emitted verbatim', function () {
    $html = wrm_render( 'probe-marked2', '<div data-widget-id="probe-marked2">z</div>' );
    Agentshell_Test::assert_equals(
        1,
        count( wrm_markers( $html ) ),
        'exactly one marked element: ' . $html
    );
    Agentshell_Test::assert_false(
        false !== strpos( $html, 'data-widget-id="probe-marked2" data-widget-id=' ),
        'no duplicate marker may be added: ' . $html
    );
} );

// ── 3. A template with no element is wrapped ────────────────────────────────

Agentshell_Test::run( 'a template with no element is wrapped in a marked element', function () {
    $html = wrm_render( 'probe-text', 'just text' );
    Agentshell_Test::assert_equals(
        array( 'probe-text' ),
        wrm_markers( $html ),
        'the text must be wrapped in a marked element: ' . $html
    );
    Agentshell_Test::assert_true(
        false !== strpos( $html, 'just text' ),
        'the text must survive: ' . $html
    );
} );

// ── 4. The no-template path still marks the element ────────────────────────

Agentshell_Test::run( 'a widget with no template still produces a marked element', function () {
    update_option( 'agentshell_config', array(
        'zones'           => array(),
        'widgets'         => array(
            array(
                'id'      => 'probe-notemplate',
                'name'    => 'No template',
                'init_js' => "window.AgentshellWidgets = window.AgentshellWidgets || {};",
            ),
        ),
        'widget_overrides' => array(),
    ) );
    $html = (string) agentshell_render_widget( 'probe-notemplate' );
    Agentshell_Test::assert_equals(
        array( 'probe-notemplate' ),
        wrm_markers( $html ),
        'the no-template path must still mark its element: ' . $html
    );
} );

// ── 5. Exactly one marked element, always ───────────────────────────────────

Agentshell_Test::run( 'every template shape produces exactly one marked element', function () {
    // For a template the author marked themselves, the marker's VALUE is
    // the author's: it selects which initialiser the runtime calls, so
    // the renderer must not rewrite it. The renderer's own marker always
    // names the widget.
    $templates = array(
        'plain'       => array( '<div class="a">1</div>', 'probe-plain' ),
        'nested'      => array( '<div class="a"><div class="b">2</div></div>', 'probe-nested' ),
        'marked'      => array( '<div data-widget="x">3</div>', 'x' ),
        'text'        => array( 'plain text', 'probe-text' ),
        'comment'     => array( '<!-- c -->', 'probe-comment' ),
        'selfclose'   => array( '<hr />', 'probe-selfclose' ),
        'attr_gt'     => array( '<div title="a>b">4</div>', 'probe-attr_gt' ),
        'multiline'   => array( "<div\n  class=\"multi\"\n>5</div>", 'probe-multiline' ),
    );
    foreach ( $templates as $name => $spec ) {
        list( $template, $expected_marker ) = $spec;
        $html = wrm_render( 'probe-' . $name, $template );
        $markers = wrm_markers( $html );
        Agentshell_Test::assert_equals(
            1,
            count( $markers ),
            "exactly one marked element for template '$name': $html"
        );
        Agentshell_Test::assert_equals(
            $expected_marker,
            $markers[0] ?? '',
            "the marker must be right for template '$name': $html"
        );
    }
} );

// ── 6. The helper is available to the blocks plugin ─────────────────────────

Agentshell_Test::run( 'the marking helper is a theme function', function () {
    Agentshell_Test::assert_true(
        function_exists( 'agentshell_mark_widget_root' ),
        'the theme must own the rule, so the blocks plugin can delegate to it'
    );
} );

// ── Report ──────────────────────────────────────────────────────────────────

// No exit: run-all.php requires every suite into one process, and exiting
// would end the aggregate run before the remaining suites execute.
echo "\n";
echo "widget root marker: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
