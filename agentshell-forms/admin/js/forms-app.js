/**
 * The application layer for the AgentShell Forms builder.
 *
 * The only module that knows both the models and the DOM. It owns the draft,
 * the form list, the in-flight snapshot and the truthful status.
 *
 * @package AgentShell_Forms
 */

'use strict';

function FormsApp( root, api, model ) {
    this.root = root;
    this.api = api;
    this.model = model || null;
    this.ui = null;
    this.forms = [];
    this.selected_form = model ? model.id : '';
    this.selected_field = null;
    this.tab = 'notification';
    this.preview_html = '';
    this.previewed = false;
    this.error = '';
    this.status = 'saved';
    this.busy = false;
    this.inflight = null;
    this.pending = null;
}

FormsApp.prototype.init = function () {
    var self = this;

    if ( typeof AgentshellFormsUi !== 'undefined' && AgentshellFormsUi.FormsUi ) {
        this.ui = new AgentshellFormsUi.FormsUi( this.root );
    }

    this.root.addEventListener( 'click', function ( event ) {
        var target = event.target.closest ? event.target.closest( '[data-action]' ) : null;
        if ( ! target ) {
            return;
        }
        var action = target.getAttribute( 'data-action' );
        var index = target.getAttribute( 'data-index' );
        var palette = target.getAttribute( 'data-palette' );
        var tab = target.getAttribute( 'data-tab' );

        self.handle_click( action, index, palette, tab, target );
    } );

    this.root.addEventListener( 'change', function ( event ) {
        self.handle_change( event.target );
    } );

    this.root.addEventListener( 'input', function ( event ) {
        self.handle_change( event.target, true );
    } );

    this.root.addEventListener( 'submit', function ( event ) {
        if ( event.target && event.target.closest && event.target.closest( '[data-preview]' ) ) {
            event.preventDefault();
        }
    } );

    this.load_forms();
};

/* -------------------------------------------------------------------------
 * State and rendering
 * ---------------------------------------------------------------------- */

FormsApp.prototype.state = function () {
    var self = this;
    var label = 'saved';

    if ( this.model ) {
        if ( this.model.dirty() ) {
            label = this.previewed ? 'changes_since_preview' : 'unsaved';
        } else if ( this.previewed ) {
            label = 'previewing';
        } else if ( this.model.version > 0 && this.model.published && AgentshellFormsModel.equal( this.model.working, this.model.published ) ) {
            label = 'published';
        }
    }

    if ( 'error' === this.status ) {
        label = 'error';
    } else if ( 'stale' === this.status ) {
        label = 'stale';
    } else if ( 'unknown' === this.status ) {
        label = 'unknown';
    } else if ( 'saved' !== this.status && 'unsaved' !== this.status ) {
        label = this.status;
    }

    return {
        forms: this.forms,
        selected_form: this.selected_form,
        selected: this.selected_form,
        model: this.model,
        selected_field: this.selected_field,
        tab: this.tab,
        preview_html: this.preview_html,
        previewed: this.previewed,
        error: this.error,
        pending: this.pending,
        status: this.status,
        status_label: label,
        busy: this.busy,
    };
};

FormsApp.prototype.render = function () {
    if ( ! this.ui ) {
        return;
    }

    if ( ! this.model ) {
        this.root.innerHTML = '';
        this.root.appendChild( this.ui.render_list( this.state(), {} ) );
        return;
    }

    var mount = this.ui.render_builder( this.state(), {} );
    mount();

    if ( this.selected_field !== null ) {
        var inspector = this.root.querySelector( '[data-inspector]' );
        if ( inspector ) {
            inspector.setAttribute( 'data-field', String( this.selected_field ) );
        }
    }
};

FormsApp.prototype.update_status = function () {
    if ( this.ui ) {
        this.ui.update_status( this.state() );
    }
};

FormsApp.prototype.fail = function ( message, status ) {
    this.error = message || 'Something went wrong.';
    this.status = status || 'error';
    this.update_status();
};

