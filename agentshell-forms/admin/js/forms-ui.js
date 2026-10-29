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
}

FormsUi.prototype.el = function ( tag, attrs, children ) {
    var node = document.createElement( tag );

    if ( attrs && typeof attrs === 'object' ) {
        Object.keys( attrs ).forEach( function ( key ) {
            if ( key === 'class' ) {
                node.className = attrs[ key ];
            } else if ( key === 'text' ) {
                node.textContent = attrs[ key ];
            } else {
                node.setAttribute( key, attrs[ key ] );
            }
        } );
    }

    if ( Array.isArray( children ) ) {
        children.forEach( function ( child ) {
            if ( child === null || child === undefined ) {
                return;
            }
            node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
        } );
    }

    return node;
};

FormsUi.prototype.mount = function ( model, api, on_change ) {
    this.root.innerHTML = '';

    var header = this.el( 'div', { class: 'asf-builder__header' }, [
        this.el( 'h2', { text: model.name || 'Untitled form' } ),
        this.el( 'span', { class: 'asf-builder__status', 'data-status': model.status || 'saved', text: model.status || 'saved' } ),
    ] );

    var fields = this.el( 'ol', { class: 'asf-builder__fields' } );

    ( model.fields() || [] ).forEach( function ( field, index ) {
        var item = this.el( 'li', { class: 'asf-builder__field', 'data-index': String( index ) }, [
            this.el( 'span', { class: 'asf-builder__field-label', text: field.label || field.key || 'Field' } ),
            this.el( 'span', { class: 'asf-builder__field-type', text: field.type } ),
            this.el( 'button', { type: 'button', class: 'asf-builder__move-up', 'data-action': 'move-up', 'data-index': String( index ), text: 'Move up' } ),
            this.el( 'button', { type: 'button', class: 'asf-builder__move-down', 'data-action': 'move-down', 'data-index': String( index ), text: 'Move down' } ),
            this.el( 'button', { type: 'button', class: 'asf-builder__remove', 'data-action': 'remove', 'data-index': String( index ), text: 'Remove' } ),
        ] );

        fields.appendChild( item );
    }, this );

    var actions = this.el( 'div', { class: 'asf-builder__actions' }, [
        this.el( 'button', { type: 'button', class: 'asf-builder__save', 'data-action': 'save', text: 'Save' } ),
        this.el( 'button', { type: 'button', class: 'asf-builder__publish', 'data-action': 'publish', text: 'Publish' } ),
        this.el( 'button', { type: 'button', class: 'asf-builder__discard', 'data-action': 'discard', text: 'Discard' } ),
        this.el( 'button', { type: 'button', class: 'asf-builder__preview', 'data-action': 'preview', text: 'Preview' } ),
    ] );

    var preview = this.el( 'div', { class: 'asf-builder__preview-panel', 'data-preview': '1' } );

    this.root.appendChild( header );
    this.root.appendChild( fields );
    this.root.appendChild( actions );
    this.root.appendChild( preview );

    if ( typeof on_change === 'function' ) {
        on_change( this.root );
    }
};

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { FormsUi: FormsUi };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsUi = { FormsUi: FormsUi };
}
