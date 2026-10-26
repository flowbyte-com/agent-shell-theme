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
        var session = options.session || null;

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
                    var staged = ( payload.open && payload.editable_by_us && payload.has_staged && payload.staged )
                        ? payload.staged
                        : null;
                    if ( session && ( ( payload.open && ! payload.editable_by_us ) || ( ! payload.open && payload.stale ) ) ) {
                        session.clear();
                    }
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
                if ( app.error.kind === 'unknown' ) {
                    return {
                        key: 'unknown',
                        label: 'Save outcome could not be verified',
                        can_save: status.can_save,
                        can_preview: status.can_preview !== false,
                    };
                }
                if ( app.error.kind === 'staged' ) {
                    // The change is staged but not committed. That is a real
                    // state, not a failure to save: the status chip says so
                    // and Save remains available to commit it.
                    return status;
                }
                return {
                    key: 'error',
                    label: 'Not saved — ' + ( app.error.kind === 'network' ? 'connection error' : 'validation error' ),
                    can_save: status.can_save,
                    can_preview: status.can_preview !== false,
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
                // A lock this session cannot act on means the capability it
                // holds is no longer the row's. Dropping it stops every later
                // request from presenting a stale proof; the server remains
                // the authority either way.
                if ( session && payload && payload.open && ! payload.editable_by_us ) {
                    session.clear();
                }
                if ( session && payload && ! payload.open && payload.stale ) {
                    session.clear();
                }
                return payload;
            } );
        }

        function is_locked() {
            return tx.get_state().mode === 'locked';
        }

        /** What to say when this session cannot act on the open transaction. */
        function lock_refusal( action ) {
            var st = tx.get_state();
            var past = { overwrite: 'overwritten', discard: 'discarded', preview: 'previewed' };
            var participle = past[ action ] || ( action + 'd' );
            if ( st.lockReason === 'other_actor' ) {
                return 'Another actor owns the open transaction. Its staged work cannot be ' + participle + ' from here.';
            }
            if ( st.lockReason === 'unbound' ) {
                return 'The open transaction was started outside this editor session. Recover it explicitly before you '
                    + action + ' it here.';
            }
            return 'Another editor session owns the open transaction. Its staged work cannot be ' + participle
                + ' from here. Recover it if it is yours, or wait for it to be saved or discarded.';
        }

        /**
         * Refuse to act on a transaction this browser session does not own.
         *
         * The server already refuses; this turns its answer into the message
         * the operator sees, and keeps a stale local mirror from being read as
         * permission. `editable_by_us` is the server's answer about *this
         * session*, not merely about this WordPress account.
         */
        function ensure_editable( payload ) {
            if ( ! payload || ! payload.open ) {
                return;
            }
            if ( payload.editable_by_us ) {
                return;
            }
            var error;
            if ( payload.lock_reason === 'other_session' ) {
                error = new Error( 'This account has a transaction open in another editor session. Its staged work cannot be read, overwritten, committed or rolled back from here.' );
                error.code = 'transaction_locked_by_session';
            } else if ( payload.lock_reason === 'unbound' ) {
                error = new Error( 'The open transaction was started outside this editor session. Recover it explicitly to continue here.' );
                error.code = 'transaction_requires_recovery';
            } else {
                error = new Error( 'Another actor owns the open transaction. Its staged work cannot be overwritten from here.' );
                error.code = 'transaction_locked_by_other';
            }
            throw error;
        }

        /**
         * Forget a capability the server has just told us is not the row's.
         *
         * Only `transaction_locked_by_session` proves that: our token is stale
         * (another session recovered the work, or the row is a later one).
         * A foreign actor's transaction or an unbound row is not our token's
         * fault, and clearing on those would discard a capability that may
         * still be valid for a transaction created afterwards.
         */
        function forget_stale_session( error ) {
            if ( error && error.code === 'transaction_locked_by_session' && session ) {
                session.clear();
            }
        }

        function remember_begin( result, label ) {
            if ( result && result.editor_session && session ) {
                session.set( result.editor_session );
            }
            tx.begin( label, result || {} );
        }

        /**
         * Ask the server what actually happened to a transaction id.
         *
         * A committed transaction has no row, and neither does one that was
         * rolled back or reclaimed. The audit log's transaction id is the
         * durable answer. Anything this fails to learn is reported as
         * `unknown` rather than guessed.
         */
        function reconcile( id ) {
            if ( ! id ) {
                return Promise.resolve( { state: 'unknown', reason: 'no_transaction_id' } );
            }
            return api.transaction_outcome( id ).catch( function ( error ) {
                return { state: 'unknown', reason: ( error && error.code ) || 'outcome_request_failed' };
            } );
        }

        /**
         * Did the server definitely refuse this request?
         *
         * A transport failure has no HTTP status and no WordPress error code.
         * An application refusal does — and WordPress maps a `WP_Error`
         * without an explicit status onto 500, so status alone is not enough:
         * the canonical validator answers a bad colour with
         * `invalid_color_value` and 500. A code and a message mean the request
         * reached the server and was refused, which is what "validation
         * failed" must mean. A 500 with no code (a PHP fatal, an HTML error
         * page) stays a connection/server failure and is reconciled.
         */
        function is_validation_error( error ) {
            if ( ! error ) { return false; }
            if ( ! error.status && ! error.code ) { return false; }
            if ( error.status >= 400 && error.status < 500 ) { return true; }
            return !! error.code
                && 'request_failed' !== error.code
                && 'internal_server_error' !== error.code;
        }

        function error_detail( error ) {
            return error && error.message ? error.message : 'The request did not complete.';
        }

        /**
         * The commit is confirmed (or reconciled as committed). Read the
         * stored configuration back and only then report Saved.
         *
         * If the commit succeeded but the refresh did not, the operator is
         * told exactly that. Reporting "Not saved" here would be false: the
         * server already published the change.
         */
        function finish_commit( id ) {
            tx.commit( id );
            editor.clear_staged();
            return api.get_config()
                .then( function ( config ) {
                    app.config = config;
                    editor.accept_saved( config );
                    app.busy = false;
                    app.hint = 'Saved.';
                    app.clear_error();
                    return load_history();
                } )
                .then( function () { app.ui.render(); } )
                .catch( function () {
                    // The commit landed; only the read failed. Accept the
                    // draft as the baseline for this page and say what is
                    // unknown. A later load replaces it with the server's
                    // canonical form.
                    editor.accept_draft_as_saved();
                    app.busy = false;
                    app.hint = '';
                    app.error = null;
                    app.message = 'Saved, but the editor could not refresh. The changes are on the server; reload to see its stored form.';
                    app.ui.render();
                } );
        }

        /** Roll back only a transaction this operation opened, and only when it is ours. */
        function rollback_own_transaction( ctx ) {
            if ( ! ctx || ! ctx.opened_here || ! ctx.tx_id ) {
                return Promise.resolve( false );
            }
            return api.rollback_transaction()
                .then( function () {
                    tx.rollback();
                    editor.clear_staged();
                    return true;
                } )
                .catch( function () { return false; } );
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

        /**
         * Save: stage if needed, commit, then re-read.
         *
         * Every step records which phase it was in, because a failure means
         * something different at each one:
         *
         *   begin    nothing was staged; there is nothing to undo.
         *   stage    a PUT may or may not have landed. The server is asked.
         *   commit   the commit may have landed and the response been lost.
         *            The audit log is asked; this is the difference between
         *            "Not saved" and a silent duplicate save.
         *   refresh  the commit is confirmed. A read failure is not a save
         *            failure.
         *
         * A rollback is sent only for a transaction this operation opened,
         * only after reconciliation says it is still ours and holds nothing,
         * and never after a commit. Another session's work is never touched.
         */
        function save() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( lock_refusal( 'overwrite' ), 'error' );
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

            var ctx = {
                opened_here: false,
                tx_id: ( tx_state.tx && tx_state.tx.id ) || null,
                already_staged: false,
                phase: 'begin',
                commit_sent: false,
            };

            return refresh_transaction()
                .then( function ( payload ) {
                    ensure_editable( payload );
                    if ( payload.open ) {
                        ctx.tx_id = payload.id;
                        if ( payload.has_staged && ! editor.has_staged() ) {
                            // The server holds staged work this editor never
                            // loaded. It is this session's — but not this
                            // draft's — and overwriting it silently would
                            // destroy work the operator cannot see.
                            var refusal = new Error( 'This transaction already holds staged changes this editor has not loaded. Reload before saving.' );
                            refusal.code = 'staged_not_loaded';
                            throw refusal;
                        }
                        ctx.already_staged = editor.has_staged() && ! editor.is_modified_since_staged();
                        return null;
                    }
                    ctx.phase = 'begin';
                    return api.begin_transaction( 'configurator save' ).then( function ( result ) {
                        ctx.opened_here = true;
                        ctx.tx_id = result && result.id;
                        remember_begin( result, 'configurator save' );
                        ctx.already_staged = false;
                    } );
                } )
                .then( function () {
                    if ( ctx.already_staged ) {
                        return null;
                    }
                    ctx.phase = 'stage';
                    return api.put_config( editor.to_payload() ).then( function () {
                        editor.mark_staged();
                        tx.mark_staged();
                    } );
                } )
                .then( function () {
                    ctx.phase = 'commit';
                    ctx.commit_sent = true;
                    return api.commit_transaction();
                } )
                .then( function () {
                    return finish_commit( ctx.tx_id );
                } )
                .catch( function ( error ) {
                    return handle_save_failure( error, ctx );
                } );
        }

        function handle_save_failure( error, ctx ) {
            forget_stale_session( error );

            // ── the commit was attempted ────────────────────────────────
            if ( ctx.commit_sent || ctx.phase === 'commit' ) {
                return reconcile( ctx.tx_id ).then( function ( outcome ) {
                    if ( outcome.state === 'committed' ) {
                        // The server committed; the response never arrived.
                        // Report it honestly and read the stored config.
                        return finish_commit( ctx.tx_id );
                    }
                    if ( outcome.state === 'open' ) {
                        if ( ! outcome.owned_by_session ) {
                            // Another session or actor holds it now. This
                            // save did not commit anything, and it must not
                            // claim the staged work either.
                            tx.rollback();
                            editor.clear_staged();
                            app.busy = false;
                            app.hint = '';
                            app.error = { kind: 'validation', message: error_detail( error ) };
                            app.message = 'Nothing was saved: the transaction is no longer this editor session\'s. ' + error_detail( error );
                            app.ui.render();
                            return null;
                        }
                        // Nothing was committed. The staged work is still
                        // there, so the draft stays and Save can be retried.
                        app.busy = false;
                        app.hint = '';
                        app.error = { kind: 'staged', message: error_detail( error ) };
                        app.message = 'Changes staged but not committed. ' + error_detail( error );
                        app.ui.render();
                        return null;
                    }
                    if ( outcome.state === 'rolled_back' || outcome.state === 'reclaimed' || outcome.state === 'stale' ) {
                        tx.rollback();
                        editor.clear_staged();
                        app.busy = false;
                        app.hint = '';
                        app.error = { kind: 'validation', message: error_detail( error ) };
                        app.message = 'Changes were not saved: the transaction was '
                            + ( outcome.state === 'reclaimed' ? 'reclaimed after expiring' : 'rolled back' )
                            + ' before the commit could be confirmed.';
                        app.ui.render();
                        return null;
                    }
                    // No evidence either way. Do not claim success, do not
                    // roll back (the commit may have landed), keep the draft.
                    app.busy = false;
                    app.hint = '';
                    app.error = { kind: 'unknown', message: error_detail( error ) };
                    app.message = 'Save outcome could not be verified. The server did not confirm whether the commit landed, and no audit record was readable. '
                        + 'Reload to check the live configuration before saving again. ' + error_detail( error );
                    app.ui.render();
                    return null;
                } );
            }

            // ── staging failed (or the transaction could not be opened) ──
            if ( ctx.phase === 'stage' ) {
                return reconcile( ctx.tx_id ).then( function ( outcome ) {
                    var open_and_ours = outcome.state === 'open' && outcome.owned_by_session;

                    // A 4xx is a definite refusal: the PUT wrote nothing. Any
                    // staged state the transaction already held is somebody's
                    // earlier work (this session's earlier preview), not this
                    // draft, so it must not be relabelled as staged now.
                    if ( is_validation_error( error ) ) {
                        if ( open_and_ours && ctx.opened_here && ! outcome.has_staged ) {
                            return rollback_own_transaction( ctx ).then( function () {
                                app.busy = false;
                                app.hint = '';
                                app.error = { kind: 'validation', message: error_detail( error ) };
                                app.message = 'Validation failed; changes remain editable. ' + error_detail( error );
                                app.ui.render();
                                return null;
                            } );
                        }
                        app.busy = false;
                        app.hint = '';
                        app.error = { kind: 'validation', message: error_detail( error ) };
                        app.message = 'Validation failed; changes remain editable. ' + error_detail( error );
                        app.ui.render();
                        return null;
                    }

                    // A network failure may or may not have staged. When this
                    // save opened the transaction, any staged state is this
                    // draft's. When it did not, the transaction may have held
                    // earlier staged work, and `has_staged` cannot prove which
                    // draft it is — say so rather than claim the new one landed.
                    if ( open_and_ours && ctx.opened_here ) {
                        if ( outcome.has_staged ) {
                            editor.mark_staged();
                            tx.mark_staged();
                            app.busy = false;
                            app.hint = '';
                            app.error = { kind: 'staged', message: error_detail( error ) };
                            app.message = 'Changes staged but not committed. ' + error_detail( error );
                            app.ui.render();
                            return null;
                        }
                        return rollback_own_transaction( ctx ).then( function ( rolled_back ) {
                            app.busy = false;
                            app.hint = '';
                            app.error = { kind: 'network', message: error_detail( error ) };
                            app.message = 'Not saved: ' + error_detail( error )
                                + ( rolled_back ? '' : ' The empty transaction could not be closed; it will expire.' );
                            app.ui.render();
                            return null;
                        } );
                    }
                    if ( open_and_ours ) {
                        app.busy = false;
                        app.hint = '';
                        app.error = { kind: 'unknown', message: error_detail( error ) };
                        app.message = 'Save outcome could not be verified. The transaction is still open'
                            + ( outcome.has_staged ? ' and holds staged changes' : '' )
                            + ', but this save\'s staging could not be confirmed. ' + error_detail( error );
                        app.ui.render();
                        return null;
                    }
                    if ( outcome.state === 'open' ) {
                        // Someone else owns it now (or it is not this
                        // session's). Never roll it back.
                        app.busy = false;
                        app.hint = '';
                        app.error = { kind: 'validation', message: error_detail( error ) };
                        app.message = 'Not saved: the transaction is no longer this editor session\'s to stage into. ' + error_detail( error );
                        app.ui.render();
                        return null;
                    }
                    if ( outcome.state === 'unknown' && ctx.opened_here ) {
                        // The PUT and the outcome read both failed. Rolling
                        // back our own transaction is safe — no commit was
                        // sent — but the rollback may not reach the server.
                        return rollback_own_transaction( ctx ).then( function ( rolled_back ) {
                            app.busy = false;
                            app.hint = '';
                            app.error = { kind: 'network', message: error_detail( error ) };
                            app.message = 'Save outcome could not be verified. ' + error_detail( error )
                                + ( rolled_back
                                    ? ' The transaction this save opened was discarded; the draft is kept.'
                                    : ' A transaction may remain open until it expires; reload before trying again.' );
                            app.ui.render();
                            return null;
                        } );
                    }
                    tx.rollback();
                    editor.clear_staged();
                    app.busy = false;
                    app.hint = '';
                    app.error = { kind: 'validation', message: error_detail( error ) };
                    app.message = 'Changes were not saved: the transaction is no longer open. ' + error_detail( error );
                    app.ui.render();
                    return null;
                } );
            }

            // ── begin failed, or a local refusal before any request ──────
            app.busy = false;
            app.hint = '';
            if ( is_validation_error( error ) ) {
                app.error = { kind: 'validation', message: error_detail( error ) };
                app.message = ( error && error.code === 'staged_not_loaded'
                    ? 'Not saved: '
                    : 'Validation failed; changes remain editable. ' ) + error_detail( error );
            } else {
                app.error = { kind: 'network', message: error_detail( error ) };
                app.message = 'Not saved: ' + error_detail( error );
            }
            app.ui.render();
            return null;
        }

        /**
         * Discard, with the same honesty about what actually happened.
         *
         * A rollback that reaches the server and then loses its response is
         * reconciled the same way a commit is. A rollback that turns out to
         * have raced a commit is reported as a commit, never as discarded.
         */
        function discard_changes() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( lock_refusal( 'discard' ), 'error' );
                return Promise.resolve();
            }

            app.busy = true;
            app.clear_error();
            app.ui.render();

            var ctx = {
                tx_id: ( tx.get_state().tx && tx.get_state().tx.id ) || null,
                rolled_back: false,
            };

            return refresh_transaction()
                .then( function ( payload ) {
                    ensure_editable( payload );
                    if ( ! payload.open ) {
                        return null;
                    }
                    ctx.tx_id = payload.id;
                    return api.rollback_transaction().then( function () {
                        ctx.rolled_back = true;
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
                    forget_stale_session( error );
                    if ( ! ctx.rolled_back ) {
                        // The rollback request did not confirm. Ask whether it
                        // landed — or whether a commit took its place.
                        return reconcile( ctx.tx_id ).then( function ( outcome ) {
                            if ( outcome.state === 'open' && outcome.owned_by_session ) {
                                app.busy = false;
                                app.error = { kind: 'network', message: error_detail( error ) };
                                app.message = 'The changes could not be discarded: the transaction is still open. ' + error_detail( error );
                                app.ui.render();
                                return null;
                            }
                            if ( outcome.state === 'open' ) {
                                // It belongs to somebody else now. Their work
                                // is not ours to discard, and reporting
                                // "Discarded" would be false.
                                tx.rollback();
                                editor.clear_staged();
                                app.busy = false;
                                app.error = null;
                                app.message = 'Nothing was discarded: the transaction is no longer this editor session\'s. Reload to see the current state.';
                                app.ui.render();
                                return null;
                            }
                            if ( outcome.state === 'committed' ) {
                                tx.rollback();
                                editor.clear_staged();
                                app.busy = false;
                                app.error = null;
                                app.message = 'Discard did not happen: the transaction had already been committed. Reload to see the saved configuration.';
                                app.ui.render();
                                return null;
                            }
                            if ( outcome.state === 'unknown' ) {
                                app.busy = false;
                                app.error = { kind: 'unknown', message: error_detail( error ) };
                                app.message = 'Discard outcome could not be verified. ' + error_detail( error );
                                app.ui.render();
                                return null;
                            }
                            // rolled_back / reclaimed / stale: the transaction
                            // is gone; the local mirror can follow.
                            tx.rollback();
                            editor.clear_staged();
                            app.busy = false;
                            app.hint = 'Discarded.';
                            app.clear_error();
                            app.ui.render();
                            return null;
                        } );
                    }
                    // The rollback itself succeeded; only the refresh failed.
                    // The draft must not keep describing a transaction that no
                    // longer exists.
                    tx.rollback();
                    editor.discard();
                    app.busy = false;
                    app.hint = '';
                    app.error = null;
                    app.message = 'Changes discarded, but the editor could not refresh. Reload to see the stored configuration.';
                    app.ui.render();
                    return null;
                } );
        }

        /* ── preview ──────────────────────────────────────────────────── */

        function preview() {
            if ( app.busy ) { return Promise.resolve(); }
            if ( is_locked() ) {
                say( lock_refusal( 'preview' ), 'error' );
                return Promise.resolve();
            }

            var tx_state = tx.get_state();
            var has_work = editor.is_dirty() ||
                ( tx_state.mode === 'editing_open' && editor.has_staged() ) ||
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

            var ctx = {
                opened_here: false,
                tx_id: ( tx_state.tx && tx_state.tx.id ) || null,
                phase: 'begin',
                staged: false,
            };

            return refresh_transaction()
                .then( function ( payload ) {
                    ensure_editable( payload );
                    if ( payload.open ) {
                        ctx.tx_id = payload.id;
                        if ( payload.has_staged && ! editor.has_staged() ) {
                            var refusal = new Error( 'This transaction already holds staged changes this editor has not loaded. Reload before previewing.' );
                            refusal.code = 'staged_not_loaded';
                            throw refusal;
                        }
                        return null;
                    }
                    ctx.phase = 'begin';
                    return api.begin_transaction( 'configurator preview' ).then( function ( result ) {
                        ctx.opened_here = true;
                        ctx.tx_id = result && result.id;
                        remember_begin( result, 'configurator preview' );
                    } );
                } )
                .then( function () {
                    // Every preview stages the draft as it is now. A second
                    // edit after a preview is therefore previewed as a second
                    // PUT, not served from the first one.
                    ctx.phase = 'stage';
                    return api.put_config( editor.to_payload() ).then( function () {
                        ctx.staged = true;
                        editor.mark_staged();
                        tx.mark_staged();
                    } );
                } )
                .then( function () {
                    ctx.phase = 'preview';
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
                    return handle_preview_failure( error, ctx, preview_window );
                } );
        }

        /**
         * A failed Preview must leave an honest state, not an unexplained lock.
         *
         * Four outcomes are distinguished by asking the server:
         *   - the draft was staged and only the URL request failed: keep it;
         *   - the PUT definitely did not land and this operation opened the
         *     transaction: close it;
         *   - the PUT's outcome is unknown: close it if it is ours to close,
         *     and say that it may remain otherwise;
         *   - the transaction is no longer ours: change nothing, say so.
         */
        function handle_preview_failure( error, ctx, preview_window ) {
            app.busy = false;
            close_preview_window( preview_window );
            forget_stale_session( error );

            // The PUT landed; only the credential request failed.
            if ( ctx.staged || ctx.phase === 'preview' ) {
                app.hint = '';
                app.error = { kind: 'staged', message: error_detail( error ) };
                app.message = 'The draft is staged for preview, but the preview tab could not be opened: ' + error_detail( error );
                app.ui.render();
                return Promise.resolve( null );
            }

            if ( ctx.phase !== 'stage' ) {
                // The transaction could not be opened (or a local refusal).
                // There is no id to reconcile; if a begin actually landed,
                // its capability was lost with the response and the editor
                // will offer explicit recovery on the next read.
                app.hint = '';
                app.error = { kind: is_validation_error( error ) ? 'validation' : 'network', message: error_detail( error ) };
                app.message = 'The preview could not be opened: ' + error_detail( error )
                    + ( ctx.opened_here ? ' A transaction may have been opened; reload to recover it if so.' : '' );
                app.ui.render();
                return Promise.resolve( null );
            }

            return reconcile( ctx.tx_id ).then( function ( outcome ) {
                var open_and_ours = outcome.state === 'open' && outcome.owned_by_session;

                if ( is_validation_error( error ) ) {
                    if ( open_and_ours && ctx.opened_here && ! outcome.has_staged ) {
                        return rollback_own_transaction( ctx ).then( function () {
                            app.hint = '';
                            app.error = { kind: 'validation', message: error_detail( error ) };
                            app.message = 'Validation failed; changes remain editable. ' + error_detail( error );
                            app.ui.render();
                            return null;
                        } );
                    }
                    app.hint = '';
                    app.error = { kind: 'validation', message: error_detail( error ) };
                    app.message = 'Validation failed; changes remain editable. ' + error_detail( error );
                    app.ui.render();
                    return null;
                }

                if ( open_and_ours && ctx.opened_here ) {
                    if ( outcome.has_staged ) {
                        editor.mark_staged();
                        tx.mark_staged();
                        app.hint = '';
                        app.error = { kind: 'staged', message: error_detail( error ) };
                        app.message = 'The draft is staged for preview, but the preview tab could not be opened: ' + error_detail( error );
                        app.ui.render();
                        return null;
                    }
                    return rollback_own_transaction( ctx ).then( function ( rolled_back ) {
                        app.hint = '';
                        app.error = { kind: 'network', message: error_detail( error ) };
                        app.message = 'The preview could not be opened: ' + error_detail( error )
                            + ( rolled_back ? ' The empty transaction was closed.' : ' The empty transaction could not be closed; it will expire.' );
                        app.ui.render();
                        return null;
                    } );
                }
                if ( open_and_ours ) {
                    app.hint = '';
                    app.error = { kind: 'unknown', message: error_detail( error ) };
                    app.message = 'The preview could not be verified. The transaction is still open'
                        + ( outcome.has_staged ? ' and holds staged changes' : '' )
                        + ', but this preview\'s staging could not be confirmed. ' + error_detail( error );
                    app.ui.render();
                    return null;
                }
                if ( outcome.state === 'open' ) {
                    app.hint = '';
                    app.error = { kind: 'validation', message: error_detail( error ) };
                    app.message = 'The preview could not be opened: this editor session no longer owns the transaction. ' + error_detail( error );
                    app.ui.render();
                    return null;
                }
                if ( outcome.state === 'unknown' && ctx.opened_here ) {
                    return rollback_own_transaction( ctx ).then( function ( rolled_back ) {
                        app.hint = '';
                        app.error = { kind: 'network', message: error_detail( error ) };
                        app.message = 'The preview could not be verified. ' + error_detail( error )
                            + ( rolled_back
                                ? ' The transaction this preview opened was discarded; the draft is kept.'
                                : ' A transaction may remain open until it expires; reload before trying again.' );
                        app.ui.render();
                        return null;
                    } );
                }
                tx.rollback();
                editor.clear_staged();
                app.hint = '';
                app.error = { kind: 'validation', message: error_detail( error ) };
                app.message = 'The preview could not be opened: the transaction is no longer open. ' + error_detail( error );
                app.ui.render();
                return null;
            } );
        }

        /* ── recovering another session's transaction ─────────────────── */

        /**
         * Explicitly recover a transaction this browser session cannot prove
         * it owns.
         *
         * This is never automatic. It is offered only when the server says the
         * same account opened the row, the row holds no durable content work,
         * and this session does not already own it — and it always asks first.
         * The server rotates the session capability, so the previous session's
         * token stops working, and it audits the act with the staged state it
         * inherited.
         */
        function take_over() {
            if ( app.busy ) { return Promise.resolve(); }
            var tx_state = tx.get_state();
            if ( ! tx_state.canTakeOver ) {
                say( 'There is no transaction this editor can recover.', 'error' );
                return Promise.resolve();
            }
            var who = tx_state.tx && tx_state.tx.actor ? tx_state.tx.actor : 'this account';
            var prompt = 'Recover the transaction opened by ' + who + '?'
                + ( tx_state.tx && tx_state.tx.has_staged
                    ? ' It holds staged changes that another editor session has not saved or discarded.'
                    : '' )
                + ' The other session will lose access immediately.';
            if ( typeof window !== 'undefined' && typeof window.confirm === 'function' && ! window.confirm( prompt ) ) {
                return Promise.resolve();
            }

            app.busy = true;
            app.clear_error();
            app.ui.render();
            return api.recover_transaction( 'configurator recovery' )
                .then( function ( result ) {
                    if ( result && result.editor_session && session ) {
                        session.set( result.editor_session );
                    }
                    return load();
                } )
                .then( function () {
                    app.busy = false;
                    app.hint = 'Recovered the staged transaction.';
                    app.clear_error();
                    app.ui.render();
                } )
                .catch( function ( error ) {
                    app.busy = false;
                    fail( error, 'The transaction could not be recovered: ' );
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
        app.take_over = take_over;
        app.session = session;
        // Exposed for the same reason as the app handle itself: the browser
        // acceptance test must be able to drop one response to reproduce a
        // network failure without a second transport. It is not an
        // authorisation boundary — the server enforces every operation.
        app.api = api;
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
        var storage = options.storage || null;
        if ( ! storage && typeof window !== 'undefined' ) {
            try { storage = window.sessionStorage; } catch ( e ) { storage = null; }
        }
        var session = options.session_model || state.make_session_model( storage );
        var api = options.api_client || apiModule.make_api( {
            restUrl: options.restUrl || '/',
            nonce: options.nonce || '',
            session: session,
        } );

        var app = make_app( {
            api: api,
            editor: editor,
            tx: tx,
            zone: zone,
            session: session,
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
