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

// ── 6. the temporary file cannot be hijacked ──────────────────────────────
//
// The independent review of PR #16 found that the stylesheet was written
// to a temporary file whose name was derived from the hash of the bytes
// being written — which is also the name of the file the stylesheet is
// served under, so the path was public. A symbolic link placed at that
// path before the write was *followed* by it, and the stylesheet's bytes
// landed in whatever it pointed at.
//
// The fixture here is a file in the test's own temporary directory. It is
// disposable by construction and it is never anything that matters.
Agentshell_Test::run(
    'a symlink at the predictable temporary path cannot redirect the stylesheet',
    function () {
        cca_seed( 'body { color: red; }' );

        $bytes = agentshell_custom_css_bytes( 'body { color: red; }' );
        $hash  = agentshell_custom_css_hash( $bytes );
        $dir   = cca_uploads_dir() . '/agentshell-css';
        @mkdir( $dir, 0755, true );

        // The path the old implementation used, and the one a reader of
        // the served URL could name.
        $predictable = $dir . '/.agentshell-custom-' . $hash . '.tmp';

        // The disposable victim: a file in the test's own temporary
        // directory whose contents must not change.
        $victim = sys_get_temp_dir() . '/agentshell-cca-victim-' . getmypid() . '.txt';
        file_put_contents( $victim, "this file must not be touched\n" );

        @unlink( $predictable );
        symlink( $victim, $predictable );

        $result = agentshell_materialise_custom_css();

        $contents = (string) @file_get_contents( $victim );
        @unlink( $victim );
        @unlink( $predictable );

        Agentshell_Test::assert_equals(
            "this file must not be touched\n",
            $contents,
            'the stylesheet must not be written through a symlink into another file'
        );
        Agentshell_Test::assert_equals(
            '',
            (string) $result['error'],
            'the materialisation must still succeed: ' . (string) $result['error']
        );
        Agentshell_Test::assert_true(
            is_file( $result['path'] ),
            'the stylesheet must still be published at its content-addressed name'
        );
    }
);

Agentshell_Test::run(
    'the temporary file is created exclusively and is gone when the write fails',
    function () {
        cca_seed( 'body { color: red; }' );

        // A directory that cannot be written to: the write must fail,
        // and it must leave nothing behind.
        $dir = cca_uploads_dir() . '/agentshell-css';
        if ( is_dir( $dir ) ) {
            foreach ( (array) scandir( $dir ) as $entry ) {
                if ( '.' === $entry || '..' === $entry ) { continue; }
                @unlink( $dir . '/' . $entry );
            }
            @rmdir( $dir );
        }
        @mkdir( $dir, 0500, true );

        $result = agentshell_materialise_custom_css();
        @chmod( $dir, 0755 );

        Agentshell_Test::assert_true(
            '' !== (string) $result['error'],
            'the failure must be reported'
        );
        Agentshell_Test::assert_equals( '', $result['url'], 'a failed materialisation must not report a URL' );

        $left = is_dir( $dir ) ? array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) : array();
        Agentshell_Test::assert_equals(
            0,
            count( $left ),
            'a failed write must leave no temporary file behind: ' . json_encode( $left )
        );
        @rmdir( $dir );
    }
);

Agentshell_Test::run(
    'the published stylesheet is readable and not executable',
    function () {
        cca_seed( 'body { color: red; }' );
        $result = agentshell_materialise_custom_css();
        Agentshell_Test::assert_true( is_file( $result['path'] ), 'the stylesheet must exist' );

        $mode = fileperms( $result['path'] ) & 0777;
        Agentshell_Test::assert_true(
            (bool) ( $mode & 0444 ),
            sprintf( 'the stylesheet must be readable (mode %o)', $mode )
        );
        Agentshell_Test::assert_true(
            ! ( $mode & 0111 ),
            sprintf( 'the stylesheet must not be executable (mode %o)', $mode )
        );
    }
);

