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
                    app.config = results[ 0 ];
                    editor.load( results[ 0 ] );
                    tx.set_tx_snapshot( results[ 1 ] );
                    return load_history();
                } )
                .then( function () {
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    say( 'The configuration could not be loaded: ' + error.message, 'error' );
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
            if ( options2 && options2.live ) {
                update_status();
                return;
            }
            clear_message();
            app.ui.render();
        }

        function update_status() {
            var status = editor.status( tx );
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
            app.ui.render();
        }

        /* ── saving ───────────────────────────────────────────────────── */

        function save() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( ! tx.can_save() ) {
                say( 'Another actor owns the open transaction. Its staged work cannot be overwritten from here.', 'error' );
                return Promise.resolve();
            }
            if ( ! editor.is_dirty() ) {
                app.hint = 'There is nothing to save.';
                app.ui.render();
                return Promise.resolve();
            }

            app.busy = true;
            app.hint = 'Saving…';
            app.ui.render();

            var opened = null;
            var tx_state = tx.get_state();

            // Whether *this* save opened the transaction. A transaction that
            // was already open is the operator's own staged work, and a
            // failed save must not throw it away: only the transaction this
            // save opened is rolled back when the save fails.
            var opened_here = false;

            var begin = ( tx_state.mode === 'editing_open' )
                ? Promise.resolve( null )
                : api.begin_transaction( 'configurator save' ).then( function ( result ) {
                    tx.begin( 'configurator save' );
                    opened_here = true;
                    return result;
                } );

            return begin
                .then( function ( result ) {
                    opened = result;
                    return api.put_config( editor.to_payload() );
                } )
                .then( function () {
                    return api.commit_transaction();
                } )
                .then( function () {
                    tx.commit( opened && opened.id );
                    return api.get_config();
                } )
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Saved.';
                    return load_history();
                } )
                .then( function () {
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    if ( ! opened_here ) {
                        // The transaction was not ours to close. Say what
                        // happened and leave it as it was.
                        app.ui.render();
                        say( 'Not saved: ' + error.message, 'error' );
                        return;
                    }
                    // The transaction was opened for this save; leave nothing
                    // behind for the operator to find later.
                    var rollback = api.rollback_transaction().catch( function () { return null; } );
                    return rollback.then( function () {
                        tx.rollback();
                        app.ui.render();
                        say( 'Not saved: ' + error.message, 'error' );
                    } );
                } );
        }

        function discard_changes() {
            if ( app.busy ) { return Promise.resolve(); }
            // Defence in depth behind the disabled button: a transaction
            // somebody else holds is not this session's to roll back.
            if ( ! tx.can_discard() ) {
                say( 'Another actor owns the open transaction. Its staged work cannot be discarded from here.', 'error' );
                return Promise.resolve();
            }
            var tx_state = tx.get_state();
            if ( tx_state.mode === 'editing_open' ) {
                app.busy = true;
                app.ui.render();
                return api.rollback_transaction()
                    .then( function () {
                        tx.rollback();
                        return api.get_config();
                    } )
                    .then( function ( config ) {
                        app.config = config;
                        editor.accept_saved( config );
                        app.busy = false;
                        app.hint = 'Discarded.';
                        app.ui.render();
                    } )
                    .catch( function ( error ) {
                        app.busy = false;
                        say( 'The changes could not be discarded: ' + error.message, 'error' );
                    } );
            }
            editor.discard();
            app.hint = 'Discarded.';
            clear_message();
            app.ui.render();
            return Promise.resolve();
        }

        /* ── preview ──────────────────────────────────────────────────── */

        function preview() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( ! editor.is_dirty() ) {
                app.hint = 'There is nothing staged to preview.';
                app.ui.render();
                return Promise.resolve();
            }
            app.busy = true;
            app.ui.render();
            return api.preview_transaction()
                .then( function ( result ) {
                    app.busy = false;
                    if ( result && result.url ) {
                        window.open( result.url, '_blank', 'noopener' );
                        app.hint = 'Preview opened in a new tab.';
                    } else {
                        app.hint = 'There is no preview to open.';
                    }
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    say( 'The preview could not be opened: ' + error.message, 'error' );
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

        function close() {
            if ( editor.is_dirty() && ! window.confirm( 'There are unsaved changes. Close the editor and lose them?' ) ) {
                return;
            }
            var panel = document.getElementById( 'agentshell-config-panel' );
            if ( panel ) {
                panel.classList.remove( 'is-open' );
                panel.setAttribute( 'aria-hidden', 'true' );
            }
            document.body.classList.remove( 'config-panel-open' );
        }

        app.on_change = on_change;
        app.set_context = set_context;
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
            if ( ! editor.is_dirty() ) {
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
