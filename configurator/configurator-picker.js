/**
 * The configurator's component picker.
 *
 * One reusable widget, used wherever a component can be added. It replaces
 * three `window.prompt` dialogs that asked the operator to type a number:
 * a native prompt cannot show what the choices are, cannot be styled like
 * the rest of the editor, and reads as a browser artefact rather than as
 * part of AgentShell.
 *
 * What this module does
 * ---------------------
 * It presents a set of choices and reports the one that was made. It does
 * not know what a component is, where it is going, or what the draft
 * looks like: the caller builds the choices and decides what to do with
 * the answer. That is what makes it reusable — the Layout context uses it
 * for every zone, and it would work unchanged anywhere else.
 *
 * The rules it holds to
 * ---------------------
 *   - Opening it changes nothing. A choice is only reported when one is
 *     actually made — by a click, or by Enter on the highlighted option.
 *   - Cancelling changes nothing, and says so by cancelling.
 *   - Escape dismisses it, and the focus goes back where it came from.
 *   - The choices are named, never numbered.
 *   - The keyboard reaches every choice: the arrow keys move the
 *     highlight, Enter takes it, and Tab stays inside the dialog.
 *   - Where the choice will land is stated in the dialog, not implied.
 *
 * The choices themselves
 * ----------------------
 * `build_choices()` turns the server's configuration into the groups the
 * picker shows. The vocabulary is the theme's own — the block types of
 * AGENTS.md §3 — and the names come from the server, so the picker cannot
 * invent a component the site does not have.
 */
