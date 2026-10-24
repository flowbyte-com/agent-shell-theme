<?php
/**
 * The narrow-viewport capture, against a real browser.
 *
 * The defect this pins: `--window-size` is not a viewport. Headless Chrome
 * will not make a window narrower than about 500 CSS pixels, so a request
 * for a 375-pixel viewport was laid out at 500 pixels, the media queries
 * evaluated at 500 pixels, and the PNG was a *crop* of that wider layout —
 * a picture of the left-hand 375 pixels of a 500-pixel page.
 *
 * What is asserted here
 * ---------------------
 * 1. At every width the platform cares about — 320, 375, 390, 480, 768,
 *    1280, 1920 — the page is laid out at exactly the requested width.
 * 2. The image is that wide: nothing is cropped.
 * 3. The media queries see the requested width, not the browser's
 *    minimum. This is checked with a page that is red below 500 pixels and
 *    blue above it, and the pixel is read out of the PNG.
 * 4. A full-page capture is taller than the viewport and is not cropped.
 *
 * The suite needs Chrome. Without one it skips, so it is safe to run
 * anywhere.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-cdp.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-screenshot-cdp.php';

use AgentShell_MCP\Screenshot_CDP;

echo "narrow viewport capture (real browser)\n";

/**
 * The browser to test with, or null.
 */
function vp_browser() {
    if ( defined( 'AGENTSHELL_CHROME_BIN' ) && is_string( AGENTSHELL_CHROME_BIN ) && '' !== AGENTSHELL_CHROME_BIN ) {
        // The screenshot suites point AGENTSHELL_CHROME_BIN at their fake
        // browser, and that variable outlives the suite in the same
        // process. The fake browser speaks no DevTools protocol, so this
        // suite must not use it: it would be testing the fake, not the
        // browser.
        if ( false === strpos( AGENTSHELL_CHROME_BIN, 'fake-browser' )
            && ( is_executable( AGENTSHELL_CHROME_BIN ) || file_exists( AGENTSHELL_CHROME_BIN ) ) ) {
            return AGENTSHELL_CHROME_BIN;
        }
    }
    foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser' ) as $binary ) {
        $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' ) );
        if ( '' !== $path && is_executable( $path ) ) {
            return $path;
        }
    }
    return null;
}

$browser = vp_browser();
if ( null === $browser ) {
    Agentshell_Test::run(
        'narrow viewport suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no Chrome or Chromium on this host' );
        }
    );
    echo "  (skipped: no Chrome or Chromium on this host)\n";
    return;
}

// A page that shows the viewport twice: once as a colour the media query
// chooses, and once as text (which the capture reports back through the
// measured innerWidth).
$page = 'data:text/html;base64,' . base64_encode(
    '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<style>html,body{margin:0;height:100%}body{background:#0000ff}'
    . '@media (max-width:500px){body{background:#ff0000}}</style></head>'
    . '<body><p>viewport</p></body></html>'
);

/**
 * Capture at a width, and report what came back.
 */
function vp_capture( $browser, $url, $width, $height, $full_page = false ) {
    static $counter = 0;
    $counter++;
    // The screenshot class's own profile: it creates the browser's HOME
    // and TMPDIR inside it, and Chrome aborts when it cannot create the
    // socket directory its process singleton needs. The image is written
    // *outside* the profile, because the profile is removed as soon as the
    // capture is over and the test still has to read the image.
    $profile = \AgentShell_MCP\Screenshot::create_profile_dir();
    $file    = sys_get_temp_dir() . '/agentshell-vp-shot-' . getmypid() . '-' . $counter . '.png';
    try {
        $out = Screenshot_CDP::capture( $browser, $url, $width, $height, $file, $profile, 60, $full_page );
    } finally {
        \AgentShell_MCP\Screenshot::destroy_profile_dir( $profile );
    }
    $out['file'] = $file;
    return $out;
}

/**
 * The first pixel of a PNG, as [r, g, b].
 */
function vp_first_pixel( $path ) {
    if ( ! function_exists( 'imagecreatefrompng' ) ) {
        return null;
    }
    $image = @imagecreatefrompng( $path );
    if ( ! $image ) {
        return null;
    }
    $rgb = imagecolorat( $image, 1, 1 );
    $out = array(
        (int) ( ( $rgb >> 16 ) & 0xFF ),
        (int) ( ( $rgb >> 8 ) & 0xFF ),
        (int) ( $rgb & 0xFF ),
    );
    // imagedestroy() is deprecated in PHP 8.5 and has had no effect since
    // 8.0; the image is released when the handle goes out of scope.
    return $out;
}

