/**
 * The view for the AgentShell Forms builder.
 *
 * Builds DOM and nothing else. It holds no state and makes no requests: every
 * change flows through `forms-app.js`, which is the only module that knows
 * both the models and the DOM.
 *
 * @package AgentShell_Forms
 */

'use strict';

function FormsUi( root ) {
    this.root = root || document.body;
    this.uid = 0;
}

FormsUi.prototype.el = function ( tag, attrs, children ) {
    var node = document.createElement( tag );

    if ( attrs && typeof attrs === 'object' ) {
        Object.keys( attrs ).forEach( function ( key ) {
            if ( key === 'class' ) {
                node.className = attrs[ key ];
            } else if ( key === 'text' ) {
                node.textContent = attrs[ key ];
            } else if ( key === 'html' ) {
                node.innerHTML = attrs[ key ];
            } else if ( key === 'checked' || key === 'disabled' || key === 'required' || key === 'selected' ) {
                node[ key ] = Boolean( attrs[ key ] );
            } else {
                node.setAttribute( key, attrs[ key ] );
            }
        } );
    }

    if ( Array.isArray( children ) ) {
        children.forEach( function ( child ) {
            if ( child === null || child === undefined || child === false ) {
                return;
            }
            node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
        } );
    }

    return node;
};

FormsUi.prototype.label = function ( text, control, id ) {
    var node = this.el( 'label', { for: id }, [ text ] );
    return this.el( 'div', { class: 'asf-form-row' }, [ node, control ] );
};

FormsUi.prototype.text = function ( name, value, opts ) {
    opts = opts || {};
    return this.el( 'input', {
        type: opts.type || 'text',
        'data-setting': name,
        value: value === undefined || value === null ? '' : String( value ),
        placeholder: opts.placeholder || '',
        min: opts.min !== undefined ? opts.min : null,
        max: opts.max !== undefined ? opts.max : null,
        step: opts.step !== undefined ? opts.step : null,
        autocomplete: 'off',
    } );
};

FormsUi.prototype.checkbox = function ( name, checked ) {
    return this.el( 'input', { type: 'checkbox', 'data-setting': name, checked: Boolean( checked ) } );
};

FormsUi.prototype.select = function ( name, value, options ) {
    var node = this.el( 'select', { 'data-setting': name } );

    options.forEach( function ( option ) {
        node.appendChild(
            this.el( 'option', { value: option.value, selected: String( option.value ) === String( value ) }, [ option.label ] )
        );
    }, this );

    return node;
};

/* -------------------------------------------------------------------------
 * The list and the form selector
 * ---------------------------------------------------------------------- */

FormsUi.prototype.render_list = function ( state, handlers ) {
    var list = this.el( 'div', { class: 'asf-list' } );

    if ( ! state.forms.length ) {
        list.appendChild( this.el( 'p', { class: 'asf-empty', text: 'No forms yet. Create one to begin.' } ) );
    } else {
        var select = this.el( 'select', { 'data-form-select': '1', class: 'asf-form-select' } );

        state.forms.forEach( function ( form ) {
            select.appendChild(
                this.el( 'option', { value: form.form_id, selected: form.form_id === state.selected_form }, [
                    form.name + ( form.status ? ' — ' + form.status : '' ),
                ] )
            );
        }, this );

        list.appendChild( this.el( 'label', { for: 'asf-form-select', text: 'Form' } ) );
        list.appendChild( select );

        var actions = this.el( 'div', { class: 'asf-list-actions' }, [
            this.el( 'button', { type: 'button', class: 'button', 'data-action': 'rename', text: 'Rename' } ),
            this.el( 'button', { type: 'button', class: 'button', 'data-action': 'duplicate', text: 'Duplicate' } ),
            this.el( 'button', { type: 'button', class: 'button asf-danger', 'data-action': 'trash', text: 'Trash' } ),
        ] );

        list.appendChild( actions );
    }

    list.appendChild(
        this.el( 'div', { class: 'asf-list-actions' }, [
            this.el( 'button', { type: 'button', class: 'button button-primary', 'data-action': 'create', text: 'Create a form' } ),
        ] )
    );

    return list;
};