(function ( root ) {
    'use strict';

    /* ── the choices, built from the server's configuration ─────────────── */

    /**
     * The groups of choices the server's configuration offers.
     *
     * The order is the order an operator thinks in: the WordPress
     * components a site is built from, then the widgets, then the widget
     * areas, then the two block types that carry no site-specific choice.
     *
     * A group whose site has nothing to offer is still returned, with an
     * empty list, so the picker can say *why* it is empty rather than
     * leaving a hole in the list.
     *
     * @param {Object} config The configuration the server reported.
     * @return {Array} A list of { id, label, empty, items }.
     */
    function build_choices( config ) {
        var cfg = config || {};

        var core = Array.isArray( cfg.core_components ) ? cfg.core_components : [];
        var widgets = Array.isArray( cfg.available_widgets ) ? cfg.available_widgets : [];
        var areas = Array.isArray( cfg.available_widget_areas ) ? cfg.available_widget_areas : [];

        function item( type, id, label, detail ) {
            return { type: type, id: id, label: label, detail: detail || '' };
        }

        return [
            {
                id: 'wp_core',
                label: 'WordPress components',
                empty: 'This site has no WordPress components to add.',
                items: core.map( function ( c ) {
                    return item( 'wp_core', c.id, c.name || c.id, '' );
                } ),
            },
            {
                id: 'widget',
                label: 'Widgets',
                empty: 'No widgets are registered on this site.',
                items: widgets.map( function ( w ) {
                    return item( 'widget', w.id, w.name || w.id, w.source ? 'from ' + w.source : '' );
                } ),
            },
            {
                id: 'wp_widget_area',
                label: 'Widget areas',
                empty: 'This site has no widget areas.',
                items: areas.map( function ( a ) {
                    return item( 'wp_widget_area', a.id, a.name || a.id, '' );
                } ),
            },
            {
                id: 'other',
                label: 'Other',
                empty: '',
                items: [
                    item( 'json_block', '', 'HTML block', 'raw HTML, saved as written' ),
                    item( 'wp_loop', '', 'Content loop', 'the site\'s posts' ),
                ],
            },
        ];
    }

    /* ── a small DOM builder ─────────────────────────────────────────────
     * The same one the view layer uses. It is small enough to repeat, and
     * repeating it keeps this module free of a dependency on the view.
     */
    function h( tag, attrs, children ) {
        var el = document.createElement( tag );
        if ( attrs ) {
            Object.keys( attrs ).forEach( function ( key ) {
                var value = attrs[ key ];
                if ( value === null || value === undefined || value === false ) {
                    return;
                }
                if ( key === 'text' ) {
                    el.textContent = String( value );
                } else if ( key === 'class' ) {
                    el.className = value;
                } else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
                    el.addEventListener( key.slice( 2 ), value );
                } else if ( value === true ) {
                    el.setAttribute( key, '' );
                } else {
                    el.setAttribute( key, String( value ) );
                }
            } );
        }
        ( children || [] ).forEach( function ( child ) {
            if ( child === null || child === undefined || child === false ) {
                return;
            }
            el.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
        } );
        return el;
    }

    /* ── the picker ──────────────────────────────────────────────────────── */

    var SEQ = 0;

    /**
     * Open the picker.
     *
     * @param {Object} options
     *   host        The element the dialog is rendered into.
     *   groups      The groups of choices, from build_choices().
     *   target      A sentence naming where the choice will go.
     *   onChoose    Called with the chosen item. Not called for a cancel.
     *   onCancel    Called when the picker is dismissed without a choice.
     *   returnFocus The element to return the focus to. Defaults to whatever
     *                had the focus when the picker opened.
     * @return {Object} A handle with close().
     */
    function open( options ) {
        var opts = options || {};
        var host = opts.host;
        if ( ! host ) {
            return { close: function () {} };
        }

        SEQ += 1;
        var uid = 'as-cfg-picker-' + SEQ;

        var groups = Array.isArray( opts.groups ) ? opts.groups : [];
        var restoreTo = opts.returnFocus
            || ( document.activeElement && document.activeElement !== document.body ? document.activeElement : null );

        var chosen = null;
        var active = -1;
        var visible = [];

        /* The choices that match the filter, flattened. */
        function matches( item, needle ) {
            if ( ! needle ) { return true; }
            var haystack = ( item.label + ' ' + item.detail + ' ' + item.id ).toLowerCase();
            return haystack.indexOf( needle ) !== -1;
        }

        function compute_visible( needle ) {
            var out = [];
            groups.forEach( function ( group ) {
                group.items.forEach( function ( item ) {
                    if ( matches( item, needle ) ) {
                        out.push( { item: item, group: group } );
                    }
                } );
            } );
            return out;
        }

        /* ── the elements ──────────────────────────────────────────────── */

        var title = h( 'h3', { class: 'as-cfg__picker-title', id: uid + '-title' }, [ 'Add a component' ] );

        var target_line = h( 'p', { class: 'as-cfg__picker-target' }, [
            h( 'span', { class: 'as-cfg__picker-target-label', text: 'Adding to ' } ),
            h( 'strong', { class: 'as-cfg__picker-target-value', text: opts.target || 'this area' } ),
        ] );

        var filter = h( 'input', {
            type: 'text',
            class: 'as-cfg__input as-cfg__picker-filter',
            id: uid + '-filter',
            placeholder: 'Filter the choices…',
            'aria-label': 'Filter the components',
            role: 'combobox',
            'aria-expanded': 'true',
            'aria-controls': uid + '-list',
            'aria-autocomplete': 'list',
            autocomplete: 'off',
        } );

        var list = h( 'div', {
            class: 'as-cfg__picker-list',
            id: uid + '-list',
            role: 'listbox',
            'aria-label': 'Components',
        } );

        var empty_note = h( 'p', { class: 'as-cfg__picker-empty', hidden: true } );

        var live = h( 'p', {
            class: 'as-cfg__picker-live',
            role: 'status',
            'aria-live': 'polite',
        } );

        var cancel = h( 'button', {
            type: 'button',
            class: 'as-cfg__btn',
            'data-focus-key': 'picker-cancel',
            onclick: function () { close( 'cancel' ); },
        }, [ 'Cancel' ] );

        var body = h( 'div', { class: 'as-cfg__picker-body' }, [
            title,
            target_line,
            filter,
            list,
            empty_note,
            live,
        ] );

        var actions = h( 'div', { class: 'as-cfg__picker-actions' }, [ cancel ] );

        var dialog = h( 'div', {
            class: 'as-cfg__picker',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': uid + '-title',
            'data-focus-key': 'picker',
        }, [ body, actions ] );

        var overlay = h( 'div', {
            class: 'as-cfg__picker-overlay',
            'data-focus-key': 'picker-overlay',
            onclick: function ( e ) {
                // A click on the backdrop is a cancel; a click inside the
                // dialog is not.
                if ( e.target === overlay ) { close( 'cancel' ); }
            },
        }, [ dialog ] );

        /* ── rendering the choices ─────────────────────────────────────── */

        function render_list( needle ) {
            visible = compute_visible( needle );
            list.textContent = '';

            if ( ! visible.length ) {
                empty_note.hidden = false;
                empty_note.textContent = needle
                    ? 'Nothing matches “' + needle + '”.'
                    : 'There is nothing to add here.';
                filter.setAttribute( 'aria-activedescendant', '' );
                active = -1;
                live.textContent = 'No components to choose from.';
                return;
            }

            empty_note.hidden = true;
            empty_note.textContent = '';

            var current_group = null;
            visible.forEach( function ( entry, index ) {
                if ( entry.group !== current_group ) {
                    current_group = entry.group;
                    list.appendChild( h( 'div', {
                        class: 'as-cfg__picker-group',
                        role: 'presentation',
                        text: current_group.label,
                    } ) );
                }
                var option = h( 'div', {
                    class: 'as-cfg__picker-option',
                    role: 'option',
                    id: uid + '-option-' + index,
                    'aria-selected': 'false',
                    'data-focus-key': 'picker-option:' + index,
                    onmouseover: function () { set_active( index, false ); },
                    onclick: function () { choose( index ); },
                }, [
                    h( 'span', { class: 'as-cfg__picker-option-label', text: entry.item.label } ),
                    entry.item.detail
                        ? h( 'span', { class: 'as-cfg__picker-option-detail', text: entry.item.detail } )
                        : null,
                ] );
                option._entry = entry;
                list.appendChild( option );
            } );

            set_active( 0, true );
        }

        function option_elements() {
            return Array.prototype.slice.call( list.querySelectorAll( '.as-cfg__picker-option' ) );
        }

        function set_active( index, announce ) {
            var options = option_elements();
            options.forEach( function ( el ) {
                el.classList.remove( 'is-active' );
                el.setAttribute( 'aria-selected', 'false' );
            } );
            active = index;
            if ( index < 0 || index >= options.length ) {
                filter.setAttribute( 'aria-activedescendant', '' );
                return;
            }
            var el = options[ index ];
            el.classList.add( 'is-active' );
            el.setAttribute( 'aria-selected', 'true' );
            filter.setAttribute( 'aria-activedescendant', el.id );
            if ( el.scrollIntoView ) {
                el.scrollIntoView( { block: 'nearest' } );
            }
            if ( announce !== false ) {
                var entry = visible[ index ];
                live.textContent = entry
                    ? ( entry.item.label + ' — ' + entry.group.label )
                    : '';
            }
        }

        function move( delta ) {
            if ( ! visible.length ) { return; }
            var next = active + delta;
            if ( next < 0 ) { next = visible.length - 1; }
            if ( next >= visible.length ) { next = 0; }
            set_active( next, true );
        }

        function choose( index ) {
            var entry = visible[ index ];
            if ( ! entry ) {
                return;
            }
            chosen = entry.item;
            close( 'choose' );
        }

        /* ── the keyboard ───────────────────────────────────────────────── */

        function on_keydown( e ) {
            if ( e.key === 'Escape' ) {
                e.preventDefault();
                e.stopPropagation();
                close( 'cancel' );
                return;
            }
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                move( 1 );
                return;
            }
            if ( e.key === 'ArrowUp' ) {
                e.preventDefault();
                move( -1 );
                return;
            }
            if ( e.key === 'Home' ) {
                e.preventDefault();
                set_active( 0, true );
                return;
            }
            if ( e.key === 'End' ) {
                e.preventDefault();
                set_active( visible.length - 1, true );
                return;
            }
            if ( e.key === 'Enter' ) {
                e.preventDefault();
                choose( active );
                return;
            }
            if ( e.key === 'Tab' ) {
                // The focus stays inside the dialog. The only controls
                // inside it are the filter field and the cancel button,
                // so the trap is small and explicit.
                var focusables = [ filter, cancel ];
                var at = focusables.indexOf( document.activeElement );
                if ( at === -1 ) {
                    e.preventDefault();
                    filter.focus();
                    return;
                }
                var next = at + ( e.shiftKey ? -1 : 1 );
                if ( next < 0 ) { next = focusables.length - 1; }
                if ( next >= focusables.length ) { next = 0; }
                e.preventDefault();
                focusables[ next ].focus();
            }
        }

        filter.addEventListener( 'input', function () {
            render_list( filter.value.trim().toLowerCase() );
        } );

        filter.addEventListener( 'keydown', on_keydown );
        cancel.addEventListener( 'keydown', on_keydown );

        /* ── closing ────────────────────────────────────────────────────── */

        var closed = false;

        function close( how ) {
            if ( closed ) { return; }
            closed = true;
            document.removeEventListener( 'keydown', on_keydown, true );
            if ( overlay.parentNode ) {
                overlay.parentNode.removeChild( overlay );
            }
            if ( restoreTo && restoreTo.focus && document.contains( restoreTo ) ) {
                restoreTo.focus();
            }
            if ( how === 'choose' && chosen && typeof opts.onChoose === 'function' ) {
                opts.onChoose( chosen );
            } else if ( how !== 'choose' && typeof opts.onCancel === 'function' ) {
                opts.onCancel();
            }
        }

        document.addEventListener( 'keydown', on_keydown, true );

        render_list( '' );
        host.appendChild( overlay );
        filter.focus();

        return { close: close };
    }

    var api = {
        build_choices: build_choices,
        open: open,
    };

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = api;
    } else {
        root.AgentshellConfiguratorPicker = api;
    }
})( typeof window !== 'undefined' ? window : globalThis );
