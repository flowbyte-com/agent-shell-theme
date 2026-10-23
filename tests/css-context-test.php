<?php
/**
 * CSS context regression: the token block is CSS, not HTML.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The saved-configuration token block was emitted with `esc_attr()`, which
 * is the HTML *attribute* escaper. Inside a `<style>` element the content
 * is raw text: the HTML parser does not decode entities there, so every
 * entity the escaper produced reached the CSS parser literally.
 *
 * A font stack is written the way every font stack is written:
 *
 *     --font-base: "Inter", system-ui, sans-serif;
 *
 * and it was emitted as
 *
 *     --font-base: &quot;Inter&quot;, system-ui, sans-serif;
 *
 * so the browser looked for a font called `&quot;Inter&quot;`. The same
 * mangling hit every value containing an ampersand, a less-than sign or
 * an apostrophe.
 *
 * HTML escaping is the wrong tool for a CSS context. The right one keeps
 * the characters CSS needs — quotes above all — and removes the characters
 * that could end the declaration, open a block, or close the `<style>`
 * element.
 *
 * What is asserted here
 * ---------------------
 * 1. A quoted font stack survives byte-for-byte.
 * 2. The emitted block contains no HTML entity at all.
 * 3. A value cannot end its declaration, open a rule, or close the
 *    `<style>` element.
 * 4. A property name that is not an identifier is refused rather than
 *    emitted.
 * 5. The ordinary design tokens still round-trip unchanged.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

echo "CSS context regression tests\n";

/** Capture what agentshell_inject_saved_styles() emits. */
function css_capture_styles() {
    ob_start();
    agentshell_inject_saved_styles();
    return (string) ob_get_clean();
}

/** A config with the given design tokens. */
function css_seed( array $design ) {
    agentshell_test_reset();
    update_option( 'agentshell_config', array(
        'zones' => array(),
        'design' => $design,
    ) );
}

// ── 1. a quoted font stack survives ─────────────────────────────────────────
Agentshell_Test::run(
    'a quoted font stack survives the token block byte-for-byte',
    function () {
        css_seed( array(
            'typography' => array( 'fontFamily' => '"Inter", system-ui, sans-serif' ),
        ) );

        $html = css_capture_styles();

        Agentshell_Test::assert_true(
            false !== strpos( $html, '--font-base: "Inter", system-ui, sans-serif;' ),
            'the font stack must be emitted with its quotes intact: ' . $html
        );
        Agentshell_Test::assert_false(
            false !== strpos( $html, '&quot;' ),
            'the token block must contain no HTML entity: ' . $html
        );
        Agentshell_Test::assert_false(
            false !== strpos( $html, '&#039;' ),
            'the token block must contain no HTML entity: ' . $html
        );
        Agentshell_Test::assert_false(
            false !== strpos( $html, '&amp;' ),
            'the token block must contain no HTML entity: ' . $html
        );
    }
);

// ── 2. ordinary tokens still round-trip ────────────────────────────────────
Agentshell_Test::run(
    'ordinary design tokens round-trip unchanged',
    function () {
        css_seed( array(
            'colors' => array( 'accent' => '#ff00ff' ),
            'layout' => array( 'radius' => '6px' ),
        ) );

        $html = css_capture_styles();

        Agentshell_Test::assert_true(
            false !== strpos( $html, '--theme-accent: #ff00ff;' ),
            'the accent token must survive: ' . $html
        );
        Agentshell_Test::assert_true(
            false !== strpos( $html, '--radius-base: 6px;' ),
            'the radius token must survive: ' . $html
        );
    }
);

