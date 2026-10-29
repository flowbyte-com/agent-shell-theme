/**
 * Pure models for the AgentShell Forms builder.
 *
 * ## Why this file is pure
 *
 * The builder's state (what is dirty, what is saved, what a reorder does) is
 * decided here, without a browser and without a network. The Node suite
 * exercises these models, so a regression in the dirty computation or the
 * reorder logic is caught where it can be run in milliseconds rather than in
 * a browser.
 *
 * The browser's own DOM, the transport and the application layer live in the
 * other three modules; only `forms-app.js` knows all of them.
 *
 * @package AgentShell_Forms
 */

'use strict';

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

function make_field_model( field, index ) {
    field = field && typeof field === 'object' ? field : {};

    return {
        key: typeof field.key === 'string' ? field.key : '',
        type: typeof field.type === 'string' ? field.type : 'text',
        label: typeof field.label === 'string' ? field.label : '',
        description: typeof field.description === 'string' ? field.description : '',
        placeholder: typeof field.placeholder === 'string' ? field.placeholder : '',
        required: true === field.required,
        settings: clone( field.settings && typeof field.settings === 'object' ? field.settings : {} ),
        options: clone( Array.isArray( field.options ) ? field.options : [] ),
        order: typeof index === 'number' ? index : 0,
    };
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
        status: 'saved',
        // True when the draft differs from the saved copy. Computed from the
        // data, never from a flag: a flag can go stale, and a stale flag is how
        // a builder comes to say "Saved" over work that was never written.
        dirty: function () {
            return ! equal( this.working, this.saved );
        },
        // The field list as models, in order.
        fields: function () {
            var raw = Array.isArray( this.working.fields ) ? this.working.fields : [];
            var out = [];
            for ( var i = 0; i < raw.length; i++ ) {
                out.push( make_field_model( raw[ i ], i ) );
            }
            return out;
        },
        // Move a field up or down. Returns the new working definition and
        // whether the move happened; the model itself is not mutated.
        move_field: function ( index, direction ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            var target = direction < 0 ? index - 1 : index + 1;

            if ( index < 0 || index >= fields.length || target < 0 || target >= fields.length ) {
                return { moved: false, working: this.working };
            }

            var tmp = fields[ index ];
            fields[ index ] = fields[ target ];
            fields[ target ] = tmp;

            var next = clone( this.working );
            next.fields = fields;

            return { moved: true, working: next };
        },
        // Remove a field by index.
        remove_field: function ( index ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];

            if ( index < 0 || index >= fields.length ) {
                return { removed: false, working: this.working };
            }

            fields.splice( index, 1 );

            var next = clone( this.working );
            next.fields = fields;

            return { removed: true, working: next };
        },
        // Add a field at the end.
        add_field: function ( field ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];
            fields.push( clone( field ) );

            var next = clone( this.working );
            next.fields = fields;

            return next;
        },
        // Replace a field's settings.
        set_field: function ( index, patch ) {
            var fields = Array.isArray( this.working.fields ) ? this.working.fields.slice() : [];

            if ( index < 0 || index >= fields.length ) {
                return this.working;
            }

            fields[ index ] = Object.assign( {}, fields[ index ], patch || {} );

            if ( patch && patch.settings && typeof patch.settings === 'object' ) {
                fields[ index ].settings = Object.assign( {}, fields[ index ].settings || {}, patch.settings );
            }

            var next = clone( this.working );
            next.fields = fields;

            return next;
        },
        // The payload the save endpoint expects.
        to_payload: function () {
            return {
                form_id: this.id,
                expected_revision: this.revision,
                definition: this.working,
            };
        },
        // Reconcile after a confirmed save: the server's revision becomes the
        // saved copy's revision, and the draft is no longer dirty.
        reconcile_save: function ( new_revision ) {
            this.revision = typeof new_revision === 'number' ? new_revision : this.revision;
            this.saved_revision = this.revision;
            this.saved = clone( this.working );
            this.status = 'saved';
        },
        // Reconcile after a confirmed publish.
        reconcile_publish: function ( new_revision, new_version ) {
            this.revision = typeof new_revision === 'number' ? new_revision : this.revision;
            this.saved_revision = this.revision;
            this.version = typeof new_version === 'number' ? new_version : this.version;
            this.saved = clone( this.working );
            this.published = clone( this.working );
            this.status = 'published';
        },
        // Discard: the draft becomes the published definition.
        discard: function () {
            this.working = clone( this.published || {} );
            this.saved = clone( this.working );
            this.revision = this.saved_revision;
            this.status = 'saved';
        },
    };
}

function status_label( model, previewed ) {
    if ( model.dirty() ) {
        if ( previewed ) {
            return 'changes_since_preview';
        }
        return 'unsaved';
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
        clone: clone,
        equal: equal,
        status_label: status_label,
        move_field: move_field,
    };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsModel = {
        make_form_model: make_form_model,
        make_field_model: make_field_model,
        clone: clone,
        equal: equal,
        status_label: status_label,
        move_field: move_field,
    };
}
