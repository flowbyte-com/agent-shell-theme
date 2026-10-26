/**
 * Navigation model tests (Node).
 *
 * Run with:  node tests/nav-model-test.js
 * Or via:    php tests/run-nav-model.php
 *
 * The model decides which submenus are open after an interaction. It is the
 * part of the navigation script that can be wrong without looking wrong: two
 * dropdowns open at once, a closed parent leaving a stale `aria-expanded` on a
 * hidden child, Escape closing the wrong level. Those are assertions, not
 * screenshots.
 */

'use strict';

var nav_model = require( '../assets/theme.js' ).nav_model;
var model = nav_model();

var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

function eq_arr( a, b, msg ) {
    var same = JSON.stringify( a ) === JSON.stringify( b );
    if ( same ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

console.log( 'navigation model tests' );

// A tree:  about > team > history   (top > mid > deep)
//          legal   (top)
var parent_of = {
    'about': null,
    'team': 'about',
    'history': 'team',
    'legal': null
};

// 1. Opening a top-level item opens it.
eq_arr( model.next_open( [], parent_of, 'about', false ), [ 'about' ],
    'opening a top-level item opens it' );

// 2. Opening a sibling closes the first: one dropdown at a time.
eq_arr( model.next_open( [ 'about' ], parent_of, 'legal', false ), [ 'legal' ],
    'opening a sibling closes the other dropdown' );

// 3. Opening a child keeps its parent open.
eq_arr( model.next_open( [ 'about' ], parent_of, 'team', false ), [ 'about', 'team' ],
    'opening a child keeps its parent open' );

// 4. Opening a different child closes the first child, keeps the parent.
eq_arr( model.next_open( [ 'about', 'team' ], parent_of, 'history', false ),
    [ 'about', 'team', 'history' ],
    'a grandchild opens inside the open branch' );

// 5. Opening a top-level item closes everything below another branch.
eq_arr( model.next_open( [ 'about', 'team', 'history' ], parent_of, 'legal', false ), [ 'legal' ],
    'opening another branch closes the whole previous branch' );

// 6. Clicking an open top-level item closes it and its descendants.
eq_arr( model.next_open( [ 'about', 'team' ], parent_of, 'about', true ), [],
    'closing a parent closes its open descendants' );

// 7. Closing a child leaves the parent open.
eq_arr( model.next_open( [ 'about', 'team' ], parent_of, 'team', true ), [ 'about' ],
    'closing a child leaves the parent open' );

// 8. Opening the same item twice is idempotent.
eq_arr( model.next_open( model.next_open( [], parent_of, 'about', false ), parent_of, 'about', false ),
    [ 'about' ], 'opening an already-open item does not duplicate it' );

// 9. Escape targets the deepest open item.
eq( model.escape_target( [ 'about', 'team' ] ), 'team', 'Escape targets the deepest open submenu' );
eq( model.escape_target( [ 'about' ] ), 'about', 'Escape targets the only open submenu' );
eq( model.escape_target( [] ), null, 'Escape with nothing open targets nothing' );

// 10. Ancestors are outermost first.
eq_arr( model.ancestors_of( parent_of, 'history' ), [ 'about', 'team' ],
    'ancestors are returned outermost first' );
eq_arr( model.ancestors_of( parent_of, 'about' ), [], 'a top-level item has no ancestors' );

// 11. Descendant detection.
eq( model.is_descendant( parent_of, 'history', 'about' ), true, 'a grandchild is a descendant' );
eq( model.is_descendant( parent_of, 'about', 'history' ), false, 'a parent is not its child\'s descendant' );
eq( model.is_descendant( parent_of, 'legal', 'about' ), false, 'a cousin is not a descendant' );

// 12. is_open is a plain membership test.
eq( model.is_open( [ 'about', 'team' ], 'team' ), true, 'is_open finds an open item' );
eq( model.is_open( [ 'about' ], 'team' ), false, 'is_open rejects a closed item' );

// 13. A malformed tree cannot loop forever.
var cyclic = { a: 'b', b: 'a' };
eq_arr( model.next_open( [], cyclic, 'a', false ).slice( 0, 1 ), [ 'a' ],
    'a cyclic parent map still returns a finite open set' );

// 14. No state is shared between calls: the model is pure.
var before = [ 'about' ];
model.next_open( before, parent_of, 'legal', false );
eq_arr( before, [ 'about' ], 'next_open does not mutate the array it was given' );

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
if ( failed > 0 ) { process.exit( 1 ); }
