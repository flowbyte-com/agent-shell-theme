<?php
/**
 * inject-block-test — agentshell_inject_json_block render contract.
 *
 * Pre-fix bug: the tool wrote a zone-level { source: 'json_block', html: '…' }
 * shape that the renderer never reads. The tool returned success; nothing
 * appeared on the page.
 *
 * Post-fix contract: the tool appends a json_block entry to the zone's
 * composition (or to its right slot for tri-slot zones), and the existing
 * json_block renderer branch in agentshell_render_block surfaces the
 * content. Sanitisation rules (script/style stripping + KSES for
 * untrusted users, full passthrough for trusted) are unchanged.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-base-tool.php';
require_once __DIR__ . '/../agentshell-mcp/includes/tools/class-inject-json-block.php';

echo "inject-block regression tests\n";

Agentshell_Test::run(
    'trusted inject into main zone appends json_block to composition',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_loop' ) ),
                ),
            ),
        );

        $updated = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone( $config, 'main', '<p>hello world</p>' );

        // Composition should have one new json_block entry, not the dead shape.
        $zone = null;
        foreach ( $updated['zones'] as $z ) {
            if ( $z['id'] === 'main' ) { $zone = $z; break; }
        }
        Agentshell_Test::assert_true( $zone !== null );
        Agentshell_Test::assert_equals( 2, count( $zone['composition'] ) );
        $new_block = $zone['composition'][1];
        Agentshell_Test::assert_equals( 'json_block', $new_block['type'] );
        Agentshell_Test::assert_equals( '<p>hello world</p>', $new_block['content'] );
        Agentshell_Test::assert_false( isset( $zone['source'] ) );
        Agentshell_Test::assert_false( isset( $zone['html'] ) );
    }
);

Agentshell_Test::run(
    'inject into a legacy three-slot zone appends to the middle-right area',
    function () {
        // Input is the historical three-slot shape on purpose: the tool must
        // accept it, place the block at the end of the middle row, and write
        // the canonical nine-area shape back (one stored shape, not two).
        $config = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                        'center' => array(),
                        'right'  => array(),
                    ),
                ),
            ),
        );

        $updated = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone( $config, 'header', '<span>badge</span>' );

        $zone = $updated['zones'][0];
        Agentshell_Test::assert_equals( 'json_block', $zone['slots']['middle-right'][0]['type'] );
        Agentshell_Test::assert_equals( '<span>badge</span>', $zone['slots']['middle-right'][0]['content'] );
        Agentshell_Test::assert_equals( 1, count( $zone['slots']['middle-left'] ) ); // unchanged, canonical id
        Agentshell_Test::assert_false( isset( $zone['slots']['left'] ), 'the legacy key must not survive the write' );
        Agentshell_Test::assert_false( isset( $zone['slots']['right'] ), 'the legacy key must not survive the write' );
    }
);

Agentshell_Test::run(
    'untrusted inject strips <script> and <style> tags',
    function () {
        $config = array( 'zones' => array( array( 'id' => 'main', 'composition' => array() ) ) );

        $updated = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone(
            $config,
            'main',
            '<p>safe</p><script>alert(1)</script><style>body{color:red}</style>',
            false /* trusted */
        );

        $block = $updated['zones'][0]['composition'][0];
        Agentshell_Test::assert_true(
            strpos( $block['content'], '<script' ) === false,
            'script tags must be stripped for untrusted users'
        );
        Agentshell_Test::assert_true(
            strpos( $block['content'], '<style' ) === false,
            'style tags must be stripped for untrusted users'
        );
        Agentshell_Test::assert_true( strpos( $block['content'], '<p>safe</p>' ) !== false );
    }
);

Agentshell_Test::run(
    'trusted inject preserves <script> (web components use case)',
    function () {
        $config = array( 'zones' => array( array( 'id' => 'main', 'composition' => array() ) ) );

        $updated = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone(
            $config,
            'main',
            '<my-widget>hi</my-widget><script>customElements.define("my-widget", class extends HTMLElement {})</script>',
            true /* trusted */
        );

        $block = $updated['zones'][0]['composition'][0];
        Agentshell_Test::assert_true(
            strpos( $block['content'], '<script' ) !== false,
            'trusted users must keep <script> for custom Web Components'
        );
    }
);

Agentshell_Test::run(
    'unknown zone_id throws',
    function () {
        $config = array( 'zones' => array( array( 'id' => 'main', 'composition' => array() ) ) );
        $threw = false;
        try {
            \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone( $config, 'mystery', 'x' );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'render-time: appended json_block actually renders through the existing json_block branch',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_loop' ) ),
                ),
            ),
        );

        $updated  = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone( $config, 'main', '<p>rendered!</p>' );
        $zone     = $updated['zones'][0];
        $rendered = agentshell_render_zone( $zone );

        Agentshell_Test::assert_true(
            strpos( $rendered, 'rendered!' ) !== false,
            'the injected content must appear in the rendered output'
        );
    }
);

Agentshell_Test::run(
    'render-time: untrusted script is dropped by the renderer as defense-in-depth',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_loop' ) ),
                ),
            ),
        );

        // Trusted at write-time so the bytes are stored; the renderer must
        // still strip <script> when the block is non-trusted-at-render.
        // The actual current renderer strips <script> unconditionally
        // inside json_block; that defense-in-depth path must remain.
        $updated  = \AgentShell_MCP\Tools\agentshell_inject_json_block_into_zone(
            $config,
            'main',
            '<p>safe</p><script>alert(1)</script>',
            true
        );
        $zone     = $updated['zones'][0];
        $rendered = agentshell_render_zone( $zone );

        Agentshell_Test::assert_true( strpos( $rendered, '<p>safe</p>' ) !== false );
        Agentshell_Test::assert_true(
            strpos( $rendered, '<script' ) === false,
            'renderer must defensively strip <script> from json_block content'
        );
    }
);