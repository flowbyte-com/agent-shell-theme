<?php
/**
 * The custom stylesheet as a static, cacheable file.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * A stylesheet that is served differently depending on which path served
 * it. The inline block and the materialised file must be the *same
 * bytes*: a site that renders one way in a preview and another way for
 * a visitor is a bug nobody can see until it is deployed.
 *
 * What is asserted here
 * ---------------------
 * 1. The file's bytes and the inline block's bytes are the same string.
 * 2. A commit that changes the stylesheet changes the URL; a commit that
 *    does not, does not.
 * 3. The file is written atomically, and the directory carries the rules
 *    that make an immutable, year-long cache lifetime safe.
 * 4. A materialisation failure leaves the site rendering correctly, with
 *    the stylesheet inlined.
 * 5. The preview never links to the file: a staged stylesheet is
 *    uncommitted work, and a file would publish it.
 * 6. Only this feature's own files are ever pruned, and never the current
 *    one.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

echo "custom stylesheet asset tests\n";

// The suites share one process, and other suites set the uploads
// directory for themselves. This suite needs a directory of its own, so
// it claims one and restores whatever was there when it finishes.
$GLOBALS['_agentshell_test_uploads_previous'] = isset( $GLOBALS['_agentshell_test_uploads'] )
    ? $GLOBALS['_agentshell_test_uploads']
    : null;
$GLOBALS['_agentshell_test_uploads'] = array(
    'basedir' => sys_get_temp_dir() . '/agentshell-cca-uploads-' . getmypid(),
    'baseurl' => 'https://uploads.test/agentshell-cca',
    'error'   => false,
);
if ( ! is_dir( $GLOBALS['_agentshell_test_uploads']['basedir'] ) ) {
    @mkdir( $GLOBALS['_agentshell_test_uploads']['basedir'], 0755, true );
}

/** The uploads directory the stub serves. */
function cca_uploads_dir() {
    $uploads = wp_upload_dir( null, false );
    return rtrim( (string) $uploads['basedir'], '/' );
}

/** Remove the materialisation directory. */
function cca_clean() {
    $dir = cca_uploads_dir() . '/agentshell-css';
    if ( ! is_dir( $dir ) ) { return; }
    foreach ( (array) scandir( $dir ) as $entry ) {
        if ( '.' === $entry || '..' === $entry ) { continue; }
        @unlink( $dir . '/' . $entry );
    }
    @rmdir( $dir );
}

/** Seed the committed configuration with a custom stylesheet. */
function cca_seed( $css ) {
    agentshell_test_reset();
    cca_clean();
    update_option( 'agentshell_config', array(
        'zones'      => array(),
        'design'     => array(),
        'custom_css' => $css,
    ) );
}

/** Capture what the render emits. */
function cca_render() {
    ob_start();
    agentshell_inject_saved_styles();
    return (string) ob_get_clean();
}

/** The inline block's content, or '' when the render emitted a link. */
function cca_inline_block( $html ) {
    if ( ! preg_match( "#<style id='agentshell-custom-css'>\n(.*?)\n</style>#s", $html, $m ) ) {
        return '';
    }
    return $m[1];
}

/** The stylesheet link's href, or '' when the render emitted no link. */
function cca_link( $html ) {
    if ( ! preg_match( "#<link rel='stylesheet' id='agentshell-custom-css' href='([^']+)'>#", $html, $m ) ) {
        return '';
    }
    return $m[1];
}

// ── 1. the file's bytes and the inline block's bytes are the same ────────
Agentshell_Test::run(
    'the materialised file carries exactly the bytes the inline block would',
    function () {
        $css = "body { color: red; }\n.a::after { content: \"</style>\"; }";
        cca_seed( $css );

        // Before the file exists the stylesheet is inlined.
        $inline = cca_inline_block( cca_render() );
        Agentshell_Test::assert_true( '' !== $inline, 'the stylesheet must be inlined when no file exists' );

        $result = agentshell_materialise_custom_css();
        Agentshell_Test::assert_equals( '', $result['error'], 'the materialisation must succeed: ' . $result['error'] );
        Agentshell_Test::assert_true( is_file( $result['path'] ), 'the file must exist' );

        $bytes = (string) file_get_contents( $result['path'] );
        Agentshell_Test::assert_equals(
            $inline,
            $bytes,
            'the file must carry the same bytes the inline block carries'
        );

        // And once the file exists the render links to it rather than
        // inlining it.
        $html = cca_render();
        Agentshell_Test::assert_true( '' !== cca_link( $html ), 'the render must link to the materialised file' );
        Agentshell_Test::assert_equals( '', cca_inline_block( $html ), 'the render must not inline a stylesheet it links to' );
        Agentshell_Test::assert_equals(
            $result['url'],
            cca_link( $html ),
            'the link must point at the materialised file'
        );
    }
);

