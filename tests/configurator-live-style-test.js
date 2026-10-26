/**
 * The live-style layer: its bookkeeping, tested without a browser.
 *
 * This suite proves the *bookkeeping*: what was captured, what is written,
 * what is written again and what is not, and what the element looks like
 * when the layer is finished with it. It does not prove that a colour
 * reaches the screen — that is the browser suite's job, with the browser's
 * own parser and the theme's own stylesheet, and the live acceptance run
 * against a real site.
 *
 *   node tests/configurator-live-style-test.js
 */
'use strict';

var path = require( 'path' );
var live = require( path.join( __dirname, '..', 'configurator', 'configurator-live-style.js' ) );

var passed = 0;
var failed = 0;

function ok( name, condition, detail ) {
    if ( condition ) {
        passed++;
        console.log( '  ok   ' + name );
        return true;
    }
    failed++;
    console.log( '  FAIL ' + name + ( undefined === detail ? '' : ' — ' + detail ) );
    return false;
}

function eq( name, actual, expected ) {
    return ok( name, actual === expected, 'expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
}

/**
 * A minimal element: enough of the CSSOM for the controller to capture,
 * write and restore. The style attribute is kept as text as well as parsed,
 * because the difference between a property that was absent and one that
 * was declared empty only exists in the text.
 */
function fake_element( style_text ) {
    var element = {
        _text: style_text || '',
        writes: 0,
        removes: 0,
        style: {
            _props: {},
            getPropertyValue: function ( name ) {
                return Object.prototype.hasOwnProperty.call( this._props, name ) ? this._props[ name ].value : '';
            },
            getPropertyPriority: function ( name ) {
                return Object.prototype.hasOwnProperty.call( this._props, name ) ? this._props[ name ].priority : '';
            },
            setProperty: function ( name, value, priority ) {
                element.writes++;
                this._props[ name ] = { value: String( value ), priority: priority || '' };
            },
            removeProperty: function ( name ) {
                element.removes++;
                delete this._props[ name ];
            },
        },
        getAttribute: function ( name ) {
            return 'style' === name ? element._text : null;
        },
        setAttribute: function ( name, value ) {
            if ( 'style' === name ) { element._text = String( value ); }
        },
        removeAttribute: function ( name ) {
            if ( 'style' === name ) { element._text = ''; }
        },
    };
    // Parse the starting declarations, so a property that was there is
    // reported as there.
    String( style_text || '' ).split( ';' ).forEach( function ( declaration ) {
        var index = declaration.indexOf( ':' );
        if ( index === -1 ) { return; }
        var name = declaration.slice( 0, index ).trim();
        var rest = declaration.slice( index + 1 ).trim();
        if ( ! name ) { return; }
        var important = /!\s*important$/i.test( rest );
        var value = rest.replace( /!\s*important$/i, '' ).trim();
        element.style._props[ name ] = { value: value, priority: important ? 'important' : '' };
    } );
    return element;
}

/** A probe that accepts everything, and one that accepts nothing. */
function permissive_probe() {
    return { style: {} };
}
/**
 * A probe that refuses every value, the way CSSOM refuses a value it cannot
 * parse: the assignment is swallowed and the property keeps its old value.
 */
function refusing_probe() {
    return {
        style: new Proxy( {}, {
            get: function () { return ''; },
            set: function () { return true; },
        } ),
    };
}

var TOKENS = {
    '--theme-bg': { probe: 'color' },
    '--theme-shadow': { probe: 'boxShadow' },
    '--zone-header-layout': { probe: 'justifyContent' },
};

var VOCABULARY = [ '--theme-bg', '--theme-shadow' ];

function make_controller( element, probe ) {
    var controller = live.make_live_style_controller( {
        root: element,
        probe: probe || permissive_probe(),
        tokens: TOKENS,
    } );
    controller.set_vocabulary( VOCABULARY );
    return controller;
}

console.log( 'live-style layer (capture, restore, repeated edits, vocabulary)' );

// ── 1. Nothing is written before the vocabulary arrives ────────────────
(function () {
    var element = fake_element( '' );
    var controller = live.make_live_style_controller( { root: element, probe: permissive_probe(), tokens: TOKENS } );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'nothing is written before the theme has said what it reads',
        element.style.getPropertyValue( '--theme-bg' ), '' );
} )();

// ── 2. A change is written once, and only once ────────────────────────
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'the draft value is written', element.style.getPropertyValue( '--theme-bg' ), '#123456' );
    eq( 'one write', element.writes, 1 );

    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'the same value is not written again', element.writes, 1 );
} )();