/* -------------------------------------------------------------------------
 * The builder
 * ---------------------------------------------------------------------- */

FormsUi.prototype.render_builder = function ( state, handlers ) {
    var model = state.model;
    var fragment = document.createDocumentFragment();

    fragment.appendChild( this.render_list( state, handlers ) );

    var header = this.el( 'div', {
        class: 'asf-builder__header',
        'data-form-id': model.id || '',
        'data-revision': String( model.revision || 0 ),
    }, [
        this.el( 'h2', { text: model.name || 'Untitled form', 'data-form-name': '1' } ),
        this.el( 'span', {
            class: 'asf-builder__status',
            'data-status': state.status || 'saved',
            'data-status-chip': '1',
            text: state.status_label || state.status || 'saved',
        } ),
    ] );

    fragment.appendChild( header );

    var error = this.el( 'div', {
        class: 'asf-builder__error' + ( state.error ? ' asf-builder__error--visible' : '' ),
        role: 'alert',
        'aria-live': 'assertive',
        'data-error': '1',
        hidden: ! state.error,
        text: state.error || '',
    } );

    fragment.appendChild( error );

    if ( state.pending ) {
        var bar = this.el( 'div', {
            class: 'asf-confirm',
            'data-confirm': '1',
            role: 'group',
            'aria-label': state.pending.title || 'Confirm',
        } );

        bar.appendChild( this.el( 'p', { class: 'asf-confirm__title', text: state.pending.title || 'Confirm' } ) );

        if ( [ 'create', 'rename', 'duplicate' ].indexOf( state.pending.action ) >= 0 ) {
            bar.appendChild( this.el( 'input', {
                type: 'text',
                'data-confirm-name': '1',
                value: state.pending.name || '',
                'aria-label': 'Form name',
            } ) );
        }

        bar.appendChild( this.el( 'button', { type: 'button', class: 'button button-primary', 'data-action': 'confirm-yes', text: 'Confirm' } ) );
        bar.appendChild( this.el( 'button', { type: 'button', class: 'button', 'data-action': 'confirm-cancel', text: 'Cancel' } ) );

        fragment.appendChild( bar );
    }

    var palette = this.el( 'div', { class: 'asf-palette', 'data-palette': '1', role: 'group', 'aria-label': 'Add a field' } );

    [ 'text', 'textarea', 'email', 'tel', 'number', 'select', 'radio', 'checkbox', 'consent', 'hidden' ].forEach( function ( type ) {
        palette.appendChild(
            this.el( 'button', {
                type: 'button',
                class: 'button asf-palette__item',
                'data-palette': type,
                'data-action': 'add-field',
                text: type,
            } )
        );
    }, this );

    fragment.appendChild( palette );

    var fields = this.el( 'ol', { class: 'asf-builder__fields', 'data-fields': '1' } );

    model.fields().forEach( function ( field, index ) {
        var item = this.el( 'li', {
            class: 'asf-builder__field' + ( index === state.selected_field ? ' asf-builder__field--selected' : '' ),
            'data-field-index': String( index ),
            'data-field-key': field.key,
        }, [
            this.el( 'button', {
                type: 'button',
                class: 'asf-field-select',
                'data-action': 'select-field',
                'data-index': String( index ),
                text: ( field.label || field.key || 'Field' ) + ' (' + field.type + ')',
            } ),
            this.el( 'button', { type: 'button', class: 'button asf-builder__move-up', 'data-action': 'move-up', 'data-index': String( index ), text: 'Move up' } ),
            this.el( 'button', { type: 'button', class: 'button asf-builder__move-down', 'data-action': 'move-down', 'data-index': String( index ), text: 'Move down' } ),
            this.el( 'button', { type: 'button', class: 'button asf-danger', 'data-action': 'remove', 'data-index': String( index ), text: 'Remove' } ),
        ] );

        fields.appendChild( item );
    }, this );

    if ( ! model.fields().length ) {
        fields.appendChild( this.el( 'li', { class: 'asf-empty', text: 'No fields yet. Add one from the palette above.' } ) );
    }

    fragment.appendChild( fields );

    if ( state.selected_field !== null && model.field_at( state.selected_field ) ) {
        fragment.appendChild( this.render_inspector( model, state.selected_field, handlers ) );
    }

    fragment.appendChild( this.render_settings( model, state.tab || 'notification', handlers ) );
    fragment.appendChild( this.render_actions( state, handlers ) );
    fragment.appendChild( this.render_preview( state, handlers ) );
    fragment.appendChild( this.render_insertion( state, handlers ) );

    return function () {
        this.root.innerHTML = '';
        this.root.appendChild( fragment );
    }.bind( this );
};