// ── 3. a value cannot escape its declaration or the element ────────────────
Agentshell_Test::run(
    'a hostile token value cannot end its declaration, open a rule or close the element',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'design' => array(
                'custom_css_vars' => array(
                    '--hostile' => 'red; } body { display: none } .x { color: blue',
                    '--tag'     => '</style><script>alert(1)</script>',
                ),
            ),
        ) );

        $html = css_capture_styles();

        // The block the function emits must still be one well-formed
        // element: exactly one opening and one closing <style>.
        Agentshell_Test::assert_equals(
            2,
            substr_count( $html, '<style' ),
            'the emitted block must contain exactly one <style> element: ' . $html
        );
        Agentshell_Test::assert_equals(
            2,
            substr_count( $html, '</style>' ),
            'the emitted block must contain exactly one closing tag: ' . $html
        );

        // The declaration itself must not have been terminated or broken.
        $line = '';
        foreach ( explode( "\n", $html ) as $candidate ) {
            if ( false !== strpos( $candidate, '--hostile:' ) ) {
                $line = $candidate;
            }
        }
        Agentshell_Test::assert_true( '' !== $line, 'the hostile token must be emitted: ' . $html );
        // The declaration the emitter writes ends with the ';' it adds
        // itself; the VALUE inside it must carry none.
        $value = trim( substr( $line, strlen( '    --hostile:' ) ) );
        $value = rtrim( $value, ';' );
        Agentshell_Test::assert_false(
            false !== strpos( $value, ';' ),
            'the emitted value must carry no terminator of its own: ' . $line
        );
        Agentshell_Test::assert_false(
            false !== strpos( $value, '{' ) || false !== strpos( $value, '}' ),
            'the emitted value must carry no block delimiter: ' . $line
        );

        $tag_line = '';
        foreach ( explode( "\n", $html ) as $candidate ) {
            if ( false !== strpos( $candidate, '--tag:' ) ) {
                $tag_line = $candidate;
            }
        }
        Agentshell_Test::assert_false(
            false !== strpos( $tag_line, '<' ) || false !== strpos( $tag_line, '>' ),
            'the emitted declaration must carry no angle bracket: ' . $tag_line
        );
        Agentshell_Test::assert_false(
            false !== strpos( $html, '<script' ),
            'no script tag may survive into the block: ' . $html
        );
    }
);

// ── 4. a property name that is not an identifier is refused ────────────────
Agentshell_Test::run(
    'a property name that is not an identifier is refused rather than emitted',
    function () {
        Agentshell_Test::assert_equals(
            '--font-base',
            agentshell_safe_css_property_name( '--font-base' ),
            'an ordinary name must be accepted'
        );
        Agentshell_Test::assert_equals(
            '',
            agentshell_safe_css_property_name( '--x; color: red' ),
            'a name carrying a terminator must be refused'
        );
        Agentshell_Test::assert_equals(
            '',
            agentshell_safe_css_property_name( '--x: red' ),
            'a name carrying a colon must be refused'
        );
        Agentshell_Test::assert_equals(
            '',
            agentshell_safe_css_property_name( '--bad name' ),
            'a name carrying whitespace must be refused'
        );
        Agentshell_Test::assert_equals(
            '',
            agentshell_safe_css_property_name( 'font-base' ),
            'a name without the custom-property prefix must be refused'
        );
        Agentshell_Test::assert_equals(
            '--日本語',
            agentshell_safe_css_property_name( '--日本語' ),
            'a non-ASCII identifier is a legitimate custom property name'
        );
    }
);

// ── 5. the value escaper keeps what CSS needs ──────────────────────────────
Agentshell_Test::run(
    'the value escaper keeps the characters CSS needs',
    function () {
        Agentshell_Test::assert_equals(
            '"Inter", system-ui, sans-serif',
            agentshell_safe_css_value( '"Inter", system-ui, sans-serif' ),
            'a font stack must survive'
        );
        Agentshell_Test::assert_equals(
            'Inter & Co',
            agentshell_safe_css_value( 'Inter & Co' ),
            'an ampersand must survive'
        );
        Agentshell_Test::assert_equals(
            "it's fine",
            agentshell_safe_css_value( "it's fine" ),
            'an apostrophe must survive'
        );
        Agentshell_Test::assert_equals(
            'url("https://example.test/a.png?x=1&y=2")',
            agentshell_safe_css_value( 'url("https://example.test/a.png?x=1&y=2")' ),
            'a url with a query string must survive'
        );
        Agentshell_Test::assert_equals(
            'red  blue',
            agentshell_safe_css_value( 'red /* a comment */ blue' ),
            'a comment must be removed'
        );
        // An unterminated comment would swallow the rest of the
        // stylesheet, so its opener must be gone. The text after it
        // survives as inert text — it is a value, and a value cannot
        // carry structure.
        $unterminated = agentshell_safe_css_value( 'red /* unterminated' );
        Agentshell_Test::assert_false(
            false !== strpos( $unterminated, '/*' ),
            'an unterminated comment opener must not survive: ' . $unterminated
        );
        Agentshell_Test::assert_true(
            false !== strpos( $unterminated, 'red' ),
            'the text before the comment opener must survive: ' . $unterminated
        );
    }
);

echo "\n";
echo "CSS context: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
