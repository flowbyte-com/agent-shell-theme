/**
 * Pure state models for the AgentShell configurator.
 *
 * Loaded in three contexts:
 *   1. The browser (via the configurator's app module, which wires the
 *      models to fetch calls and DOM events).
 *   2. Node-based tests via tests/configurator-state-test.js and
 *      tests/configurator-editor-test.js, which exercise the transitions in
 *      isolation.
 *   3. Nothing else. These modules touch no DOM, no fetch, and no window
 *      globals beyond being handed their dependencies.
 *
 * Three models live here, and they are three different kinds of state:
 *
 *   - `make_state_model()` — the *transaction* state: idle, editing, or
 *     locked by another actor. It mirrors the server's transaction lifecycle.
 *   - `make_zone_model()` — the *slot grid* rules: which area to open on,
 *     what a move does, when a zone renders as nine areas, and which
 *     submenu is open.
 *   - `make_editor_model()` — the *editor* state: which context is open,
 *     which zone and area and block are selected, and whether the draft
 *     differs from what the server last reported. This is the model the
 *     whole interface is a view of.
 *
 * The models do not touch the DOM, fetch, or window globals — every
 * dependency is injected. That keeps the JS test surface small and
 * deterministic.
 *
 * State transitions (transaction):
 *
 *   idle             ── begin() ──▶ editing_open   (we own the tx)
 *   idle             ── (foreign tx detected) ──▶ locked
 *   editing_open     ── commit() ──▶ idle
 *   editing_open     ── rollback() ─▶ idle
 *   locked           ── (foreign tx released) ──▶ idle
 *
 * The save flow:
 *
 *   save(config):
 *     1. If state is locked, return { ok: false, reason: 'locked' }.
 *     2. If state is idle, begin a tx and transition to editing_open.
 *     3. PUT the config (caller does this; staged via existing hook).
 *     4. commit() the tx and transition to idle.
 */