FormsUi.prototype.render_inspector = function ( model, index, handlers ) {
    var field = model.field_at( index );
    var wrap = this.el( 'fieldset', { class: 'asf-inspector', 'data-inspector': '1' } );
    wrap.appendChild( this.el( 'legend', { text: 'Field settings' } ) );

    wrap.appendChild( this.label( 'Label', this.text( 'label', field.label ), 'asf-label-' + index ) );
    wrap.appendChild( this.label( 'Key', this.text( 'key', field.key ), 'asf-key-' + index ) );
    wrap.appendChild( this.label( 'Description', this.text( 'description', field.description ), 'asf-description-' + index ) );
    wrap.appendChild( this.label( 'Placeholder', this.text( 'placeholder', field.placeholder ), 'asf-placeholder-' + index ) );
    wrap.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
        this.el( 'label', {}, [ this.checkbox( 'required', field.required ), ' Required' ] ),
    ] ) );

    [ 'text', 'email', 'tel' ].indexOf( field.type ) >= 0
        ? wrap.appendChild( this.label( 'Autocomplete', this.text( 'autocomplete', field.settings.autocomplete || 'off' ), 'asf-autocomplete-' + index ) )
        : null;

    if ( [ 'text', 'textarea', 'email', 'tel' ].indexOf( field.type ) >= 0 ) {
        wrap.appendChild( this.label( 'Maximum length', this.text( 'max_length', field.settings.max_length || '', { type: 'number', min: 0 } ), 'asf-max-' + index ) );
    }

    if ( field.type === 'textarea' ) {
        wrap.appendChild( this.label( 'Rows', this.text( 'rows', field.settings.rows || 4, { type: 'number', min: 1 } ), 'asf-rows-' + index ) );
    }

    if ( field.type === 'number' ) {
        wrap.appendChild( this.label( 'Minimum', this.text( 'min', field.settings.min !== undefined ? field.settings.min : '', { type: 'number' } ), 'asf-min-' + index ) );
        wrap.appendChild( this.label( 'Maximum', this.text( 'max', field.settings.max !== undefined ? field.settings.max : '', { type: 'number' } ), 'asf-nummax-' + index ) );
    }

    if ( field.type === 'hidden' ) {
        wrap.appendChild( this.label( 'Value', this.text( 'value', field.settings.value || '' ), 'asf-value-' + index ) );
    }

    if ( [ 'select', 'radio', 'checkbox' ].indexOf( field.type ) >= 0 ) {
        wrap.appendChild( this.render_options( field, index ) );
    }

    return wrap;
};

FormsUi.prototype.render_options = function ( field, index ) {
    var wrap = this.el( 'div', { class: 'asf-options', 'data-options': String( index ) } );
    wrap.appendChild( this.el( 'p', { class: 'asf-options__title', text: 'Options' } ) );

    field.options.forEach( function ( option, option_index ) {
        wrap.appendChild(
            this.el( 'div', { class: 'asf-option-row', 'data-option-index': String( option_index ) }, [
                this.el( 'input', { type: 'text', 'data-option-value': String( option_index ), value: option.value || '', placeholder: 'value' } ),
                this.el( 'input', { type: 'text', 'data-option-label': String( option_index ), value: option.label || '', placeholder: 'label' } ),
                this.el( 'button', { type: 'button', class: 'button', 'data-action': 'remove-option', 'data-option': String( option_index ), text: 'Remove' } ),
            ] )
        );
    }, this );

    wrap.appendChild( this.el( 'button', { type: 'button', class: 'button', 'data-action': 'add-option', text: 'Add option' } ) );

    return wrap;
};

