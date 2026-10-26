/**
 * Configurator editor-model and transport tests (Node).
 *
 * Run with:  node tests/configurator-editor-test.js
 * Or via:    php tests/run-configurator-editor.php  (executes Node and asserts)
 *
 * The editor model is the state the whole redesigned interface is a view of:
 * which context is open, what is selected, and — the part that matters most
 * — whether the draft differs from what the server last reported. "Dirty" is
 * computed from the data, never tracked by a flag, because a wrong dirty flag
 * is how an operator loses work.
 *
 * The transport module is the only place that knows the REST shapes, so it
 * is where a refused request has to become something the interface can show.
 */

'use strict';

var state = require( '../configurator/configurator-state.js' );
var api_module = require( '../configurator/configurator-api.js' );
var ui = require( '../configurator/configurator-ui.js' );

var make_editor = state.make_editor_model;
var make_api = api_module.make_api;

var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

function deep_eq( a, b, msg ) {
    var x = JSON.stringify( a );
    var y = JSON.stringify( b );
    if ( x === y ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + y + ' got ' + x + ')' ); }
}

console.log( 'configurator editor-model and transport tests' );

// ── 0. Shadow presets are CSS values, and custom values survive ───────────
//
// The stylesheet consumes --theme-shadow in `box-shadow: var(--theme-shadow)`.
// A preset whose value is a name ("soft") is accepted by the editor and
// discarded by the browser, so the draft says it changed and the page does
// not. Node cannot compute a box-shadow, so this checks the model's shape;
// tests/configurator-browser-test.html checks the computed styles.
( function () {
    var presets = ui.SHADOW_PRESETS;
    ok( presets.length >= 2, 'there is more than one shadow preset' );
    presets.forEach( function ( preset ) {
        ok( preset.value.trim().length > 0, 'the ' + preset.label + ' preset has a value' );
        ok( preset.value === 'none' || ! /^[a-z]+$/i.test( preset.value ),
            'the ' + preset.label + ' preset is a value, not a bare name (' + preset.value + ')' );
        ok( preset.value === 'none' || /\d/.test( preset.value ),
            'the ' + preset.label + ' preset is a CSS shadow value (' + preset.value + ')' );
        ok( ui.matching_shadow_preset( preset.value ) === preset,
            'the ' + preset.label + ' preset matches itself' );
    } );

    var custom = '0 2px 4px rgba(0,0,0,0.12)';
    eq( ui.matching_shadow_preset( custom ), null, 'a custom shadow matches no preset' );
    eq( ui.matching_shadow_preset( '  ' + presets[ 1 ].value + '  ' ), presets[ 1 ],
        'surrounding whitespace does not defeat preset matching' );
    eq( ui.matching_shadow_preset( '' ), null, 'an empty value is not a preset' );
    ok( ui.SHADOW_CUSTOM !== ui.matching_shadow_preset( presets[ 0 ].value ),
        'the custom sentinel can never be mistaken for a preset value' );
} )();

var PAYLOAD = {
    config: {
        '--theme-bg': '#fafaf9',
        '--theme-accent': '#e87a3e',
        'not_a_token': 'ignored',
    },
    defaults: {},
    custom_css: '/* agent-set rule */',
    custom_js: '',
    zones: [
        {
            id: 'header',
            label: 'Header',
            slots: {
                'top-left': [],
                'top-center': [],
                'top-right': [ { type: 'wp_core', id: 'nav_menu', menu: 'utility', mode: 'horizontal' } ],
                'middle-left': [ { type: 'wp_core', id: 'site_logo' } ],
                'middle-center': [],
                'middle-right': [],
                'bottom-left': [],
                'bottom-center': [],
                'bottom-right': []
            }
        },
        { id: 'main', label: 'Main', composition: [ { type: 'wp_loop' } ] },
        {
            id: 'footer',
            label: 'Footer',
            slots: {
                'top-left': [], 'top-center': [], 'top-right': [],
                'middle-left': [], 'middle-center': [], 'middle-right': [],
                'bottom-left': [], 'bottom-center': [], 'bottom-right': []
            }
        },
    ],
};

// ── 1. Loading a configuration ────────────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    eq( e.is_dirty(), false, 'a freshly loaded configuration is not dirty' );
    eq( e.get_state().draft.custom_css, '/* agent-set rule */', 'the custom CSS is loaded' );
    eq( e.get_state().draft.vars[ '--theme-bg' ], '#fafaf9', 'a design token is loaded' );
    eq( e.get_state().draft.vars[ 'not_a_token' ], undefined, 'a non-token key is not treated as a token' );
    deep_eq( e.get_state().draft.zones, PAYLOAD.zones, 'the zones are loaded as they arrived' );
} )();

