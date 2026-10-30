/**
 * Pure models for the AgentShell Forms builder.
 *
 * The builder's state (what is dirty, what is saved, what a reorder does,
 * how a snapshot is reconciled) is decided here, without a browser and
 * without a network. The Node suite exercises these models, so a regression
 * in the dirty computation or the in-flight save reconciliation is caught
 * where it can be run in milliseconds.
 *
 * @package AgentShell_Forms
 */

'use strict';

var TYPES = [ 'text', 'textarea', 'email', 'tel', 'number', 'select', 'radio', 'checkbox', 'consent', 'hidden' ];

var TYPE_SETTINGS = {
    text: [ 'max_length', 'min_length', 'autocomplete' ],
    textarea: [ 'max_length', 'min_length', 'rows' ],
    email: [ 'max_length', 'autocomplete' ],
    tel: [ 'max_length', 'autocomplete' ],
    number: [ 'min', 'max', 'step' ],
    select: [ 'placeholder' ],
    radio: [],
    checkbox: [],
    consent: [],
    hidden: [],
};

function clone( value ) {
    if ( value === null || typeof value !== 'object' ) {
        return value;
    }

    if ( Array.isArray( value ) ) {
        var out = [];
        for ( var i = 0; i < value.length; i++ ) {
            out.push( clone( value[ i ] ) );
        }
        return out;
    }

    var copy = {};
    Object.keys( value ).forEach( function ( key ) {
        copy[ key ] = clone( value[ key ] );
    } );
    return copy;
}

function equal( a, b ) {
    return JSON.stringify( a ) === JSON.stringify( b );
}

function default_field_settings() {
    return {};
}

function make_field_model( field, index ) {
    field = field && typeof field === 'object' ? field : {};

    return {
        key: typeof field.key === 'string' ? field.key : '',
        type: typeof field.type === 'string' ? field.type : 'text',
        label: typeof field.label === 'string' ? field.label : '',
        description: typeof field.description === 'string' ? field.description : '',
        placeholder: typeof field.placeholder === 'string' ? field.placeholder : '',
        required: true === field.required,
        settings: clone( field.settings && typeof field.settings === 'object' ? field.settings : default_field_settings() ),
        options: clone( Array.isArray( field.options ) ? field.options : [] ),
        order: typeof index === 'number' ? index : 0,
    };
}

/**
 * A field as the schema wants it, built from an inspector edit.
 *
 * Only the keys the schema knows are emitted; an unknown key would be refused
 * by the server, and silently normalising it away would hide the mistake.
 *
 * @param {Object} field The field model.
 * @return {Object} The definition-shaped field.
 */
function field_to_definition( field ) {
    var out = {
        key: String( field.key || '' ),
        type: String( field.type || 'text' ),
        label: String( field.label || '' ),
        required: true === field.required,
    };

    if ( field.description ) {
        out.description = String( field.description );
    }

    if ( field.placeholder ) {
        out.placeholder = String( field.placeholder );
    }

    var settings = {};

    ( TYPE_SETTINGS[ out.type ] || [] ).forEach( function ( key ) {
        if ( field.settings && field.settings[ key ] !== undefined && field.settings[ key ] !== '' ) {
            settings[ key ] = field.settings[ key ];
        }
    } );

    if ( out.type === 'hidden' && field.settings && field.settings.value !== undefined ) {
        settings.value = String( field.settings.value );
    }

    out.settings = settings;

    if ( [ 'select', 'radio', 'checkbox' ].indexOf( out.type ) >= 0 ) {
        out.options = clone( Array.isArray( field.options ) ? field.options : [] );
    }

    return out;
}