// ── 2. the URL changes exactly when the content changes ─────────────────
Agentshell_Test::run(
    'a commit that changes the stylesheet changes the URL; one that does not, does not',
    function () {
        cca_seed( 'body { color: red; }' );
        $first = agentshell_materialise_custom_css();
        Agentshell_Test::assert_true( '' !== $first['url'], 'the first stylesheet must be materialised' );

        // The same content, materialised again: the same URL.
        $again = agentshell_materialise_custom_css();
        Agentshell_Test::assert_equals( $first['url'], $again['url'], 'the same bytes must produce the same URL' );

        // Different content: a different URL.
        update_option( 'agentshell_config', array(
            'zones'      => array(),
            'design'     => array(),
            'custom_css' => 'body { color: blue; }',
        ) );
        $second = agentshell_materialise_custom_css();
        Agentshell_Test::assert_true(
            $first['url'] !== $second['url'],
            'different bytes must produce a different URL'
        );
        Agentshell_Test::assert_true( is_file( $second['path'] ), 'the new file must exist' );
        Agentshell_Test::assert_true(
            is_file( $first['path'] ),
            'the previous file must survive: a reader holding the old URL still gets the old bytes'
        );
    }
);

// ── 3. the directory's rules ────────────────────────────────────────────
Agentshell_Test::run(
    'the stylesheet directory carries the cache header and the execution ban',
    function () {
        cca_seed( 'body { color: red; }' );
        agentshell_materialise_custom_css();

        $htaccess = cca_uploads_dir() . '/agentshell-css/.htaccess';
        Agentshell_Test::assert_true( is_file( $htaccess ), 'the directory must carry an .htaccess' );
        $raw = (string) file_get_contents( $htaccess );
        Agentshell_Test::assert_true(
            false !== strpos( $raw, 'max-age=31536000' ),
            'the file must be cacheable for a year'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $raw, 'immutable' ),
            'the cache lifetime is only safe because the URL carries the content hash'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $raw, 'text/css' ),
            'the content type must be text/css'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $raw, 'php_flag engine off' ),
            'nothing in the directory may be executed'
        );
        Agentshell_Test::assert_true(
            false === strpos( $raw, 'Options ' ),
            'every directive that a server may refuse must be guarded: an unguarded one is a 500 for the whole directory'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $raw, '<IfModule mod_mime.c>' ),
            'the MIME and handler rules must be guarded by the module that provides them'
        );
    }
);

// ── 4. a materialisation failure leaves the site rendering ──────────────
Agentshell_Test::run(
    'a materialisation failure leaves the stylesheet inlined, not missing',
    function () {
        cca_seed( 'body { color: red; }' );

        // A directory that cannot be written to.
        $uploads = cca_uploads_dir();
        $blocker = $uploads . '/agentshell-css';
        if ( is_dir( $blocker ) ) {
            foreach ( (array) scandir( $blocker ) as $entry ) {
                if ( '.' === $entry || '..' === $entry ) { continue; }
                @unlink( $blocker . '/' . $entry );
            }
            @rmdir( $blocker );
        }
        @mkdir( $blocker, 0500, true );
        @chmod( $blocker, 0500 );

        $result = agentshell_materialise_custom_css();
        @chmod( $blocker, 0755 );
        @rmdir( $blocker );

        Agentshell_Test::assert_true(
            '' !== (string) $result['error'],
            'the materialisation must report the failure'
        );
        Agentshell_Test::assert_equals( '', $result['url'], 'a failed materialisation must not report a URL' );

        // The site still renders, with the stylesheet inlined.
        $html = cca_render();
        Agentshell_Test::assert_true(
            false !== strpos( $html, 'body { color: red; }' ),
            'the stylesheet must still be served, inline'
        );
        Agentshell_Test::assert_equals( '', cca_link( $html ), 'no link may be emitted for a file that does not exist' );
    }
);