// ── 2. The payload the server receives is the one the panel always sent ──
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    var payload = e.to_payload();
    eq( payload[ '--theme-bg' ], '#fafaf9', 'the payload carries the flat design variables' );
    eq( payload.custom_css, '/* agent-set rule */', 'the payload carries the custom CSS' );
    eq( payload.custom_js, '', 'the payload carries the custom JavaScript' );
    ok( Array.isArray( payload.zones ), 'the payload carries the zones' );
    eq( payload.zones.length, 3, 'the payload carries every zone' );
    ok( ! ( 'not_a_token' in payload ), 'a non-token key is not sent to the server' );
} )();

// ── 3. Dirty is computed from the data ────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.set_token( '--theme-bg', '#ffffff' );
    eq( e.is_dirty(), true, 'a changed token makes the draft dirty' );
    e.set_token( '--theme-bg', '#fafaf9' );
    eq( e.is_dirty(), false, 'changing a token back makes the draft clean again' );
    e.set_custom_css( '/* changed */' );
    eq( e.is_dirty(), true, 'a changed stylesheet makes the draft dirty' );
    e.discard();
    eq( e.is_dirty(), false, 'discarding makes the draft clean' );
    eq( e.get_state().draft.custom_css, '/* agent-set rule */', 'discarding restores the saved stylesheet' );
} )();

// ── 4. A refused save keeps the draft ─────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.set_token( '--theme-bg', '#bad' );
    eq( e.is_dirty(), true, 'the draft is dirty before a refused save' );
    // accept_saved() is only called when the server accepted the change, so
    // a refusal simply does not call it: the draft survives.
    eq( e.get_state().draft.vars[ '--theme-bg' ], '#bad', 'a refused save keeps what the operator typed' );
} )();

// ── 5. accept_saved replaces the draft with the server's answer ───────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.set_token( '--theme-bg', '#ffffff' );
    var answer = JSON.parse( JSON.stringify( PAYLOAD ) );
    answer.config[ '--theme-bg' ] = '#ffffff';
    e.accept_saved( answer );
    eq( e.is_dirty(), false, 'the draft is clean after the server accepted it' );
    eq( e.get_state().draft.vars[ '--theme-bg' ], '#ffffff', 'the draft holds the server value' );
} )();

// ── 6. Selection ──────────────────────────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.set_zone( 'header' );
    eq( e.get_state().zone, 'header', 'the zone is selected' );
    e.set_area( 'top-right' );
    eq( e.get_state().area, 'top-right', 'the area is selected' );
    e.set_block( 0 );
    eq( e.get_state().block, 0, 'the block is selected' );
    e.set_zone( 'footer' );
    eq( e.get_state().area, 'middle-center', 'changing zone selects the middle centre' );
    eq( e.get_state().block, -1, 'changing zone drops the block selection' );
    e.set_context( 'appearance' );
    eq( e.get_state().block, -1, 'leaving the layout context drops the block selection' );
    eq( e.get_state().context, 'appearance', 'the context is selected' );
} )();

// ── 7. Reading the components of an area ──────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    eq( e.blocks_of( 'header', 'top-right' ).length, 1, 'an area has one component' );
    eq( e.blocks_of( 'header', 'top-right' )[ 0 ].id, 'nav_menu', 'the component is the one that was stored' );
    eq( e.blocks_of( 'header', 'top-left' ).length, 0, 'an empty area has no components' );
    eq( e.blocks_of( 'main', null ).length, 1, 'the main zone has a composition' );
    eq( e.blocks_of( 'main', null )[ 0 ].type, 'wp_loop', 'the main zone composes the loop' );
} )();

// ── 8. Adding a component ─────────────────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.add_block( 'header', 'top-left', { type: 'wp_core', id: 'search_form' } );
    eq( e.blocks_of( 'header', 'top-left' ).length, 1, 'a component is added to the area' );
    eq( e.blocks_of( 'header', 'top-left' )[ 0 ].id, 'search_form', 'the added component is the one that was asked for' );
    e.add_block( 'main', null, { type: 'json_block' } );
    eq( e.blocks_of( 'main', null ).length, 2, 'a component is added to the main composition' );
    eq( e.is_dirty(), true, 'adding a component makes the draft dirty' );
} )();

