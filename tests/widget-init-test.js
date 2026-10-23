/**
 * Widget runtime regression tests (Node).
 *
 * Run with:  node tests/widget-init-test.js <path-to-runtime.js>
 * Or via:    php tests/widget-init-test.php  (extracts the runtime and asserts)
 *
 * The failure this exists to prevent
 * ----------------------------------
 * A widget's `init_js` *registers* an initialiser:
 *
 *     window.AgentshellWidgets['<id>'] = { init: function(el) { … } };
 *
 * and something has to call it. Nothing did. The theme emitted each
 * widget's registration script into the footer and stopped there, so
 * every widget that needed to run any JavaScript at all rendered as an
 * empty element — the `init` function was defined, never invoked.
 *
 * The blocks plugin carried a second, also-broken copy of the missing
 * half: a MutationObserver that called `AgentshellWidgets.initWidget(el,
 * id)`, a function it never defined, while selecting `[data-widget]`
 * where every renderer in this repository emits `data-widget-id`.
 *
 * What is asserted here
 * ---------------------
 * 1. Every widget element is initialised, and `init` receives the
 *    element itself.
 * 2. Both attribute spellings are recognised — `data-widget` and
 *    `data-widget-id` — because a widget's own template may use either
 *    and the renderer emits the latter.
 * 3. Initialisation is idempotent: an element is initialised once,
 *    however many times it is asked for.
 * 4. One widget's failure is contained: a widget whose init throws is
 *    logged, and every other widget still initialises.
 * 5. Elements added after load are initialised.
 * 6. An element whose widget has not registered yet is left unmarked,
 *    so a later registration still initialises it.
 *
 * The DOM here is a small purpose-built stub, not a browser. The runtime
 * uses a narrow slice of the DOM API — nodeType, getAttribute,
 * hasAttribute, querySelectorAll, addEventListener, MutationObserver —
 * and the stub implements exactly that slice, so a change to the
 * runtime that reaches for more of the DOM shows up here as a failure
 * rather than as silence.
 */

'use strict';

var fs = require( 'fs' );

var runtimePath = process.argv[ 2 ];
if ( ! runtimePath ) {
    console.error( 'usage: node widget-init-test.js <runtime.js>' );
    process.exit( 2 );
}

var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

// ── a small DOM ──────────────────────────────────────────────────────────────

function El( attrs ) {
    this.nodeType = 1;
    this.attrs = attrs || {};
    this.children = [];
    this.textContent = '';
}
El.prototype.getAttribute = function ( name ) {
    return Object.prototype.hasOwnProperty.call( this.attrs, name ) ? this.attrs[ name ] : null;
};
El.prototype.hasAttribute = function ( name ) {
    return Object.prototype.hasOwnProperty.call( this.attrs, name );
};
El.prototype.setAttribute = function ( name, value ) {
    this.attrs[ name ] = String( value );
};
El.prototype.appendChild = function ( child ) {
    this.children.push( child );
    child.parentNode = this;
    return child;
};
El.prototype.matches = function ( selector ) {
    var parts = String( selector ).split( ',' ).map( function ( s ) { return s.trim(); } );
    for ( var i = 0; i < parts.length; i++ ) {
        var m = /^\[([a-zA-Z0-9_-]+)\]$/.exec( parts[ i ] );
        if ( m && this.hasAttribute( m[ 1 ] ) ) { return true; }
    }
    return false;
};
El.prototype.querySelectorAll = function ( selector ) {
    var out = [];
    ( function walk( node ) {
        for ( var i = 0; i < node.children.length; i++ ) {
            var child = node.children[ i ];
            if ( child.matches( selector ) ) { out.push( child ); }
            walk( child );
        }
    } )( this );
    return out;
};

function Doc() {
    this.nodeType = 9;
    this.listeners = {};
    this.body = new El( {} );
    this.body.parentNode = this;
}
Doc.prototype.addEventListener = function ( type, cb ) {
    ( this.listeners[ type ] = this.listeners[ type ] || [] ).push( cb );
};
Doc.prototype.dispatch = function ( type ) {
    ( this.listeners[ type ] || [] ).forEach( function ( cb ) { cb(); } );
};
Doc.prototype.querySelectorAll = function ( selector ) {
    return this.body.querySelectorAll( selector );
};

function ObserverStub( registry ) {
    this.callback = null;
    registry.push( this );
}
ObserverStub.prototype.observe = function ( target, options ) {
    this.target = target;
    this.options = options;
};
ObserverStub.prototype.fire = function ( addedNodes ) {
    if ( this.callback ) { this.callback( [ { addedNodes: addedNodes || [] } ] ); }
};

// ── harness ──────────────────────────────────────────────────────────────────

