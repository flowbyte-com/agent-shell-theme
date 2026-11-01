/**
 * Configurator component-picker tests (Node).
 *
 * Run with:  node tests/configurator-picker-test.js
 * Or via:    php tests/run-configurator-picker.php  (executes Node and asserts)
 *
 * The picker's job is to present the choices the server's configuration
 * offers, and to offer nothing else. These tests cover the half of that
 * which is pure: the choices are built from the configuration, they carry
 * the server's names, they use the theme's own block vocabulary, and a
 * configuration with nothing to offer says so rather than presenting an
 * empty list.
 *
 * The interactive half — the keyboard, the focus, the cancel — needs a
 * DOM and is covered by tests/configurator-browser-test.html.
 */

'use strict';

var picker = require( '../wordpress/themes/agentshell/configurator/configurator-picker.js' );

var passed = 0, failed = 0;

function ok( cond, msg ) {
    if ( cond ) { passed++; console.log( '  ok   ' + msg ); }
    else { failed++; console.log( '  FAIL ' + msg ); }
}

function eq( a, b, msg ) {
    if ( a === b ) { ok( true, msg ); }
    else { ok( false, msg + ' (expected ' + JSON.stringify( b ) + ' got ' + JSON.stringify( a ) + ')' ); }
}

console.log( 'configurator component-picker tests' );

var CONFIG = {
    core_components: [
        { id: 'site_title', name: 'Site Title' },
        { id: 'site_logo', name: 'Site Logo' },
        { id: 'nav_menu', name: 'Navigation Menu' },
    ],
    available_widgets: [
        { id: 'chart', name: 'Chart', source: 'file' },
        { id: 'clock', name: 'Clock', source: 'api' },
    ],
    available_widget_areas: [
        { id: 'primary-sidebar', name: 'Primary Sidebar' },
    ],
};

// ── 1. The groups ──────────────────────────────────────────────────────────
(function () {
    var groups = picker.build_choices( CONFIG );
    eq( groups.length, 5, 'there are five groups of choices' );
    eq( groups[ 0 ].id, 'wp_core', 'the WordPress components come first' );
    eq( groups[ 1 ].id, 'widget', 'then the widgets' );
    eq( groups[ 2 ].id, 'wp_widget_area', 'then the widget areas' );
    eq( groups[ 3 ].id, 'agentshell_form', 'then the forms' );
    eq( groups[ 4 ].id, 'other', 'then the block types that carry no site choice' );
} )();

// ── 2. The names are the server's names ────────────────────────────────────
(function () {
    var groups = picker.build_choices( CONFIG );
    eq( groups[ 0 ].items.length, 3, 'every WordPress component is offered' );
    eq( groups[ 0 ].items[ 0 ].label, 'Site Title', 'the component is named, not numbered' );
    eq( groups[ 0 ].items[ 2 ].id, 'nav_menu', 'the component carries the server id' );
    eq( groups[ 0 ].items[ 0 ].type, 'wp_core', 'the component carries the block type' );
    eq( groups[ 1 ].items[ 0 ].label, 'Chart', 'the widget is named' );
    eq( groups[ 1 ].items[ 0 ].type, 'widget', 'the widget carries the block type' );
    eq( groups[ 2 ].items[ 0 ].label, 'Primary Sidebar', 'the widget area is named' );
    eq( groups[ 2 ].items[ 0 ].type, 'wp_widget_area', 'the widget area carries the block type' );
} )();

// ── 3. The vocabulary is the theme's own ────────────────────────────────────
(function () {
    var groups = picker.build_choices( CONFIG );
    var types = [];
    groups.forEach( function ( group ) {
        group.items.forEach( function ( item ) { types.push( item.type ); } );
    } );
    var allowed = [ 'wp_loop', 'wp_core', 'widget', 'json_block', 'wp_widget_area', 'agentshell_form' ];
    var ok_types = types.every( function ( t ) { return allowed.indexOf( t ) !== -1; } );
    ok( ok_types, 'every choice uses a block type the server supports: ' + JSON.stringify( types ) );
    eq( groups[ 4 ].items.length, 2, 'the HTML block and the content loop are offered' );
    eq( groups[ 4 ].items[ 0 ].type, 'json_block', 'the raw HTML block is offered' );
    eq( groups[ 4 ].items[ 1 ].type, 'wp_loop', 'the content loop is offered' );
    ok( ! groups[ 4 ].items[ 0 ].id, 'a block type that names no site object carries no id' );
} )();

// ── 4. A configuration with nothing to offer says so ───────────────────────
(function () {
    var groups = picker.build_choices( { core_components: [], available_widgets: [], available_widget_areas: [] } );
    eq( groups[ 0 ].items.length, 0, 'no WordPress components are offered' );
    ok( groups[ 0 ].empty.length > 0, 'the group says why it is empty' );
    ok( /WordPress components/i.test( groups[ 0 ].empty ), 'the message names what is missing' );
    eq( groups[ 1 ].items.length, 0, 'no widgets are offered' );
    ok( groups[ 1 ].empty.length > 0, 'the widget group says why it is empty' );
    eq( groups[ 2 ].items.length, 0, 'no widget areas are offered' );
    ok( groups[ 2 ].empty.length > 0, 'the widget area group says why it is empty' );
    eq( groups[ 3 ].items.length, 0, 'no forms are offered' );
    ok( groups[ 3 ].empty.length > 0, 'the forms group says why it is empty' );
    eq( groups[ 4 ].items.length, 2, 'the block types are still offered' );
} )();

