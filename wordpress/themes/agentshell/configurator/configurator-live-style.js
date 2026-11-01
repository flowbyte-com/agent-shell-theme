/**
 * The live-style layer: a browser-local overlay of the draft's design tokens.
 *
 * The original configurator applied a design token to the page the moment it
 * was changed, and the administrator saw the colour move while deciding
 * whether to keep it. The redesigned editor made every visual change wait
 * for Preview, which is safer and much less pleasant. This module restores
 * the immediacy without giving up the safety: it writes CSS custom
 * properties on the page's root element, in the browser that is showing the
 * editor, and writes nothing anywhere else.
 *
 * ## What it is, and what it is not
 *
 * It is an overlay. It does not open a transaction, does not send a REST
 * mutation, does not write a WordPress option, does not affect another
 * visitor or another tab, and does not execute draft JavaScript. The
 * configuration remains where it was: in the editor's draft model, and in
 * the server's configuration only once the operator saves it.
 *
 * ## The rule that decides what is shown
 *
 * A custom property is only worth writing if the theme's stylesheet reads
 * it. The browser accepts any value for any custom property — including
 * nonsense — and quietly ignores the ones nothing reads. A field that
 * "works" in the editor and changes nothing on the page is worse than a
 * field that says it cannot be previewed live, so the vocabulary comes from
 * the server (`live_tokens`: the properties the theme's own stylesheet
 * consumes) and the value is checked with the browser's own CSS parser.
 *
 * ## The two baselines
 *
 * The controller keeps two sets of values, and the difference between them
 * is the whole design:
 *
 *   - `stylesheet` — what the page's own CSS provides. Captured once, when
 *     the editor loads. It does not change while the page is open, even
 *     after a save: the stylesheet in the page is still the one that was
 *     served.
 *   - `desired` — what should be on screen right now. Normally the draft;
 *     after a confirmed save, the configuration the server has committed.
 *
 * A property is written only when `desired` differs from `stylesheet`.
 * That single comparison produces every behaviour the editor needs:
 *
 *   - a fresh editor, draft == committed == stylesheet: nothing is written,
 *     so the page looks exactly like the committed site;
 *   - an edit: the changed properties are written, so the page follows the
 *     draft;
 *   - a discard: `desired` returns to the committed configuration, and the
 *     properties that differ from the stylesheet keep their override while
 *     the rest are removed — the page returns to the committed appearance;
 *   - a save: the committed configuration changes but the stylesheet in the
 *     page does not, so the properties that differ from the stylesheet keep
 *     an override that now holds the *committed* value. The page therefore
 *     shows the saved design, and the override is not obsolete: it is the
 *     only thing holding the new value in a page whose stylesheet is old.
 *     A reload makes it unnecessary, and the next load writes nothing.
 *
 * ## Restoration
 *
 * The original inline state of every property the controller touches is
 * captured before the first write: whether the property was declared at all,
 * its value, and its priority. A property that was declared with an empty
 * value is not the same as a property that was absent, and the difference is
 * kept. Restoring puts back exactly what was there, and touches nothing
 * else on the element.
 */