FormsApp.prototype.clear_error = function () {
    this.error = '';
    if ( [ 'error', 'stale', 'unknown' ].indexOf( this.status ) >= 0 ) {
        this.status = 'saved';
    }
    this.update_status();
};

/* -------------------------------------------------------------------------
 * Forms
 * ---------------------------------------------------------------------- */

FormsApp.prototype.refresh_forms_list = function ( select_id ) {
    var self = this;

    return this.api.list().then( function ( result ) {
        if ( ! result || ! result.ok || ! result.body ) {
            self.fail( 'The form list could not be loaded.' );
            return;
        }

        self.forms = result.body.forms || [];

        if ( select_id && ! self.forms.some( function ( form ) { return form.form_id === select_id; } ) ) {
            self.fail( 'The form is no longer listed.' );
            return;
        }

        self.render();
    } );
};

FormsApp.prototype.load_forms = function ( select_id, preserve_selection ) {
    var self = this;

    return this.api.list().then( function ( result ) {
        if ( ! result || ! result.ok || ! result.body ) {
            self.fail( 'The form list could not be loaded.' );
            return;
        }

        self.forms = result.body.forms || [];

        var wanted = select_id || self.selected_form || ( self.forms.length ? self.forms[ 0 ].form_id : '' );

        if ( wanted && self.forms.some( function ( form ) { return form.form_id === wanted; } ) ) {
            return self.load_form( wanted, Boolean( preserve_selection ) );
        }

        self.model = null;
        self.selected_form = '';
        self.render();
    } );
};

FormsApp.prototype.load_form = function ( form_id, preserve_selection ) {
    var self = this;

    return this.api.get( form_id ).then( function ( result ) {
        if ( ! result || ! result.ok || ! result.body ) {
            self.fail( 'That form could not be loaded.' );
            return;
        }

        var body = result.body;

        self.model = AgentshellFormsModel.make_form_model( {
            id: body.form_id,
            name: body.name,
            slug: body.slug,
            version: body.version,
            revision: body.revision,
            working: body.working,
            published: body.published,
        } );

        self.selected_form = body.form_id;

        if ( ! preserve_selection ) {
            self.selected_field = null;
        } else if ( self.selected_field !== null && ! self.model.field_at( self.selected_field ) ) {
            self.selected_field = null;
        }
        self.preview_html = '';
        self.previewed = false;
        self.error = '';
        self.status = 'saved';
        self.render();
    } );
};