// ── 5. A configuration that says nothing is handled ────────────────────────
(function () {
    var groups = picker.build_choices( {} );
    eq( groups.length, 5, 'the groups are still there' );
    eq( groups[ 0 ].items.length, 0, 'nothing is invented from an empty configuration' );
    eq( groups[ 3 ].items.length, 0, 'no forms are invented from an empty configuration' );
    eq( groups[ 4 ].items.length, 2, 'the block types are still offered' );
    var null_groups = picker.build_choices( null );
    eq( null_groups.length, 5, 'a missing configuration is handled' );
    eq( null_groups[ 0 ].items.length, 0, 'nothing is invented from a missing configuration' );
} )();

// ── 6. A component with no name falls back to its id ───────────────────────
(function () {
    var groups = picker.build_choices( { core_components: [ { id: 'mystery' } ] } );
    eq( groups[ 0 ].items[ 0 ].label, 'mystery', 'a component with no name is shown by its id' );
    eq( groups[ 0 ].items[ 0 ].id, 'mystery', 'and it still carries the id the server sent' );
} )();

// ── 7. The picker's own guards ─────────────────────────────────────────────
(function () {
    ok( typeof picker.open === 'function', 'the picker can be opened' );
    ok( typeof picker.build_choices === 'function', 'the choices can be built' );
    // Opening with no host must not throw: the picker is opened from a
    // click handler, and an exception there would take the editor's
    // render with it.
    var threw = false;
    try {
        picker.open( {} );
    } catch ( e ) {
        threw = true;
    }
    ok( ! threw, 'opening the picker without a host does not throw' );
} )();


// ── 8. The Forms group offers published forms, by immutable id ─────────────
(function () {
    var config = {
        core_components: [], available_widgets: [], available_widget_areas: [],
        forms_available: true,
        available_forms: [
            { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', name: 'Contact us', slug: 'contact-us' },
            { id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', name: 'Newsletter', slug: 'newsletter' },
        ],
    };
    var groups = picker.build_choices( config );
    var forms = groups.filter( function ( g ) { return g.id === 'agentshell_form'; } )[ 0 ];

    ok( !! forms, 'there is a Forms group' );
    eq( forms.label, 'Forms', 'the group is labelled Forms' );
    eq( forms.items.length, 2, 'both published forms are offered' );
    eq( forms.items[ 0 ].type, 'agentshell_form', 'the entry carries the form block type' );
    eq( forms.items[ 0 ].id, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'the entry carries the immutable internal id' );
    eq( forms.items[ 0 ].label, 'Contact us', 'the entry shows the readable name' );
    ok( forms.items[ 0 ].detail.indexOf( 'contact-us' ) !== -1, 'the entry names the slug a human pastes into a shortcode' );
} )();

// ── 9. A duplicate form entry is offered once ──────────────────────────────
(function () {
    var config = {
        core_components: [], available_widgets: [], available_widget_areas: [],
        forms_available: true,
        available_forms: [
            { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', name: 'Contact us', slug: 'contact-us' },
            { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', name: 'Contact us (again)', slug: 'contact-us' },
        ],
    };
    var forms = picker.build_choices( config ).filter( function ( g ) { return g.id === 'agentshell_form'; } )[ 0 ];
    eq( forms.items.length, 1, 'the same form is not offered twice' );
    eq( forms.items[ 0 ].label, 'Contact us', 'the first entry is the one kept' );
} )();

// ── 10. The two empty states are distinguishable ───────────────────────────
(function () {
    var without_plugin = picker.build_choices( {
        core_components: [], available_widgets: [], available_widget_areas: [],
        forms_available: false, available_forms: [],
    } ).filter( function ( g ) { return g.id === 'agentshell_form'; } )[ 0 ];

    var nothing_published = picker.build_choices( {
        core_components: [], available_widgets: [], available_widget_areas: [],
        forms_available: true, available_forms: [],
    } ).filter( function ( g ) { return g.id === 'agentshell_form'; } )[ 0 ];

    eq( without_plugin.items.length, 0, 'a site without the plugin offers no forms' );
    eq( nothing_published.items.length, 0, 'a site with nothing published offers no forms' );
    ok( without_plugin.empty !== nothing_published.empty, 'the two situations are explained differently' );
    ok( /plugin/i.test( without_plugin.empty ), 'the plugin-absent message names the plugin' );
    ok( /published/i.test( nothing_published.empty ), 'the nothing-published message says a form must be published' );
} )();

// ── 11. Every offered form id is a storable one ────────────────────────────
(function () {
    var malformed = [
        { id: '7', name: 'Post id, not a form id' },
        { id: 'Contact us', name: 'A name, not an id' },
        { id: '', name: 'Nothing at all' },
        { name: 'No id key' },
    ];
    var forms = picker.build_choices( {
        core_components: [], available_widgets: [], available_widget_areas: [],
        forms_available: true, available_forms: malformed,
    } ).filter( function ( g ) { return g.id === 'agentshell_form'; } )[ 0 ];

    eq( forms.items.length, 0, 'an entry with no usable id is not offered' );
} )();

console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
if ( failed > 0 ) { process.exit( 1 ); }