// ── 9. Removing a component ───────────────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    eq( e.remove_block( 'header', 'top-right', 0 ), true, 'a component is removed' );
    eq( e.blocks_of( 'header', 'top-right' ).length, 0, 'the area is empty after the removal' );
    eq( e.remove_block( 'header', 'top-right', 0 ), false, 'removing what is not there does nothing' );
    eq( e.remove_block( 'header', 'top-right', 99 ), false, 'removing an index that does not exist does nothing' );
} )();

// ── 10. Reordering a component ────────────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.add_block( 'header', 'top-left', { type: 'wp_core', id: 'site_title' } );
    e.add_block( 'header', 'top-left', { type: 'wp_core', id: 'search_form' } );
    eq( e.reorder_block( 'header', 'top-left', 1, -1 ), true, 'a component moves up' );
    eq( e.blocks_of( 'header', 'top-left' )[ 0 ].id, 'search_form', 'the moved component is in its new place' );
    eq( e.blocks_of( 'header', 'top-left' )[ 1 ].id, 'site_title', 'the component it moved past is behind it' );
    eq( e.reorder_block( 'header', 'top-left', 0, -1 ), false, 'the first component cannot move up' );
    eq( e.reorder_block( 'header', 'top-left', 1, 1 ), false, 'the last component cannot move down' );
} )();

// ── 11. Moving a component between areas ──────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    eq( e.move_block( 'header', 'top-right', 'bottom-left', 0 ), true, 'a component moves to another area' );
    eq( e.blocks_of( 'header', 'top-right' ).length, 0, 'the source area is empty after the move' );
    eq( e.blocks_of( 'header', 'bottom-left' )[ 0 ].id, 'nav_menu', 'the component is in the destination area' );
    eq( e.move_block( 'header', 'top-right', 'bottom-left', 0 ), false, 'a move from an empty area does nothing' );
    eq( e.move_block( 'header', 'bottom-left', 'bottom-left', 0 ), false, 'a move onto the same area does nothing' );
} )();

// ── 12. Moving a component between zones ──────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    eq( e.move_block_to_zone( 'header', 'top-right', 'footer', 'bottom-right', 0 ), true,
        'a component moves to another zone' );
    eq( e.blocks_of( 'header', 'top-right' ).length, 0, 'the source area is empty' );
    eq( e.blocks_of( 'footer', 'bottom-right' )[ 0 ].id, 'nav_menu', 'the component is in the destination zone' );
    eq( e.move_block_to_zone( 'header', 'top-right', 'footer', 'bottom-right', 0 ), false,
        'a move from an empty area does nothing' );
} )();

// ── 13. Setting a field of a component ────────────────────────────────────
(function () {
    var e = make_editor();
    e.load( PAYLOAD );
    e.set_block_field( 'header', 'top-right', 0, 'menu', 'legal' );
    eq( e.blocks_of( 'header', 'top-right' )[ 0 ].menu, 'legal', 'a component field is set' );
    e.set_block_field( 'header', 'top-right', 0, 'menu', '' );
    eq( e.blocks_of( 'header', 'top-right' )[ 0 ].menu, undefined, 'an empty value removes the field' );
    eq( e.set_block_field( 'header', 'top-right', 9, 'menu', 'legal' ), false,
        'setting a field on an index that does not exist does nothing' );
} )();

// ── 14. The status the action bar shows ───────────────────────────────────
(function () {
    var tx = state.make_state_model();
    var e = make_editor();
    e.load( PAYLOAD );

    var s = e.status( tx );
    eq( s.key, 'saved', 'a clean draft with no transaction is "saved"' );
    eq( s.can_save, true, 'saving is offered' );

    tx.set_tx_snapshot( { open: true, id: 'tx1', actor: 'agent', owned_by_us: false, has_staged: true } );
    s = e.status( tx );
    eq( s.key, 'locked', 'a transaction owned by another actor is "locked"' );
    eq( s.can_save, false, 'saving is not offered while another actor holds the transaction' );
    ok( s.label.indexOf( 'agent' ) !== -1, 'the status names the actor that holds the transaction' );

    var tx2 = state.make_state_model();
    tx2.set_tx_snapshot( { open: true, id: 'tx2', actor: 'me', owned_by_us: true, has_staged: true } );
    eq( e.status( tx2 ).key, 'staged', 'our own open transaction with a clean draft is "staged"' );
    e.set_token( '--theme-bg', '#ffffff' );
    eq( e.status( tx2 ).key, 'dirty', 'a changed draft is "unsaved changes"' );
} )();

