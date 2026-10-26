/**
 * Pure state model for the AgentShell configurator.
 *
 * Loaded in two contexts:
 *   1. The browser (via the main configurator.js, which wires the model
 *      to DOM events and fetch calls).
 *   2. Node-based tests via tests/configurator-state-test.js, which
 *      exercise the state transitions in isolation.
 *
 * The model does not touch the DOM, fetch, or window globals — every
 * dependency is injected. That keeps the JS test surface small and
 * deterministic.
 *
 * State transitions:
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
            commit: commit,
            rollback: rollback,
            can_save: can_save,
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

        return {
            resolve_area: resolve_area,
            move_block: move_block,
            layout: layout,
        };
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_state_model: make_state_model, make_zone_model: make_zone_model };
    } else {
        root.AgentshellConfiguratorState = {
            make_state_model: make_state_model,
            make_zone_model: make_zone_model,
        };
    }
})( typeof window !== 'undefined' ? window : globalThis );