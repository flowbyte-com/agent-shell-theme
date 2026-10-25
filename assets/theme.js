/**
 * AgentShell theme.js
 *
 * Two jobs:
 *
 *   1. The header mobile toggle: converts the header's middle-centre area into
 *      a drop-down panel on small screens. Desktop is unaffected. (Unchanged
 *      behaviour; the selector now also matches the nine-area grid's centre
 *      cell.)
 *
 *   2. Navigation block enhancement: a disclosure button per submenu, open and
 *      close state, Escape, focus management and compact mode.
 *
 * Progressive enhancement is the contract. With JavaScript off, a desktop menu
 * still opens on hover and on :focus-within (plain CSS), a vertical menu is
 * always open, and a compact menu renders as a plain list rather than an empty
 * box. This script only *adds* affordances; it never owns the only way in.
 *
 * The state rules are exported as `nav_model()` so the Node suite can exercise
 * them without a DOM. Everything DOM-shaped lives in `init()`.
 */
(function ( root ) {
    'use strict';

    /* ── Pure state model ─────────────────────────────────────────────────
     * A menu's open state is an array of item ids, outermost first. The rules
     * are small on purpose:
     *
     *   - opening an item keeps its ancestors open and closes everything else
     *     (a sibling dropdown and a cousin's dropdown cannot both be open in a
     *     menu that only has room for one),
     *   - closing an item closes its descendants too, because their buttons
     *     live inside the list that just disappeared,
     *   - Escape closes the deepest open item and focuses its own button, which
     *     is where the user was.
     */
    function nav_model() {
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
            next_open: next_open,
            ancestors_of: ancestors_of,
            is_descendant: is_descendant,
            escape_target: escape_target,
            is_open: is_open,
        };
    }

    var model = nav_model();

    /* ── DOM ──────────────────────────────────────────────────────────── */

    function init() {
        init_header_toggle();
        init_nav_menus();
    }

    /* Job 1: the header mobile toggle (historical behaviour). */
    function init_header_toggle() {
        var header = document.getElementById( 'zone-header' );
        if ( ! header || header.dataset.agentshellNav ) {
            return;
        }
        header.dataset.agentshellNav = '1';

        // The middle-centre cell: `.slot-center` in the historical three-slot
        // layout, `data-slot="middle-center"` in the nine-area grid. Both
        // layouts put the navigation in the same place, so the toggle looks
        // for the place, not for one layout's class name.
        var centerSlot = header.querySelector( '.zone-slot.slot-center, .zone-slot[data-slot="middle-center"]' );
        if ( ! centerSlot ) {
            return;
        }

        var button = document.createElement( 'button' );
        button.type = 'button';
        button.className = 'mobile-menu-toggle';
        button.setAttribute( 'aria-expanded', 'false' );
        button.setAttribute( 'aria-controls', 'agentshell-mobile-nav' );
        button.innerHTML =
            '<span class="mobile-menu-icon" aria-hidden="true"></span>' +
            '<span class="screen-reader-text">Menu</span>';

        centerSlot.id = 'agentshell-mobile-nav';

        var rightSlot = header.querySelector( '.zone-slot.slot-right, .zone-slot[data-slot="middle-right"]' );
        if ( rightSlot ) {
            rightSlot.insertBefore( button, rightSlot.firstChild );
        } else {
            header.appendChild( button );
        }

        function setOpen( open ) {
            header.classList.toggle( 'mobile-nav-open', open );
            button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
        }

        button.addEventListener( 'click', function () {
            setOpen( ! header.classList.contains( 'mobile-nav-open' ) );
        } );

        document.addEventListener( 'click', function ( event ) {
            if (
                header.classList.contains( 'mobile-nav-open' ) &&
                ! header.contains( event.target )
            ) {
                setOpen( false );
            }
        } );

        document.addEventListener( 'keydown', function ( event ) {
            if ( event.key === 'Escape' ) {
                setOpen( false );
                button.focus();
            }
        } );

        centerSlot.addEventListener( 'click', function ( event ) {
            if ( event.target.closest( 'a' ) ) {
                setOpen( false );
            }
        } );
    }

    /* Job 2: navigation blocks. */
    function init_nav_menus() {
        var uid = 0;
        // Our own wrapper class, so this matches navigation *blocks* and nothing
        // else on the page: a `<nav>` an author wrote into post content, or one
        // from another plugin, is left alone.
        var navs = document.querySelectorAll( '.wp-core-nav_menu .wp-core-nav' );
        if ( ! navs.length ) {
            return;
        }

        Array.prototype.forEach.call( navs, function ( nav ) {
            if ( nav.dataset.navEnhanced === '1' ) {
                return;
            }
            nav.dataset.navEnhanced = '1';

            uid += 1;
            if ( ! nav.id ) {
                nav.id = 'agentshell-nav-' + uid;
            }

            init_compact( nav, function () { uid += 1; return uid; } );
            init_submenus( nav, function () { uid += 1; return uid; } );
        } );
    }

    /**
     * Compact mode: one button in front of the list.
     *
     * The button is glued to the right of the link text through the markup
     * (button first, list second), so a `vertical` or `compact` menu reads as
     * "Menu ▾" followed by nothing until it is asked for.
     */
    function init_compact( nav, next_uid ) {
        if ( ! nav.classList.contains( 'wp-core-nav--compact' ) ) {
            return;
        }
        var list = nav.querySelector( ':scope > ul' );
        if ( ! list ) {
            return;
        }
        if ( ! list.id ) {
            list.id = 'agentshell-nav-list-' + next_uid();
        }

        var button = document.createElement( 'button' );
        button.type = 'button';
        button.className = 'nav-compact-toggle';
        button.setAttribute( 'aria-expanded', 'false' );
        button.setAttribute( 'aria-controls', list.id );
        // The accessible name is the nav's own label, so two compact menus on
        // one page are "Legal ▾" and "Company ▾", not two "Menu" buttons.
        button.textContent = nav.getAttribute( 'aria-label' ) || 'Navigation';

        nav.insertBefore( button, list );

        button.addEventListener( 'click', function () {
            var open = nav.classList.toggle( 'is-open' );
            button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
        } );

        nav.addEventListener( 'keydown', function ( event ) {
            if ( event.key === 'Escape' && nav.classList.contains( 'is-open' ) ) {
                nav.classList.remove( 'is-open' );
                button.setAttribute( 'aria-expanded', 'false' );
                button.focus();
            }
        } );
    }

    /**
     * Nested menus: one disclosure button per item that has children.
     *
     * Why a button rather than "the link opens the submenu": the parent link
     * has a destination. Hijacking it would make some menu items unreachable
     * and would make the menu behave differently from every other WordPress
     * menu on the web.
     */
    function init_submenus( nav, next_uid ) {
        var parents = nav.querySelectorAll( 'li.menu-item-has-children, li.page_item_has_children' );
        if ( ! parents.length ) {
            return;
        }

        var open = [];
        var items = {};
        var parent_of = {};

        function parent_item_id( li ) {
            var node = li.parentElement ? li.parentElement.closest( 'li' ) : null;
            while ( node && nav.contains( node ) ) {
                if ( node.dataset.navItem ) {
                    return node.dataset.navItem;
                }
                node = node.parentElement ? node.parentElement.closest( 'li' ) : null;
            }
            return null;
        }

        function apply() {
            Object.keys( items ).forEach( function ( id ) {
                var item = items[ id ];
                var is_open = model.is_open( open, id );
                item.li.classList.toggle( 'is-open', is_open );
                item.toggle.setAttribute( 'aria-expanded', is_open ? 'true' : 'false' );
            } );
        }

        function close_all() {
            open = [];
            apply();
        }

        // Ancestors are emitted before descendants, so a child's chain can be
        // resolved against ids that already exist.
        Array.prototype.forEach.call( parents, function ( li ) {
            var link = li.querySelector( ':scope > a' );
            var submenu = li.querySelector( ':scope > .sub-menu, :scope > ul' );
            if ( ! link || ! submenu ) {
                return;
            }

            var id = 'agentshell-sub-' + next_uid();
            li.dataset.navItem = id;
            if ( ! submenu.id ) {
                submenu.id = id + '-menu';
            }

            var label = ( link.textContent || '' ).replace( /\s+/g, ' ' ).trim();
            var toggle = document.createElement( 'button' );
            toggle.type = 'button';
            toggle.className = 'submenu-toggle';
            toggle.setAttribute( 'aria-expanded', 'false' );
            toggle.setAttribute( 'aria-controls', submenu.id );
            toggle.setAttribute(
                'aria-label',
                label ? 'Open submenu: ' + label : 'Open submenu'
            );

            link.parentNode.insertBefore( toggle, link.nextSibling );

            parent_of[ id ] = parent_item_id( li );
            items[ id ] = { li: li, toggle: toggle };

            toggle.addEventListener( 'click', function () {
                var is_open = toggle.getAttribute( 'aria-expanded' ) === 'true';
                open = model.next_open( open, parent_of, id, is_open );
                apply();
            } );
        } );

        nav.addEventListener( 'keydown', function ( event ) {
            if ( event.key !== 'Escape' ) {
                return;
            }
            var target = model.escape_target( open );
            if ( ! target ) {
                return;
            }
            // Close the deepest open submenu and put focus back on the control
            // that opened it, so Escape does not strand the keyboard user at
            // the end of a list that just vanished.
            event.stopPropagation();
            open = model.next_open( open, parent_of, target, true );
            apply();
            items[ target ].toggle.focus();
        } );

        // Focus leaving the menu closes it. Tabbing between items inside the
        // menu keeps it open, because relatedTarget is still inside.
        nav.addEventListener( 'focusout', function ( event ) {
            if ( ! nav.contains( event.relatedTarget ) ) {
                close_all();
            }
        } );

        document.addEventListener( 'click', function ( event ) {
            if ( open.length && ! nav.contains( event.target ) ) {
                close_all();
            }
        } );
    }

    if ( typeof document !== 'undefined' ) {
        if ( document.readyState === 'loading' ) {
            document.addEventListener( 'DOMContentLoaded', init );
        } else {
            init();
        }
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { nav_model: nav_model };
    } else {
        root.AgentshellNav = { nav_model: nav_model };
    }
})( typeof window !== 'undefined' ? window : globalThis );
