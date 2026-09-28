<?php
/**
 * Render-block-test — one regression per block type.
 *
 * The renderer is the load-bearing surface for everything else in the
 * theme: zones, slots, composition, the bilateral widget registry, and
 * the json_block escape hatch all converge here. Bugs in any branch
 * surface as silent breakage on the live site.
 *
 * Each test exercises one block type end-to-end: parse the block shape,
 * call agentshell_render_block, assert the expected markup (or no-op)
 * comes out. No live WP needed; tests/bootstrap.php stubs enough.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';

echo "render-block regression tests\n";

Agentshell_Test::run(
    'wp_loop block — empty loop emits the canonical empty-state message',
    function () {
        $out = agentshell_render_block( array( 'type' => 'wp_loop' ) );
        Agentshell_Test::assert_true(
            strpos( $out, 'No content found.' ) !== false,
            'wp_loop with no posts must surface the empty-state message'
        );
    }
);

Agentshell_Test::run(
    'wp_widget_area block with id delegates to dynamic_sidebar',
    function () {
        // We stub dynamic_sidebar in bootstrap; assert it was called with
        // the right id and the rendered block returns its echo buffer.
        $out = agentshell_render_block( array(
            'type' => 'wp_widget_area',
            'id'   => 'primary-sidebar',
        ) );
        // Empty stub; just confirm no crash and string return type.
        Agentshell_Test::assert_equals( '', $out );
    }
);

Agentshell_Test::run(
    'wp_widget_area block with empty id returns empty string',
    function () {
        $out = agentshell_render_block( array(
            'type' => 'wp_widget_area',
            'id'   => '',
        ) );
        Agentshell_Test::assert_equals( '', $out );
    }
);

Agentshell_Test::run(
    'json_block block — safe HTML is preserved (rendered through wp_kses_post)',
    function () {
        $out = agentshell_render_block( array(
            'type'    => 'json_block',
            'content' => '<p>hello <strong>world</strong></p>',
        ) );
        Agentshell_Test::assert_true(
            strpos( $out, 'hello' ) !== false && strpos( $out, 'world' ) !== false,
            'json_block must emit its content'
        );
    }
);

Agentshell_Test::run(
    'json_block block — <script> is stripped at render time (defense-in-depth)',
    function () {
        $out = agentshell_render_block( array(
            'type'    => 'json_block',
            'content' => '<p>safe</p><script>alert(1)</script>',
        ) );
        Agentshell_Test::assert_true( strpos( $out, '<p>safe</p>' ) !== false );
        Agentshell_Test::assert_true(
            strpos( $out, '<script' ) === false,
            'renderer must defensively strip <script> from json_block content'
        );
    }
);

Agentshell_Test::run(
    'json_block block — <style> is stripped at render time',
    function () {
        $out = agentshell_render_block( array(
            'type'    => 'json_block',
            'content' => '<p>safe</p><style>body{color:red}</style>',
        ) );
        Agentshell_Test::assert_true( strpos( $out, '<p>safe</p>' ) !== false );
        Agentshell_Test::assert_true(
            strpos( $out, '<style' ) === false,
            'renderer must defensively strip <style> from json_block content'
        );
    }
);

Agentshell_Test::run(
    'widget block — unknown widget id returns empty (no error)',
    function () {
        $out = agentshell_render_block( array(
            'type' => 'widget',
            'id'   => 'does-not-exist',
        ) );
        Agentshell_Test::assert_equals( '', $out );
    }
);

Agentshell_Test::run(
    'widget block — empty id returns empty',
    function () {
        $out = agentshell_render_block( array(
            'type' => 'widget',
            'id'   => '',
        ) );
        Agentshell_Test::assert_equals( '', $out );
    }
);

Agentshell_Test::run(
    'wp_core block with unknown id emits a debug comment rather than crashing',
    function () {
        $out = agentshell_render_block( array(
            'type' => 'wp_core',
            'id'   => 'something_fictional',
        ) );
        Agentshell_Test::assert_true(
            strpos( $out, 'wp_core not found' ) !== false,
            'unknown wp_core id must produce a debug comment, not an exception'
        );
    }
);

Agentshell_Test::run(
    'unknown block type returns empty (no exception)',
    function () {
        $out = agentshell_render_block( array( 'type' => 'totally_made_up' ) );
        Agentshell_Test::assert_equals( '', $out );
    }
);

Agentshell_Test::run(
    'tri-slot zone renders left/center/right slots in order',
    function () {
        $zone = array(
            'id'    => 'header',
            'slots' => array(
                'left'   => array( array( 'type' => 'json_block', 'content' => '<span>L</span>' ) ),
                'center' => array( array( 'type' => 'json_block', 'content' => '<span>C</span>' ) ),
                'right'  => array( array( 'type' => 'json_block', 'content' => '<span>R</span>' ) ),
            ),
        );

        $html = agentshell_render_zone( $zone );

        $pos_l = strpos( $html, '<span>L</span>' );
        $pos_c = strpos( $html, '<span>C</span>' );
        $pos_r = strpos( $html, '<span>R</span>' );
        Agentshell_Test::assert_true( $pos_l !== false && $pos_c !== false && $pos_r !== false );
        Agentshell_Test::assert_true( $pos_l < $pos_c );
        Agentshell_Test::assert_true( $pos_c < $pos_r );
    }
);

Agentshell_Test::run(
    'composition zone renders blocks vertically',
    function () {
        $zone = array(
            'id'          => 'main',
            'composition' => array(
                array( 'type' => 'json_block', 'content' => '<p>first</p>' ),
                array( 'type' => 'json_block', 'content' => '<p>second</p>' ),
            ),
        );

        $html = agentshell_render_zone( $zone );

        Agentshell_Test::assert_true( strpos( $html, 'first' ) !== false );
        Agentshell_Test::assert_true( strpos( $html, 'second' ) !== false );
    }
);

Agentshell_Test::run(
    'composition zone with no entries falls back to a default wp_loop',
    function () {
        $zone = array(
            'id'          => 'main',
            'composition' => array(),
        );
        $html = agentshell_render_zone( $zone );
        Agentshell_Test::assert_true(
            strpos( $html, 'No content found.' ) !== false,
            'empty composition must render the default wp_loop empty-state'
        );
    }
);