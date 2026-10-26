/**
 * Run the configurator's browser test.
 *
 *   node tests/configurator-browser-test.js
 *
 * The test itself is tests/configurator-browser-test.html: the real
 * stylesheet, the real modules, the real markup. This runner finds a
 * browser, drives it once, and reads the results back out of the document
 * title.
 *
 * A missing browser is a skip, not a pass. Set AGENTSHELL_REQUIRE_BROWSER=1
 * to turn the skip into a failure — which is what CI does, because a skip
 * is not a test.
 */

'use strict';

var child_process = require( 'child_process' );
var fs = require( 'fs' );
var os = require( 'os' );
var path = require( 'path' );

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
            var found = child_process.execFileSync( 'which', [ name ], { stdio: [ 'ignore', 'pipe', 'ignore' ] } );
            var path_found = found.toString().trim();
            if ( path_found ) { return path_found; }
        } catch ( e ) {
            // Not on PATH: try the next one.
        }
    }
    return null;
}

function run_scenario( browser, harness, profile, scenario ) {
    var args = [
        '--headless',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        // The panel slides in with a CSS transition. A headless browser whose
        // virtual clock does not advance animations would measure the panel
        // while it is still off screen, so the geometry assertions would be
        // about a panel nobody can see. The stylesheet honours the reduced
        // motion preference, so this is the interface's own code path.
        '--force-prefers-reduced-motion',
        '--user-data-dir=' + profile,
        '--virtual-time-budget=20000',
        '--window-size=1280,900',
        '--dump-dom',
        'file://' + harness + ( scenario ? '?scenario=' + scenario : '' ),
    ];

    var output = '';
    var failed_to_run = null;
    try {
        output = child_process.execFileSync( browser, args, {
            maxBuffer: 64 * 1024 * 1024,
            stdio: [ 'ignore', 'pipe', 'pipe' ],
        } ).toString();
    } catch ( e ) {
        failed_to_run = e;
        output = ( e.stdout ? e.stdout.toString() : '' );
    }

    if ( failed_to_run && ! output ) {
        console.error( 'FAIL the browser did not run: ' + ( failed_to_run.message || failed_to_run ) );
        process.exit( 1 );
    }

    var match = output.match( /<title>([\s\S]*?)<\/title>/ );
    if ( ! match ) {
        console.error( 'FAIL the harness did not report a result' );
        process.exit( 1 );
    }

    var title = match[ 1 ]
        .replace( /&quot;/g, '"' )
        .replace( /&#39;/g, "'" )
        .replace( /&lt;/g, '<' )
        .replace( /&gt;/g, '>' )
        .replace( /&amp;/g, '&' );

    var report;
    try {
        report = JSON.parse( title );
    } catch ( e ) {
        console.error( 'FAIL the harness reported something that is not JSON: ' + title.slice( 0, 400 ) );
        process.exit( 1 );
    }

    if ( report.harness_error ) {
        console.error( 'FAIL the harness itself failed: ' + report.harness_error );
        if ( report.stack ) { console.error( report.stack ); }
        if ( report.errors && report.errors.length ) { console.error( 'page errors: ' + report.errors.join( '; ' ) ); }
        process.exit( 1 );
    }
    return report;
}

function main() {
    var browser = find_browser();
    if ( ! browser ) {
        var message = 'no browser found (looked for ' + CANDIDATES.filter( Boolean ).join( ', ' ) + ')';
        if ( process.env.AGENTSHELL_REQUIRE_BROWSER === '1' ) {
            console.error( 'FAIL the configurator browser test needs a browser: ' + message );
            process.exit( 1 );
        }
        console.log( 'SKIP: ' + message );
        process.exit( 0 );
    }

    var harness = path.join( __dirname, 'configurator-browser-test.html' );
    if ( ! fs.existsSync( harness ) ) {
        console.error( 'FAIL the harness is missing: ' + harness );
        process.exit( 1 );
    }

    var profile = fs.mkdtempSync( path.join( os.tmpdir(), 'agentshell-cfg-browser-' ) );
    var report;
    try {
        // Two scenarios: the editor on its own, and the editor while another
        // actor holds the transaction.
        var plain = run_scenario( browser, harness, profile, 'default' );
        var locked = run_scenario( browser, harness, profile, 'locked' );
        var refusal = run_scenario( browser, harness, profile, 'refusal' );
        var picker = run_scenario( browser, harness, profile, 'picker' );
        report = {
            total: ( plain.total || 0 ) + ( locked.total || 0 ) + ( refusal.total || 0 ) + ( picker.total || 0 ),
            failures: ( plain.failures || [] )
                .concat( locked.failures || [] )
                .concat( refusal.failures || [] )
                .concat( picker.failures || [] ),
        };
    } finally {
        try { fs.rmSync( profile, { recursive: true, force: true } ); } catch ( e2 ) {}
    }

    var failed = 0;
    ( report.failures || [] ).forEach( function ( failure ) {
        failed += 1;
        console.log( '  FAIL ' + failure.name + ( failure.detail === null || failure.detail === undefined ? '' : ' (' + failure.detail + ')' ) );
    } );
    var passed = ( report.total || 0 ) - failed;

    console.log( '  the editor ran ' + ( report.total || 0 ) + ' browser assertions against the real interface' );
    console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
    if ( failed > 0 ) { process.exit( 1 ); }
}

main();