// ── 3. Repeated edits replace each other ───────────────────────────────
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': 'red' } );
    controller.flush();
    controller.set_desired( { '--theme-bg': 'blue' } );
    controller.flush();
    controller.set_desired( { '--theme-bg': 'green' } );
    controller.flush();
    eq( 'the last value is the one on the page', element.style.getPropertyValue( '--theme-bg' ), 'green' );
    eq( 'nothing accumulated', Object.keys( element.style._props ).length, 1 );
} )();

// ── 4. The draft and the committed configuration are different things ──
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );

    // A draft equal to the committed configuration writes nothing: the page
    // already shows it.
    controller.set_desired( { '--theme-bg': '#fafaf9' } );
    controller.flush();
    eq( 'a draft equal to the committed configuration writes nothing',
        element.style.getPropertyValue( '--theme-bg' ), '' );

    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'a changed draft is written', element.style.getPropertyValue( '--theme-bg' ), '#123456' );

    // Discarding puts the desired value back to the committed one, and the
    // override is taken back.
    controller.set_desired( { '--theme-bg': '#fafaf9' } );
    controller.flush();
    eq( 'discarding takes the override back', element.style.getPropertyValue( '--theme-bg' ), '' );
} )();

// ── 5. The original inline state is restored exactly ───────────────────
(function () {
    var element = fake_element( '--theme-bg: #abcdef !important; color: red;' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'the override is written', element.style.getPropertyValue( '--theme-bg' ), '#123456' );

    controller.set_desired( { '--theme-bg': '#fafaf9' } );
    controller.flush();
    eq( 'the original value is restored', element.style.getPropertyValue( '--theme-bg' ), '#abcdef' );
    eq( 'the original priority is restored', element.style.getPropertyPriority( '--theme-bg' ), 'important' );
    eq( 'an unrelated inline property is untouched', element.style.getPropertyValue( 'color' ), 'red' );
} )();

// ── 6. A property that was absent and one that was empty differ ──────
(function () {
    var absent = fake_element( 'color: red;' );
    var controller = make_controller( absent );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    controller.set_desired( { '--theme-bg': '#fafaf9' } );
    controller.flush();
    eq( 'a property that was absent is absent again',
        Object.prototype.hasOwnProperty.call( absent.style._props, '--theme-bg' ), false );

    var empty = fake_element( '--theme-bg: ; color: red;' );
    var controller2 = make_controller( empty );
    controller2.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller2.set_desired( { '--theme-bg': '#123456' } );
    controller2.flush();
    controller2.set_desired( { '--theme-bg': '#fafaf9' } );
    controller2.flush();
    ok( 'a property that was declared empty is declared empty again',
        empty.getAttribute( 'style' ).indexOf( '--theme-bg: ;' ) !== -1,
        'style attribute is now: ' + empty.getAttribute( 'style' ) );
} )();

// ── 7. A value the browser refuses leaves the page alone ──────────────
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element, refusing_probe() );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    eq( 'a refused value is not written', element.style.getPropertyValue( '--theme-bg' ), '' );
} )();

// ── 8. A token the theme does not read is never written ───────────────
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--zone-header-layout': 'flex-start' } );
    controller.set_desired( { '--zone-header-layout': 'center' } );
    controller.flush();
    eq( 'a token outside the vocabulary is not written',
        element.style.getPropertyValue( '--zone-header-layout' ), '' );
    eq( 'no write was made at all', element.writes, 0 );
} )();

// ── 9. Restoring removes every override ───────────────────────────────
(function () {
    var element = fake_element( 'color: red;' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9', '--theme-shadow': 'none' } );
    controller.set_desired( { '--theme-bg': '#123456', '--theme-shadow': '0 1px 2px red' } );
    controller.flush();
    ok( 'two overrides are written',
        element.style.getPropertyValue( '--theme-bg' ) === '#123456' &&
        element.style.getPropertyValue( '--theme-shadow' ) === '0 1px 2px red' );

    controller.restore();
    eq( 'the first override is gone', element.style.getPropertyValue( '--theme-bg' ), '' );
    eq( 'the second override is gone', element.style.getPropertyValue( '--theme-shadow' ), '' );
    eq( 'the unrelated property is untouched', element.style.getPropertyValue( 'color' ), 'red' );
} )();

// ── 10. The layer reports what it did ──────────────────────────────────
(function () {
    var element = fake_element( '' );
    var controller = make_controller( element );
    controller.set_stylesheet( { '--theme-bg': '#fafaf9' } );
    controller.set_desired( { '--theme-bg': '#123456' } );
    controller.flush();
    var state = controller.get_state();
    eq( 'the layer reports the override it wrote', state.applied.length, 1 );
    eq( 'and which property', state.applied[ 0 ].name, '--theme-bg' );
    ok( 'and that the vocabulary was received', state.has_vocabulary );
} )();

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
process.exit( failed > 0 ? 1 : 0 );