FormsApp.prototype.handle_click = function ( action, index, palette, tab, target ) {
    var self = this;

    if ( 'create' === action ) {
        this.pending = { action: 'create', name: '', title: 'Name the form' };
        this.render();
        return;
    }

    if ( 'rename' === action ) {
        if ( ! this.model ) { return; }
        this.pending = { action: 'rename', name: this.model.name, title: 'Rename the form' };
        this.render();
        return;
    }

    if ( 'duplicate' === action ) {
        if ( ! this.model ) { return; }
        this.pending = { action: 'duplicate', name: this.model.name + ' (copy)', title: 'Name the copy' };
        this.render();
        return;
    }

    if ( 'trash' === action ) {
        if ( ! this.model ) { return; }
        this.pending = { action: 'trash', name: '', title: 'Move this form to the trash?' };
        this.render();
        return;
    }

    if ( 'confirm-cancel' === action ) {
        this.pending = null;
        this.render();
        return;
    }

    if ( 'confirm-yes' === action ) {
        var pending = this.pending;

        if ( pending && [ 'create', 'rename', 'duplicate' ].indexOf( pending.action ) >= 0 ) {
            var name_input = this.root.querySelector( '[data-confirm-name]' );
            pending.name = name_input ? name_input.value : pending.name;
        }

        this.pending = null;
        this.confirm_pending( pending );
        return;
    }

    if ( 'add-field' === action && palette ) {
        if ( ! this.model ) { return; }
        var key = palette + '_' + ( this.model.fields().length + 1 );
        this.selected_field = this.model.add_field( {
            key: key,
            type: palette,
            label: palette.charAt( 0 ).toUpperCase() + palette.slice( 1 ) + ' ' + ( this.model.fields().length + 1 ),
            required: false,
        } );
        this.previewed = false;
        this.status = 'unsaved';
        this.render();
        return;
    }

    if ( 'select-field' === action ) {
        this.selected_field = parseInt( index, 10 );
        this.render();
        return;
    }

    if ( 'move-up' === action || 'move-down' === action ) {
        if ( ! this.model ) { return; }
        var at = parseInt( index, 10 );
        var moved = this.model.move_field( at, 'move-up' === action ? -1 : 1 );
        if ( moved ) {
            this.selected_field = 'move-up' === action ? at - 1 : at + 1;
            this.previewed = false;
            this.status = 'unsaved';
            this.render();
        }
        return;
    }

    if ( 'remove' === action ) {
        if ( ! this.model ) { return; }
        var remove_at = parseInt( index, 10 );
        if ( this.model.remove_field( remove_at ) ) {
            this.selected_field = null;
            this.previewed = false;
            this.status = 'unsaved';
            this.render();
        }
        return;
    }

    if ( 'add-option' === action ) {
        if ( ! this.model || this.selected_field === null ) { return; }
        var field = this.model.field_at( this.selected_field );
        var options = ( field.options || [] ).slice();
        options.push( { value: 'option_' + ( options.length + 1 ), label: 'Option ' + ( options.length + 1 ) } );
        this.model.set_field( this.selected_field, { options: options } );
        this.previewed = false;
        this.status = 'unsaved';
        this.render();
        return;
    }

    if ( 'remove-option' === action ) {
        if ( ! this.model || this.selected_field === null ) { return; }
        var current = this.model.field_at( this.selected_field );
        var remaining = ( current.options || [] ).slice();
        remaining.splice( parseInt( target.getAttribute( 'data-option' ), 10 ), 1 );
        this.model.set_field( this.selected_field, { options: remaining } );
        this.previewed = false;
        this.status = 'unsaved';
        this.render();
        return;
    }

    if ( 'set-tab' === action ) {
        this.tab = tab;
        this.render();
        return;
    }

    if ( 'save' === action ) {
        this.save();
        return;
    }

    if ( 'publish' === action ) {
        this.publish();
        return;
    }

    if ( 'discard' === action ) {
        this.discard();
        return;
    }

    if ( 'preview' === action ) {
        this.preview();
        return;
    }

    if ( 'copy-shortcode' === action ) {
        var input = this.root.querySelector( '[data-shortcode]' );
        if ( input ) {
            input.select();
            try {
                if ( navigator.clipboard && navigator.clipboard.writeText ) {
                    navigator.clipboard.writeText( input.value );
                } else {
                    document.execCommand( 'copy' );
                }
                target.textContent = 'Copied';
            } catch ( e ) {
                target.textContent = 'Copy failed';
            }
        }
    }
};

FormsApp.prototype.handle_change = function ( target, live ) {
    if ( ! target || ! target.getAttribute ) {
        return;
    }

    if ( target.getAttribute( 'data-form-select' ) ) {
        this.selected_form = target.value;
        this.load_form( target.value );
        return;
    }

    var option_value = target.getAttribute( 'data-option-value' );
    var option_label = target.getAttribute( 'data-option-label' );

    if ( ( option_value !== null || option_label !== null ) && this.model && this.selected_field !== null ) {
        var field = this.model.field_at( this.selected_field );
        var options = ( field.options || [] ).slice();
        var at = parseInt( option_value !== null ? option_value : option_label, 10 );
        var option = Object.assign( {}, options[ at ] || {} );
        if ( option_value !== null ) {
            option.value = target.value;
        } else {
            option.label = target.value;
        }
        options[ at ] = option;
        this.model.set_field( this.selected_field, { options: options } );
        this.previewed = false;
        this.status = 'unsaved';
        this.update_status();
        return;
    }

    var setting = target.getAttribute( 'data-setting' );

    if ( ! setting || ! this.model ) {
        return;
    }

    if ( setting.indexOf( '.' ) < 0 ) {
        if ( this.selected_field === null ) {
            return;
        }
        var patch = {};
        if ( 'required' === setting ) {
            patch.required = target.checked;
        } else if ( [ 'label', 'key', 'description', 'placeholder' ].indexOf( setting ) >= 0 ) {
            patch[ setting ] = target.value;
        } else {
            var settings = {};
            settings[ setting ] = 'number' === target.type ? Number( target.value ) || 0 : target.value;
            patch.settings = settings;
        }
        this.model.set_field( this.selected_field, patch );
    } else {
        var value = target.type === 'checkbox' ? target.checked : target.value;

        if ( 'notification.recipients' === setting ) {
            value = target.value.split( ',' ).map( function ( part ) {
                return part.trim();
            } ).filter( Boolean );
        }

        if ( 'spam.rate_limit.max' === setting || 'spam.rate_limit.window' === setting || 'spam.min_seconds' === setting || 'storage.retain_days' === setting ) {
            value = Number( value ) || 0;
        }

        this.model.set_setting( setting, value );
    }

    this.previewed = false;
    this.status = 'unsaved';
    this.update_status();
};