/**
 * The dimensions of a PNG, read from its header.
 */
function vp_dimensions( $path ) {
    if ( ! is_readable( $path ) ) {
        return array( 0, 0 );
    }
    $header = (string) file_get_contents( $path, false, null, 0, 24 );
    if ( strlen( $header ) < 24 ) {
        return array( 0, 0 );
    }
    $w = unpack( 'N', substr( $header, 16, 4 ) );
    $h = unpack( 'N', substr( $header, 20, 4 ) );
    return array( (int) $w[1], (int) $h[1] );
}

$widths = array( 320, 375, 390, 480, 768, 1280, 1920 );

foreach ( $widths as $width ) {
    Agentshell_Test::run(
        'the page is laid out at exactly ' . $width . ' pixels',
        function () use ( $browser, $page, $width ) {
            $out = vp_capture( $browser, $page, $width, 800 );
            Agentshell_Test::assert_true(
                ! empty( $out['ok'] ),
                'the capture must succeed: ' . (string) ( $out['error'] ?? '' )
            );
            Agentshell_Test::assert_equals(
                $width,
                (int) ( $out['viewport']['width'] ?? 0 ),
                'the viewport the page measured must be the requested width'
            );
            list( $png_w, $png_h ) = vp_dimensions( $out['file'] );
            Agentshell_Test::assert_equals(
                $width,
                $png_w,
                'the image must be the requested width and not a crop of a wider layout'
            );
            @unlink( $out['file'] );
        }
    );
}

Agentshell_Test::run(
    'the media queries see the requested width',
    function () use ( $browser, $page ) {
        // Below 500 pixels the page is red; above it, blue. If the layout
        // were still happening at the browser's minimum width, every
        // capture would be blue.
        foreach ( array( 320, 375, 390, 480 ) as $width ) {
            $out = vp_capture( $browser, $page, $width, 800 );
            Agentshell_Test::assert_true( ! empty( $out['ok'] ), 'the capture at ' . $width . ' must succeed' );
            $pixel = vp_first_pixel( $out['file'] );
            if ( null === $pixel ) {
                // No image library: the viewport measurement above already
                // proves the width; the colour check needs GD.
                Agentshell_Test::assert_true( true, 'skipped: no image library to read the pixel' );
                @unlink( $out['file'] );
                return;
            }
            Agentshell_Test::assert_true(
                $pixel[0] > 200 && $pixel[1] < 60 && $pixel[2] < 60,
                'at ' . $width . ' pixels the media query must have matched (expected red, got '
                    . implode( ',', $pixel ) . ')'
            );
            @unlink( $out['file'] );
        }

        foreach ( array( 768, 1280 ) as $width ) {
            $out = vp_capture( $browser, $page, $width, 800 );
            $pixel = vp_first_pixel( $out['file'] );
            if ( null === $pixel ) {
                return;
            }
            Agentshell_Test::assert_true(
                $pixel[2] > 200 && $pixel[0] < 60 && $pixel[1] < 60,
                'at ' . $width . ' pixels the media query must not have matched (expected blue, got '
                    . implode( ',', $pixel ) . ')'
            );
            @unlink( $out['file'] );
        }
    }
);

Agentshell_Test::run(
    'a full-page capture is taller than the viewport and is not cropped',
    function () use ( $browser ) {
        // A page three viewports tall.
        $tall = 'data:text/html;base64,' . base64_encode(
            '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{margin:0}.box{height:800px;background:#cccccc}</style></head>'
            . '<body><div class="box"></div><div class="box"></div><div class="box"></div></body></html>'
        );
        $out = vp_capture( $browser, $tall, 375, 600, true );
        Agentshell_Test::assert_true( ! empty( $out['ok'] ), 'the capture must succeed: ' . (string) ( $out['error'] ?? '' ) );
        list( $png_w, $png_h ) = vp_dimensions( $out['file'] );
        Agentshell_Test::assert_equals( 375, $png_w, 'the width must still be the viewport width' );
        Agentshell_Test::assert_true(
            $png_h > 600,
            'the full-page capture must be taller than the viewport; it is ' . $png_h . ' pixels'
        );
        @unlink( $out['file'] );
    }
);