function make_form_model( saved ) {
    saved = saved && typeof saved === 'object' ? saved : {};

    var working = clone( saved.working || saved.published || {} );
    var saved_working = clone( working );

    return {
        id: typeof saved.id === 'string' ? saved.id : ( typeof saved.form_id === 'string' ? saved.form_id : '' ),
        name: typeof saved.name === 'string' ? saved.name : '',
        slug: typeof saved.slug === 'string' ? saved.slug : '',
        version: typeof saved.version === 'number' ? saved.version : 0,
        revision: typeof saved.revision === 'number' ? saved.revision : 0,
        saved_revision: typeof saved.revision === 'number' ? saved.revision : 0,
        working: working,
        saved: saved_working,
        published: clone( saved.published || null ),
        dirty: function () {
            return ! equal( this.working, this.saved );
        },
        fields: function () {
            var raw = Array.isArray( this.working.fields ) ? this.working.fields : [];
            var out = [];
            for ( var i = 0; i < raw.length; i++ ) {
                out.push( make_field_model( raw[ i ], i ) );
            }
            return out;
        },
        field_at: function ( index ) {
            var raw = Array.isArray( this.working.fields ) ? this.working.fields : [];
            return raw[ index ] ? make_field_model( raw[ index ], index ) : null;
        },
        add_field: function ( field ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            fields.push( field_to_definition( field ) );
            var next = clone( this.working );
            next.fields = fields;
            this.working = next;
            return index_of_length( fields ) - 1;
        },
        remove_field: function ( index ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            if ( index < 0 || index >= fields.length ) {
                return false;
            }
            fields.splice( index, 1 );
            var next = clone( this.working );
            next.fields = fields;
            this.working = next;
            return true;
        },
        move_field: function ( index, direction ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            var target = direction < 0 ? index - 1 : index + 1;
            if ( index < 0 || index >= fields.length || target < 0 || target >= fields.length ) {
                return false;
            }
            var tmp = fields[ index ];
            fields[ index ] = fields[ target ];
            fields[ target ] = tmp;
            var next = clone( this.working );
            next.fields = fields;
            this.working = next;
            return true;
        },
        set_field: function ( index, patch ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            if ( index < 0 || index >= fields.length ) {
                return false;
            }
            fields[ index ] = field_to_definition( Object.assign( {}, fields[ index ], patch || {} ) );
            var next = clone( this.working );
            next.fields = fields;
            this.working = next;
            return true;
        },
        settings: function () {
            var settings = this.working.settings && typeof this.working.settings === 'object' ? this.working.settings : {};
            return clone( settings );
        },
        set_setting: function ( path, value ) {
            var next = clone( this.working );
            var parts = String( path ).split( '.' );
            var node = next;

            if ( ! node.settings || typeof node.settings !== 'object' ) {
                node.settings = {};
            }

            node = node.settings;

            for ( var i = 0; i < parts.length - 1; i++ ) {
                if ( ! node[ parts[ i ] ] || typeof node[ parts[ i ] ] !== 'object' ) {
                    node[ parts[ i ] ] = {};
                }
                node = node[ parts[ i ] ];
            }

            node[ parts[ parts.length - 1 ] ] = value;
            this.working = next;
        },
        to_payload: function () {
            return {
                form_id: this.id,
                expected_revision: this.revision,
                definition: this.working,
            };
        },
        /**
         * The immutable copy a save request is made with.
         *
         * The request must be settled against exactly this snapshot. If the
         * operator edits while it is in flight, the newer edits are *not* what
         * the server saved, and must not be marked saved.
         */
        snapshot: function () {
            return { definition: clone( this.working ), revision: this.revision };
        },
        reconcile_save: function ( snapshot, new_revision ) {
            this.saved = clone( snapshot.definition );
            this.revision = typeof new_revision === 'number' ? new_revision : this.revision;
            this.saved_revision = this.revision;
        },
        reconcile_publish: function ( snapshot, new_revision, new_version ) {
            this.saved = clone( snapshot.definition );
            this.revision = typeof new_revision === 'number' ? new_revision : this.revision;
            this.saved_revision = this.revision;
            this.version = typeof new_version === 'number' ? new_version : this.version;
            this.published = clone( snapshot.definition );
        },
        reconcile_discard: function ( state ) {
            state = state && typeof state === 'object' ? state : {};
            if ( state.working && typeof state.working === 'object' ) {
                this.working = clone( state.working );
                this.saved = clone( state.working );
            }
            if ( state.published && typeof state.published === 'object' ) {
                this.published = clone( state.published );
            }
            if ( typeof state.revision === 'number' ) {
                this.revision = state.revision;
                this.saved_revision = state.revision;
            }
            if ( typeof state.version === 'number' ) {
                this.version = state.version;
            }
        },
    };
}

function index_of_length( list ) {
    return list.length;
}

function status_label( model, previewed ) {
    if ( model.dirty() ) {
        return previewed ? 'changes_since_preview' : 'unsaved';
    }
    if ( model.published && equal( model.working, model.published ) && model.version > 0 ) {
        return 'published';
    }
    return 'saved';
}

function move_field( fields, from, to ) {
    fields = Array.isArray( fields ) ? fields.slice() : [];
    if ( from < 0 || from >= fields.length || to < 0 || to >= fields.length ) {
        return { moved: false, fields: fields };
    }
    var item = fields.splice( from, 1 )[ 0 ];
    fields.splice( to, 0, item );
    return { moved: true, fields: fields };
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        make_form_model: make_form_model,
        make_field_model: make_field_model,
        field_to_definition: field_to_definition,
        clone: clone,
        equal: equal,
        status_label: status_label,
        move_field: move_field,
        TYPES: TYPES,
        TYPE_SETTINGS: TYPE_SETTINGS,
    };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsModel = {
        make_form_model: make_form_model,
        make_field_model: make_field_model,
        field_to_definition: field_to_definition,
        clone: clone,
        equal: equal,
        status_label: status_label,
        move_field: move_field,
        TYPES: TYPES,
        TYPE_SETTINGS: TYPE_SETTINGS,
    };
}