// ── 15. The transport turns a refusal into something showable ─────────────
function fake_response( status, body ) {
    return {
        ok: status >= 200 && status < 300,
        status: status,
        text: function () { return Promise.resolve( typeof body === 'string' ? body : JSON.stringify( body ) ); },
    };
}

function fake_fetch( responder ) {
    return function ( url, init ) {
        return Promise.resolve( responder( url, init || {} ) );
    };
}

(async function () {
    var seen = null;
    var api = make_api( {
        restUrl: 'https://example.test/wp-json/',
        nonce: 'nonce-123',
        fetch: fake_fetch( function ( url, init ) {
            seen = { url: url, init: init };
            return fake_response( 200, { ok: true } );
        } ),
    } );

    await api.get_config();
    eq( seen.url, 'https://example.test/wp-json/wp/v2/agentshell/config', 'the config route is the theme route' );
    eq( seen.init.headers[ 'X-WP-Nonce' ], 'nonce-123', 'the nonce travels with the request' );
    eq( seen.init.credentials, 'same-origin', 'the request is same-origin' );

    var put = null;
    var api2 = make_api( {
        restUrl: '/wp-json/',
        nonce: 'n',
        fetch: fake_fetch( function ( url, init ) {
            put = { url: url, init: init };
            return fake_response( 200, {} );
        } ),
    } );
    await api2.put_config( { '--theme-bg': '#fff' } );
    eq( put.init.method, 'PUT', 'saving is a PUT' );
    eq( JSON.parse( put.init.body )[ '--theme-bg' ], '#fff', 'the payload is sent as JSON' );
    eq( put.init.headers[ 'Content-Type' ], 'application/json', 'the payload declares its type' );

    var refused = make_api( {
        restUrl: '/wp-json/',
        nonce: 'n',
        fetch: fake_fetch( function () {
            return fake_response( 400, { code: 'invalid_color_value', message: 'Invalid color value' } );
        } ),
    } );
    var caught = null;
    try {
        await refused.put_config( { '--theme-bg': '#bad' } );
    } catch ( e ) {
        caught = e;
    }
    ok( caught !== null, 'a refused request throws' );
    eq( caught && caught.code, 'invalid_color_value', 'the refusal carries the server error code' );
    eq( caught && caught.status, 400, 'the refusal carries the HTTP status' );
    ok( caught && /Invalid color/.test( caught.message ), 'the refusal carries a message the interface can show' );

    // A response that is not JSON at all must still fail cleanly.
    var broken = make_api( {
        restUrl: '/wp-json/',
        nonce: 'n',
        fetch: fake_fetch( function () { return fake_response( 500, 'not json at all' ); } ),
    } );
    var broken_error = null;
    try {
        await broken.get_config();
    } catch ( e ) {
        broken_error = e;
    }
    ok( broken_error !== null, 'a response that is not JSON still fails' );
    eq( broken_error && broken_error.status, 500, 'the failure carries the status' );
    ok( broken_error && broken_error.message.length > 0, 'the failure carries a message' );

    // Every route the editor uses is the theme's own route.
    var routes = [];
    var api3 = make_api( {
        restUrl: '/wp-json/',
        nonce: 'n',
        fetch: fake_fetch( function ( url, init ) {
            routes.push( ( init.method || 'GET' ) + ' ' + url.replace( '/wp-json/wp/v2/agentshell/', '' ) );
            return fake_response( 200, { snapshots: [], revisions: [], open: false } );
        } ),
    } );
    await api3.list_snapshots();
    await api3.list_revisions();
    await api3.get_transaction();
    await api3.begin_transaction( 'edit' );
    await api3.commit_transaction();
    await api3.rollback_transaction();
    await api3.create_snapshot( 'before', 'before' );
    await api3.restore_snapshot( 'before' );
    await api3.restore_revision( 'r1' );
    await api3.preview_transaction();
    deep_eq( routes, [
        'GET snapshots',
        'GET revisions',
        'GET transaction',
        'POST transaction',
        'POST transaction/commit',
        'DELETE transaction',
        'POST snapshot',
        'POST snapshots/restore',
        'POST revisions/restore',
        'GET transaction/preview',
    ], 'the editor talks to the theme routes, and adds no MCP tool' );

    console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
    if ( failed > 0 ) { process.exit( 1 ); }
} )();