FormsUi.prototype.render_settings = function ( model, tab, handlers ) {
    var settings = model.settings();
    var wrap = this.el( 'div', { class: 'asf-settings', 'data-settings': '1' } );
    var tabs = this.el( 'div', { class: 'asf-tabs', role: 'tablist' } );

    [ 'notification', 'spam', 'storage', 'privacy' ].forEach( function ( name ) {
        tabs.appendChild(
            this.el( 'button', {
                type: 'button',
                role: 'tab',
                'aria-selected': name === tab ? 'true' : 'false',
                class: 'button asf-tab' + ( name === tab ? ' asf-tab--active' : '' ),
                'data-tab': name,
                'data-action': 'set-tab',
                text: name,
            } )
        );
    }, this );

    wrap.appendChild( tabs );

    var panel = this.el( 'div', { class: 'asf-tab-panel', 'data-tab-panel': tab, role: 'tabpanel' } );

    if ( tab === 'notification' ) {
        var n = settings.notification || {};
        panel.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
            this.el( 'label', {}, [ this.checkbox( 'notification.enabled', n.enabled ), ' Send an email when a submission arrives' ] ),
        ] ) );
        panel.appendChild( this.label( 'Recipients (comma separated)', this.text( 'notification.recipients', ( n.recipients || [] ).join( ', ' ) ), 'asf-recipients' ) );
        panel.appendChild( this.label( 'Subject', this.text( 'notification.subject', n.subject ), 'asf-subject' ) );
        panel.appendChild( this.label( 'Body template', this.text( 'notification.body_template', n.body_template ), 'asf-body' ) );
        panel.appendChild( this.label( 'Reply-To field (key)', this.text( 'notification.reply_to_field', n.reply_to_field ), 'asf-reply' ) );

        var c = n.confirmation || {};
        panel.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
            this.el( 'label', {}, [ this.checkbox( 'notification.confirmation.enabled', c.enabled ), ' Send a confirmation to the submitter' ] ),
        ] ) );
        panel.appendChild( this.label( 'Confirmation subject', this.text( 'notification.confirmation.subject', c.subject ), 'asf-csubject' ) );
        panel.appendChild( this.label( 'Confirmation body', this.text( 'notification.confirmation.body_template', c.body_template ), 'asf-cbody' ) );
    }

    if ( tab === 'spam' ) {
        var spam = settings.spam || {};
        panel.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
            this.el( 'label', {}, [ this.checkbox( 'spam.honeypot', spam.honeypot ), ' Honeypot' ] ),
        ] ) );
        panel.appendChild( this.label( 'Minimum seconds', this.text( 'spam.min_seconds', spam.min_seconds, { type: 'number', min: 0 } ), 'asf-minseconds' ) );
        panel.appendChild( this.label( 'Turnstile', this.select( 'spam.turnstile', spam.turnstile, [
            { value: 'disabled', label: 'Disabled' },
            { value: 'optional', label: 'Optional' },
            { value: 'required', label: 'Required' },
        ] ), 'asf-turnstile' ) );
        panel.appendChild( this.label( 'Rate limit: submissions', this.text( 'spam.rate_limit.max', ( spam.rate_limit || {} ).max, { type: 'number', min: 1 } ), 'asf-ratemax' ) );
        panel.appendChild( this.label( 'Rate limit: window (seconds)', this.text( 'spam.rate_limit.window', ( spam.rate_limit || {} ).window, { type: 'number', min: 1 } ), 'asf-ratewindow' ) );
    }

    if ( tab === 'storage' ) {
        var storage = settings.storage || {};
        panel.appendChild( this.label( 'Retain submissions (days; 0 = site default)', this.text( 'storage.retain_days', storage.retain_days, { type: 'number', min: 0 } ), 'asf-retain' ) );
        panel.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
            this.el( 'label', {}, [ this.checkbox( 'storage.store_ip', storage.store_ip ), ' Store a hashed address' ] ),
        ] ) );
        panel.appendChild( this.el( 'div', { class: 'asf-form-row' }, [
            this.el( 'label', {}, [ this.checkbox( 'storage.store_user_agent', storage.store_user_agent ), ' Store the user agent' ] ),
        ] ) );
    }

    if ( tab === 'privacy' ) {
        var privacy = settings.privacy || {};
        panel.appendChild( this.label( 'Lawful basis', this.select( 'privacy.lawful_basis', privacy.lawful_basis, [
            { value: 'consent', label: 'Consent' },
            { value: 'contract', label: 'Contract' },
            { value: 'legitimate_interests', label: 'Legitimate interests' },
            { value: 'legal_obligation', label: 'Legal obligation' },
        ] ), 'asf-basis' ) );
        panel.appendChild( this.label( 'Privacy notice URL', this.text( 'privacy.privacy_notice_url', privacy.privacy_notice_url ), 'asf-notice' ) );
    }

    wrap.appendChild( panel );

    return wrap;
};

