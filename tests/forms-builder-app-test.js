'use strict';

/* App-level regression tests for the builder's state integrity.
 *
 * These exercise the real FormsApp (not only the pure model) with a stubbed
 * transport: deferred promises let two requests overlap exactly the way two
 * racing REST responses do in the browser.
 */

var assert = require( 'assert' );

global.AgentshellFormsModel = require( '../wordpress/plugins/agentshell-forms/admin/js/forms-model' );

var FormsApp = require( '../wordpress/plugins/agentshell-forms/admin/js/forms-app' ).FormsApp;

function deferred() {
    var d = {};
    d.promise = new Promise( function ( resolve, reject ) {
        d.resolve = resolve;
        d.reject = reject;
    } );
    return d;
}

function stub_root() {
    return { addEventListener: function () {} };
}

function form_body( id, revision, fields, extra ) {
    var definition = {
        schema_version: 1,
        form_id: id,
        slug: 'slug-' + id,
        name: 'Name ' + id,
        description: '',
        version: 0,
        status: 'draft',
        fields: fields,
        settings: {},
    };
    return Object.assign(
        {
            form_id: id,
            name: 'Name ' + id,
            slug: 'slug-' + id,
            version: 0,
            revision: revision,
            working: definition,
            published: null,
        },
        extra || {}
    );
}

function field( key ) {
    return { key: key, type: 'text', label: key, required: false, settings: {}, options: [] };
}

function make_app( model, api ) {
    return new FormsApp( stub_root(), api, model || null );
}

function make_model( id, revision, fields ) {
    return global.AgentshellFormsModel.make_form_model( {
        id: id,
        name: 'Name ' + id,
        slug: 'slug-' + id,
        version: 0,
        revision: revision,
        working: {
            schema_version: 1,
            form_id: id,
            slug: 'slug-' + id,
            name: 'Name ' + id,
            description: '',
            version: 0,
            status: 'draft',
            fields: fields,
            settings: {},
        },
        published: null,
    } );
}

async function test_stale_load_dropped() {
    var gets = {};
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function ( id ) {
            gets[ id ] = deferred();
            return gets[ id ].promise;
        },
    };

    var app = make_app( make_model( 'a', 1, [ field( 'x' ) ] ), api );
    app.selected_form = 'a';

    var first = app.load_form( 'b' );
    var second = app.load_form( 'c' );

    gets.c.resolve( { ok: true, body: form_body( 'c', 5, [ field( 'y' ) ] ) } );
    await second;
    assert.strictEqual( app.model.id, 'c', 'the second load wins once it resolves' );

    gets.b.resolve( { ok: true, body: form_body( 'b', 7, [ field( 'z' ) ] ) } );
    await first;
    assert.strictEqual( app.model.id, 'c', 'a stale first response must not replace the model' );
    assert.strictEqual( app.selected_form, 'c', 'form selection follows the winning load' );
    assert.strictEqual( app.model.revision, 5, 'the stale revision is not applied' );

    console.log( 'ok - a stale load response never replaces the model' );
}

async function test_inflight_save_preserves_edits() {
    var saved_calls = [];
    var save_gate = deferred();
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
        save: function ( id, expected_revision, definition ) {
            saved_calls.push( { id: id, expected_revision: expected_revision, definition: definition } );
            return save_gate.promise;
        },
    };

    var model = make_model( 'f', 1, [ field( 'a' ) ] );
    var app = make_app( model, api );
    app.selected_form = 'f';

    var pending = app.save();

    // The operator edits while the request is in flight.
    model.working.fields.push( field( 'b' ) );
    assert.strictEqual( model.dirty(), true, 'the in-flight edit makes the draft dirty' );

    save_gate.resolve( { ok: true, body: { revision: 2 } } );
    await pending;

    assert.strictEqual( saved_calls.length, 1, 'one save request is sent' );
    assert.strictEqual( saved_calls[ 0 ].expected_revision, 1, 'the save carries the revision it was made against' );
    assert.deepStrictEqual(
        saved_calls[ 0 ].definition.fields.map( function ( f ) { return f.key; } ),
        [ 'a' ],
        'the server receives the snapshot, not the newer edit'
    );
    assert.deepStrictEqual(
        model.working.fields.map( function ( f ) { return f.key; } ),
        [ 'a', 'b' ],
        'the browser retains the newer edit'
    );
    assert.strictEqual( model.dirty(), true, 'the newer edit is still unsaved' );
    assert.strictEqual( app.status, 'unsaved', 'the status chip reads Unsaved changes' );

    console.log( 'ok - an in-flight save reconciles its snapshot and keeps newer edits' );
}

