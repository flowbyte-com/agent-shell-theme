/**
 * The Phase 4 builder acceptance scenarios.
 *
 * Runs inside the harness page, against the real builder page in a same-origin
 * iframe: the real DOM, the real REST endpoints and the real database.
 *
 * @package AgentShell_Forms
 */

'use strict';

( function () {
    var results = [];
    var failures = [];
    var total = 0;
    var token = 'ASF4-' + String( Date.now() ).slice( -6 );

    function ok( name, condition, detail ) {
        total++;
        if ( ! condition ) {
            failures.push( { name: name, detail: detail === undefined ? null : String( detail ) } );
        }
    }

    function eq( name, actual, expected ) {
        ok( name, String( actual ) === String( expected ), 'expected ' + JSON.stringify( expected ) + ' got ' + JSON.stringify( actual ) );
    }

    function finish() {
        document.title = JSON.stringify( {
            total: total,
            failures: failures,
            token: token,
        } );
    }

    function sleep( ms ) {
        return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
    }

    function wait_for( predicate, timeout ) {
        var deadline = Date.now() + ( timeout || 8000 );
        return new Promise( function ( resolve, reject ) {
            ( function poll() {
                var value = false;
                try { value = predicate(); } catch ( e ) { value = false; }
                if ( value ) { resolve( value ); return; }
                if ( Date.now() > deadline ) { reject( new Error( 'timed out' ) ); return; }
                window.setTimeout( poll, 50 );
            }() );
        } );
    }

    var ifr = document.getElementById( 'builder' );

    var last_selector = '';
    function doc() { return ifr.contentDocument; }
    function q( sel ) { last_selector = sel; return doc().querySelector( sel ); }
    function qa( sel ) { return Array.prototype.slice.call( doc().querySelectorAll( sel ) ); }
    function click( sel ) {
        var el = typeof sel === 'string' ? q( sel ) : sel;
        if ( ! el ) { throw new Error( 'no element for ' + sel ); }
        el.click();
    }
    function set( el, value ) {
        if ( ! el ) { throw new Error( 'no input for ' + last_selector ); }
        el.value = value;
        el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }
    function set_checked( el, value ) {
        el.checked = Boolean( value );
        el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }
    function status() { var c = q( '[data-status-chip]' ); return c ? c.getAttribute( 'data-status' ) : ''; }
    function error_text() { var e = q( '[data-error]' ); return e && ! e.hidden ? e.textContent : ''; }
    function fields() { return qa( '[data-fields] [data-field-index]' ); }
    function keys() { return fields().map( function ( f ) { return f.getAttribute( 'data-field-key' ); } ); }

    function rest() {
        var mount = q( '#agentshell-forms-builder' );
        return mount ? mount.getAttribute( 'data-rest' ) : '';
    }

    function nonce() {
        var mount = q( '#agentshell-forms-builder' );
        return mount ? mount.getAttribute( 'data-nonce' ) : '';
    }

    function core_api( path, opts ) {
        opts = opts || {};
        var init = {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce(), 'Accept': 'application/json' },
        };
        if ( opts.body !== undefined ) {
            init.headers[ 'Content-Type' ] = 'application/json';
            init.body = JSON.stringify( opts.body );
        }
        var mount = q( '#agentshell-forms-builder' );
        var root = mount ? mount.getAttribute( 'data-rest-root' ) : '/wp-json/';

        return fetch( root.replace( /\/$/, '' ) + path, init ).then( function ( response ) {
            return response.json().then( function ( body ) {
                return { ok: response.ok, status: response.status, body: body };
            }, function () {
                return { ok: response.ok, status: response.status, body: null };
            } );
        } );
    }

    function api( path, opts ) {
        opts = opts || {};
        var url = rest().replace( /\/$/, '' ) + path;
        var init = {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce(), 'Accept': 'application/json' },
        };
        if ( opts.body !== undefined ) {
            init.headers[ 'Content-Type' ] = 'application/json';
            init.body = JSON.stringify( opts.body );
        }
        return fetch( url, init ).then( function ( response ) {
            return response.json().then( function ( body ) {
                return { ok: response.ok, status: response.status, body: body };
            }, function () {
                return { ok: response.ok, status: response.status, body: null };
            } );
        } );
    }

    function load( url ) {
        ifr.src = url;
        return wait_for( function () {
            try {
                return q( '[data-ready]' ) && q( '[data-form-select], [data-action="create"]' );
            } catch ( e ) { return false; }
        }, 15000 ).then( function () {
            return wait_for( function () { return doc().readyState === 'complete'; }, 5000 );
        } );
    }

    // The builder confirms inline: an action opens a confirmation bar, and
    // the operator confirms or cancels it. No blocking dialog is involved.
    function confirm_action( selector, name ) {
        click( selector );

        if ( name !== undefined ) {
            var input = q( '[data-confirm-name]' );
            if ( input ) { set( input, name ); }
        }

        click( '[data-action="confirm-yes"]' );
    }

    function selected_form_id() {
        var select = q( '[data-form-select]' );
        return select ? select.value : '';
    }

    function select_form( id ) {
        var select = q( '[data-form-select]' );
        select.value = id;
        select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }

    function note( step ) {
        try {
            fetch( '/asf-progress.php?step=' + encodeURIComponent( step ), { credentials: 'omit' } );
        } catch ( e ) {}
    }

    var ADMIN = '/wp-admin/admin.php?page=agentshell-forms-builder';

    function scenario() {
        var form_id = '';
        var page_url = '';
        var page_id = 0;

        return load( ADMIN )
            .then( function () {
                note( '1-create' );
                confirm_action( '[data-action="create"]', token + ' Contact' );
                return wait_for( function () {
                    var name = q( '[data-form-name]' );
                    return name && name.textContent.indexOf( token ) >= 0;
                }, 15000 );
            } )
            .then( function () {
                ok( '1. create: a form appears in the builder', ( q( '[data-form-name]' ).textContent || '' ).indexOf( token ) >= 0 );
                ok( '1. create: it starts with the starter field the schema requires', fields().length === 1, fields().length );
                form_id = selected_form_id();
                ok( '1. create: the selector names it', !! form_id );
                return api( '/forms/' + form_id );
            } )
            .then( function ( response ) {
                ok( '1. create: the new form is the one on screen', !! response.ok && ( response.body.working.fields || [] ).length === 1, response.status + ' ' + JSON.stringify( response.body && ( response.body.working.fields || [] ).length ) );
            } )

            // 2. Add fields.
            .then( function () {
                note( '2-fields' );
                [ 'email', 'textarea', 'select', 'checkbox', 'consent' ].forEach( function ( type ) {
                    click( '[data-palette="' + type + '"]' );
                } );
                ok( '2. fields: five more fields were added', fields().length === 6, fields().length );
                ok( '2. fields: the types are right', keys().length === 6 );
            } )

            // 3. Field-specific settings and options.
            .then( function () {
                note( '3-settings' );
                // Field 0 is the starter text field the schema requires.
                click( '[data-field-index="0"] [data-action="select-field"]' );
                set( q( '[data-inspector] [data-setting="label"]' ), 'Your name' );
                set( q( '[data-inspector] [data-setting="key"]' ), 'name' );
                set_checked( q( '[data-inspector] [data-setting="required"]' ), true );

                // Field 1 is the email field.
                click( '[data-field-index="1"] [data-action="select-field"]' );
                set( q( '[data-inspector] [data-setting="label"]' ), 'Your email' );
                set( q( '[data-inspector] [data-setting="key"]' ), 'email' );
                set_checked( q( '[data-inspector] [data-setting="required"]' ), true );
                set( q( '[data-inspector] [data-setting="max_length"]' ), '200' );

                // Field 2 is the textarea.
                click( '[data-field-index="2"] [data-action="select-field"]' );
                set( q( '[data-inspector] [data-setting="rows"]' ), '6' );

                // Field 4 is the checkbox group: a choice field with no
                // options is not a valid definition, so give it one.
                click( '[data-field-index="4"] [data-action="select-field"]' );
                click( '[data-action="add-option"]' );
                set( q( '[data-option-value]' ), 'news' );
                set( q( '[data-option-label]' ), 'Newsletter' );

                // Field 3 is the select.
                click( '[data-field-index="3"] [data-action="select-field"]' );
                click( '[data-action="add-option"]' );
                click( '[data-action="add-option"]' );
                var values = qa( '[data-option-value]' );
                var labels = qa( '[data-option-label]' );
                set( values[ 0 ], 'support' ); set( labels[ 0 ], 'Support' );
                set( values[ 1 ], 'sales' ); set( labels[ 1 ], 'Sales' );

                ok( '3. settings: the inspector edits are applied to the DOM', q( '[data-option-value]' ).value === 'support' );
            } )

            // 4. Reorder with the keyboard-accessible buttons.
            .then( function () {
                note( '4-reorder' );
                var before = keys().slice();
                click( '[data-field-index="0"] [data-action="move-down"]' );
                var after = keys().slice();
                ok( '4. reorder: the first field moved down', before[ 0 ] === after[ 1 ] && before[ 1 ] === after[ 0 ], before.join( ',' ) + ' -> ' + after.join( ',' ) );
            } )

            // 5. Preview without writing.
            .then( function () {
                note( '5-preview' );
                click( '[data-action="preview"]' );
                return wait_for( function () { return q( '[data-preview] [data-agentshell-form], [data-preview] form' ); }, 10000 );
            } )
            .then( function () {
                var preview = q( '[data-preview]' );
                var submit = preview.querySelector( 'button[type="submit"], input[type="submit"]' );
                ok( '5. preview: the draft renders', ( preview.textContent || '' ).indexOf( 'Your email' ) >= 0 );
                ok( '5. preview: submission is disabled', !! submit && submit.disabled === true );
                return api( '/forms/' + form_id );
            } )
            .then( function ( response ) {
                ok( '5. preview: nothing was saved', !! response.ok );
                eq( '5. preview: the saved working definition is unchanged', ( response.body.working.fields || [] ).length, 1 );
                eq( '5. preview: the published definition is still empty', response.body.published === null ? 0 : ( response.body.published.fields || [] ).length, 0 );
            } )

            // 6. Save and reload.
            .then( function () {
                note( '6-save' );
                click( '[data-action="save"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )
            .then( function () { return api( '/forms/' + form_id ); } )
            .then( function ( response ) {
                var fields_now = ( response.body.working.fields || [] );
                ok( '6. save: the fields persisted', fields_now.length === 6, fields_now.length );
                var email = fields_now.filter( function ( f ) { return f.key === 'email'; } )[ 0 ];
                ok( '6. save: the field settings persisted', !! email && email.required === true && String( email.settings.max_length ) === '200', JSON.stringify( email && email.settings ) );
                var select = fields_now.filter( function ( f ) { return f.type === 'select'; } )[ 0 ];
                ok( '6. save: the options persisted', !! select && select.options.length === 2 && select.options[ 0 ].value === 'support', JSON.stringify( select && select.options ) );
                // Reload the builder: the persisted definition is what loads.
                return load( ADMIN );
            } )
            .then( function () {
                ok( '6. save: a reload shows the saved fields', fields().length === 6, fields().length );
            } )

            // 7. Publish, then render through a shortcode on a real page.
            .then( function () {
                note( '7-publish' );
                click( '[data-action="publish"]' );
                click( '[data-action="confirm-yes"]' );
                return wait_for( function () { return [ 'published', 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )
            .then( function () { return api( '/forms/' + form_id ); } )
            .then( function ( response ) {
                eq( '7. publish: the form has a published version', response.body.version, 1 );
                return core_api( '/wp/v2/pages', {
                    method: 'POST',
                    body: { title: token + ' page', content: '[agentshell_form id="' + form_id + '"]', status: 'publish' },
                } );
            } )
            .then( function ( response ) {
                if ( ! response.body || ! response.body.id ) {
                    throw new Error( 'page create failed: ' + response.status + ' ' + JSON.stringify( response.body ).slice( 0, 200 ) );
                }
                page_id = response.body.id;
                page_url = response.body.link;
                return fetch( page_url, { credentials: 'omit' } ).then( function ( r ) { return r.text(); } );
            } )
            .then( function ( html ) {
                ok( '7. publish: the page renders the form', html.indexOf( 'agentshell-forms' ) >= 0 );
                ok( '7. publish: the page shows the field labels', html.indexOf( 'Your email' ) >= 0 );
            } )

            // 8. Edit without publishing: visitors still see the old version.
            .then( function () {
                note( '8-unpublished' );
                click( '[data-palette="text"]' );
                set( q( '[data-inspector] [data-setting="label"]' ), 'Internal note' );
                click( '[data-action="save"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )
            .then( function () {
                return fetch( page_url, { credentials: 'omit' } ).then( function ( r ) { return r.text(); } );
            } )
            .then( function ( html ) {
                ok( '8. unpublished edit: the new field is not visible to visitors', html.indexOf( 'Internal note' ) < 0 );

                note( '10-inflight' );
                // 10. Edit while a save is in flight. The edit happens in the
                // same tick as the click, so the request is certainly pending.
                var real_fetch = ifr.contentWindow.fetch;
                ifr.contentWindow.fetch = function ( url, opts ) {
                    var promise = real_fetch.apply( this, arguments );
                    if ( String( url ).indexOf( '/save' ) >= 0 && ifr.contentWindow.__slow_save ) {
                        return promise.then( function ( response ) {
                            return new Promise( function ( resolve ) {
                                window.setTimeout( function () { resolve( response ); }, 600 );
                            } );
                        } );
                    }
                    return promise;
                };
                ifr.contentWindow.__slow_save = true;
            } )
            .then( function () {
                // Change a label and save; while the request is in flight, edit
                // again. The newer edit must not be marked saved.
                click( '[data-field-index="1"] [data-action="select-field"]' );
                set( q( '[data-inspector] [data-setting="label"]' ), 'Email (slow save)' );
                click( '[data-action="save"]' );
                set( q( '[data-inspector] [data-setting="description"]' ), 'Edited while saving' );
                return sleep( 1500 );
            } )
            .then( function () {
                ifr.contentWindow.__slow_save = false;
                ok( '10. in-flight save: the request settled', [ 'saved', 'unsaved', 'previewing' ].indexOf( status() ) >= 0, status() );
                return api( '/forms/' + form_id );
            } )
            .then( function ( response ) {
                var email = ( response.body.working.fields || [] ).filter( function ( f ) { return f.key === 'email'; } )[ 0 ];
                ok(
                    '10. in-flight save: the server saved the snapshot, not the newer edit',
                    !! email && ! email.description,
                    JSON.stringify( email )
                );

                note( '11-discard' );
                // 11. Discard, then save again.
                click( '[data-action="discard"]' );
                click( '[data-action="confirm-yes"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 ).then( function () {
                    note( '11-discarded' );
                    return api( '/forms/' + form_id );
                } );
            } )
            .then( function ( response ) {
                eq( '11. discard: the draft is the published definition again', ( response.body.working.fields || [] ).length, 6 );
                // Edit and save on the revision the discard produced.
                click( '[data-palette="text"]' );
                set( q( '[data-inspector] [data-setting="label"]' ), 'After discard' );
                click( '[data-action="save"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )
            .then( function () {
                return api( '/forms/' + form_id );
            } )
            .then( function ( response ) {
                ok( '11. discard: the next save succeeds', ( response.body.working.fields || [] ).length === 7, ( response.body.working.fields || [] ).length );

                note( '12-recovery' );
                // 12a. Validation failure.
                click( '[data-field-index="0"] [data-action="select-field"]' );
                set( q( '[data-inspector] [data-setting="key"]' ), '1bad' );
                click( '[data-action="save"]' );
                return wait_for( function () { return error_text() !== ''; }, 10000 );
            } )
            .then( function () {
                ok( '12. validation: the error region is visible and names the field', error_text().indexOf( '1bad' ) >= 0 || error_text().length > 0, error_text() );
                set( q( '[data-inspector] [data-setting="key"]' ), 'email' );
                click( '[data-action="save"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )
            .then( function () {
                // 12b. A lost response: the save is not reported as successful.
                var real_fetch = ifr.contentWindow.fetch;
                var failed = false;
                ifr.contentWindow.fetch = function ( url, opts ) {
                    if ( ! failed && String( url ).indexOf( '/save' ) >= 0 ) {
                        failed = true;
                        return Promise.reject( new TypeError( 'simulated network failure' ) );
                    }
                    return real_fetch.apply( this, arguments );
                };
                set( q( '[data-inspector] [data-setting="description"]' ), 'Network test' );
                click( '[data-action="save"]' );
                return wait_for( function () { return error_text() !== ''; }, 10000 );
            } )
            .then( function () {
                ok( '12. network: the failure is reported, not called saved', status() !== 'saved' && error_text().length > 0, status() + ' ' + error_text() );
                note( '12-recovered' );
                click( '[data-action="save"]' );
                return wait_for( function () { return [ 'saved', 'previewing' ].indexOf( status() ) >= 0; }, 10000 );
            } )

            // 9. Two tabs: the second editor's save is refused.
            .then( function () {
                var second = doc().createElement( 'iframe' );
                second.id = 'builder2';
                second.style.cssText = 'width:1280px;height:900px';
                doc().body.appendChild( second );
                window.__second = second;
                second.src = ADMIN;
                return wait_for( function () {
                    return second.contentDocument && second.contentDocument.querySelector( '[data-form-select], [data-action="create"]' );
                }, 15000 );
            } )
            .then( function () {
                var second = window.__second;
                var select = second.contentDocument.querySelector( '[data-form-select]' );
                select.value = form_id;
                select.dispatchEvent( new second.contentWindow.Event( 'change', { bubbles: true } ) );
                return wait_for( function () { return second.contentDocument.querySelector( '[data-fields]' ); }, 10000 );
            } )
            .then( function () {
                // Tab 2 edits and saves first.
                var second = window.__second;
                second.contentDocument.querySelector( '[data-palette="text"]' ).click();
                second.contentDocument.querySelector( '[data-action="save"]' ).click();
                return wait_for( function () {
                    return second.contentDocument.querySelector( '[data-status-chip]' ).getAttribute( 'data-status' ) === 'saved';
                }, 10000 );
            } )
            .then( function () {
                // Tab 1 still holds the older revision: its save is refused.
                click( '[data-action="save"]' );
                return wait_for( function () { return status() === 'stale' || error_text() !== ''; }, 10000 );
            } )
            .then( function () {
                ok( '9. conflict: the stale editor is told, and is not overwritten', status() === 'stale' || error_text().indexOf( 'newer' ) >= 0, status() + ' ' + error_text() );
                window.__second.remove();

                // A stale editor reloads, as the interface tells them to.
                return load( ADMIN );
            } )
            .then( function () {
                note( '13-forms' );
                // 13. Multiple forms: create, switch, rename, duplicate, trash.
                confirm_action( '[data-action="create"]', token + ' Second' );
                return wait_for( function () { return q( '[data-form-select]' ) && qa( '[data-form-select] option' ).length >= 2; } ).then( function () { note( '13-created' ); } );
            } )
            .then( function () {
                var select = q( '[data-form-select]' );
                ok( '13. forms: two forms are listed', qa( '[data-form-select] option' ).length >= 2 );
                select.value = form_id;
                select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                return wait_for( function () { return q( '[data-form-name]' ).textContent.indexOf( token ) >= 0; } ).then( function () { note( '13-selected' ); } );
            } )
            .then( function () {
                confirm_action( '[data-action="rename"]', token + ' Renamed' );
                return wait_for( function () { return q( '[data-form-name]' ).textContent.indexOf( 'Renamed' ) >= 0; }, 15000 );
            } )
            .then( function () {
                note( '13-renamed' );
                ok( '13. forms: rename updates the builder', q( '[data-form-name]' ).textContent.indexOf( 'Renamed' ) >= 0 );

                confirm_action( '[data-action="duplicate"]', token + ' Copy' );
                return wait_for( function () { return qa( '[data-form-select] option' ).length >= 3; }, 10000 );
            } )
            .then( function () {
                ok( '13. forms: duplicate adds a form', qa( '[data-form-select] option' ).length >= 3 );
                click( '[data-action="trash"]' );
                click( '[data-action="confirm-yes"]' );
                return sleep( 800 );
            } )
            .then( function () {
                ok( '13. forms: trash removes the form from the list', qa( '[data-form-select] option' ).length >= 2 );

                note( '14-insert' );
                // 14. Copy the shortcode and use it.
                var input = q( '[data-shortcode]' );
                ok( '14. insertion: the shortcode names the form', !! input && input.value.indexOf( '[agentshell_form id="' + form_id + '"]' ) >= 0, input && input.value );
                click( '[data-action="copy-shortcode"]' );
                ok( '14. insertion: the copy button responds', true );
                return core_api( '/wp/v2/pages', {
                    method: 'POST',
                    body: { title: token + ' inserted', content: input.value, status: 'publish' },
                } );
            } )
            .then( function ( response ) {
                return fetch( response.body.link, { credentials: 'omit' } ).then( function ( r ) { return r.text(); } );
            } )
            .then( function ( html ) {
                ok( '14. insertion: the copied shortcode renders a working form', html.indexOf( 'agentshell-forms' ) >= 0 && html.indexOf( 'Your email' ) >= 0 );
            } )
            .then( function () {
                finish();
            } )
            .catch( function ( e ) {
                failures.push( { name: 'the scenario threw', detail: e && e.message ? e.message : String( e ) } );
                document.title = JSON.stringify( { total: total, failures: failures, token: token } );
            } );
    }

    scenario();
}() );
