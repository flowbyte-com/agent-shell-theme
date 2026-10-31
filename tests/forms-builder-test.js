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

// The field model normalises a missing field.
var field = model.make_field_model( { key: 'consent', type: 'consent', required: true }, 0 );
assert.strictEqual( field.key, 'consent' );
assert.strictEqual( field.required, true );
assert.deepStrictEqual( field.settings, {} );

// The payload shape carries the revision the server must compare against.
var m2 = make( { id: 'f2', revision: 7, working: { fields: [] } } );
var payload = m2.to_payload();
assert.strictEqual( payload.form_id, 'f2' );
assert.strictEqual( payload.expected_revision, 7 );
assert.ok( payload.definition && Array.isArray( payload.definition.fields ) );

// reconcile_save advances the revision.
m2.working.fields.push( { key: 'name', type: 'text' } );
assert.strictEqual( m2.dirty(), true );
m2.reconcile_save( m2.snapshot(), 8 );
assert.strictEqual( m2.dirty(), false, 'reconcile_save clears dirty when the draft is what was saved' );
assert.strictEqual( m2.revision, 8 );
assert.strictEqual( m2.saved_revision, 8 );

// discard reconciliation uses the server's state, including the revision.
var m3 = make( { id: 'f3', revision: 2, working: { fields: [ { key: 'a' } ] }, published: { fields: [ { key: 'b' } ] } } );
m3.reconcile_discard( { working: { fields: [ { key: 'b' } ] }, published: { fields: [ { key: 'b' } ] }, revision: 9, version: 4 } );
assert.deepStrictEqual( m3.working.fields.map( function ( f ) { return f.key; } ), [ 'b' ], 'discard takes the server working definition' );
assert.strictEqual( m3.revision, 9, 'discard takes the server revision, so the next save matches' );
assert.strictEqual( m3.dirty(), false );

// ── A. in-flight save: newer edits are never marked saved ──────────────────
var m4 = make( { id: 'f4', revision: 1, working: { fields: [ { key: 'a' } ] } } );
var snapshot = m4.snapshot();
assert.notStrictEqual( snapshot.definition, m4.working, 'the snapshot is a copy, not the live object' );

// The operator edits while the request is in flight.
m4.working.fields.push( { key: 'b' } );
m4.reconcile_save( snapshot, 2 );

assert.strictEqual( m4.revision, 2, 'the server revision is recorded' );
assert.strictEqual( m4.saved.fields.length, 1, 'the saved copy is the snapshot that was saved' );
assert.strictEqual( m4.dirty(), true, 'the newer edit is still unsaved' );
assert.notStrictEqual( model.status_label( m4, false ), 'saved', 'the status never says saved for the newer edit' );

// A second save settles the rest.
var snapshot2 = m4.snapshot();
m4.reconcile_save( snapshot2, 3 );
assert.strictEqual( m4.dirty(), false );
assert.strictEqual( m4.revision, 3 );

// ── C. discard reconciliation: the next save uses the server revision ──────
var m5 = make( { id: 'f5', revision: 5, working: { fields: [ { key: 'a' } ] }, published: { fields: [] } } );
m5.reconcile_discard( { working: { fields: [] }, published: { fields: [] }, revision: 6, version: 1 } );
assert.strictEqual( m5.to_payload().expected_revision, 6, 'the save after a discard carries the revision the discard produced' );

// status_label never says saved for unsaved work.
var dirty_model = make( { id: 'fd', name: 'Dirty', revision: 1, working: { fields: [ { key: 'a' } ] } } );
dirty_model.working.fields.push( { key: 'b' } );
assert.notEqual( model.status_label( dirty_model, false ), 'saved', 'a dirty model is never labelled saved' );
assert.strictEqual( model.status_label( make( { id: 'f6', revision: 1, working: { fields: [] } } ), false ), 'saved' );

// The palette vocabulary is the ten MVP types.
assert.deepStrictEqual( model.TYPES, [ 'text', 'textarea', 'email', 'tel', 'number', 'select', 'radio', 'checkbox', 'consent', 'hidden' ] );

// A field built by the inspector carries only the schema's keys.
var built = model.field_to_definition( {
    key: 'topics',
    type: 'select',
    label: 'Topics',
    required: true,
    placeholder: 'Pick one',
    settings: { max_length: 10, autocomplete: 'off', unknown: 'x' },
    options: [ { value: 'a', label: 'A' } ],
} );
assert.deepStrictEqual( built.options, [ { value: 'a', label: 'A' } ] );
assert.strictEqual( built.settings.unknown, undefined, 'an unknown setting is not passed through' );
assert.strictEqual( built.settings.max_length, undefined, 'a setting the type does not use is not passed through' );
assert.strictEqual( built.placeholder, 'Pick one' );

// Settings edits are path-based and do not mutate a previously taken snapshot.
var m7 = make( { id: 'f7', revision: 1, working: { fields: [] } } );
m7.set_setting( 'notification.enabled', true );
m7.set_setting( 'spam.rate_limit.max', 9 );
assert.strictEqual( m7.working.settings.notification.enabled, true );
assert.strictEqual( m7.working.settings.spam.rate_limit.max, 9 );
assert.strictEqual( m7.dirty(), true, 'a settings edit makes the draft dirty' );

console.log( 'forms builder models: all assertions passed' );

// ── uncertain outcomes: revision movement alone never proves a write ───────
var attempted = { fields: [ { key: 'a' } ] };
var other = { fields: [ { key: 'a' }, { key: 'b' } ] };

// Save: the server holding the attempted definition means it landed, even
// with newer local edits present (the caller keeps working untouched).
assert.strictEqual( model.decide_unknown_outcome( 'save', attempted, 5, { working: attempted, revision: 6 } ), 'landed', 'save: identical content means landed' );
// Same revision, different content: the write never landed.
assert.strictEqual( model.decide_unknown_outcome( 'save', attempted, 5, { working: { fields: [] }, revision: 5 } ), 'not_saved', 'save: same revision means not saved' );
// Moved revision, different content: another editor's save, not ours.
assert.strictEqual( model.decide_unknown_outcome( 'save', attempted, 5, { working: other, revision: 6 } ), 'conflict', 'save: moved revision with other content is a conflict' );

// Publish: only the published document proves it.
assert.strictEqual( model.decide_unknown_outcome( 'publish', attempted, 5, { working: attempted, revision: 6, published: attempted, version: 2 } ), 'landed', 'publish: matching published means landed' );
assert.strictEqual( model.decide_unknown_outcome( 'publish', attempted, 5, { working: attempted, revision: 5, published: { fields: [] }, version: 1 } ), 'not_saved', 'publish: same revision means not saved' );
assert.strictEqual( model.decide_unknown_outcome( 'publish', attempted, 5, { working: other, revision: 6, published: other, version: 2 } ), 'conflict', 'publish: other published content is a conflict' );

// Discard: landed exactly when working equals published.
assert.strictEqual( model.decide_unknown_outcome( 'discard', null, 5, { working: { fields: [] }, revision: 6, published: { fields: [] } } ), 'landed', 'discard: working==published means landed' );
assert.strictEqual( model.decide_unknown_outcome( 'discard', null, 5, { working: attempted, revision: 5, published: { fields: [] } } ), 'not_saved', 'discard: same revision means not saved' );

console.log( 'forms builder uncertain outcomes: all assertions passed' );