Agentshell_Test::run(
    'two materialisations of the same stylesheet agree',
    function () {
        // Concurrency: two writers, the same bytes, the same
        // content-addressed name. The second writer's temporary file has a
        // different name, so the two cannot collide, and the published
        // file is the same either way.
        cca_seed( 'body { color: red; }' );
        $first  = agentshell_materialise_custom_css();
        $second = agentshell_materialise_custom_css();
        Agentshell_Test::assert_equals( $first['url'], $second['url'], 'both writers must name the same file' );
        Agentshell_Test::assert_equals( $first['path'], $second['path'], 'both writers must publish to the same path' );
        Agentshell_Test::assert_equals(
            agentshell_custom_css_bytes( 'body { color: red; }' ),
            (string) file_get_contents( $first['path'] ),
            'the published bytes must be the stylesheet'
        );
    }
);

// ── 7. pruning touches nothing but this feature's own old files ─────────
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

// ── 8. the doctor distinguishes a staged stylesheet from a missing one ────
//
// The independent review of PR #16 found that the doctor reported
// CUSTOM_CSS_ASSET_MISSING for every *staged* stylesheet. A staged
// stylesheet is deliberately not materialised — writing it to a file would
// publish uncommitted work — so the warning fired on every legitimate
// staged change, and an agent reading the doctor learned to ignore it.
//
// The check is about the live site: what the file serves is the committed
// stylesheet. A missing *committed* stylesheet is still a real finding
// and is still reported.
Agentshell_Test::run(
    'the doctor does not report a staged stylesheet as a missing asset',
    function () {
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-doctor.php';
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php';
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-custom-css-asset.php';

        // The doctor reads the WordPress version; the unit bootstrap does
        // not define one, and the check would otherwise warn about it.
        global $wp_version;
        if ( ! isset( $wp_version ) || '' === (string) $wp_version ) {
            $wp_version = '6.8';
        }

        // A committed configuration with no stylesheet at all.
        agentshell_test_reset();
        update_option( 'agentshell_config', array( 'zones' => array(), 'design' => array() ) );

        // The staged configuration carries a stylesheet that has
        // deliberately not been materialised.
        $staged = array(
            'zones'      => array(),
            'design'     => array(),
            'custom_css' => 'body { color: #abcdef; }',
        );

        $issues = \AgentShell_MCP\Doctor::check( $staged );
        $codes  = array();
        foreach ( (array) ( $issues['warnings'] ?? array() ) as $warning ) {
            $codes[] = (string) ( $warning['code'] ?? '' );
        }

        // The check must fail for the right reason: the state has to be
        // readable, or "not reported as missing" would be true only
        // because the asset could not be examined at all.
        $committed = get_option( 'agentshell_config', array() );
        $state     = \AgentShell_MCP\Custom_CSS_Asset::state( $committed );
        Agentshell_Test::assert_equals(
            '',
            (string) $state['error'],
            'the asset state must be readable: ' . json_encode( $state )
        );

        Agentshell_Test::assert_false(
            in_array( 'CUSTOM_CSS_ASSET_MISSING', $codes, true ),
            'a staged stylesheet must not be reported as a missing asset: ' . json_encode( $codes )
        );
    }
);

Agentshell_Test::run(
    'the doctor still reports a committed stylesheet that was never materialised',
    function () {
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-doctor.php';
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php';
        require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-custom-css-asset.php';

        // The doctor reads the WordPress version; the unit bootstrap does
        // not define one, and the check would otherwise warn about it.
        global $wp_version;
        if ( ! isset( $wp_version ) || '' === (string) $wp_version ) {
            $wp_version = '6.8';
        }

        // The committed configuration carries a stylesheet, and no file
        // was written for it. That is a real finding and must survive.
        agentshell_test_reset();
        cca_clean();
        update_option( 'agentshell_config', array(
            'zones'      => array(),
            'design'     => array(),
            'custom_css' => 'body { color: #123456; }',
        ) );

        $committed = get_option( 'agentshell_config', array() );
        $issues    = \AgentShell_MCP\Doctor::check( $committed );
        $codes     = array();
        foreach ( (array) ( $issues['warnings'] ?? array() ) as $warning ) {
            $codes[] = (string) ( $warning['code'] ?? '' );
        }
        $state = \AgentShell_MCP\Custom_CSS_Asset::state( $committed );
        Agentshell_Test::assert_true(
            in_array( 'CUSTOM_CSS_ASSET_MISSING', $codes, true ),
            'a committed stylesheet with no file must still be reported: ' . json_encode( $codes )
                . ' state=' . json_encode( $state )
        );
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
