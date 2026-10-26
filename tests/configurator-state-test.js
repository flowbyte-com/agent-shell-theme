/**
 * Configurator state-model tests (Node).
 *
 * Run with:  node tests/configurator-state-test.js
 * Or via:    php tests/run-configurator-state.php  (executes Node and asserts)
 */

'use strict';

var make = require( '../configurator/configurator-state.js' ).make_state_model;

var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

console.log( 'configurator state-model tests' );

// 1. Fresh model is idle.
(function () {
    var s = make();
    eq( s.get_state().mode, 'idle', 'fresh model is idle' );
    eq( s.status_label(), 'Idle', 'fresh model status is Idle' );
    eq( s.can_save(), true, 'fresh model can save' );
})();

// 2. begin() transitions idle → editing_open.
(function () {
    var s = make();
    var r = s.begin( 'human edit' );
    eq( r.ok, true, 'begin() on idle returns ok' );
    eq( s.get_state().mode, 'editing_open', 'begin() transitions to editing_open' );
    eq( s.status_label(), 'Editing', 'status is Editing after begin' );
    eq( s.can_save(), true, 'editing_open can save' );
})();

// 3. set_tx_snapshot with foreign tx transitions to locked.
(function () {
    var s = make();
    s.set_tx_snapshot( {
        open: true,
        id: 'tx_other',
        actor: 'agent',
        label: 'agent work',
        owned_by_us: false,
        has_staged: true,
    } );
    eq( s.get_state().mode, 'locked', 'foreign tx → locked' );
    eq( s.can_save(), false, 'locked cannot save' );
    eq( s.status_label(), 'Locked by agent', 'status reports the foreign actor' );
})();

// 4. set_tx_snapshot with own tx transitions to editing_open.
(function () {
    var s = make();
    s.set_tx_snapshot( {
        open: true,
        id: 'tx_self',
        actor: 'self',
        label: 'human edit',
        owned_by_us: true,
        has_staged: false,
    } );
    eq( s.get_state().mode, 'editing_open', 'own tx → editing_open' );
    eq( s.can_save(), true, 'own tx can save (continuing edit)' );
})();

// 5. set_tx_snapshot with no open tx returns to idle from locked.
(function () {
    var s = make();
    s.set_tx_snapshot( { open: true, actor: 'other', owned_by_us: false } );
    eq( s.get_state().mode, 'locked', 'precondition: locked' );
    s.set_tx_snapshot( { open: false } );
    eq( s.get_state().mode, 'idle', 'no tx → idle (foreign tx released)' );
    eq( s.can_save(), true, 'idle can save again' );
})();

// 6. commit() transitions editing_open → idle.
(function () {
    var s = make();
    s.begin( 'human edit' );
    var r = s.commit( 'tx_self' );
    eq( r.ok, true, 'commit() on editing_open returns ok' );
    eq( s.get_state().mode, 'idle', 'commit() transitions to idle' );
    eq( r.committed, 'tx_self', 'commit() returns the tx id' );
})();

// 7. rollback() transitions editing_open → idle.
(function () {
    var s = make();
    s.begin( 'human edit' );
    var r = s.rollback();
    eq( r.ok, true, 'rollback() on editing_open returns ok' );
    eq( s.get_state().mode, 'idle', 'rollback() transitions to idle' );
})();

// 8. rollback() on locked is refused (foreign tx).
(function () {
    var s = make();
    s.set_tx_snapshot( { open: true, actor: 'agent', owned_by_us: false } );
    var r = s.rollback();
    eq( r.ok, false, 'rollback() on locked refuses' );
    eq( r.reason, 'locked', 'rollback() on locked returns reason' );
    eq( s.get_state().mode, 'locked', 'state remains locked' );
})();

// 9. begin() on locked is refused.
(function () {
    var s = make();
    s.set_tx_snapshot( { open: true, actor: 'agent', owned_by_us: false } );
    var r = s.begin( 'human edit' );
    eq( r.ok, false, 'begin() on locked refuses' );
    eq( r.reason, 'locked', 'begin() on locked returns reason' );
})();

// 10. commit() on idle is refused.
(function () {
    var s = make();
    var r = s.commit();
    eq( r.ok, false, 'commit() on idle refuses' );
    eq( r.reason, 'not_editing', 'commit() on idle returns reason' );
})();

// 11. status label for editing_open with staged changes.
(function () {
    var s = make();
    s.set_tx_snapshot( {
        open: true,
        actor: 'self',
        label: 'human edit',
        owned_by_us: true,
        has_staged: true,
    } );
    eq( s.status_label(), 'Editing (staged)', 'status reflects staged changes' );
})();

