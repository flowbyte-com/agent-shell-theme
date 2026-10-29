/**
 * The transport for the AgentShell Forms builder.
 *
 * Every request carries the REST nonce on `X-WP-Nonce`. Nothing else lives
 * here: no state, no DOM, no models.
 *
 * @package AgentShell_Forms
 */

'use strict';

function FormsApi( rest_url, nonce ) {
    this.rest = ( rest_url || '' ).replace( /\/+$/, '' );
    this.nonce = nonce || '';
}

FormsApi.prototype.request = function ( method, path, body ) {
    var url = this.rest + ( path.charAt( 0 ) === '/' ? path : '/' + path );
    var init = {
        method: method,
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': this.nonce, 'Accept': 'application/json' },
    };

    if ( body !== undefined && body !== null ) {
        init.headers[ 'Content-Type' ] = 'application/json';
        init.body = JSON.stringify( body );
    }

    return window.fetch( url, init ).then( function ( response ) {
        return response.json().then(
            function ( parsed ) {
                return { ok: response.ok, status: response.status, body: parsed };
            },
            function () {
                return { ok: response.ok, status: response.status, body: null };
            }
        );
    } );
};

FormsApi.prototype.list = function () {
    return this.request( 'GET', '/forms' );
};

FormsApi.prototype.get = function ( id ) {
    return this.request( 'GET', '/forms/' + encodeURIComponent( id ) );
};

FormsApi.prototype.create = function ( name ) {
    return this.request( 'POST', '/forms', { name: name } );
};

FormsApi.prototype.save = function ( id, expected_revision, definition ) {
    return this.request( 'POST', '/forms/' + encodeURIComponent( id ) + '/save', {
        expected_revision: expected_revision,
        definition: definition,
    } );
};

FormsApi.prototype.publish = function ( id, expected_revision ) {
    return this.request( 'POST', '/forms/' + encodeURIComponent( id ) + '/publish', {
        expected_revision: expected_revision,
    } );
};

FormsApi.prototype.discard = function ( id ) {
    return this.request( 'POST', '/forms/' + encodeURIComponent( id ) + '/discard', {} );
};

FormsApi.prototype.preview = function ( id, definition ) {
    return this.request( 'POST', '/forms/' + encodeURIComponent( id ) + '/preview', {
        definition: definition,
    } );
};

FormsApi.prototype.trash = function ( id ) {
    return this.request( 'DELETE', '/forms/' + encodeURIComponent( id ) );
};

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { FormsApi: FormsApi };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsApi = { FormsApi: FormsApi };
}