async function test_save_after_form_switch() {
    var save_gate = deferred();
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
        save: function () { return save_gate.promise; },
    };

    var model_a = make_model( 'a', 1, [ field( 'x' ) ] );
    var model_b = make_model( 'b', 3, [ field( 'y' ) ] );
    var app = make_app( model_a, api );
    app.selected_form = 'a';

    var pending = app.save();

    // The operator switches forms while the first form's save is in flight.
    app.model = model_b;
    app.selected_form = 'b';

    save_gate.resolve( { ok: true, body: { revision: 2 } } );
    await pending;

    assert.deepStrictEqual(
        model_b.working.fields.map( function ( f ) { return f.key; } ),
        [ 'y' ],
        'the other form keeps its own working draft'
    );
    assert.strictEqual( model_b.revision, 3, 'the other form keeps its own revision' );
    assert.deepStrictEqual(
        model_a.working.fields.map( function ( f ) { return f.key; } ),
        [ 'x' ],
        'the saved form is untouched in memory'
    );

    console.log( 'ok - a save that resolves after a form switch never touches the other model' );
}

async function test_reconcile_conflict_preserves_edits() {
    var server_working = { fields: [ field( 'a' ), field( 'rival' ) ] };
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [ { form_id: 'f' } ] } } ); },
        get: function () {
            return Promise.resolve( { ok: true, body: { working: server_working, revision: 6, published: null, version: 0 } } );
        },
    };

    var model = make_model( 'f', 5, [ field( 'a' ) ] );
    var attempted = model.snapshot();
    var app = make_app( model, api );
    app.selected_form = 'f';

    // Newer unsaved edits land after the uncertain attempt.
    model.working.fields.push( field( 'mine' ) );

    app.reconcile_unknown( 'Save outcome could not be verified.', attempted, 'save' );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );

    assert.strictEqual( app.status, 'stale', 'a concurrent write is reported as a conflict' );
    assert.ok( /expected=5/.test( app.error ), 'the conflict names the expected revision' );
    assert.ok( /authoritative=6/.test( app.error ), 'the conflict names the authoritative revision' );
    assert.deepStrictEqual(
        model.working.fields.map( function ( f ) { return f.key; } ),
        [ 'a', 'mine' ],
        'the working draft keeps the newer unsaved edit'
    );
    assert.strictEqual( model.revision, 6, 'the saved pointer is rebased on the server truth' );
    assert.deepStrictEqual(
        model.saved.fields.map( function ( f ) { return f.key; } ),
        [ 'a', 'rival' ],
        'saved reflects the server, not the uncertain attempt'
    );

    console.log( 'ok - an uncertain outcome never mistakes another editor save for ours' );
}

async function test_reconcile_landed_despite_normalisation() {
    var attempted = {
        fields: [ { key: 'a', type: 'text', label: 'A' } ],
        settings: {},
    };
    var server_working = {
        fields: [ { key: 'a', type: 'text', label: 'A', required: false, description: '', placeholder: '', settings: {}, options: [] } ],
        settings: {},
    };
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [ { form_id: 'f' } ] } } ); },
        get: function () {
            return Promise.resolve( { ok: true, body: { working: server_working, revision: 6, published: null, version: 0 } } );
        },
    };

    var model = make_model( 'f', 5, global.AgentshellFormsModel.clone( attempted.fields ) );
    // The model's working copy carries exactly the attempted fields.
    model.working = global.AgentshellFormsModel.clone( attempted );
    model.saved = global.AgentshellFormsModel.clone( attempted );
    var app = make_app( model, api );
    app.selected_form = 'f';

    app.reconcile_unknown( 'Save outcome could not be verified.', { definition: attempted, revision: 5 }, 'save' );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );

    assert.strictEqual( app.status, 'saved', 'a landed write is recognised despite normalisation differences' );
    assert.strictEqual( app.error, '', 'no error is shown for a landed write' );

    console.log( 'ok - a landed write is recognised despite server normalisation' );
}

async function test_selection_identity() {
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
    };
    var app = make_app( make_model( 'f1', 1, [ field( 'a' ) ] ), api );
    app.selected_form = 'f1';
    app.selected_field = 2;

    var state = app.state();

    assert.strictEqual( state.selected_form, 'f1', 'the form selection carries the form id' );
    assert.strictEqual( state.selected_field, 2, 'the field selection carries the index' );
    assert.strictEqual( state.selected, 'f1', 'the legacy alias still names the form, never the field' );

    console.log( 'ok - form selection and field selection are unambiguous' );
}