/* -------------------------------------------------------------------------
 * Save, publish, discard, preview
 * ---------------------------------------------------------------------- */

FormsApp.prototype.save = function () {
    var self = this;

    if ( ! this.model || this.busy ) {
        return;
    }

    // The request is made with an immutable snapshot. If the operator edits
    // while it is in flight, the response settles the snapshot that was
    // actually saved — not the newer draft.
    var snapshot = this.model.snapshot();
    var form_id = this.model.id;

    this.busy = true;
    this.inflight = snapshot;
    this.clear_error();
    this.status = 'saving';
    this.update_status();

    this.api.save( form_id, snapshot.revision, snapshot.definition ).then( function ( result ) {
        self.busy = false;
        self.inflight = null;

        if ( ! result || ! result.ok ) {
            self.handle_failure( result, 'The changes were not saved.', { form_id: form_id, expected_revision: snapshot.revision } );
            return;
        }

        // Reconcile the snapshot that was actually saved. Newer edits made
        // while the request was in flight stay unsaved: reconcile_save sets
        // saved to the snapshot, so dirty() remains true when working has
        // moved on. The model itself is never replaced here — replacing it
        // with a fresh GET would discard those newer edits. Only the list is
        // refreshed.
        self.model.reconcile_save( snapshot, result.body.revision );
        self.status = self.model.dirty() ? 'unsaved' : 'saved';
        self.render();
        self.refresh_forms_list( form_id );
    } ).catch( function () {
        var attempted = self.inflight;
        self.busy = false;
        self.inflight = null;
        self.reconcile_unknown( 'Save outcome could not be verified.', attempted, 'save' );
    } );
};

/**
 * Perform the action the confirmation bar was asking about.
 *
 * @param {Object|null} pending The pending action.
 * @return {void}
 */
FormsApp.prototype.confirm_pending = function ( pending ) {
    var self = this;

    if ( ! pending ) {
        return;
    }

    if ( 'create' === pending.action ) {
        if ( ! pending.name ) { return; }
        this.clear_error();
        this.api.create( pending.name ).then( function ( result ) {
            if ( ! result || ! result.ok ) {
                self.fail( ( result && result.body && result.body.message ) || 'The form could not be created.' );
                return;
            }
            return self.load_forms( result.body.form_id );
        } );
        return;
    }

    if ( 'rename' === pending.action ) {
        if ( ! pending.name ) { return; }
        var rename_id = this.model.id;
        var rename_rev = this.model.revision;
        this.clear_error();
        this.api.rename( rename_id, pending.name, rename_rev ).then( function ( result ) {
            if ( ! result || ! result.ok ) {
                self.handle_failure( result, 'The form could not be renamed.', { form_id: rename_id, expected_revision: rename_rev } );
                return;
            }
            // The rename touches the name, not the fields: keep the working
            // draft (including any edits made since) and record the new
            // revision the server produced.
            self.model.name = result.body.name;
            self.model.working.name = result.body.name;
            self.model.saved.name = result.body.name;
            self.model.revision = result.body.revision;
            self.model.saved_revision = result.body.revision;
            self.render();
            self.refresh_forms_list( rename_id );
        } );
        return;
    }

    if ( 'duplicate' === pending.action ) {
        if ( ! pending.name ) { return; }
        this.clear_error();
        this.api.duplicate( this.model.id, pending.name ).then( function ( result ) {
            if ( ! result || ! result.ok ) {
                self.fail( ( result && result.body && result.body.message ) || 'The form could not be duplicated.' );
                return;
            }
            return self.load_forms( result.body.form_id );
        } );
        return;
    }

    if ( 'trash' === pending.action ) {
        this.clear_error();
        this.api.trash( this.model.id ).then( function ( result ) {
            if ( ! result || ! result.ok ) {
                self.fail( ( result && result.body && result.body.message ) || 'The form could not be trashed.' );
                return;
            }
            self.selected_form = '';
            return self.load_forms();
        } );
        return;
    }

    if ( 'publish' === pending.action ) {
        this.publish_confirmed();
        return;
    }

    if ( 'discard' === pending.action ) {
        this.discard_confirmed();
        return;
    }
};

