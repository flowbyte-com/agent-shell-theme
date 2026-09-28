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

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_state_model: make_state_model };
    } else {
        root.AgentshellConfiguratorState = { make_state_model: make_state_model };
    }
})( typeof window !== 'undefined' ? window : globalThis );