FormsUi.prototype.render_actions = function ( state, handlers ) {
    return this.el( 'div', { class: 'asf-builder__actions' }, [
        this.el( 'button', { type: 'button', class: 'button button-primary', 'data-action': 'save', text: 'Save' } ),
        this.el( 'button', { type: 'button', class: 'button', 'data-action': 'publish', text: 'Publish' } ),
        this.el( 'button', { type: 'button', class: 'button', 'data-action': 'preview', text: 'Preview' } ),
        this.el( 'button', { type: 'button', class: 'button asf-danger', 'data-action': 'discard', text: 'Discard' } ),
    ] );
};

FormsUi.prototype.render_preview = function ( state, handlers ) {
    var panel = this.el( 'div', { class: 'asf-preview', 'data-preview': '1' } );

    panel.appendChild( this.el( 'h3', { text: 'Preview' } ) );

    if ( state.preview_html ) {
        var body = this.el( 'div', { class: 'asf-preview__body', html: state.preview_html } );

        // A preview is not a submission surface.
        body.querySelectorAll( 'form' ).forEach( function ( form ) {
            form.addEventListener( 'submit', function ( event ) {
                event.preventDefault();
            } );
        } );
        body.querySelectorAll( 'button[type="submit"], input[type="submit"]' ).forEach( function ( button ) {
            button.disabled = true;
        } );

        panel.appendChild( body );
    } else {
        panel.appendChild( this.el( 'p', { class: 'asf-empty', text: 'Preview shows the current draft here.' } ) );
    }

    return panel;
};

FormsUi.prototype.render_insertion = function ( state, handlers ) {
    var wrap = this.el( 'div', { class: 'asf-insertion', 'data-insertion': '1' } );
    var shortcode = '[agentshell_form id="' + ( state.model.id || '' ) + '"]';

    wrap.appendChild( this.el( 'h3', { text: 'Insert this form' } ) );
    wrap.appendChild(
        this.el( 'input', {
            type: 'text',
            readonly: 'readonly',
            class: 'asf-shortcode',
            'data-shortcode': '1',
            value: shortcode,
            'aria-label': 'Shortcode',
        } )
    );
    wrap.appendChild(
        this.el( 'div', { class: 'asf-list-actions' }, [
            this.el( 'button', { type: 'button', class: 'button', 'data-action': 'copy-shortcode', text: 'Copy shortcode' } ),
            this.el( 'a', {
                class: 'button',
                'data-action': 'open-page',
                href: 'post-new.php?post_type=page',
                target: '_blank',
                rel: 'noopener',
                text: 'Open a new page',
            } ),
        ] )
    );

    return wrap;
};

FormsUi.prototype.update_status = function ( state ) {
    var chip = this.root.querySelector( '[data-status-chip]' );

    if ( chip ) {
        chip.textContent = state.status_label || state.status || 'saved';
        chip.setAttribute( 'data-status', state.status || 'saved' );
    }

    var error = this.root.querySelector( '[data-error]' );

    if ( error ) {
        error.textContent = state.error || '';
        error.hidden = ! state.error;
        error.className = 'asf-builder__error' + ( state.error ? ' asf-builder__error--visible' : '' );
    }
};

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { FormsUi: FormsUi };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsUi = { FormsUi: FormsUi };
}
