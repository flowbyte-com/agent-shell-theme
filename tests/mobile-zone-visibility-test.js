/**
 * The mobile visibility of the header's and the footer's middle centre
 * (Node + a headless browser).
 *
 * Run with:  node tests/mobile-zone-visibility-test.js
 * Or via:    php tests/run-mobile-zone-visibility.php
 *
 * What this covers
 * ----------------
 * The theme has two markup shapes for a structural zone: the historical
 * three-slot zone (`slot-center`) and the nine-area grid
 * (`data-slot="middle-center"`). The mobile rules in style.css have to
 * work for both, and they have to treat the header and the footer
 * differently:
 *
 *   - The header's middle centre collapses on a narrow viewport, so the
 *     hamburger has something to reveal.
 *   - The footer's middle centre is *content*. An unscoped rule once hid
 *     it, and every mobile site lost whatever the operator had put
 *     there — a navigation menu, a social row, an address.
 *
 * The rule that hid the footer's centre was fixed by scoping it to the
 * header. That fix was made in the *legacy* class name only, and the
 * nine-area grid arrived with a different one. Nothing tested the pair,
 * so a change to either could have broken the other without a word.
 *
 * This test renders the zones with the theme's own renderer, loads the
 * theme's own stylesheet, and measures the computed styles in a browser
 * at 390 pixels — in both layouts, for both zones, closed and open.
 */

'use strict';

var child_process = require( 'child_process' );
var fs = require( 'fs' );
var os = require( 'os' );
var path = require( 'path' );

var ROOT = path.join( __dirname, '..' );
var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

console.log( 'mobile zone visibility (real stylesheet, real markup)' );

// ── the browser ─────────────────────────────────────────────────────────────

var CANDIDATES = [
    process.env.AGENTSHELL_BROWSER,
    'google-chrome',
    'google-chrome-stable',
    'chromium',
    'chromium-browser',
    'chrome',
];

function find_browser() {
    for ( var i = 0; i < CANDIDATES.length; i++ ) {
        var name = CANDIDATES[ i ];
        if ( ! name ) { continue; }
        try {
            var found = child_process.execFileSync( 'which', [ name ], { stdio: [ 'ignore', 'pipe', 'ignore' ] } ).toString().trim();
            if ( found ) { return found; }
        } catch ( e ) {}
    }
    return null;
}

// ── the markup, from the theme's own renderer ──────────────────────────────

function render_zones() {
    var script = path.join( __dirname, 'render-mobile-zones.php' );
    var out = child_process.execFileSync( 'php', [ script ], { maxBuffer: 8 * 1024 * 1024 } ).toString();
    return JSON.parse( out );
}

function build_page( zones, layout ) {
    var css = fs.readFileSync( path.join( ROOT, 'style.css' ), 'utf8' );
    var markup = zones[ layout ];
    return [
        '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<style>' + css + '</style></head><body>',
        '<div id="zone-header">' + markup.header + '</div>',
        '<div id="zone-main"><p>main</p></div>',
        '<div id="zone-footer">' + markup.footer + '</div>',
        '</body></html>',
    ].join( '' );
}

/**
 * The measurements the browser takes, as JSON in the document title.
 */