async function test_rename_after_form_switch() {
    var rename_gate = deferred();
    var rename_calls = 0;
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [ { form_id: 'a' }, { form_id: 'b' } ] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
        rename: function ( id, name, expected_revision ) {
            rename_calls++;
            assert.strictEqual( id, 'a', 'the rename targets the form it was requested on' );
            return rename_gate.promise;
        },
    };
    var model_a = make_model( 'a', 1, [ field( 'x' ) ] );
    var model_b = make_model( 'b', 3, [ field( 'y' ) ] );
    var app = make_app( model_a, api );
    app.selected_form = 'a';

    app.confirm_pending( { action: 'rename', name: 'A renamed' } );

    // The operator switches forms while the rename is in flight.
    app.model = model_b;
    app.selected_form = 'b';

    rename_gate.resolve( { ok: true, body: { name: 'A renamed', revision: 2 } } );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );

    assert.strictEqual( rename_calls, 1, 'exactly one rename request is sent' );
    assert.strictEqual( model_b.name, 'Name b', 'the other form keeps its name' );
    assert.deepStrictEqual(
        model_b.working.fields.map( function ( f ) { return f.key; } ),
        [ 'y' ],
        'the other form keeps its working draft'
    );
    assert.strictEqual( model_b.revision, 3, 'the other form keeps its revision' );
    assert.strictEqual( model_a.name, 'Name a', 'the renamed form is untouched in memory' );
    assert.strictEqual( model_a.revision, 1, 'the renamed form keeps its revision' );
    assert.strictEqual( app.selected_form, 'b', 'selection stays where the operator put it' );

    console.log( 'ok - a rename that resolves after a form switch never touches the other model' );
}

async function test_preview_after_form_switch() {
    var preview_gate = deferred();
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
        preview: function () { return preview_gate.promise; },
    };
    var model_a = make_model( 'a', 1, [ field( 'x' ) ] );
    var model_b = make_model( 'b', 2, [ field( 'y' ) ] );
    var app = make_app( model_a, api );
    app.selected_form = 'a';

    app.preview();

    // The operator switches forms while the preview is in flight.
    app.model = model_b;
    app.selected_form = 'b';

    preview_gate.resolve( { ok: true, body: { html: '<p>A preview</p>' } } );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );

    assert.strictEqual( app.preview_html, '', 'form B never displays form A preview HTML' );
    assert.strictEqual( app.previewed, false, 'form B is not marked as previewed' );

    console.log( 'ok - a preview that resolves after a form switch is dropped' );
}

async function test_preview_superseded() {
    var first_gate = deferred();
    var second_gate = deferred();
    var calls = 0;
    var api = {
        list: function () { return Promise.resolve( { ok: true, body: { forms: [] } } ); },
        get: function () { return Promise.resolve( { ok: false, body: null } ); },
        preview: function () {
            calls++;
            return calls === 1 ? first_gate.promise : second_gate.promise;
        },
    };
    var app = make_app( make_model( 'a', 1, [ field( 'x' ) ] ), api );
    app.selected_form = 'a';

    app.preview();
    app.preview();

    second_gate.resolve( { ok: true, body: { html: '<p>newer</p>' } } );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );
    assert.strictEqual( app.preview_html, '<p>newer</p>', 'the newer preview is shown' );

    first_gate.resolve( { ok: true, body: { html: '<p>older</p>' } } );
    await new Promise( function ( resolve ) { setTimeout( resolve, 10 ); } );
    assert.strictEqual( app.preview_html, '<p>newer</p>', 'the older response must not replace the newer preview' );

    console.log( 'ok - an older preview response never replaces a newer preview' );
}

( async function main() {
    await test_stale_load_dropped();
    await test_inflight_save_preserves_edits();
    await test_save_after_form_switch();
    await test_reconcile_conflict_preserves_edits();
    await test_reconcile_landed_despite_normalisation();
    await test_selection_identity();
    await test_rename_after_form_switch();
    await test_preview_after_form_switch();
    await test_preview_superseded();
    console.log( 'forms builder app: all assertions passed' );
} )().catch( function ( error ) {
    console.error( 'FAIL ' + ( error && error.message ? error.message : error ) );
    process.exit( 1 );
} );
