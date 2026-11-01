/**
 * Run the forms builder browser test.
 *
 *   node tests/forms-builder-browser-test.js
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
            var p = found.toString().trim();
            if ( p ) { return p; }
        } catch ( e ) {}
    }
    return null;
}

function browser_env() {
    var env = {};
    Object.keys( process.env ).forEach( function ( key ) {
        if ( key !== 'LD_LIBRARY_PATH' ) {
            env[ key ] = process.env[ key ];
        }
    } );
    return env;
}

function php_binary() {
    return process.env.AGENTSHELL_PHP || 'php';
}

function generate_page( out_file ) {
    var fixture = path.join( __dirname, 'forms-builder-browser-fixture.php' );
    child_process.execFileSync( php_binary(), [ fixture, out_file ], {
        stdio: [ 'ignore', 'pipe', 'pipe' ],
        maxBuffer: 16 * 1024 * 1024,
        timeout: 60000,
    } );
    if ( ! fs.existsSync( out_file ) ) {
        throw new Error( 'the fixture was not written' );
    }
}

function run_scenario( browser, page, profile ) {
    var args = [
        '--headless', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
        '--no-first-run', '--no-default-browser-check', '--force-prefers-reduced-motion',
        '--user-data-dir=' + profile, '--virtual-time-budget=20000',
        '--window-size=1280,900', '--dump-dom', 'file://' + page,
    ];

    var output = child_process.execFileSync( browser, args, {
        maxBuffer: 64 * 1024 * 1024,
        stdio: [ 'ignore', 'pipe', 'pipe' ],
        timeout: 180000,
        env: browser_env(),
    } ).toString();

    var match = output.match( /<title>([\s\S]*?)<\/title>/ );
    if ( ! match ) {
        throw new Error( 'no result reported' );
    }

    var title = match[ 1 ]
        .replace( /&quot;/g, '"' )
        .replace( /&#39;/g, "'" )
        .replace( /&lt;/g, '<' )
        .replace( /&gt;/g, '>' )
        .replace( /&amp;/g, '&' );

    return JSON.parse( title );
}

function main() {
    var browser = find_browser();

    if ( ! browser ) {
        if ( process.env.AGENTSHELL_REQUIRE_BROWSER === '1' ) {
            console.error( 'FAIL the forms builder browser test needs a browser' );
            process.exit( 1 );
        }
        console.log( 'SKIP: no browser found' );
        process.exit( 0 );
    }

    var work = fs.mkdtempSync( path.join( os.tmpdir(), 'agentshell-forms-builder-browser-' ) );
    var page = path.join( work, 'fixture.html' );
    var profile = path.join( work, 'profile' );
    fs.mkdirSync( profile, { recursive: true } );

    var report;

    try {
        generate_page( page );
        report = run_scenario( browser, page, profile );
    } catch ( e ) {
        console.error( 'FAIL ' + ( e.message || e ) );
        process.exit( 1 );
    } finally {
        try { fs.rmSync( work, { recursive: true, force: true } ); } catch ( e ) {}
    }

    ( report.failures || [] ).forEach( function ( f ) {
        console.log( '  FAIL ' + f.name + ( f.detail === null ? '' : ' (' + f.detail + ')' ) );
    } );

    console.log( '  the page ran ' + ( report.total || 0 ) + ' builder assertions' );
    console.log( '\n' + ( ( report.total || 0 ) - ( report.failures || [] ).length ) + ' passed, ' + ( ( report.failures || [] ).length ) + ' failed' );

    process.exit( ( report.failures && report.failures.length ) ? 1 : 0 );
}

main();