// ── Nine-area zone model ────────────────────────────────────────────────────
var makeZone = require( '../configurator/configurator-state.js' ).make_zone_model;

// The area list the server sends, in render order.
var AREAS = [
    { id: 'top-left', row: 'top', column: 0, label: 'Top Left', short: 'TL' },
    { id: 'top-center', row: 'top', column: 1, label: 'Top Centre', short: 'TC' },
    { id: 'top-right', row: 'top', column: 2, label: 'Top Right', short: 'TR' },
    { id: 'middle-left', row: 'middle', column: 0, label: 'Middle Left', short: 'ML' },
    { id: 'middle-center', row: 'middle', column: 1, label: 'Middle Centre', short: 'MC' },
    { id: 'middle-right', row: 'middle', column: 2, label: 'Middle Right', short: 'MR' },
    { id: 'bottom-left', row: 'bottom', column: 0, label: 'Bottom Left', short: 'BL' },
    { id: 'bottom-center', row: 'bottom', column: 1, label: 'Bottom Centre', short: 'BC' },
    { id: 'bottom-right', row: 'bottom', column: 2, label: 'Bottom Right', short: 'BR' }
];

// 12. A fresh zone opens on the middle-centre area.
(function () {
    var z = makeZone();
    eq( z.resolve_area( AREAS, undefined, 'middle-center' ), 'middle-center',
        'fresh zone opens on middle-centre' );
})();

// 13. A current selection wins while it is still a real area.
(function () {
    var z = makeZone();
    eq( z.resolve_area( AREAS, 'bottom-right', 'middle-center' ), 'bottom-right',
        'existing selection is kept' );
})();

// 14. A selection the server no longer offers falls back, never to an unknown id.
(function () {
    var z = makeZone();
    eq( z.resolve_area( AREAS, 'left', 'middle-center' ), 'middle-center',
        'legacy/unknown selection falls back to middle-centre' );
    eq( z.resolve_area( [], 'middle-center', 'middle-center' ), '',
        'no areas at all resolves to the empty id, not a guessed one' );
})();

// 15. A move takes the block out of one area and appends it to another.
(function () {
    var z = makeZone();
    var slots = {
        'middle-left': [ { type: 'wp_core', id: 'site_logo' } ],
        'middle-right': [ { type: 'wp_core', id: 'search_form' } ]
    };
    var next = z.move_block( slots, 'middle-left', 'bottom-center', 0 );
    eq( next[ 'middle-left' ].length, 0, 'move empties the source area' );
    eq( next[ 'bottom-center' ].length, 1, 'move appends to the destination area' );
    eq( next[ 'bottom-center' ][ 0 ].id, 'site_logo', 'the moved block is the same block' );
    eq( next[ 'middle-right' ].length, 1, 'unrelated areas are untouched' );
    eq( slots[ 'middle-left' ].length, 1, 'the input slots are not mutated' );
})();

// 16. Nonsense moves are no-ops, not surprises.
(function () {
    var z = makeZone();
    var slots = { 'middle-left': [ { type: 'wp_core', id: 'site_logo' } ] };
    eq( z.move_block( slots, 'middle-left', 'middle-left', 0 )[ 'middle-left' ].length, 1,
        'moving an area onto itself changes nothing' );
    eq( ( z.move_block( slots, 'middle-left', 'top-left', 99 )[ 'top-left' ] || [] ).length, 0,
        'an out-of-range index moves nothing' );
    eq( slots[ 'middle-left' ].length, 1, 'a refused move leaves the input alone' );
})();

// 17. Layout mode follows content: middle-only stays standard, any other row expands.
(function () {
    var z = makeZone();
    eq( z.layout( AREAS, { 'middle-left': [ { type: 'wp_core', id: 'site_logo' } ] } ), 'standard',
        'middle-row content keeps the three-slot layout' );
    eq( z.layout( AREAS, {
        'middle-left': [ { type: 'wp_core', id: 'site_logo' } ],
        'top-right': [ { type: 'wp_core', id: 'search_form' } ]
    } ), 'expanded', 'top-row content switches to the nine-area layout' );
    eq( z.layout( AREAS, {
        'bottom-center': [ { type: 'json_block', content: '<p>x</p>' } ]
    } ), 'expanded', 'bottom-row content switches to the nine-area layout' );
    eq( z.layout( AREAS, {} ), 'standard', 'an empty grid is standard' );
})();

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
if ( failed > 0 ) { process.exit( 1 ); }