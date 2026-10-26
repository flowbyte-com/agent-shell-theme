/**
 * The configurator's application layer.
 *
 * This is the only module that knows about both the models and the DOM. It
 * owns the wiring — the transport calls, the event handlers, the dirty
 * guard — and it owns nothing else: the draft lives in the editor model, the
 * render lives in the view module, and the requests live in the API module.
 *
 * The save path is the one the server already defines, unchanged:
 *
 *   1. If no transaction is open, open one (the PUT is then staged rather
 *      than written straight through).
 *   2. PUT the configuration. The server validates it; a refusal comes back
 *      as an error with a message, and the draft is kept so the operator can
 *      correct it.
 *   3. Commit the transaction.
 *   4. Re-read the configuration, so the editor shows what the server stored
 *      rather than what it was asked to store.
 *
 * A failed save rolls the transaction back — but only the transaction that
 * save opened. A transaction that was already open is the operator's own
 * staged work, and a refusal must not throw it away.
 */
(function ( root ) {
    'use strict';

    function make_app( options ) {
        var api = options.api;
        var editor = options.editor;
        var tx = options.tx;
        var zone = options.zone;

        var app = {
            config: null,
            history: { snapshots: [], revisions: [] },
            message: '',
            hint: '',
            busy: false,
            editor: editor,
            tx: tx,
            // The slot-grid rules: which area to open on, what a move does,
            // when a zone renders as nine areas. The view asks this model
            // rather than deciding for itself, so the rules the server and
            // the tools use are the rules the editor uses.
            zone: zone,
        };


        /* ── messages ─────────────────────────────────────────────────── */

        function say( message, kind ) {
            app.message = message || '';
            app.hint = kind === 'error' ? '' : ( message ? '' : app.hint );
            app.ui.render();
        }

        function clear_message() {
            app.message = '';
        }

        /* ── loading ──────────────────────────────────────────────────── */

        function load() {
            return Promise.all( [ api.get_config(), api.get_transaction() ] )
                .then( function ( results ) {
                    var payload = results[ 1 ] || {};
                    var staged = ( payload.open && payload.owned_by_us && payload.has_staged && payload.staged )
                        ? payload.staged
                        : null;
                    app.config = results[ 0 ];
                    editor.load( results[ 0 ], staged );
                    tx.set_tx_snapshot( payload );
                    app.clear_error();
                    return load_history();
                } )
                .then( function () {
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.ui.render();
                    fail( error, 'The configuration could not be loaded: ' );
                } );
        }

        function load_history() {
            return Promise.all( [
                api.list_snapshots().catch( function () { return { snapshots: [] }; } ),
                api.list_revisions().catch( function () { return { revisions: [] }; } ),
            ] ).then( function ( results ) {
                app.history.snapshots = results[ 0 ].snapshots || [];
                app.history.revisions = results[ 1 ].revisions || [];
            } );
        }

        /* ── changes ──────────────────────────────────────────────────── */

        function on_change( options2 ) {
            // A live change (typing in a field) updates the status without
            // re-rendering the whole canvas, so the caret stays where the
            // operator left it.
            app.clear_error();
            if ( options2 && options2.live ) {
                update_status();
                // A live edit does not re-render the canvas (the caret must
                // stay where it is), so the obsolete alert is hidden here.
                var alert = document.querySelector( '#agentshell-config-panel .as-cfg__alert' );
                if ( alert ) {
                    alert.hidden = true;
                    alert.textContent = '';
                }
                return;
            }
            clear_message();
            app.ui.render();
        }

        function update_status() {
            var status = app.status();
            var chip = document.querySelector( '#agentshell-config-panel .as-cfg__status' );
            if ( chip ) {
                chip.textContent = status.label;
                chip.setAttribute( 'data-status', status.key );
            }
            var panel = document.getElementById( 'agentshell-config-panel' );
            if ( panel ) { panel.setAttribute( 'data-state', status.key ); }
        }

        function set_context( context ) {
            editor.set_context( context );
            clear_message();
            app.clear_error();
            app.ui.render();
        }

        /* ── failure state ────────────────────────────────────────────── */

        /**
         * Record a failed operation without losing the draft.
         *
         * `validation` is the server refusing a configuration with a code;
         * `network` is the request never getting an answer. Both leave the
         * draft exactly as the operator typed it, and both are cleared by the
         * next successful operation or the next edit.
         */
        function fail( error, prefix ) {
            app.error = {
                kind: ( error && ( error.status || error.code ) ) ? 'validation' : 'network',
                message: error && error.message ? error.message : 'The request did not complete.',
            };
            app.message = ( prefix || '' ) + app.error.message;
            app.hint = '';
            if ( app.ui ) {
                app.ui.render();
            }
        }

        function clear_error() {
            app.error = null;
            app.message = '';
        }

        function status_now() {
            var status = editor.status( tx );
            if ( app.error && status.key !== 'locked' ) {
                return {
                    key: 'error',
                    label: 'Not saved — ' + ( app.error.kind === 'network' ? 'connection error' : 'validation error' ),
                    can_save: status.can_save,
                };
            }
            return status;
        }

        /**
         * The server's current transaction, mirrored into the local model.
         *
         * Ownership is re-read rather than remembered. A reload, an expired
         * lease or a second session can all change the answer, and a local
         * flag that disagrees with the server is how a browser decides it may
         * close somebody else's work.
         */
        function refresh_transaction() {
            return api.get_transaction().then( function ( payload ) {
                tx.set_tx_snapshot( payload );
                return payload;
            } );
        }

        function is_locked() {
            return tx.get_state().mode === 'locked';
        }

        /**
         * Staged work this session has not seen belongs to another editor
         * session of the same account. It is never overwritten, committed or
         * rolled back from here.
         */
        function has_foreign_session_staging( payload ) {
            return !!(
                payload &&
                payload.open &&
                payload.owned_by_us &&
                payload.has_staged &&
                ! editor.has_staged()
            );
        }

        function open_preview_window() {
            if ( typeof window === 'undefined' || typeof window.open !== 'function' ) {
                return null;
            }
            // Opened synchronously, inside the click. A tab opened after the
            // network calls is a pop-up the browser is entitled to block.
            try {
                var win = window.open( 'about:blank', '_blank' );
                if ( win ) {
                    try { win.opener = null; } catch ( e ) {}
                }
                return win || null;
            } catch ( e ) {
                return null;
            }
        }

        function close_preview_window( win ) {
            if ( ! win ) { return; }
            try { win.close(); } catch ( e ) {}
        }

        function show_preview( win, url ) {
            if ( win && ! win.closed ) {
                try {
                    win.location.replace( url );
                    return true;
                } catch ( e ) {}
            }
            // The placeholder was blocked or lost. Try once more; if the
            // browser blocks this too, the operator is told plainly rather
            // than shown a credential in the page.
            try {
                var fallback = window.open( url, '_blank' );
                if ( fallback ) {
                    try { fallback.opener = null; } catch ( e ) {}
                    return true;
                }
            } catch ( e ) {}
            return false;
        }

        /* ── saving ───────────────────────────────────────────────────── */

        function save() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( 'Another actor owns the open transaction. Its staged work cannot be overwritten from here.', 'error' );
                return Promise.resolve();
            }

            var tx_state = tx.get_state();
            var open_owned = tx_state.mode === 'editing_open';
            if ( ! editor.is_dirty() && ! open_owned ) {
                app.hint = 'There is nothing to save.';
                app.ui.render();
                return Promise.resolve();
            }

            app.busy = true;
            app.hint = 'Saving…';
            app.clear_error();
            app.ui.render();

            var opened_here = false;
            var current = null;
            var already_staged = false;

            return refresh_transaction()
                .then( function ( payload ) {
                    if ( payload.open && ! payload.owned_by_us ) {
                        throw new Error( 'Another actor owns the open transaction. Its staged work cannot be overwritten from here.' );
                    }
                    if ( has_foreign_session_staging( payload ) ) {
                        var refusal = new Error( 'This account has changes staged in another editor session. Reload the editor to review them before saving.' );
                        refusal.code = 'foreign_session_staged';
                        throw refusal;
                    }

                    current = payload;
                    if ( payload.open ) {
                        // The server, not the local mirror, says whether this
                        // transaction already holds exactly this draft.
                        already_staged = editor.has_staged() && ! editor.is_modified_since_staged();
                        return null;
                    }
                    return api.begin_transaction( 'configurator save' ).then( function ( result ) {
                        tx.begin( 'configurator save' );
                        opened_here = true;
                        already_staged = false;
                        current = { open: true, id: result && result.id, owned_by_us: true, has_staged: false };
                        return result;
                    } );
                } )
                .then( function () {
                    // A preview already staged this draft. Saving it must
                    // commit that transaction rather than begin a second
                    // one; only a draft that moved since it was staged is
                    // written again.
                    if ( already_staged ) {
                        return null;
                    }
                    return api.put_config( editor.to_payload() ).then( function () {
                        editor.mark_staged();
                        tx.mark_staged();
                    } );
                } )
                .then( function () {
                    return api.commit_transaction();
                } )
                .then( function () {
                    tx.commit( current && current.id );
                    return api.get_config();
                } )
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Saved.';
                    app.clear_error();
                    return load_history();
                } )
                .then( function () {
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    if ( ! opened_here ) {
                        // The transaction was not opened by this save. It may
                        // hold a preview the operator still wants; leave it.
                        app.ui.render();
                        fail( error, 'Not saved: ' );
                        return;
                    }
                    // This save opened the transaction; leave nothing behind.
                    return api.rollback_transaction().catch( function () { return null; } ).then( function () {
                        tx.rollback();
                        editor.clear_staged();
                        app.ui.render();
                        fail( error, 'Not saved: ' );
                    } );
                } );
        }

        function discard_changes() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( 'Another actor owns the open transaction. Its staged work cannot be discarded from here.', 'error' );
                return Promise.resolve();
            }

            app.busy = true;
            app.clear_error();
            app.ui.render();

            var rolled_back = false;

            return refresh_transaction()
                .then( function ( payload ) {
                    if ( payload.open && ! payload.owned_by_us ) {
                        throw new Error( 'Another actor owns the open transaction. Its staged work cannot be discarded from here.' );
                    }
                    if ( has_foreign_session_staging( payload ) ) {
                        var refusal = new Error( 'This account has changes staged in another editor session. Reload the editor to review them before discarding.' );
                        refusal.code = 'foreign_session_staged';
                        throw refusal;
                    }
                    if ( ! payload.open ) {
                        return null;
                    }
                    return api.rollback_transaction().then( function () {
                        rolled_back = true;
                        tx.rollback();
                        editor.clear_staged();
                    } );
                } )
                .then( function () {
                    return api.get_config();
                } )
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Discarded.';
                    app.clear_error();
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    if ( rolled_back ) {
                        // The rollback itself succeeded; only the refresh
                        // failed. The draft must not keep describing a
                        // transaction that no longer exists.
                        tx.rollback();
                        editor.discard();
                    }
                    app.ui.render();
                    fail( error, 'The changes could not be discarded: ' );
                } );
        }

        /* ── preview ──────────────────────────────────────────────────── */

        function preview() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( 'Another actor owns the open transaction. Its staged work cannot be previewed from here.', 'error' );
                return Promise.resolve();
            }

            var tx_state = tx.get_state();
            var has_work = editor.is_dirty() ||
                ( tx_state.mode === 'editing_open' && editor.has_staged() && editor.is_modified_since_staged() ) ||
                ( tx_state.mode === 'editing_open' && tx_state.tx && tx_state.tx.has_staged && ! editor.has_staged() );

            if ( ! has_work ) {
                app.hint = 'There is nothing staged to preview.';
                app.ui.render();
                return Promise.resolve();
            }

            // The tab is opened before any request. This is the only moment
            // the browser still attributes the window to the operator's
            // click; everything after it is asynchronous.
            var preview_window = open_preview_window();

            app.busy = true;
            app.hint = 'Staging…';
            app.clear_error();
            app.ui.render();

            var staged_here = false;

            return refresh_transaction()
                .then( function ( payload ) {
                    if ( payload.open && ! payload.owned_by_us ) {
                        throw new Error( 'Another actor owns the open transaction. Its staged work cannot be previewed from here.' );
                    }
                    if ( has_foreign_session_staging( payload ) ) {
                        var refusal = new Error( 'This account has changes staged in another editor session. Reload the editor to review them before previewing.' );
                        refusal.code = 'foreign_session_staged';
                        throw refusal;
                    }
                    if ( payload.open ) {
                        return null;
                    }
                    return api.begin_transaction( 'configurator preview' ).then( function () {
                        tx.begin( 'configurator preview' );
                    } );
                } )
                .then( function () {
                    // Every preview stages the draft as it is now. A second
                    // edit after a preview is therefore previewed as a second
                    // PUT, not served from the first one.
                    return api.put_config( editor.to_payload() ).then( function () {
                        staged_here = true;
                        editor.mark_staged();
                        tx.mark_staged();
                    } );
                } )
                .then( function () {
                    return api.preview_transaction();
                } )
                .then( function ( result ) {
                    app.busy = false;
                    if ( ! result || ! result.url ) {
                        close_preview_window( preview_window );
                        app.hint = 'There is no preview to open.';
                        app.ui.render();
                        return;
                    }
                    var opened = show_preview( preview_window, result.url );
                    app.clear_error();
                    if ( opened ) {
                        app.hint = 'Preview opened in a new tab. Nothing has been saved.';
                    } else {
                        // The draft is staged; only the tab was blocked.
                        // Calling it a failure to save would be false.
                        app.message = 'The preview was staged, but the browser blocked the new tab. Allow pop-ups for this site and press Preview again.';
                        app.hint = '';
                    }
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    close_preview_window( preview_window );
                    if ( ! staged_here ) {
                        editor.clear_staged();
                        fail( error, 'The preview could not be opened: ' );
                    } else {
                        // The PUT landed, so the work is staged and can be
                        // saved or discarded. The credential request failed;
                        // that is shown without mislabelling the draft.
                        app.message = 'The preview could not be opened: ' + ( error && error.message ? error.message : 'the request did not complete.' );
                        app.hint = '';
                    }
                    app.ui.render();
                } );
        }

        /* ── history ──────────────────────────────────────────────────── */

        function create_snapshot( name ) {
            var clean = String( name || '' ).trim();
            if ( ! clean ) {
                say( 'Give the snapshot a name first.', 'error' );
                return Promise.resolve();
            }
            app.busy = true;
            app.ui.render();
            return api.create_snapshot( clean, clean )
                .then( function () {
                    app.busy = false;
                    app.hint = 'Snapshot taken.';
                    return load_history();
                } )
                .then( function () { app.ui.render(); } )
                .catch( function ( error ) {
                    app.busy = false;
                    say( 'The snapshot was not taken: ' + error.message, 'error' );
                } );
        }

        function restore_snapshot( name ) {
            app.busy = true;
            app.ui.render();
            return api.restore_snapshot( name )
                .then( function () {
                    return api.get_config();
                } )
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Snapshot restored.';
                    return load_history();
                } )
                .then( function () { app.ui.render(); } )
                .catch( function ( error ) {
                    app.busy = false;
                    say( 'The snapshot was not restored: ' + error.message, 'error' );
                } );
        }

        function restore_revision( id ) {
            app.busy = true;
            app.ui.render();
            return api.restore_revision( id )
                .then( function () {
                    return api.get_config();
                } )
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Revision restored.';
                    return load_history();
                } )
                .then( function () { app.ui.render(); } )
                .catch( function ( error ) {
                    app.busy = false;
                    say( 'The revision was not restored: ' + error.message, 'error' );
                } );
        }

        /* ── adding components ────────────────────────────────────────── */

        /**
         * Open the component picker for an area.
         *
         * One picker, used for every zone and every area. The choices come
         * from the server's own configuration, so the picker cannot offer
         * a component the site does not have, and the names it shows are
         * the server's names rather than numbers an operator has to
         * translate.
         *
         * Nothing is added until a choice is made: the picker reports the
         * choice, and this function is what acts on it.
         *
         * @param {string} zone_id
         * @param {string|null} area_id
         * @param {Element|null} trigger The element to return the focus to.
         */
        function open_component_picker( zone_id, area_id, trigger ) {
            var picker = options.picker || root.AgentshellConfiguratorPicker;
            if ( ! picker || typeof picker.open !== 'function' ) {
                say( 'The component picker is not available.', 'error' );
                return;
            }

            var panel = document.getElementById( 'agentshell-config-panel' );
            if ( ! panel ) {
                say( 'The component picker is not available.', 'error' );
                return;
            }

            var groups = picker.build_choices( app.config );
            var target = component_picker_target( zone_id, area_id );

            picker.open( {
                host: panel,
                groups: groups,
                target: target,
                returnFocus: trigger || null,
                onChoose: function ( item ) {
                    var block = { type: item.type };
                    if ( item.id ) { block.id = item.id; }
                    if ( ! editor.add_block( zone_id, area_id, block ) ) {
                        say( 'That component could not be added here.', 'error' );
                        return;
                    }
                    say( item.label + ' was added to ' + target + '.', 'info' );
                    on_change();
                },
            } );
        }

        /**
         * Where a component would land, in words.
         *
         * The operator is told this before choosing, so "Add component"
         * never has to be guessed at.
         */
        function component_picker_target( zone_id, area_id ) {
            var state = editor.get_state();
            var zones = ( app.config && app.config.zones ) || [];
            var zone = zones.filter( function ( z ) { return z.id === zone_id; } )[ 0 ];
            var zone_label = zone && zone.label ? zone.label : ( zone_id || 'this zone' );
            if ( ! area_id ) {
                return zone_label;
            }
            var areas = ( app.config && app.config.zone_areas ) || [];
            var area = areas.filter( function ( a ) { return a.id === area_id; } )[ 0 ];
            var area_label = area && area.label ? area.label : area_id;
            return zone_label + ' · ' + area_label;
        }

        /* ── closing ──────────────────────────────────────────────────── */

        function close_panel() {
            var panel = document.getElementById( 'agentshell-config-panel' );
            if ( panel ) {
                panel.classList.remove( 'is-open' );
                panel.setAttribute( 'aria-hidden', 'true' );
            }
            document.body.classList.remove( 'config-panel-open' );
        }

        function close() {
            if ( app.busy ) { return; }

            var tx_state = tx.get_state();
            var open_owned = tx_state.mode === 'editing_open';
            var staged_here = open_owned && editor.has_staged();
            var staged_elsewhere = open_owned && tx_state.tx && tx_state.tx.has_staged && ! editor.has_staged();

            if ( staged_elsewhere ) {
                if ( ! window.confirm( 'This account has changes staged by another editor session. Close without discarding them?' ) ) {
                    return;
                }
                close_panel();
                return;
            }

            if ( staged_here || open_owned ) {
                var prompt = staged_here
                    ? 'Changes are staged for preview but not saved. Close and discard them? Choose Cancel to keep editing.'
                    : 'A transaction is open. Close it and discard it? Choose Cancel to keep editing.';
                if ( ! window.confirm( prompt ) ) {
                    return;
                }
                // Discard rolls back only the transaction this session has
                // staged into, then refreshes from the server. If that fails
                // the editor stays open with the reason on screen.
                discard_changes().then( function () {
                    if ( ! app.message ) {
                        close_panel();
                    }
                } );
                return;
            }

            if ( editor.is_dirty() && ! window.confirm( 'There are unsaved changes. Close the editor and lose them?' ) ) {
                return;
            }
            close_panel();
        }

        app.on_change = on_change;
        app.set_context = set_context;
        app.status = status_now;
        app.clear_error = clear_error;
        app.refresh_transaction = refresh_transaction;
        app.save = save;
        app.discard_changes = discard_changes;
        app.preview = preview;
        app.create_snapshot = create_snapshot;
        app.restore_snapshot = restore_snapshot;
        app.restore_revision = restore_revision;
        app.open_component_picker = open_component_picker;
        app.close = close;
        app.load = load;

        return app;
    }

    /**
     * Start the editor.
     *
     * The trigger button opens the dock; the configuration is fetched the
     * first time it is opened, so a visitor who never opens the editor pays
     * for the assets and nothing else. The unsaved-changes guard is the
     * browser's own: leaving the page with unsaved work asks first.
     */
    function boot( options ) {
        options = options || {};
        var panel = document.getElementById( 'agentshell-config-panel' );
        var trigger = document.getElementById( 'agentshell-config-trigger' );
        if ( ! panel || ! trigger ) {
            return null;
        }

        var state = options.state || root.AgentshellConfiguratorState;
        var apiModule = options.api || root.AgentshellConfiguratorApi;
        var uiModule = options.ui || root.AgentshellConfiguratorUi;

        var editor = options.editor || state.make_editor_model();
        var tx = options.tx || state.make_state_model();
        var zone = options.zone || state.make_zone_model();
        var api = options.api_client || apiModule.make_api( {
            restUrl: options.restUrl || '/',
            nonce: options.nonce || '',
        } );

        var app = make_app( {
            api: api,
            editor: editor,
            tx: tx,
            zone: zone,
            picker: options.picker || root.AgentshellConfiguratorPicker,
        } );
        app.ui = uiModule.make_ui( { app: app } );

        // Reachable for debugging and for the browser tests: one handle on
        // the running editor, rather than a dozen closures nobody can reach.
        if ( typeof root !== 'undefined' ) {
            root.AgentshellConfigurator = app;
        }

        var loaded = false;
        trigger.addEventListener( 'click', function () {
            panel.classList.add( 'is-open' );
            panel.setAttribute( 'aria-hidden', 'false' );
            document.body.classList.add( 'config-panel-open' );
            if ( ! loaded ) {
                loaded = true;
                app.load();
            }
        } );

        window.addEventListener( 'beforeunload', function ( event ) {
            var tx_state = tx.get_state();
            var staged = tx_state.mode === 'editing_open' &&
                ( editor.has_staged() || ( tx_state.tx && tx_state.tx.has_staged ) );
            if ( ! editor.is_dirty() && ! staged ) {
                return undefined;
            }
            event.preventDefault();
            event.returnValue = '';
            return '';
        } );

        return app;
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_app: make_app, boot: boot };
    } else {
        root.AgentshellConfiguratorApp = { make_app: make_app, boot: boot };
    }
})( typeof window !== 'undefined' ? window : globalThis );