FormsApp.prototype.publish = function () {
    if ( ! this.model || this.busy ) {
        return;
    }

    if ( this.model.dirty() ) {
        this.fail( 'Save your changes before publishing.' );
        return;
    }

    this.pending = { action: 'publish', name: '', title: 'Publish this form? Visitors will see it immediately.' };
    this.render();
};

FormsApp.prototype.publish_confirmed = function () {
    var self = this;

    var snapshot = this.model.snapshot();
    this.busy = true;
    this.clear_error();
    this.status = 'publishing';
    this.update_status();

    this.api.publish( this.model.id, snapshot.revision ).then( function ( result ) {
        self.busy = false;

        if ( ! result || ! result.ok ) {
            self.handle_failure( result, 'The form was not published.', { form_id: self.model.id, expected_revision: snapshot.revision } );
            return;
        }

        self.model.reconcile_publish( snapshot, result.body.revision, result.body.version );
        self.status = 'published';
        self.render();
        self.refresh_forms_list( self.model.id );
    } ).catch( function () {
        var attempted_pub = self.model ? self.model.snapshot() : null;
        self.busy = false;
        self.reconcile_unknown( 'Publish outcome could not be verified.', attempted_pub, 'publish' );
    } );
};

FormsApp.prototype.discard = function () {
    if ( ! this.model || this.busy ) {
        return;
    }

    this.pending = { action: 'discard', name: '', title: 'Discard your changes and restore the published definition?' };
    this.render();
};

FormsApp.prototype.discard_confirmed = function () {
    var self = this;

    this.busy = true;
    this.clear_error();
    this.status = 'discarding';
    this.update_status();

    var discard_snapshot = this.model.snapshot();

    this.api.discard( this.model.id, this.model.revision ).then( function ( result ) {
        self.busy = false;

        if ( ! result || ! result.ok ) {
            self.handle_failure( result, 'The changes were not discarded.', { form_id: self.model.id, expected_revision: discard_snapshot.revision } );
            return;
        }

        self.model.reconcile_discard( result.body );
        self.selected_field = null;
        self.preview_html = '';
        self.previewed = false;
        self.status = 'saved';
        self.render();
        self.refresh_forms_list( self.model.id );
    } ).catch( function () {
        self.busy = false;
        self.reconcile_unknown( 'Discard outcome could not be verified.', discard_snapshot, 'discard' );
    } );
};

FormsApp.prototype.preview = function () {
    var self = this;

    if ( ! this.model ) {
        return;
    }

    // The preview is rendered from the draft as it is *now*: it is the draft
    // version the operator is looking at, not a saved one.
    var definition = AgentshellFormsModel.clone( this.model.working );
    this.clear_error();

    this.api.preview( this.model.id, definition ).then( function ( result ) {
        if ( ! result || ! result.ok ) {
            self.fail( ( result && result.body && result.body.message ) || 'The preview could not be rendered.' );
            return;
        }

        self.preview_html = result.body.html || '';
        self.previewed = true;
        self.render();
    } );
};