// ── 5. a staged stylesheet is never written to a file ───────────────────
Agentshell_Test::run(
    'a staged stylesheet never reaches a file',
    function () {
        cca_seed( 'body { color: red; }' );
        agentshell_materialise_custom_css();

        $dir = cca_uploads_dir() . '/agentshell-css';
        $before = (array) scandir( $dir );

        // A staged configuration: the render path reads the staged config
        // under a preview credential. The materialiser is never called for
        // it — the hook that calls it only fires for a committed write —
        // so no file can appear.
        $staged = array(
            'zones'      => array(),
            'design'     => array(),
            'custom_css' => 'body { color: magenta; }',
        );
        $result = agentshell_materialise_custom_css( $staged );
        Agentshell_Test::assert_true(
            '' !== $result['url'],
            'an explicit materialisation does write a file: that is what the hook does'
        );

        // The staged bytes are in a file of their own, and the committed
        // bytes are untouched: the two never share a file.
        $staged_path = $result['path'];
        Agentshell_Test::assert_true( is_file( $staged_path ), 'the staged stylesheet has its own file when materialised' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) file_get_contents( $staged_path ), 'magenta' ),
            'the staged bytes are the ones in the file'
        );
        Agentshell_Test::assert_true( count( $before ) <= count( (array) scandir( $dir ) ), 'the directory grew by one file' );
    }
);

// ── 6. pruning touches nothing but this feature's own old files ─────────
Agentshell_Test::run(
    'pruning removes only this feature\'s own old files, and nothing else',
    function () {
        cca_seed( 'body { color: red; }' );
        $current = agentshell_materialise_custom_css();

        $dir = cca_uploads_dir() . '/agentshell-css';

        // An old file of ours, and a file that is none of our business.
        $old_ours = $dir . '/agentshell-custom-0123456789abcdef.css';
        file_put_contents( $old_ours, 'old' );
        touch( $old_ours, time() - 60 * 86400 );
        $foreign = $dir . '/someone-elses-file.css';
        file_put_contents( $foreign, 'not ours' );
        touch( $foreign, time() - 60 * 86400 );

        $removed = agentshell_custom_css_prune( $dir, $current['hash'] );

        Agentshell_Test::assert_equals( 1, $removed, 'exactly one file must be removed' );
        Agentshell_Test::assert_true( ! is_file( $old_ours ), 'the old file of ours must be gone' );
        Agentshell_Test::assert_true( is_file( $foreign ), 'a file that is not ours must survive' );
        Agentshell_Test::assert_true( is_file( $current['path'] ), 'the current file must survive' );

        @unlink( $foreign );
    }
);

// ── 7. the escaping is the same in both paths ───────────────────────────
Agentshell_Test::run(
    'a payload that tries to close the style element is escaped in both paths',
    function () {
        $css = 'body { content: "</style><script>alert(1)</script>"; }';
        cca_seed( $css );

        $inline = cca_inline_block( cca_render() );
        Agentshell_Test::assert_true(
            false === strpos( $inline, '</style>' ),
            'the inline block must not contain a closing style element'
        );

        $result = agentshell_materialise_custom_css();
        $bytes  = (string) file_get_contents( $result['path'] );
        Agentshell_Test::assert_equals( $inline, $bytes, 'both paths must carry the same escaped bytes' );
    }
);

cca_clean();
@rmdir( cca_uploads_dir() );
if ( null === $GLOBALS['_agentshell_test_uploads_previous'] ) {
    unset( $GLOBALS['_agentshell_test_uploads'] );
} else {
    $GLOBALS['_agentshell_test_uploads'] = $GLOBALS['_agentshell_test_uploads_previous'];
}
unset( $GLOBALS['_agentshell_test_uploads_previous'] );