function makeEnv( runtimeSource ) {
    var doc = new Doc();
    var observers = [];
    var errors = [];

    global.window = global;
    global.document = doc;
    global.MutationObserver = function ( callback ) {
        var observer = new ObserverStub( observers );
        observer.callback = callback;
        return observer;
    };
    global.console = Object.assign( {}, console, {
        error: function () { errors.push( Array.prototype.slice.call( arguments ) ); }
    } );

    // The runtime is an IIFE that registers itself on window.
    ( new Function( runtimeSource ) )();

    return {
        doc: doc,
        observers: observers,
        errors: errors,
        registry: global.AgentshellWidgets,
        fireDomContentLoaded: function () { doc.dispatch( 'DOMContentLoaded' ); }
    };
}

// ── the tests ────────────────────────────────────────────────────────────────

var runtimeSource = fs.readFileSync( runtimePath, 'utf8' );

console.log( 'widget runtime regression tests' );

// 1. Every widget element is initialised, with the element itself.
( function () {
    var env = makeEnv( runtimeSource );
    var seen = [];
    env.registry['atlas'] = { init: function ( el ) { seen.push( el ); el.textContent = 'atlas'; } };

    var a = new El( { 'data-widget': 'atlas' } );
    var b = new El( { 'data-widget-id': 'atlas' } );
    env.doc.body.appendChild( a );
    env.doc.body.appendChild( b );

    env.fireDomContentLoaded();

    eq( seen.length, 2, 'both spellings of the widget attribute are initialised' );
    ok( seen.indexOf( a ) !== -1, 'init receives the element itself' );
    ok( seen.indexOf( b ) !== -1, 'the data-widget-id spelling is initialised too' );
    eq( a.textContent, 'atlas', 'the widget may write to the element' );
} )();

// 2. Initialisation is idempotent.
( function () {
    var env = makeEnv( runtimeSource );
    var count = 0;
    env.registry['atlas'] = { init: function () { count++; } };

    var a = new El( { 'data-widget': 'atlas' } );
    env.doc.body.appendChild( a );

    env.fireDomContentLoaded();
    env.fireDomContentLoaded();
    env.registry.initWidget( a, 'atlas' );

    eq( count, 1, 'an element is initialised exactly once, however many times it is asked for' );
} )();

// 3. One widget's failure is contained.
( function () {
    var env = makeEnv( runtimeSource );
    var okCount = 0;
    env.registry['broken'] = { init: function () { throw new Error( 'boom' ); } };
    env.registry['atlas'] = { init: function () { okCount++; } };

    var broken = new El( { 'data-widget': 'broken' } );
    var atlas = new El( { 'data-widget': 'atlas' } );
    env.doc.body.appendChild( broken );
    env.doc.body.appendChild( atlas );

    env.fireDomContentLoaded();

    eq( okCount, 1, 'a widget whose init throws does not stop the others' );
    eq( env.errors.length, 1, 'the failure is reported once' );
} )();

// 4. Elements added after load are initialised.
( function () {
    var env = makeEnv( runtimeSource );
    var seen = [];
    env.registry['atlas'] = { init: function ( el ) { seen.push( el ); } };

    env.fireDomContentLoaded();
    eq( seen.length, 0, 'nothing to initialise before any element exists' );

    var late = new El( { 'data-widget': 'atlas' } );
    env.doc.body.appendChild( late );

    // The observer is what notices a late element.
    eq( env.observers.length, 1, 'the runtime installs a MutationObserver' );
    env.observers[ 0 ].fire( [ late ] );

    eq( seen.length, 1, 'an element added after load is initialised' );
    ok( seen.indexOf( late ) !== -1, 'the late element is the one initialised' );
} )();

// 5. An element whose widget has not registered yet is left unmarked.
( function () {
    var env = makeEnv( runtimeSource );
    var count = 0;
    env.registry['atlas'] = { init: function () { count++; } };

    var a = new El( { 'data-widget': 'late-registration' } );
    env.doc.body.appendChild( a );

    env.fireDomContentLoaded();
    eq( count, 0, 'a widget that has not registered is not initialised' );

    // The registration arrives later.
    env.registry['late-registration'] = { init: function () { count++; } };
    env.registry.initAll( env.doc );

    eq( count, 1, 'the element is initialised once the widget registers' );
} )();

// 6. initWidget returns whether it ran, and tolerates rubbish arguments.
( function () {
    var env = makeEnv( runtimeSource );
    env.registry['atlas'] = { init: function () {} };

    eq( env.registry.initWidget( null, 'atlas' ), false, 'a null element is refused' );
    eq( env.registry.initWidget( undefined, 'atlas' ), false, 'an undefined element is refused' );
    eq( env.registry.initWidget( new El( {} ), '' ), false, 'an element with no widget id is refused' );
    // An id supplied by the caller is honoured even when the element
    // carries no attribute of its own: the caller has said which widget
    // this is.
    eq( env.registry.initWidget( new El( {} ), 'atlas' ), true, 'an explicit id is honoured' );
} )();

console.log( '' );
console.log( 'widget runtime: ' + passed + ' passed, ' + failed + ' failed' );
process.exit( failed === 0 ? 0 : 1 );