(function ( root ) {
    'use strict';

    /**
     * A property's state before the controller touched it.
     *
     * `present` is true when the property was declared in the element's
     * inline style — including when it was declared with an empty value,
     * which `getPropertyValue()` cannot distinguish from absence.
     */
    function capture_property( element, name ) {
        var declared = false;
        var text = element.getAttribute ? ( element.getAttribute( 'style' ) || '' ) : '';
        if ( text ) {
            // The inline style attribute is a declaration list; a property
            // name is matched at the start of a declaration.
            var pattern = new RegExp( '(^|;)\\s*' + name.replace( /[-]/g, '\\-' ) + '\\s*:' );
            declared = pattern.test( text );
        }
        return {
            present: declared,
            value: element.style.getPropertyValue( name ),
            priority: element.style.getPropertyPriority( name ),
        };
    }

    /**
     * Is this value something the browser can actually use?
     *
     * The check is the browser's own parser: the value is handed to a real
     * CSS property on a detached element and read back. A value the browser
     * refuses is refused here too, which is what keeps a half-typed colour
     * or a malformed length from replacing a good one on the page. The
     * operator's text is never changed — this only decides whether the page
     * follows it.
     *
     * @param {Element} probe A detached element used as a parser.
     * @param {string}  property The CSS property to test against.
     * @param {string}  value
     * @return {boolean}
     */
    function accepts( probe, property, value ) {
        if ( ! probe || ! property ) { return false; }
        var text = String( value );
        if ( '' === text ) { return false; }
        try {
            probe.style[ property ] = '';
            probe.style[ property ] = text;
            return '' !== probe.style[ property ];
        } catch ( e ) {
            return false;
        }
    }

    function make_live_style_controller( options ) {
        options = options || {};

        var root = options.root || ( typeof document !== 'undefined' ? document.documentElement : null );
        // The element the browser's own parser is asked about a value. It can
        // be injected, which is what lets the controller's bookkeeping be
        // tested without a browser; the values it accepts are then the
        // browser's verdict, not a second set of rules.
        var probe = options.probe || null;
        var tokens = options.tokens || {};
        var allowed = null;          // null until the server's list arrives.
        var stylesheet = {};         // what the page's own CSS provides.
        var desired = {};            // what should be on screen now.
        var applied = {};            // what the controller has written.
        var captured = {};           // the original inline state, per property.
        var elementText = null;      // the element's original style attribute.
        var elementHadAttribute = false;
        var frame = null;
        var timer = null;

        function ensure_probe() {
            if ( probe || typeof document === 'undefined' ) { return probe; }
            probe = document.createElement( 'div' );
            probe.style.position = 'absolute';
            probe.style.left = '-9999px';
            probe.style.top = '0';
            probe.style.width = '1px';
            probe.style.height = '1px';
            probe.style.overflow = 'hidden';
            return probe;
        }

        /**
         * The vocabulary: the theme's own list of consumed properties.
         *
         * Until the server has said which properties the theme reads, the
         * controller writes nothing. A guess would be a field that looks
         * like it works.
         */
        function set_vocabulary( names ) {
            var next = {};
            if ( Array.isArray( names ) ) {
                names.forEach( function ( name ) {
                    var key = String( name || '' ).toLowerCase();
                    if ( key.indexOf( '--' ) === 0 && tokens[ key ] ) { next[ key ] = true; }
                } );
            }
            allowed = next;
            schedule();
        }

        /**
         * The values the page's own stylesheet provides.
         *
         * Captured once, when the editor loads. This is the comparison the
         * controller makes against, and it deliberately does not change when
         * the configuration is saved: the stylesheet in an open page is the
         * one the server sent, and pretending otherwise is how a saved
         * change appears to be undone.
         */
        function set_stylesheet( values ) {
            stylesheet = {};
            Object.keys( values || {} ).forEach( function ( name ) {
                stylesheet[ name ] = String( values[ name ] );
            } );
            schedule();
        }

        /** What should be on screen right now: the draft, or the committed configuration. */
        function set_desired( values ) {
            desired = {};
            Object.keys( values || {} ).forEach( function ( name ) {
                desired[ name ] = String( values[ name ] );
            } );
            schedule();
        }

        function is_live( name ) {
            if ( ! allowed ) { return false; }
            if ( ! Object.prototype.hasOwnProperty.call( allowed, name ) ) { return false; }
            return true;
        }

        function value_for( name ) {
            if ( ! Object.prototype.hasOwnProperty.call( desired, name ) ) { return ''; }
            return String( desired[ name ] );
        }

        /**
         * What should happen to this property.
         *
         *   write  — put this value on the page.
         *   clear  — take the override back; the stylesheet's value applies.
         *   keep   — leave the page exactly as it is.
         *
         * The `keep` case is the one that matters while an operator is
         * typing: a half-typed colour is not a value the browser can use, so
         * the page keeps showing the last good one while the field keeps the
         * text the operator is typing. Removing the override instead would
         * make the page jump back to the committed design mid-edit, which is
         * the opposite of the feedback the layer exists to give.
         */
        function target_value( name ) {
            var value = value_for( name );
            if ( '' === value ) { return { action: 'clear' }; }
            if ( value === stylesheet[ name ] ) { return { action: 'clear' }; }
            var meta = tokens[ name ] || {};
            if ( ! meta.probe ) { return { action: 'keep' }; }
            if ( ! accepts( ensure_probe(), meta.probe, value ) ) { return { action: 'keep' }; }
            return { action: 'write', value: value };
        }

        function capture( name ) {
            if ( ! root ) { return; }
            if ( ! captured[ name ] ) {
                captured[ name ] = capture_property( root, name );
            }
        }

        function write( name, value ) {
            if ( ! root ) { return; }
            if ( '' === value ) {
                if ( applied[ name ] ) {
                    restore_property( name );
                }
                return;
            }
            if ( applied[ name ] === value ) { return; }
            capture( name );
            try {
                root.style.setProperty( name, value );
            } catch ( e ) {
                // A property the browser refuses is not written; the page
                // keeps the value it had.
            }
            applied[ name ] = value;
        }

        function restore_property( name ) {
            if ( ! root ) { return; }
            var original = captured[ name ];
            if ( ! original ) {
                try { root.style.removeProperty( name ); } catch ( e ) {}
                delete applied[ name ];
                return;
            }
            if ( original.present && '' === original.value ) {
                // The property was declared with an empty value. Writing an
                // empty value through the CSSOM removes it, so the element's
                // style attribute is put back exactly as it was.
                if ( elementText !== null ) {
                    if ( elementHadAttribute ) {
                        root.setAttribute( 'style', elementText );
                    } else {
                        root.removeAttribute( 'style' );
                    }
                }
            } else if ( original.present ) {
                try {
                    root.style.setProperty( name, original.value, original.priority );
                } catch ( e ) {}
            } else {
                try { root.style.removeProperty( name ); } catch ( e ) {}
            }
            delete applied[ name ];
        }

        /** Apply the desired values to the page. */
        function flush() {
            frame = null;
            if ( timer ) { clearTimeout( timer ); timer = null; }
            if ( ! root || ! allowed ) { return; }

            Object.keys( tokens ).forEach( function ( name ) {
                if ( ! is_live( name ) ) { return; }
                var target = target_value( name );
                if ( 'keep' === target.action ) { return; }
                write( name, 'write' === target.action ? target.value : '' );
            } );

            // A property that is no longer wanted must be taken back, even
            // if the vocabulary no longer mentions it.
            Object.keys( applied ).forEach( function ( name ) {
                if ( ! is_live( name ) ) { restore_property( name ); }
            } );
        }

        /**
         * Batch the writes into one frame: a slider drag must not thrash the
         * page.
         *
         * Two timers are armed, not one. `requestAnimationFrame` is the right
         * clock — the write lands before the next paint — but a frame is not
         * guaranteed: a background tab throttles it, and a headless browser
         * may never paint at all. A plain timeout is armed alongside it, and
         * whichever fires first does the work; the other is cancelled. The
         * cost of the fallback is one extra timer per change, and the cost of
         * not having it is a live preview that stops following the operator
         * when the tab is not in front.
         */
        function schedule() {
            if ( frame ) { return; }
            var done = false;
            var run = function () {
                if ( done ) { return; }
                done = true;
                if ( timer ) { clearTimeout( timer ); timer = null; }
                frame = null;
                flush();
            };
            if ( typeof requestAnimationFrame === 'function' ) {
                frame = requestAnimationFrame( run );
            }
            timer = setTimeout( run, 16 );
        }

        /**
         * Remove every override and put the element back as it was.
         */
        function restore() {
            if ( frame ) {
                if ( typeof cancelAnimationFrame === 'function' ) { cancelAnimationFrame( frame ); }
                frame = null;
            }
            if ( timer ) {
                clearTimeout( timer );
                timer = null;
            }
            Object.keys( applied ).forEach( restore_property );
            applied = {};
        }

        /**
         * The state of the layer, for the interface and the tests.
         */
        function get_state() {
            return {
                applied: Object.keys( applied ).map( function ( name ) {
                    return { name: name, value: applied[ name ] };
                } ),
                captured: captured,
                vocabulary: allowed ? Object.keys( allowed ) : [],
                has_vocabulary: !! allowed,
            };
        }

        return {
            set_vocabulary: set_vocabulary,
            set_stylesheet: set_stylesheet,
            set_desired: set_desired,
            apply: schedule,
            flush: flush,
            restore: restore,
            get_state: get_state,
        };
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_live_style_controller: make_live_style_controller };
    } else {
        root.AgentshellConfiguratorLiveStyle = { make_live_style_controller: make_live_style_controller };
    }
})( typeof window !== 'undefined' ? window : globalThis );