/* -------------------------------------------------------------------------
 * Failure and uncertainty
 * ---------------------------------------------------------------------- */

FormsApp.prototype.describe_request = function ( context ) {
    context = context && typeof context === 'object' ? context : {};

    var target = context.form_id || ( this.model ? this.model.id : '' );
    var expected = ( context.expected_revision !== undefined && context.expected_revision !== null )
        ? context.expected_revision
        : ( this.model ? this.model.revision : '' );

    return ' [form ' + target
        + ' expected_rev=' + expected
        + ' selected=' + ( this.selected_form || '' )
        + ' target=' + target + ']';
};

FormsApp.prototype.handle_failure = function ( result, fallback, context ) {
    var body = result && result.body ? result.body : {};
    var status = result ? result.status : 0;

    if ( body && body.errors && typeof body.errors === 'object' ) {
        var messages = [];
        Object.keys( body.errors ).forEach( function ( key ) {
            messages.push( key + ': ' + body.errors[ key ] );
        } );
        this.fail( messages.join( '; ') || fallback, 'validation' );
        return;
    }

    if ( 409 === status || 'form_revision_mismatch' === ( body.code || '' ) ) {
        // Instrument the conflict: the expected revision this editor sent,
        // the authoritative revision the server reports, which form is
        // selected, and which form the request targeted. A harness can read
        // these apart: a genuine stale write, a load that has not completed,
        // a wait satisfied by the wrong form, or a storage defect each leave
        // a different signature here. WP_Error data arrives nested under
        // `data`, so both shapes are read.
        var errdata = ( body.data && typeof body.data === 'object' ) ? body.data : {};
        var exp = ( body.expected_revision !== undefined ? body.expected_revision
            : ( errdata.expected_revision !== undefined ? errdata.expected_revision
            : ( context && context.expected_revision ) ) );
        var auth = ( body.actual_revision !== undefined ? body.actual_revision
            : ( errdata.actual_revision !== undefined ? errdata.actual_revision
            : ( body.revision !== undefined ? body.revision : '?' ) ) );
        var detail = ' [expected=' + exp
            + ' authoritative=' + auth
            + ' selected=' + ( this.selected_form || '' )
            + ' target=' + ( ( context && context.form_id ) || ( this.model ? this.model.id : '' ) ) + ']';

        this.fail( ( body.message || 'Another editor saved a newer version of this form.' ) + detail + ' Reload to see it.', 'stale' );
        return;
    }

    this.fail( ( body.message || fallback ) + this.describe_request( context ), 'error' );
};

