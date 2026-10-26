/**
 * The configurator's transport layer.
 *
 * Every request the editor makes goes through here, and this module is the
 * only place that knows the REST shapes. It is deliberately thin: it parses
 * URLs, attaches the nonce, and turns a WordPress error into a thrown object
 * with a `code` and a `message` the interface can show. It knows nothing about
 * what a zone is, and it holds no state.
 *
 * The endpoints it calls are the theme's own, and they are the same endpoints
 * the agent tooling uses underneath:
 *
 *   GET  /wp/v2/agentshell/config                 the configuration
 *   PUT  /wp/v2/agentshell/config                 save (staged while a tx is open)
 *   GET  /wp/v2/agentshell/transaction            the open transaction
 *   POST /wp/v2/agentshell/transaction            begin one
 *   DELETE /wp/v2/agentshell/transaction          roll one back
 *   POST /wp/v2/agentshell/transaction/commit     commit one
 *   GET  /wp/v2/agentshell/transaction/preview    a signed preview URL
 *   POST /wp/v2/agentshell/snapshot                take a snapshot
 *   GET  /wp/v2/agentshell/snapshots               list snapshots
 *   POST /wp/v2/agentshell/snapshots/restore       restore a snapshot
 *   GET  /wp/v2/agentshell/revisions                list revisions
 *   POST /wp/v2/agentshell/revisions/restore       restore a revision
 *
 * A failure is thrown as an Error with `code` and `status` properties, so the
 * interface can distinguish "the server refused this" from "the network is
 * down" and say something useful in each case.
 */
(function ( root ) {
    'use strict';

    function make_api( options ) {
        options = options || {};
        var restUrl   = options.restUrl || '/';
        var nonce     = options.nonce || '';
        var fetchImpl = options.fetch || ( typeof root !== 'undefined' ? root.fetch : null );

        function url( path ) {
            return restUrl.replace( /\/$/, '' ) + '/wp/v2/agentshell/' + path;
        }

        function request( path, init ) {
            if ( ! fetchImpl ) {
                return Promise.reject( error( 'no_transport', 'This browser cannot make requests.', 0 ) );
            }
            var opts = init || {};
            var headers = { 'X-WP-Nonce': nonce };
            if ( opts.body ) {
                headers['Content-Type'] = 'application/json';
            }
            return fetchImpl( url( path ), {
                method: opts.method || 'GET',
                headers: headers,
                credentials: 'same-origin',
                body: opts.body ? JSON.stringify( opts.body ) : undefined,
            } ).then( function ( response ) {
                return response.text().then( function ( text ) {
                    var data = null;
                    try {
                        data = text ? JSON.parse( text ) : null;
                    } catch ( e ) {
                        data = null;
                    }
                    if ( ! response.ok ) {
                        var code = ( data && data.code ) || 'request_failed';
                        var message = ( data && data.message )
                            ? data.message
                            : 'The server refused the request (' + response.status + ').';
                        throw error( code, message, response.status, data );
                    }
                    return data;
                } );
            } );
        }

        function error( code, message, status, data ) {
            var e = new Error( message );
            e.code = code;
            e.status = status || 0;
            e.data = data || null;
            return e;
        }

        return {
            get_config: function () {
                return request( 'config' );
            },
            put_config: function ( payload ) {
                return request( 'config', { method: 'PUT', body: payload } );
            },
            get_transaction: function () {
                return request( 'transaction' );
            },
            begin_transaction: function ( label ) {
                return request( 'transaction', { method: 'POST', body: { label: label || 'configurator edit' } } );
            },
            commit_transaction: function () {
                return request( 'transaction/commit', { method: 'POST' } );
            },
            rollback_transaction: function () {
                return request( 'transaction', { method: 'DELETE' } );
            },
            preview_transaction: function () {
                return request( 'transaction/preview' );
            },
            create_snapshot: function ( name, label ) {
                return request( 'snapshot', { method: 'POST', body: { name: name, label: label || name } } );
            },
            list_snapshots: function () {
                return request( 'snapshots' );
            },
            restore_snapshot: function ( name ) {
                return request( 'snapshots/restore', { method: 'POST', body: { name: name } } );
            },
            list_revisions: function () {
                return request( 'revisions' );
            },
            restore_revision: function ( id ) {
                return request( 'revisions/restore', { method: 'POST', body: { id: id } } );
            },
        };
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_api: make_api };
    } else {
        root.AgentshellConfiguratorApi = { make_api: make_api };
    }
})( typeof window !== 'undefined' ? window : globalThis );