var PAGE = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="STYLE_URL">
</head><body>
<div id="zone-header">HEADER</div>
<div id="zone-main"><p>main</p></div>
<div id="zone-footer">FOOTER</div>
<script>
function q(sel) { return document.querySelector(sel); }
function visible(el) {
  if (!el) { return null; }
  var s = getComputedStyle(el);
  return s.display !== 'none' && s.visibility !== 'hidden';
}
function measure() {
  var header = q('#zone-header');
  var footer = q('#zone-footer');
  var headerCentre = header.querySelector('.zone-slot.slot-center, .zone-slot[data-slot="middle-center"]');
  var footerCentre = footer.querySelector('.zone-slot.slot-center, .zone-slot[data-slot="middle-center"]');
  var out = {
    header_centre_found: !!headerCentre,
    footer_centre_found: !!footerCentre,
    header_centre_visible: visible(headerCentre),
    footer_centre_visible: visible(footerCentre),
  };
  // The script's open state: the header carries the mobile-nav-open class,
  // and the centre must come back.
  header.classList.add('mobile-nav-open');
  out.header_centre_visible_open = visible(headerCentre);
  header.classList.remove('mobile-nav-open');
  out.errors = window.__errors || [];
  return out;
}
window.__errors = [];
window.onerror = function (m) { window.__errors.push(m); };
setTimeout(function () {
  var m = measure();
  document.title = JSON.stringify(m);
}, 250);
</script></body></html>`;

function measure_in_browser( browser, zones, layout, workdir ) {
    var css_path = path.join( workdir, 'style.css' );
    fs.copyFileSync( path.join( ROOT, 'style.css' ), css_path );
    var html = PAGE
        .replace( 'STYLE_URL', 'style.css' )
        .replace( 'HEADER', zones[ layout ].header )
        .replace( 'FOOTER', zones[ layout ].footer );
    var page = path.join( workdir, 'page.html' );
    fs.writeFileSync( page, html );

    var profile = fs.mkdtempSync( path.join( os.tmpdir(), 'agentshell-mobile-' ) );
    var args = [
        '--headless',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--user-data-dir=' + profile,
        '--virtual-time-budget=8000',
        '--window-size=390,844',
        '--dump-dom',
        'file://' + page,
    ];
    var output = '';
    try {
        output = child_process.execFileSync( browser, args, { maxBuffer: 32 * 1024 * 1024 } ).toString();
    } finally {
        try { fs.rmSync( profile, { recursive: true, force: true } ); } catch ( e ) {}
    }
    var match = output.match( /<title>([\s\S]*?)<\/title>/ );
    if ( ! match ) { return { error: 'the browser reported no result' }; }
    var title = match[ 1 ]
        .replace( /&quot;/g, '"' )
        .replace( /&#39;/g, "'" )
        .replace( /&lt;/g, '<' )
        .replace( /&gt;/g, '>' )
        .replace( /&amp;/g, '&' );
    try {
        return JSON.parse( title );
    } catch ( e ) {
        return { error: 'the result was not JSON: ' + title.slice( 0, 300 ) };
    }
}

// ── the run ─────────────────────────────────────────────────────────────────

var browser = find_browser();
if ( ! browser ) {
    if ( process.env.AGENTSHELL_REQUIRE_BROWSER === '1' ) {
        console.error( 'FAIL the mobile zone visibility test needs a browser' );
        process.exit( 1 );
    }
    console.log( 'SKIP: no browser found' );
    process.exit( 0 );
}

var zones = render_zones();
var workdir = fs.mkdtempSync( path.join( os.tmpdir(), 'agentshell-mobile-page-' ) );

try {
    [ 'legacy', 'expanded' ].forEach( function ( layout ) {
        var m = measure_in_browser( browser, zones, layout, workdir );

        ok( ! m.error, 'the ' + layout + ' layout was measured' + ( m.error ? ': ' + m.error : '' ) );
        if ( m.error ) { return; }

        eq( m.errors.length, 0, 'the ' + layout + ' layout raised no JavaScript errors' );

        ok( m.header_centre_found, 'the ' + layout + ' layout has a header middle centre' );
        ok( m.footer_centre_found, 'the ' + layout + ' layout has a footer middle centre' );

        eq(
            m.header_centre_visible,
            false,
            'the header\'s middle centre is hidden on a narrow viewport (' + layout + ')'
        );
        eq(
            m.footer_centre_visible,
            true,
            'the footer\'s middle centre is still visible on a narrow viewport (' + layout + ')'
        );
        eq(
            m.header_centre_visible_open,
            true,
            'the header\'s middle centre comes back when the menu is opened (' + layout + ')'
        );
    } );
} finally {
    try { fs.rmSync( workdir, { recursive: true, force: true } ); } catch ( e ) {}
}

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
if ( failed > 0 ) { process.exit( 1 ); }
