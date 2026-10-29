'use strict';

var assert = require( 'assert' );

var model = require( '../agentshell-forms/admin/js/forms-model' );

function make( saved ) {
    return model.make_form_model( saved || {} );
}

// Dirty is computed from the data, never from a flag.
var m = make( { id: 'f1', name: 'Contact', revision: 3, working: { fields: [ { key: 'name', type: 'text', label: 'Name' } ] }, published: null } );
assert.strictEqual( m.dirty(), false, 'a fresh model is clean' );

m.working.fields.push( { key: 'email', type: 'email', label: 'Email' } );
assert.strictEqual( m.dirty(), true, 'adding a field makes the draft dirty' );

m.saved = model.clone( m.working );
assert.strictEqual( m.dirty(), false, 'reconciling the saved copy clears dirty' );

// Reorder is immutable and reports whether it happened.
var before = [ 'a', 'b', 'c' ].map( function ( k ) { return { key: k }; } );
var moved = model.move_field( before, 0, 2 );
assert.strictEqual( moved.moved, true, 'a valid move reports moved' );
assert.deepStrictEqual( moved.fields.map( function ( f ) { return f.key; } ), [ 'b', 'c', 'a' ], 'the item lands where it was sent' );
assert.deepStrictEqual( before.map( function ( f ) { return f.key; } ), [ 'a', 'b', 'c' ], 'move_field does not mutate the input' );

var invalid = model.move_field( before, 0, 9 );
assert.strictEqual( invalid.moved, false, 'an out-of-range target does not move' );
assert.deepStrictEqual( invalid.fields.map( function ( f ) { return f.key; } ), [ 'a', 'b', 'c' ], 'an invalid move leaves the list untouched' );

// The field model normalises a missing field.
var field = model.make_field_model( { key: 'consent', type: 'consent', required: true }, 0 );
assert.strictEqual( field.key, 'consent' );
assert.strictEqual( field.type, 'consent' );
assert.strictEqual( field.required, true );
assert.deepStrictEqual( field.settings, {} );

// The payload shape carries the revision the server must compare against.
var m2 = make( { id: 'f2', revision: 7, working: { fields: [] } } );
var payload = m2.to_payload();
assert.strictEqual( payload.form_id, 'f2' );
assert.strictEqual( payload.expected_revision, 7 );
assert.ok( payload.definition && Array.isArray( payload.definition.fields ) );

// reconcile_save advances the revision and clears dirty.
m2.working.fields.push( { key: 'name', type: 'text' } );
assert.strictEqual( m2.dirty(), true );
m2.reconcile_save( 8 );
assert.strictEqual( m2.dirty(), false, 'reconcile_save clears dirty' );
assert.strictEqual( m2.revision, 8, 'reconcile_save records the server revision' );
assert.strictEqual( m2.saved_revision, 8 );

// discard restores the published definition.
var m3 = make( { id: 'f3', revision: 2, working: { fields: [ { key: 'a' } ] }, published: { fields: [ { key: 'b' } ] } } );
m3.discard();
assert.deepStrictEqual( m3.working.fields.map( function ( f ) { return f.key; } ), [ 'b' ], 'discard restores the published field list' );
assert.strictEqual( m3.dirty(), false, 'discard leaves the model clean' );

// status_label never says saved for unsaved work.
var dirty_model = make( { id: 'fd', name: 'Dirty', revision: 1, working: { fields: [ { key: 'a' } ] } } );
dirty_model.working.fields.push( { key: 'b' } );
assert.notEqual( model.status_label( dirty_model, false ), 'saved', 'a dirty model is never labelled saved' );
assert.strictEqual( model.status_label( m2, false ), 'saved', 'clean, never published => saved' );

console.log( 'forms builder models: all assertions passed' );