(function ( root ) {
    'use strict';

    function make_state_model( initial ) {
        var state = initial || {
            mode: 'idle',           // 'idle' | 'editing_open' | 'locked'
            tx: null,               // { id, actor, label, has_staged }
            ownedByUs: true,
        };

        function get_state() { return state; }

        function set_tx_snapshot( txPayload ) {
            // Called on init / refresh from GET /wp/v2/agentshell/transaction.
            if ( ! txPayload || ! txPayload.open ) {
                state = { mode: 'idle', tx: null, ownedByUs: true };
                return state;
            }
            if ( txPayload.owned_by_us ) {
                state = {
                    mode: 'editing_open',
                    tx: {
                        id: txPayload.id,
                        actor: txPayload.actor,
                        label: txPayload.label,
                        has_staged: !!txPayload.has_staged,
                    },
                    ownedByUs: true,
                };
            } else {
                state = {
                    mode: 'locked',
                    tx: {
                        id: txPayload.id,
                        actor: txPayload.actor,
                        label: txPayload.label,
                        has_staged: !!txPayload.has_staged,
                    },
                    ownedByUs: false,
                };
            }
            return state;
        }

        /**
         * Record that this transaction has accepted staged work.
         *
         * The server is authoritative; this only keeps the local mirror from
         * reporting an open transaction with nothing in it between the PUT
         * and the next GET.
         */
        function mark_staged() {
            if ( state.mode === 'editing_open' && state.tx ) {
                state.tx.has_staged = true;
            }
            return state;
        }

        function begin( label ) {
            // Caller must perform the network call and pass the result.
            // This just transitions the local state.
            if ( state.mode === 'locked' ) {
                return { ok: false, reason: 'locked', state: state };
            }
            state = {
                mode: 'editing_open',
                tx: {
                    id: 'pending',
                    actor: 'self',
                    label: label || '',
                    has_staged: false,
                },
                ownedByUs: true,
            };
            return { ok: true, state: state };
        }

        function commit( txId ) {
            if ( state.mode !== 'editing_open' ) {
                return { ok: false, reason: 'not_editing', state: state };
            }
            state = { mode: 'idle', tx: null, ownedByUs: true };
            return { ok: true, committed: txId || null, state: state };
        }

        function rollback() {
            if ( state.mode === 'locked' ) {
                // Foreign tx — cannot rollback from the configurator.
                return { ok: false, reason: 'locked', state: state };
            }
            state = { mode: 'idle', tx: null, ownedByUs: true };
            return { ok: true, state: state };
        }

        function can_save() {
            return state.mode !== 'locked';
        }

        /**
         * Is a transaction open at all — ours or somebody else's?
         *
         * The interface asks this before offering a destructive action
         * (restoring a snapshot writes the live configuration, and a
         * transaction that is open is a staged state that would be
         * overwritten).
         */
        /**
         * May this session roll a transaction back?
         *
         * Discarding rolls the transaction back. A transaction somebody else
         * holds is not ours to roll back: the button is refused here, and
         * the app refuses again before it sends the request, so the rule
         * holds even if the button is re-enabled from a script.
         */
        function can_discard() {
            return state.mode !== 'locked';
        }

        function is_active() {
            return state.mode === 'editing_open' || state.mode === 'locked';
        }

        function status_label() {
            if ( state.mode === 'idle' ) { return 'Idle'; }
            if ( state.mode === 'editing_open' ) {
                var staged = state.tx && state.tx.has_staged ? ' (staged)' : '';
                return 'Editing' + staged;
            }
            if ( state.mode === 'locked' ) {
                return 'Locked by ' + ( state.tx && state.tx.actor ? state.tx.actor : 'another actor' );
            }
            return '';
        }

        return {
            get_state: get_state,
            set_tx_snapshot: set_tx_snapshot,
            begin: begin,
            mark_staged: mark_staged,
            commit: commit,
            rollback: rollback,
            can_save: can_save,
            can_discard: can_discard,
            is_active: is_active,
            status_label: status_label,
        };
    }

    /**
     * Pure nine-area zone model.
     *
     * The configurator and the Node tests share these rules so there is one
     * definition of "which area is open", "what a move does" and "when is a
     * zone expanded". None of it touches the DOM or the network.
     *
     * The canonical area list is *not* here: the server owns it and sends it
     * in the config response (`zone_areas`). These helpers take it as an
     * argument, which is why they cannot drift from the stored schema.
     */
    function make_zone_model() {
        function ids( areas ) {
            return ( areas || [] ).map( function ( area ) { return area.id; } );
        }

        /**
         * The area the editor should open on: the current selection when it
         * still exists, else the preferred one, else the middle-centre area,
         * else the first area the server offered. Never an id the server did
         * not offer — an unknown selection is how a panel ends up rendering
         * an empty editor for an area that does not exist.
         */
        function resolve_area( areas, current, preferred ) {
            var known = ids( areas );
            if ( current && known.indexOf( current ) !== -1 ) { return current; }
            if ( preferred && known.indexOf( preferred ) !== -1 ) { return preferred; }
            if ( known.indexOf( 'middle-center' ) !== -1 ) { return 'middle-center'; }
            return known.length ? known[ 0 ] : '';
        }

        /**
         * Move one block between areas and return the new slots. The input is
         * not mutated: a re-render must never depend on whether the model ran.
         *
         * A move onto the same area, or from an index that does not exist, is
         * a no-op. The destination is taken as given — the caller offers only
         * the areas the server listed, and the server validates the resulting
         * configuration on save. This model's job is the move, not the
         * authority on what a valid area is.
         */
        function move_block( slots, from, to, index ) {
            var next = {};
            Object.keys( slots || {} ).forEach( function ( key ) {
                next[ key ] = ( slots[ key ] || [] ).slice();
            } );
            if ( ! from || ! to || from === to ) { return next; }
            var source = next[ from ] || [];
            if ( index < 0 || index >= source.length ) { return next; }
            if ( ! next[ to ] ) { next[ to ] = []; }
            var block = source.splice( index, 1 )[ 0 ];
            next[ to ].push( block );
            return next;
        }

        /**
         * 'expanded' when an area outside the middle row holds a block.
         * Mirrors agentshell_zone_is_expanded() in functions.php; the PHP is
         * the authority for rendering, and this is the panel's label for it.
         */
        function layout( areas, slots ) {
            var by_id = {};
            ( areas || [] ).forEach( function ( area ) { by_id[ area.id ] = area; } );
            var keys = Object.keys( slots || {} );
            for ( var i = 0; i < keys.length; i++ ) {
                var id  = keys[ i ];
                var row = by_id[ id ] ? by_id[ id ].row : '';
                if ( row !== 'middle' && ( slots[ id ] || [] ).length > 0 ) {
                    return 'expanded';
                }
            }
            return 'standard';
        }

        /* Is `candidate` somewhere below `ancestor`? */
        function is_descendant( parent_of, candidate, ancestor ) {
            var node = parent_of[ candidate ] || null;
            var guard = 0;
            while ( node && guard < 100 ) {
                if ( node === ancestor ) {
                    return true;
                }
                node = parent_of[ node ] || null;
                guard += 1;
            }
            return false;
        }

        /* Ancestors of `id`, outermost first. */
        function ancestors_of( parent_of, id ) {
            var out = [];
            var node = parent_of[ id ] || null;
            var guard = 0;
            while ( node && guard < 100 ) {
                out.unshift( node );
                node = parent_of[ node ] || null;
                guard += 1;
            }
            return out;
        }

        /**
         * The open set after toggling `id`.
         *
         * `parent_of` maps every item id to its parent item id (or null at the
         * top level). Opening an item keeps its ancestors and closes
         * everything else. Closing an item closes its descendants too: their
         * buttons live inside the list that just disappeared, and a hidden
         * `aria-expanded="true"` is a lie a screen reader will repeat.
         */
        function next_open( open, parent_of, id, is_open ) {
            open = open || [];
            parent_of = parent_of || {};

            if ( is_open ) {
                return open.filter( function ( other ) {
                    return other !== id && ! is_descendant( parent_of, other, id );
                } );
            }

            var kept = ancestors_of( parent_of, id ).filter( function ( ancestor ) {
                return open.indexOf( ancestor ) !== -1;
            } );
            kept.push( id );
            return kept;
        }

        function escape_target( open ) {
            if ( ! open || ! open.length ) {
                return null;
            }
            return open[ open.length - 1 ];
        }

        function is_open( open, id ) {
            return ( open || [] ).indexOf( id ) !== -1;
        }

        return {
            resolve_area: resolve_area,
            move_block: move_block,
            layout: layout,
        };
    }

    /* ── The editor model ───────────────────────────────────────────────────
     *
     * The interface is a view of this model, and nothing else holds editing
     * state. Two drafts are kept: the one being edited, and the one the
     * server last reported. "Dirty" is the difference between them, computed
     * from the data rather than tracked by a flag — a flag can be wrong, and a
     * wrong dirty flag is how an operator loses work.
     */
    function make_editor_model() {
        var state = {
            context: 'layout',        // layout | appearance | history | advanced
            zone: 'header',           // the zone open in the layout context
            area: 'middle-center',    // the area open in that zone
            block: -1,                // index of the selected block, -1 = none
            draft: null,              // { vars, custom_css, custom_js, zones }
            saved: null,              // the same shape, as the server last reported it
            staged: null,             // the draft last accepted into the open transaction
        };

        function clone( value ) {
            return value === undefined ? value : JSON.parse( JSON.stringify( value ) );
        }

        function get_state() { return state; }

        function set_context( context ) {
            state.context = context;
            // Leaving the layout context drops a selection that only means
            // something there.
            if ( context !== 'layout' ) {
                state.block = -1;
            }
            return state;
        }

        function set_zone( zone ) {
            state.zone = zone;
            state.area = 'middle-center';
            state.block = -1;
            return state;
        }

        function set_area( area ) {
            state.area = area;
            state.block = -1;
            return state;
        }

        function set_block( index ) {
            state.block = ( typeof index === 'number' && index >= 0 ) ? index : -1;
            return state;
        }

        /**
         * Load a configuration response into the draft.
         *
         * `stagedPayload` is the owner's staged configuration, in the same
         * shape as a GET, when a transaction survived a reload. In that case
         * the draft is the staged work (what the operator was previewing) and
         * `saved` remains the committed state, so "Staged for preview" and
         * "Changes since preview" are both true statements after a reload.
         */
        function load( payload, stagedPayload ) {
            state.saved = draft_from( payload );
            if ( stagedPayload ) {
                state.draft = draft_from( stagedPayload );
                state.staged = clone( state.draft );
            } else {
                state.draft = clone( state.saved );
                state.staged = null;
            }
            return state;
        }

        /** Replace the draft with the server's current answer (after a save). */
        function accept_saved( payload ) {
            state.draft = draft_from( payload );
            state.saved = clone( state.draft );
            state.staged = null;
            return state;
        }

        /** Throw the draft away and go back to what the server last reported. */
        function discard() {
            state.draft = clone( state.saved );
            state.staged = null;
            return state;
        }

        /** The draft the open transaction last accepted. */
        function mark_staged() {
            state.staged = clone( state.draft );
            return state.staged;
        }

        function clear_staged() {
            state.staged = null;
        }

        function has_staged() {
            return state.staged !== null;
        }

        /**
         * Has the draft moved since it was last staged for preview?
         *
         * With no staged snapshot this is true: an open transaction whose
         * staged work this session never saw is not this draft, so the draft
         * must be staged before it can be previewed or saved.
         */
        function is_modified_since_staged() {
            if ( ! state.staged ) {
                return true;
            }
            return JSON.stringify( state.draft ) !== JSON.stringify( state.staged );
        }

        function draft_from( payload ) {
            payload = payload || {};
            var flat = payload.config || payload.defaults || {};
            var vars = {};
            Object.keys( flat ).forEach( function ( key ) {
                if ( key.indexOf( '--' ) === 0 && typeof flat[ key ] === 'string' ) {
                    vars[ key ] = flat[ key ];
                }
            } );
            return {
                vars: vars,
                custom_css: payload.custom_css || '',
                custom_js: payload.custom_js || '',
                zones: clone( payload.zones || [] ),
            };
        }

        function is_dirty() {
            if ( ! state.draft || ! state.saved ) {
                return false;
            }
            return JSON.stringify( state.draft ) !== JSON.stringify( state.saved );
        }

        function zone( id ) {
            var zones = ( state.draft && state.draft.zones ) || [];
            for ( var i = 0; i < zones.length; i++ ) {
                if ( zones[ i ] && zones[ i ].id === id ) {
                    return zones[ i ];
                }
            }
            return null;
        }

        function blocks_of( zone_id, area_id ) {
            var z = zone( zone_id );
            if ( ! z ) {
                return [];
            }
            if ( area_id ) {
                return ( z.slots && z.slots[ area_id ] ) || [];
            }
            return z.composition || [];
        }

        function set_token( name, value ) {
            if ( ! state.draft ) { return null; }
            state.draft.vars[ name ] = value;
            return state.draft.vars[ name ];
        }

        function set_custom_css( value ) {
            if ( ! state.draft ) { return null; }
            state.draft.custom_css = value;
            return state.draft.custom_css;
        }

        function set_custom_js( value ) {
            if ( ! state.draft ) { return null; }
            state.draft.custom_js = value;
            return state.draft.custom_js;
        }

        /**
         * The payload the server expects: the flat design variables, the two
         * custom assets, and the zones. The same shape the panel has always
         * sent, so a redesigned interface cannot change what is stored.
         */
        function to_payload() {
            if ( ! state.draft ) { return null; }
            var payload = {};
            Object.keys( state.draft.vars ).forEach( function ( key ) {
                payload[ key ] = state.draft.vars[ key ];
            } );
            payload.custom_css = state.draft.custom_css;
            payload.custom_js = state.draft.custom_js;
            payload.zones = state.draft.zones;
            return payload;
        }

        /* ── zone editing ───────────────────────────────────────────────── */

        function area_list( z ) {
            if ( ! z || ! z.slots ) {
                return [];
            }
            return Object.keys( z.slots );
        }

        function add_block( zone_id, area_id, block ) {
            var z = zone( zone_id );
            if ( ! z ) { return false; }
            if ( area_id ) {
                if ( ! z.slots ) { z.slots = {}; }
                if ( ! Array.isArray( z.slots[ area_id ] ) ) { z.slots[ area_id ] = []; }
                z.slots[ area_id ].push( clone( block ) );
            } else {
                if ( ! Array.isArray( z.composition ) ) { z.composition = []; }
                z.composition.push( clone( block ) );
            }
            return true;
        }

        function remove_block( zone_id, area_id, index ) {
            var list = blocks_of( zone_id, area_id );
            if ( index < 0 || index >= list.length ) { return false; }
            list.splice( index, 1 );
            state.block = -1;
            return true;
        }

        /** Move a block inside its area: delta -1 (up) or +1 (down). */
        function reorder_block( zone_id, area_id, index, delta ) {
            var list = blocks_of( zone_id, area_id );
            if ( index < 0 || index >= list.length ) { return false; }
            var target = index + delta;
            if ( target < 0 || target >= list.length ) { return false; }
            var moved = list.splice( index, 1 )[ 0 ];
            list.splice( target, 0, moved );
            state.block = target;
            return true;
        }

        /** Move a block to another area of the same zone. */
        function move_block( zone_id, from_area, to_area, index ) {
            if ( ! from_area || ! to_area || from_area === to_area ) { return false; }
            var z = zone( zone_id );
            if ( ! z || ! z.slots ) { return false; }
            var list = blocks_of( zone_id, from_area );
            if ( index < 0 || index >= list.length ) { return false; }
            var moved = list.splice( index, 1 )[ 0 ];
            if ( ! Array.isArray( z.slots[ to_area ] ) ) { z.slots[ to_area ] = []; }
            z.slots[ to_area ].push( moved );
            state.block = z.slots[ to_area ].length - 1;
            return true;
        }

        /** Move a block into a different zone's area. */
        function move_block_to_zone( from_zone, from_area, to_zone, to_area, index ) {
            if ( ! from_area || ! to_area || from_zone === to_zone ) { return false; }
            var list = blocks_of( from_zone, from_area );
            if ( index < 0 || index >= list.length ) { return false; }
            var moved = list.splice( index, 1 )[ 0 ];
            var target = zone( to_zone );
            if ( ! target ) { return false; }
            if ( ! target.slots ) { target.slots = {}; }
            if ( ! Array.isArray( target.slots[ to_area ] ) ) { target.slots[ to_area ] = []; }
            target.slots[ to_area ].push( moved );
            state.block = target.slots[ to_area ].length - 1;
            return true;
        }

        function set_block_field( zone_id, area_id, index, field, value ) {
            var list = blocks_of( zone_id, area_id );
            if ( index < 0 || index >= list.length ) { return false; }
            if ( value === '' || value === null || value === undefined ) {
                delete list[ index ][ field ];
            } else {
                list[ index ][ field ] = value;
            }
            return true;
        }

        /**
         * The status the action bar shows.
         *
         * A staged draft is never described as Saved. The four states that
         * matter to an operator are distinguished from the data: committed,
         * edited locally, staged for preview, and edited again since that
         * preview. "Locked" is the server's answer about somebody else's
         * transaction, not a local guess.
         */
        function status( tx_model ) {
            var tx_state = tx_model ? tx_model.get_state() : { mode: 'idle' };
            if ( 'locked' === tx_state.mode ) {
                return {
                    key: 'locked',
                    label: 'Locked by ' + ( tx_state.tx && tx_state.tx.actor ? tx_state.tx.actor : 'another actor' ),
                    can_save: false,
                };
            }

            var open = tx_state.mode === 'editing_open';
            var dirty = is_dirty();

            if ( open && state.staged ) {
                if ( is_modified_since_staged() ) {
                    return { key: 'staged_dirty', label: 'Changes since preview', can_save: true };
                }
                return { key: 'staged', label: 'Staged for preview', can_save: true };
            }

            if ( open && tx_state.tx && tx_state.tx.has_staged ) {
                // The server says the transaction holds staged work, but this
                // session has not seen it (it belongs to another editor
                // session of the same account). Do not call it this draft's
                // preview and do not silently overwrite it.
                return { key: 'staged_foreign_session', label: 'Staged in another session', can_save: true };
            }

            if ( dirty ) {
                return { key: 'dirty', label: 'Unsaved changes', can_save: true };
            }

            if ( open ) {
                return { key: 'transaction', label: 'Transaction open', can_save: true };
            }

            return { key: 'saved', label: 'Saved', can_save: true };
        }

        return {
            get_state: get_state,
            set_context: set_context,
            set_zone: set_zone,
            set_area: set_area,
            set_block: set_block,
            load: load,
            accept_saved: accept_saved,
            discard: discard,
            mark_staged: mark_staged,
            clear_staged: clear_staged,
            has_staged: has_staged,
            is_modified_since_staged: is_modified_since_staged,
            is_dirty: is_dirty,
            zone: zone,
            area_list: area_list,
            blocks_of: blocks_of,
            set_token: set_token,
            set_custom_css: set_custom_css,
            set_custom_js: set_custom_js,
            to_payload: to_payload,
            add_block: add_block,
            remove_block: remove_block,
            reorder_block: reorder_block,
            move_block: move_block,
            move_block_to_zone: move_block_to_zone,
            set_block_field: set_block_field,
            status: status,
        };
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            make_state_model: make_state_model,
            make_zone_model: make_zone_model,
            make_editor_model: make_editor_model,
        };
    } else {
        root.AgentshellConfiguratorState = {
            make_state_model: make_state_model,
            make_zone_model: make_zone_model,
            make_editor_model: make_editor_model,
        };
    }
})( typeof window !== 'undefined' ? window : globalThis );