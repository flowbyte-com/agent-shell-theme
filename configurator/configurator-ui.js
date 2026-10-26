/**
 * The configurator's view layer.
 *
 * This module builds DOM. It holds no state of its own: everything it renders
 * comes from the editor model, and everything the operator does is reported
 * back through the `app` object it is given. That split is what makes the
 * interface testable without a browser and the model testable without a DOM.
 *
 * The shape of the editor
 * -----------------------
 *   head     the title, the state of the draft, and the close control
 *   rail     the contexts: Layout, Appearance, History, Advanced
 *   canvas   the selected context, rendered in place
 *   actions  Preview · Save · Discard, always on screen
 *
 * The actions are in their own bar because the previous panel put "Save" at
 * the bottom of a 3,870-pixel scroll: an operator had to pass every setting
 * in the theme to reach the button that applied them.
 */
(function ( root ) {
    'use strict';

    /* ── a very small DOM builder ────────────────────────────────────────
     * Elements are built, not parsed from strings. The previous panel
     * assembled its markup as HTML text, which is why a label could not be
     * associated with its input without string surgery, and why a value with a
     * quote in it had to be escaped by hand.
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
                } else if ( key === 'html' ) {
                    el.innerHTML = value;
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

    /* ── the design tokens the editor exposes ──────────────────────────────
     *
     * A token belongs to a *scope* (global, header, main, footer) and to a
     * group within it. The previous panel grouped tokens by kind — every
     * border width together, every colour together — so a header colour sat
     * five screens away from the header's own area map. Scope is what the
     * operator is thinking about.
     */
    var TOKENS = {
        '--theme-bg':            { type: 'color', scope: 'global', group: 'Colours', label: 'Background' },
        '--theme-surface':       { type: 'color', scope: 'global', group: 'Colours', label: 'Surface' },
        '--theme-text':          { type: 'color', scope: 'global', group: 'Colours', label: 'Text' },
        '--theme-accent':        { type: 'color', scope: 'global', group: 'Colours', label: 'Accent' },
        '--theme-border':        { type: 'color', scope: 'global', group: 'Colours', label: 'Border' },

        '--font-base':           { type: 'text',  scope: 'global', group: 'Typography', label: 'Base font' },
        '--font-mono':           { type: 'text',  scope: 'global', group: 'Typography', label: 'Mono font' },

        '--spacing-base':        { type: 'text',  scope: 'global', group: 'Spacing', label: 'Base spacing' },

        '--border-width':        { type: 'text',  scope: 'global', group: 'Borders & shadows', label: 'Border width' },
        '--border-style':        { type: 'select', scope: 'global', group: 'Borders & shadows', label: 'Border style',
                                   options: [ { value: 'solid', label: 'Solid' }, { value: 'dashed', label: 'Dashed' }, { value: 'none', label: 'None' } ] },
        '--theme-shadow':        { type: 'shadow', scope: 'global', group: 'Borders & shadows', label: 'Island shadow' },

        '--zone-header-layout':  { type: 'select', scope: 'header', group: 'Layout', label: 'Header layout',
                                   options: [ { value: 'flex-start', label: 'Left' }, { value: 'center', label: 'Centre' }, { value: 'space-between', label: 'Space between' } ] },
        '--theme-header-bg':     { type: 'color', scope: 'header', group: 'Colours', label: 'Background' },
        '--theme-header-text':   { type: 'color', scope: 'header', group: 'Colours', label: 'Text' },
        '--theme-header-accent': { type: 'color', scope: 'header', group: 'Colours', label: 'Accent' },
        '--theme-header-border': { type: 'color', scope: 'header', group: 'Colours', label: 'Border line' },
        '--zone-header-border-width': { type: 'text', scope: 'header', group: 'Borders', label: 'Border width' },
        '--zone-header-radius':  { type: 'text', scope: 'header', group: 'Borders', label: 'Corner radius' },

        '--zone-main-align':     { type: 'select', scope: 'main', group: 'Layout', label: 'Content align',
                                   options: [ { value: 'flex-start', label: 'Top' }, { value: 'center', label: 'Middle' } ] },
        '--zone-main-border-width': { type: 'text', scope: 'main', group: 'Borders', label: 'Border width' },
        '--zone-main-radius':    { type: 'text', scope: 'main', group: 'Borders', label: 'Corner radius' },

        '--zone-footer-layout':  { type: 'select', scope: 'footer', group: 'Layout', label: 'Footer layout',
                                   options: [ { value: 'flex-start', label: 'Left' }, { value: 'center', label: 'Centre' }, { value: 'space-between', label: 'Space between' } ] },
        '--theme-footer-bg':     { type: 'color', scope: 'footer', group: 'Colours', label: 'Background' },
        '--theme-footer-text':   { type: 'color', scope: 'footer', group: 'Colours', label: 'Text' },
        '--theme-footer-accent': { type: 'color', scope: 'footer', group: 'Colours', label: 'Accent' },
        '--theme-footer-border': { type: 'color', scope: 'footer', group: 'Colours', label: 'Border line' },
        '--zone-footer-border-width': { type: 'text', scope: 'footer', group: 'Borders', label: 'Border width' },
        '--zone-footer-radius':  { type: 'text', scope: 'footer', group: 'Borders', label: 'Corner radius' },
    };

    var SHADOW_PRESETS = [
        { value: 'none', label: 'None' },
        { value: 'soft', label: 'Soft' },
        { value: '0 4px 6px rgba(0,0,0,0.1)', label: 'Custom' },
    ];

    var CONTEXTS = [
        { id: 'layout',     label: 'Layout' },
        { id: 'appearance', label: 'Appearance' },
        { id: 'history',    label: 'History' },
        { id: 'advanced',   label: 'Advanced' },
    ];

    var ZONES = [
        { id: 'header', label: 'Header' },
        { id: 'main',   label: 'Main' },
        { id: 'footer', label: 'Footer' },
    ];

    function make_ui( options ) {
        var app = options.app;

        function editor() { return app.editor; }
        function config() { return app.config || {}; }

        /* ── fields ────────────────────────────────────────────────────── */

        function field_row( label, control, hint ) {
            return h( 'div', { class: 'as-cfg__field' }, [
                h( 'label', { class: 'as-cfg__label', for: control.id }, [ label ] ),
                control,
                hint ? h( 'p', { class: 'as-cfg__hint' }, [ hint ] ) : null,
            ] );
        }

        function color_field( name, meta, value ) {
            var id = 'as-cfg-t-' + name.replace( /[^a-z0-9]/gi, '-' );
            var swatch = null;
            var text_input = h( 'input', {
                type: 'text', id: id, class: 'as-cfg__input as-cfg__input--hex',
                value: value || '', 'data-token': name, 'data-focus-key': 'token:' + name,
                oninput: function ( e ) {
                    editor().set_token( name, e.target.value );
                    // The swatch follows the typed value, so the operator sees
                    // the colour they are describing. An incomplete value
                    // ("#12") is left alone rather than shown as black: the
                    // field is still being typed, not wrong.
                    if ( swatch && is_color( e.target.value ) ) {
                        swatch.value = normalise_color( e.target.value );
                    }
                    app.on_change( { live: true } );
                },
            } );
            swatch = h( 'input', {
                type: 'color', class: 'as-cfg__swatch', value: normalise_color( value ),
                'aria-label': meta.label + ' colour', 'data-focus-key': 'swatch:' + name,
                oninput: function ( e ) {
                    editor().set_token( name, e.target.value );
                    if ( text_input ) { text_input.value = e.target.value; }
                    app.on_change( { live: true } );
                },
            } );
            return field_row( meta.label, h( 'div', { class: 'as-cfg__color' }, [ swatch, text_input ] ) );
        }

        /** Is this a colour the swatch can show? */
        function is_color( value ) {
            return /^#[0-9a-f]{3}$/i.test( String( value || '' ).trim() )
                || /^#[0-9a-f]{6}$/i.test( String( value || '' ).trim() );
        }

        function text_field( name, meta, value ) {
            var id = 'as-cfg-t-' + name.replace( /[^a-z0-9]/gi, '-' );
            var input = h( 'input', {
                type: 'text', id: id, class: 'as-cfg__input', value: value || '',
                'data-token': name, 'data-focus-key': 'token:' + name,
                oninput: function ( e ) {
                    editor().set_token( name, e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            return field_row( meta.label, input );
        }

        function select_field( name, meta, value ) {
            var id = 'as-cfg-t-' + name.replace( /[^a-z0-9]/gi, '-' );
            var select = h( 'select', {
                id: id, class: 'as-cfg__input as-cfg__select', 'data-token': name, 'data-focus-key': 'token:' + name,
                onchange: function ( e ) {
                    editor().set_token( name, e.target.value );
                    app.on_change();
                },
            } );
            ( meta.options || [] ).forEach( function ( option ) {
                select.appendChild( h( 'option', { value: option.value, text: option.label, selected: option.value === value } ) );
            } );
            return field_row( meta.label, select );
        }

        function shadow_field( name, meta, value ) {
            var id = 'as-cfg-t-' + name.replace( /[^a-z0-9]/gi, '-' );
            var select = h( 'select', {
                id: id, class: 'as-cfg__input as-cfg__select', 'data-token': name, 'data-focus-key': 'token:' + name,
                onchange: function ( e ) {
                    editor().set_token( name, e.target.value );
                    app.on_change();
                },
            } );
            var known = SHADOW_PRESETS.some( function ( p ) { return p.value === value; } );
            SHADOW_PRESETS.forEach( function ( preset ) {
                select.appendChild( h( 'option', { value: preset.value, text: preset.label, selected: preset.value === value } ) );
            } );
            if ( ! known && value ) {
                select.appendChild( h( 'option', { value: value, text: 'Custom', selected: true } ) );
            }
            var input = h( 'input', {
                type: 'text', class: 'as-cfg__input as-cfg__input--shadow', value: value || '',
                'aria-label': 'Shadow value', 'data-focus-key': 'shadow:' + name,
                oninput: function ( e ) {
                    editor().set_token( name, e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            return field_row( meta.label, h( 'div', { class: 'as-cfg__shadow' }, [ select, input ] ) );
        }

        function token_field( name, meta, value ) {
            if ( meta.type === 'color' ) { return color_field( name, meta, value ); }
            if ( meta.type === 'select' ) { return select_field( name, meta, value ); }
            if ( meta.type === 'shadow' ) { return shadow_field( name, meta, value ); }
            return text_field( name, meta, value );
        }

        function normalise_color( value ) {
            var v = String( value || '' ).trim();
            if ( /^#[0-9a-f]{6}$/i.test( v ) ) { return v; }
            if ( /^#[0-9a-f]{3}$/i.test( v ) ) {
                return '#' + v.charAt( 1 ) + v.charAt( 1 ) + v.charAt( 2 ) + v.charAt( 2 ) + v.charAt( 3 ) + v.charAt( 3 );
            }
            return '#000000';
        }

        /* ── the area map and the block list ───────────────────────────── */

        function area_map( zone_id, zone_config, area_defs ) {
            var selected = editor().get_state().area;
            var map = h( 'div', { class: 'as-cfg__map', role: 'group', 'aria-label': 'Areas of the ' + zone_id } );
            area_defs.forEach( function ( def ) {
                var blocks = ( zone_config.slots && zone_config.slots[ def.id ] ) || [];
                var is_selected = def.id === selected;
                map.appendChild( h( 'button', {
                    type: 'button',
                    class: 'as-cfg__area' + ( is_selected ? ' is-selected' : '' ) + ( blocks.length ? '' : ' is-empty' ),
                    'aria-pressed': is_selected ? 'true' : 'false',
                    'data-area': def.id,
                    'data-focus-key': 'area:' + zone_id + ':' + def.id,
                    title: def.label + ( blocks.length ? ' — ' + blocks.length + ' component' + ( blocks.length === 1 ? '' : 's' ) : ' — empty' ),
                    onclick: function () {
                        editor().set_area( def.id );
                        app.on_change();
                    },
                }, [
                    h( 'span', { class: 'as-cfg__area-code', 'aria-hidden': 'true', text: def.short || def.id } ),
                    h( 'span', { class: 'as-cfg__area-count', 'aria-hidden': 'true', text: blocks.length ? String( blocks.length ) : '' } ),
                    h( 'span', { class: 'as-cfg__sr', text: def.label + ( blocks.length ? ', ' + blocks.length + ' components' : ', empty' ) } ),
                ] ) );
            } );
            return map;
        }

        function block_label( block ) {
            if ( ! block || ! block.type ) { return 'Component'; }
            if ( block.type === 'wp_core' ) {
                var components = config().core_components || [];
                var found = components.filter( function ( c ) { return c.id === block.id; } )[ 0 ];
                return found ? found.name : ( block.id || 'Core component' );
            }
            if ( block.type === 'widget' ) {
                var widgets = config().available_widgets || [];
                var w = widgets.filter( function ( x ) { return x.id === block.id; } )[ 0 ];
                return w ? w.name : ( 'Widget ' + ( block.id || '' ) );
            }
            if ( block.type === 'wp_widget_area' ) {
                var areas = config().available_widget_areas || [];
                var a = areas.filter( function ( x ) { return x.id === block.id; } )[ 0 ];
                return a ? a.name : ( 'Widget area ' + ( block.id || '' ) );
            }
            if ( block.type === 'wp_loop' ) { return 'Content loop'; }
            if ( block.type === 'json_block' ) { return 'HTML block'; }
            return block.type;
        }

        function block_list( zone_id, area_id, blocks ) {
            var list = h( 'ul', { class: 'as-cfg__blocks', 'aria-label': 'Components in this area' } );
            var selected = editor().get_state().block;

            if ( ! blocks.length ) {
                list.appendChild( h( 'li', { class: 'as-cfg__blocks-empty' }, [ 'This area is empty.' ] ) );
            }

            blocks.forEach( function ( block, index ) {
                var is_selected = index === selected;
                var item = h( 'li', {
                    class: 'as-cfg__block' + ( is_selected ? ' is-selected' : '' ),
                    'data-block-index': index,
                }, [
                    h( 'button', {
                        type: 'button', class: 'as-cfg__block-name', 'aria-pressed': is_selected ? 'true' : 'false',
                        'data-focus-key': 'block:' + zone_id + ':' + area_id + ':' + index,
                        onclick: function () {
                            editor().set_block( index );
                            app.on_change();
                        },
                    }, [ block_label( block ) ] ),
                    h( 'div', { class: 'as-cfg__block-tools' }, [
                        h( 'button', {
                            type: 'button', class: 'as-cfg__icon', 'aria-label': 'Move ' + block_label( block ) + ' up',
                            disabled: index === 0 ? 'disabled' : null,
                            onclick: function () {
                                editor().reorder_block( zone_id, area_id, index, -1 );
                                app.on_change();
                            },
                        }, [ '↑' ] ),
                        h( 'button', {
                            type: 'button', class: 'as-cfg__icon', 'aria-label': 'Move ' + block_label( block ) + ' down',
                            disabled: index === blocks.length - 1 ? 'disabled' : null,
                            onclick: function () {
                                editor().reorder_block( zone_id, area_id, index, 1 );
                                app.on_change();
                            },
                        }, [ '↓' ] ),
                        h( 'button', {
                            type: 'button', class: 'as-cfg__icon as-cfg__icon--danger', 'aria-label': 'Remove ' + block_label( block ),
                            onclick: function () {
                                editor().remove_block( zone_id, area_id, index );
                                app.on_change();
                            },
                        }, [ '×' ] ),
                    ] ),
                ] );
                list.appendChild( item );
            } );

            return list;
        }

        function add_component( zone_id, area_id ) {
            // A button, not a select: the choices are made in the picker,
            // which can show what they are. A select offered four kinds of
            // component and then asked a native dialog which one, which
            // is two steps through two interfaces to make one choice.
            var button = h( 'button', {
                type: 'button', class: 'as-cfg__btn as-cfg__btn--wide',
                'aria-label': 'Add a component to ' + ( area_id || 'this zone' ),
                'data-focus-key': 'add:' + zone_id + ':' + ( area_id || 'composition' ),
                onclick: function ( e ) {
                    app.open_component_picker( zone_id, area_id, e.currentTarget );
                },
            }, [ '+ Add component' ] );
            return button;
        }

        /* ── the block inspector ─────────────────────────────────────────── */

        function inspector( zone_id, area_id ) {
            var state = editor().get_state();
            var blocks = editor().blocks_of( zone_id, area_id );
            var block = blocks[ state.block ];
            var box = h( 'div', { class: 'as-cfg__inspector' } );

            if ( ! block ) {
                box.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Component settings' ] ) );
                box.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'Select a component above to configure it.' ] ) );
                return box;
            }

            box.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ block_label( block ) ] ) );

            if ( block.type === 'wp_core' && block.id === 'nav_menu' ) {
                box.appendChild( nav_fields( zone_id, area_id, block ) );
            } else if ( block.type === 'wp_core' ) {
                box.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'This component has no options of its own.' ] ) );
            } else if ( block.type === 'widget' ) {
                box.appendChild( choice_field( zone_id, area_id, block, 'Widget', config().available_widgets || [], 'id' ) );
            } else if ( block.type === 'wp_widget_area' ) {
                box.appendChild( choice_field( zone_id, area_id, block, 'Widget area', config().available_widget_areas || [], 'id' ) );
            } else if ( block.type === 'json_block' ) {
                box.appendChild( text_area_field( zone_id, area_id, block, 'content', 'HTML', 6 ) );
            } else if ( block.type === 'wp_loop' ) {
                box.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'The loop renders the content of the page being viewed. It has no options.' ] ) );
            }

            // Moving a component between areas, and between zones, without a
            // drag-and-drop gesture: a select is reachable by keyboard and
            // works on a touch screen.
            var moves = h( 'div', { class: 'as-cfg__moves' } );
            var area_defs = config().zone_areas || [];
            var target = h( 'select', {
                class: 'as-cfg__input as-cfg__select', 'aria-label': 'Move this component to another area',
                'data-focus-key': 'move:' + zone_id + ':' + area_id + ':' + state.block,
                onchange: function ( e ) {
                    var value = e.target.value;
                    e.target.value = '';
                    if ( ! value ) { return; }
                    var parts = value.split( '/' );
                    if ( parts[ 0 ] === zone_id ) {
                        editor().move_block( zone_id, area_id, parts[ 1 ], state.block );
                    } else {
                        editor().move_block_to_zone( zone_id, area_id, parts[ 0 ], parts[ 1 ], state.block );
                    }
                    app.on_change();
                },
            } );
            target.appendChild( h( 'option', { value: '', text: 'Move to…' } ) );
            ZONES.forEach( function ( zone ) {
                if ( zone.id === 'main' ) { return; }
                area_defs.forEach( function ( def ) {
                    var value = zone.id + '/' + def.id;
                    if ( zone.id === zone_id && def.id === area_id ) { return; }
                    target.appendChild( h( 'option', { value: value, text: zone.label + ' · ' + def.label } ) );
                } );
            } );
            moves.appendChild( field_row( 'Move to', target ) );
            box.appendChild( moves );

            return box;
        }

        function nav_fields( zone_id, area_id, block ) {
            var wrap = h( 'div', { class: 'as-cfg__nav-fields' } );
            var menus = config().available_menus || [];
            var locations = config().available_menu_locations || [];

            var menu_select = h( 'select', {
                class: 'as-cfg__input as-cfg__select', 'aria-label': 'WordPress menu',
                'data-focus-key': 'nav-menu:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                onchange: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, 'menu', e.target.value );
                    if ( e.target.value ) {
                        editor().set_block_field( zone_id, area_id, editor().get_state().block, 'location', '' );
                    }
                    app.on_change();
                },
            } );
            menu_select.appendChild( h( 'option', { value: '', text: '— none (use a location) —' } ) );
            menus.forEach( function ( menu ) {
                menu_select.appendChild( h( 'option', {
                    value: menu.slug, text: menu.name + ( menu.count ? ' (' + menu.count + ')' : '' ),
                    selected: menu.slug === block.menu,
                } ) );
            } );
            if ( block.menu && ! menus.some( function ( m ) { return m.slug === block.menu; } ) ) {
                menu_select.appendChild( h( 'option', { value: block.menu, text: block.menu + ' (unavailable)', selected: true } ) );
            }
            wrap.appendChild( field_row( 'WordPress menu', menu_select ) );

            var location_select = h( 'select', {
                class: 'as-cfg__input as-cfg__select', 'aria-label': 'Theme menu location',
                'data-focus-key': 'nav-location:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                onchange: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, 'location', e.target.value );
                    if ( e.target.value ) {
                        editor().set_block_field( zone_id, area_id, editor().get_state().block, 'menu', '' );
                    }
                    app.on_change();
                },
            } );
            location_select.appendChild( h( 'option', { value: '', text: '— none (use a menu) —' } ) );
            locations.forEach( function ( location ) {
                location_select.appendChild( h( 'option', {
                    value: location.slug,
                    text: ( location.label || location.slug ) + ( location.menu_id ? '' : ' (no menu assigned)' ),
                    selected: location.slug === block.location,
                } ) );
            } );
            if ( block.location && ! locations.some( function ( l ) { return l.slug === block.location; } ) ) {
                location_select.appendChild( h( 'option', { value: block.location, text: block.location + ' (not registered)', selected: true } ) );
            }
            wrap.appendChild( field_row( 'Menu location', location_select ) );

            var mode_select = h( 'select', {
                class: 'as-cfg__input as-cfg__select', 'aria-label': 'Presentation',
                'data-focus-key': 'nav-mode:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                onchange: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, 'mode', e.target.value );
                    app.on_change();
                },
            } );
            [ { value: 'horizontal', label: 'Horizontal' },
              { value: 'vertical', label: 'Vertical' },
              { value: 'compact', label: 'Compact' } ].forEach( function ( option ) {
                mode_select.appendChild( h( 'option', {
                    value: option.value, text: option.label,
                    selected: ( block.mode || 'horizontal' ) === option.value,
                } ) );
            } );
            wrap.appendChild( field_row( 'Presentation', mode_select ) );

            var label_input = h( 'input', {
                type: 'text', class: 'as-cfg__input', maxlength: '100',
                value: block.label || '', 'aria-label': 'Accessible name',
                'data-focus-key': 'nav-label:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                oninput: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, 'label', e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            wrap.appendChild( field_row( 'Accessible name', label_input, 'Defaults to the menu name. It is what a screen reader announces for the navigation landmark.' ) );

            return wrap;
        }

        function choice_field( zone_id, area_id, block, label, choices, field ) {
            var select = h( 'select', {
                class: 'as-cfg__input as-cfg__select', 'aria-label': label,
                'data-focus-key': 'choice:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                onchange: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, field, e.target.value );
                    app.on_change();
                },
            } );
            select.appendChild( h( 'option', { value: '', text: '— none —' } ) );
            choices.forEach( function ( choice ) {
                select.appendChild( h( 'option', { value: choice.id, text: choice.name || choice.id, selected: choice.id === block[ field ] } ) );
            } );
            if ( block[ field ] && ! choices.some( function ( c ) { return c.id === block[ field ]; } ) ) {
                select.appendChild( h( 'option', { value: block[ field ], text: block[ field ] + ' (unavailable)', selected: true } ) );
            }
            return field_row( label, select );
        }

        function text_area_field( zone_id, area_id, block, field, label, rows ) {
            var area = h( 'textarea', {
                class: 'as-cfg__input as-cfg__code', rows: String( rows || 6 ),
                'aria-label': label,
                'data-focus-key': 'text:' + zone_id + ':' + area_id + ':' + editor().get_state().block,
                oninput: function ( e ) {
                    editor().set_block_field( zone_id, area_id, editor().get_state().block, field, e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            area.value = block[ field ] || '';
            return field_row( label, area );
        }

        /* ── contexts ────────────────────────────────────────────────────── */

        function token_groups( scope ) {
            var groups = {};
            var vars = ( editor().get_state().draft || { vars: {} } ).vars || {};
            Object.keys( TOKENS ).forEach( function ( name ) {
                var meta = TOKENS[ name ];
                if ( meta.scope !== scope ) { return; }
                if ( ! groups[ meta.group ] ) { groups[ meta.group ] = []; }
                groups[ meta.group ].push( { name: name, meta: meta, value: vars[ name ] || '' } );
            } );
            var out = h( 'div', { class: 'as-cfg__groups' } );
            Object.keys( groups ).forEach( function ( group ) {
                var section = h( 'section', { class: 'as-cfg__group' } );
                section.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ group ] ) );
                groups[ group ].forEach( function ( entry ) {
                    section.appendChild( token_field( entry.name, entry.meta, entry.value ) );
                } );
                out.appendChild( section );
            } );
            if ( ! Object.keys( groups ).length ) {
                out.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'No settings in this group yet.' ] ) );
            }
            return out;
        }

        function layout_view() {
            var state = editor().get_state();
            var zone_id = state.zone;
            var zone_config = editor().zone( zone_id ) || { id: zone_id, slots: {} };
            var view = h( 'div', { class: 'as-cfg__view' } );

            var zone_bar = h( 'div', { class: 'as-cfg__segmented', role: 'group', 'aria-label': 'Zone' } );
            ZONES.forEach( function ( zone ) {
                zone_bar.appendChild( h( 'button', {
                    type: 'button', class: 'as-cfg__segment', 'aria-pressed': zone.id === zone_id ? 'true' : 'false',
                    'data-focus-key': 'zone:' + zone.id,
                    onclick: function () {
                        editor().set_zone( zone.id );
                        // The area to open is the one the server offered, not
                        // one the editor assumed: a site whose grid does not
                        // carry the middle-centre area opens the first area
                        // it does have, rather than an area that is not
                        // there.
                        editor().set_area( app.zone.resolve_area( config().zone_areas || [], null, 'middle-center' ) );
                        app.on_change();
                    },
                }, [ zone.label ] ) );
            } );
            view.appendChild( zone_bar );

            var area_id = zone_id === 'main' ? null : state.area;
            var blocks = editor().blocks_of( zone_id, area_id );

            if ( zone_id !== 'main' ) {
                view.appendChild( area_map( zone_id, zone_config, config().zone_areas || [] ) );
            }

            var area = h( 'section', { class: 'as-cfg__area-body' } );
            var area_title = zone_id === 'main'
                ? 'Content'
                : ( ( config().zone_areas || [] ).filter( function ( d ) { return d.id === state.area; } )[ 0 ] || { label: state.area } ).label;
            area.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ area_title ] ) );
            area.appendChild( block_list( zone_id, area_id, blocks ) );
            area.appendChild( add_component( zone_id, area_id ) );
            view.appendChild( area );

            view.appendChild( inspector( zone_id, area_id ) );
            view.appendChild( token_groups( zone_id ) );
            return view;
        }

        function appearance_view() {
            var view = h( 'div', { class: 'as-cfg__view' } );
            view.appendChild( h( 'p', { class: 'as-cfg__lede' }, [ 'The settings that apply to the whole site. Zone-specific settings live with their zone in Layout.' ] ) );
            view.appendChild( token_groups( 'global' ) );
            return view;
        }

        function history_view() {
            var view = h( 'div', { class: 'as-cfg__view' } );
            var tx_state = app.tx.get_state();
            var busy = app.tx.is_active();

            view.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Open transaction' ] ) );
            view.appendChild( h( 'p', { class: 'as-cfg__muted' }, [
                busy
                    ? ( tx_state.tx && tx_state.tx.has_staged ? 'There are staged changes in this transaction. Save or discard them from the actions below.' : 'A transaction is open with no changes staged yet.' )
                    : 'No transaction is open. Saving from this editor opens one and commits it when it succeeds.',
            ] ) );

            var snapshot_name = h( 'input', {
                type: 'text', class: 'as-cfg__input', 'aria-label': 'Snapshot name',
                placeholder: 'before the header change', 'data-focus-key': 'snapshot-name',
            } );
            view.appendChild( field_row( 'Take a snapshot', h( 'div', { class: 'as-cfg__row' }, [
                snapshot_name,
                h( 'button', {
                    type: 'button', class: 'as-cfg__btn', 'data-focus-key': 'snapshot-create',
                    onclick: function () {
                        app.create_snapshot( snapshot_name.value );
                    },
                }, [ 'Snapshot' ] ),
            ] ) ) );

            view.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Snapshots' ] ) );
            var snapshots = app.history.snapshots || [];
            if ( ! snapshots.length ) {
                view.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'No snapshots yet.' ] ) );
            } else {
                var snap_list = h( 'ul', { class: 'as-cfg__records' } );
                snapshots.forEach( function ( snapshot ) {
                    snap_list.appendChild( h( 'li', { class: 'as-cfg__record' }, [
                        h( 'span', { class: 'as-cfg__record-name' }, [ snapshot.name ] ),
                        h( 'span', { class: 'as-cfg__record-meta' }, [ ' — ' + ( snapshot.label || '' ) + ( snapshot.actor ? ' by ' + snapshot.actor : '' ) ] ),
                        h( 'button', {
                            type: 'button', class: 'as-cfg__btn as-cfg__btn--quiet',
                            disabled: busy ? 'disabled' : null,
                            title: busy ? 'Commit or discard the open transaction first' : 'Restore this snapshot',
                            onclick: function () { app.restore_snapshot( snapshot.name ); },
                        }, [ 'Restore' ] ),
                    ] ) );
                } );
                view.appendChild( snap_list );
            }

            view.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Revisions' ] ) );
            var revisions = app.history.revisions || [];
            if ( ! revisions.length ) {
                view.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'No revisions recorded yet.' ] ) );
            } else {
                var rev_list = h( 'ul', { class: 'as-cfg__records' } );
                revisions.slice( 0, 12 ).forEach( function ( revision ) {
                    rev_list.appendChild( h( 'li', { class: 'as-cfg__record' }, [
                        h( 'span', { class: 'as-cfg__record-name' }, [ revision.label || revision.id ] ),
                        h( 'span', { class: 'as-cfg__record-meta' }, [ ' — ' + ( revision.actor || '' ) ] ),
                        h( 'button', {
                            type: 'button', class: 'as-cfg__btn as-cfg__btn--quiet',
                            disabled: busy ? 'disabled' : null,
                            title: busy ? 'Commit or discard the open transaction first' : 'Restore this revision',
                            onclick: function () { app.restore_revision( revision.id ); },
                        }, [ 'Restore' ] ),
                    ] ) );
                } );
                view.appendChild( rev_list );
            }

            if ( busy ) {
                view.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'Restoring is refused while a transaction is open: commit or discard it first.' ] ) );
            }
            return view;
        }

        function advanced_view() {
            var view = h( 'div', { class: 'as-cfg__view' } );
            var draft = editor().get_state().draft || { vars: {}, custom_css: '', custom_js: '', zones: [] };

            view.appendChild( h( 'p', { class: 'as-cfg__lede' }, [ 'Specialist controls. Everything here applies to the whole site and is validated by the same server-side validator the agent tools use.' ] ) );

            var css = h( 'textarea', {
                class: 'as-cfg__input as-cfg__code as-cfg__code--large', rows: '16',
                'aria-label': 'Custom CSS', 'data-focus-key': 'custom-css',
                spellcheck: 'false',
                oninput: function ( e ) {
                    editor().set_custom_css( e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            css.value = draft.custom_css || '';
            var css_meta = h( 'p', { class: 'as-cfg__hint' }, [ css_bytes( draft.custom_css ) + ' of 256 KB used.' ] );
            view.appendChild( field_row( 'Custom CSS', css, null ) );
            view.appendChild( css_meta );

            var js = h( 'textarea', {
                class: 'as-cfg__input as-cfg__code as-cfg__code--large', rows: '12',
                'aria-label': 'Custom JavaScript', 'data-focus-key': 'custom-js',
                spellcheck: 'false',
                oninput: function ( e ) {
                    editor().set_custom_js( e.target.value );
                    app.on_change( { live: true } );
                },
            } );
            js.value = draft.custom_js || '';
            view.appendChild( field_row( 'Custom JavaScript', js, js_bytes( draft.custom_js ) + ' of 10 KB used.' ) );

            var deps = ( config().navigation_dependencies || {} );
            if ( deps.menus && deps.menus.length ) {
                view.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Menus this design expects' ] ) );
                var list = h( 'ul', { class: 'as-cfg__records' } );
                deps.menus.forEach( function ( entry ) {
                    list.appendChild( h( 'li', { class: 'as-cfg__record' }, [
                        h( 'span', { class: 'as-cfg__record-name' }, [ entry.reference ] ),
                        h( 'span', { class: 'as-cfg__record-meta' }, [ ' — ' + entry.kind ] ),
                    ] ) );
                } );
                view.appendChild( list );
            }

            view.appendChild( h( 'h4', { class: 'as-cfg__h4' }, [ 'Configuration' ] ) );
            view.appendChild( h( 'p', { class: 'as-cfg__muted' }, [ 'The configuration as it will be saved. Read-only: it is the same JSON the agent tools read and write.' ] ) );
            var pre = h( 'pre', { class: 'as-cfg__json' } );
            pre.textContent = JSON.stringify( editor().to_payload(), null, 2 );
            view.appendChild( pre );
            return view;
        }

        function css_bytes( value ) {
            return ( new Blob( [ value || '' ] ).size ) + ' bytes';
        }

        function js_bytes( value ) {
            return ( value || '' ).length + ' characters';
        }

        /* ── the shell ───────────────────────────────────────────────────── */

        function render() {
            var state = editor().get_state();
            var status = editor().status( app.tx );

            var panel = document.getElementById( 'agentshell-config-panel' );
            if ( ! panel ) { return; }

            var focus_key = document.activeElement && document.activeElement.dataset
                ? document.activeElement.dataset.focusKey
                : null;

            // The panel's own class is what slides it into view, so the class
            // list must survive a render: replacing it wholesale closed the
            // editor again the moment it had finished loading.
            var was_open = panel.classList.contains( 'is-open' );
            panel.className = 'as-cfg' + ( was_open ? ' is-open' : '' );
            panel.setAttribute( 'data-state', status.key );

            var head = h( 'header', { class: 'as-cfg__head' }, [
                h( 'span', { class: 'as-cfg__brand' }, [ 'AgentShell' ] ),
                h( 'span', {
                    class: 'as-cfg__status', role: 'status', 'aria-live': 'polite',
                    'data-status': status.key, text: status.label,
                } ),
                h( 'button', {
                    type: 'button', class: 'as-cfg__icon as-cfg__close', 'aria-label': 'Close the editor',
                    'data-focus-key': 'close',
                    onclick: function () { app.close(); },
                }, [ '×' ] ),
            ] );

            var rail = h( 'nav', {
                class: 'as-cfg__rail', role: 'tablist', 'aria-orientation': 'vertical', 'aria-label': 'Editor sections',
            } );
            CONTEXTS.forEach( function ( context, index ) {
                var selected = context.id === state.context;
                rail.appendChild( h( 'button', {
                    type: 'button', role: 'tab', id: 'as-cfg-tab-' + context.id,
                    class: 'as-cfg__tab' + ( selected ? ' is-selected' : '' ),
                    'aria-selected': selected ? 'true' : 'false',
                    'aria-controls': 'as-cfg-canvas',
                    tabindex: selected ? '0' : '-1',
                    'data-context': context.id,
                    'data-focus-key': 'tab:' + context.id,
                    onclick: function () { app.set_context( context.id ); },
                    onkeydown: function ( e ) { rail_keys( e, index ); },
                }, [ context.label ] ) );
            } );

            var canvas = h( 'section', {
                class: 'as-cfg__canvas', id: 'as-cfg-canvas', role: 'tabpanel',
                'aria-labelledby': 'as-cfg-tab-' + state.context, tabindex: '0',
            } );
            if ( state.context === 'layout' ) { canvas.appendChild( layout_view() ); }
            if ( state.context === 'appearance' ) { canvas.appendChild( appearance_view() ); }
            if ( state.context === 'history' ) { canvas.appendChild( history_view() ); }
            if ( state.context === 'advanced' ) { canvas.appendChild( advanced_view() ); }

            var body = h( 'div', { class: 'as-cfg__body' }, [ rail, canvas ] );

            var alert_box = h( 'div', {
                class: 'as-cfg__alert', role: 'alert', hidden: app.message ? null : 'hidden',
            }, [ app.message || '' ] );

            var actions = h( 'footer', { class: 'as-cfg__actions' }, [
                h( 'button', {
                    type: 'button', class: 'as-cfg__btn', 'data-focus-key': 'preview',
                    title: 'Open the staged site in a new tab',
                    onclick: function () { app.preview(); },
                }, [ 'Preview' ] ),
                h( 'button', {
                    type: 'button', class: 'as-cfg__btn as-cfg__btn--primary', 'data-focus-key': 'save',
                    disabled: ! status.can_save || app.busy ? 'disabled' : null,
                    onclick: function () { app.save(); },
                }, [ app.busy ? 'Working…' : 'Save' ] ),
                h( 'button', {
                    type: 'button', class: 'as-cfg__btn', 'data-focus-key': 'discard',
                    // Discarding rolls the transaction back, so it is
                    // refused while another actor holds it: their staged
                    // work is not this session's to throw away.
                    disabled: ( app.busy || ! app.tx.can_discard() ) ? 'disabled' : null,
                    title: app.tx.can_discard() ? '' : 'Another actor holds the open transaction',
                    onclick: function () { app.discard_changes(); },
                }, [ 'Discard' ] ),
                h( 'span', { class: 'as-cfg__actions-hint' }, [ app.hint || '' ] ),
            ] );

            panel.textContent = '';
            panel.appendChild( head );
            panel.appendChild( body );
            panel.appendChild( alert_box );
            panel.appendChild( actions );

            if ( focus_key ) {
                var restore = panel.querySelector( '[data-focus-key="' + focus_key.replace( /"/g, '\\"' ) + '"]' );
                if ( restore && restore.focus ) {
                    restore.focus();
                    if ( restore.setSelectionRange && restore.type === 'text' ) {
                        try { restore.setSelectionRange( restore.value.length, restore.value.length ); } catch ( e ) {}
                    }
                }
            }
        }

        function rail_keys( event, index ) {
            var last = CONTEXTS.length - 1;
            var next = null;
            if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) { next = Math.min( index + 1, last ); }
            if ( event.key === 'ArrowUp' || event.key === 'ArrowLeft' ) { next = Math.max( index - 1, 0 ); }
            if ( event.key === 'Home' ) { next = 0; }
            if ( event.key === 'End' ) { next = last; }
            if ( next === null ) { return; }
            event.preventDefault();
            app.set_context( CONTEXTS[ next ].id );
            var tab = document.getElementById( 'as-cfg-tab-' + CONTEXTS[ next ].id );
            if ( tab ) { tab.focus(); }
        }

        return { render: render, TOKENS: TOKENS, CONTEXTS: CONTEXTS };
    }

    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { make_ui: make_ui, h: h, TOKENS: TOKENS };
    } else {
        root.AgentshellConfiguratorUi = { make_ui: make_ui, h: h, TOKENS: TOKENS };
    }
})( typeof window !== 'undefined' ? window : globalThis );