FormsApp.prototype.reconcile_unknown = function ( message, snapshot, operation ) {
    var self = this;

    operation = operation || 'save';
    snapshot = snapshot && typeof snapshot === 'object' ? snapshot : null;

    if ( ! snapshot && self.model ) {
        snapshot = self.model.snapshot();
    }

    if ( ! snapshot || ! self.model ) {
        self.fail( message + ' The server could not be reached to verify it.', 'unknown' );
        return;
    }

    var attempted = AgentshellFormsModel.clone( snapshot.definition );
    var base_revision = snapshot.revision;
    var form_id = self.model.id;

    this.fail( message + ' Checking the server…', 'unknown' );

    this.api.get( form_id ).then( function ( result ) {
        if ( ! result || ! result.ok || ! result.body ) {
            self.fail( message + ' The server could not be reached to verify it.', 'unknown' );
            return;
        }

        var body = result.body;
        var server_working = body.working || {};
        var server_revision = body.revision;

        if ( 'discard' === operation ) {
            // A discard landed exactly when the server holds the published
            // definition as working.
            if ( 'landed' === AgentshellFormsModel.decide_unknown_outcome( 'discard', attempted, base_revision, body ) ) {
                self.model.reconcile_discard( body );
                self.selected_field = null;
                self.preview_html = '';
                self.previewed = false;
                self.status = 'saved';
                self.error = '';
                self.render();
                self.refresh_forms_list( form_id );
                return;
            }

            self.fail( message + ' The change was not saved.', 'error' );
            return;
        }

        if ( 'publish' === operation ) {
            if ( 'landed' === AgentshellFormsModel.decide_unknown_outcome( 'publish', attempted, base_revision, body ) ) {
                self.model.reconcile_publish( { definition: attempted, revision: base_revision }, server_revision, body.version );
                self.status = self.model.dirty() ? 'unsaved' : 'published';
                self.error = '';
                self.render();
                self.refresh_forms_list( form_id );
                return;
            }

            var pub_outcome = AgentshellFormsModel.decide_unknown_outcome( 'publish', attempted, base_revision, body );

            if ( 'not_saved' === pub_outcome ) {
                self.fail( message + ' The change was not saved.', 'error' );
                return;
            }

            // Someone else moved the form: keep this editor's working draft,
            // rebase the saved pointer on the server truth, and report the
            // conflict rather than mistaking their save for ours.
            self.model.saved = AgentshellFormsModel.clone( server_working );
            self.model.revision = server_revision;
            self.model.saved_revision = server_revision;
            if ( body.published ) {
                self.model.published = AgentshellFormsModel.clone( body.published );
            }
            if ( body.version !== undefined ) {
                self.model.version = body.version;
            }
            self.fail(
                message + ' Another editor saved a newer version [expected=' + base_revision
                    + ' authoritative=' + server_revision
                    + ' selected=' + ( self.selected_form || '' )
                    + ' target=' + form_id + ']. Reload to see it.',
                'stale'
            );
            return;
        }

        // Save (the default): the write landed exactly when the server holds
        // the attempted definition. A moved revision alone proves nothing.
        if ( 'landed' === AgentshellFormsModel.decide_unknown_outcome( 'save', attempted, base_revision, { working: server_working, revision: server_revision } ) ) {
            self.model.reconcile_save( { definition: attempted, revision: base_revision }, server_revision );
            self.status = self.model.dirty() ? 'unsaved' : 'saved';
            self.error = '';
            self.render();
            self.refresh_forms_list( form_id );
            return;
        }

        var save_outcome = AgentshellFormsModel.decide_unknown_outcome( 'save', attempted, base_revision, { working: server_working, revision: server_revision } );

        if ( 'not_saved' === save_outcome ) {
            self.fail( message + ' The change was not saved.', 'error' );
            return;
        }

        // The revision moved but the content is not ours: another editor's
        // save, not ours. Rebase the saved pointer, keep the working draft
        // (including newer unsaved edits), and report the conflict.
        self.model.saved = AgentshellFormsModel.clone( server_working );
        self.model.revision = server_revision;
        self.model.saved_revision = server_revision;
        if ( body.published ) {
            self.model.published = AgentshellFormsModel.clone( body.published );
        }
        if ( body.version !== undefined ) {
            self.model.version = body.version;
        }
        self.fail(
            message + ' Another editor saved a newer version [expected=' + base_revision
                + ' authoritative=' + server_revision
                + ' selected=' + ( self.selected_form || '' )
                + ' target=' + form_id + ']. Reload to see it.',
            'stale'
        );
    } );
};

function bootstrap() {
    var mount = document.getElementById( 'agentshell-forms-builder' );

    if ( ! mount ) {
        return;
    }

    var ApiCtor = ( typeof AgentshellFormsApi !== 'undefined' && AgentshellFormsApi.FormsApi )
        ? AgentshellFormsApi.FormsApi
        : null;

    if ( ! ApiCtor ) {
        return;
    }

    var api = new ApiCtor( mount.getAttribute( 'data-rest' ), mount.getAttribute( 'data-nonce' ) );
    var app = new FormsApp( mount, api, null );

    mount.setAttribute( 'data-ready', '1' );
    app.init();
}

if ( typeof document !== 'undefined' ) {
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootstrap );
    } else {
        bootstrap();
    }
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { FormsApp: FormsApp, bootstrap: bootstrap };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsApp = { FormsApp: FormsApp, bootstrap: bootstrap };
}
