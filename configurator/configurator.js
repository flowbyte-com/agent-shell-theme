(function() {
    'use strict';

    // Pure state model lives in a sibling file so Node tests can
    // exercise it without a browser. The model handles idle / editing_open
    // / locked transitions and the nine-area zone editing rules; the
    // configurator wires the model to fetch calls and DOM events.
    //
    // The sibling file is an enqueued dependency (functions.php), so its
    // absence is a build error rather than a state the browser can reach. If
    // it is somehow absent, say so and disable the zone editor rather than
    // growing a second implementation of the same rules here.
    if ( typeof window !== 'undefined' && window.AgentshellConfiguratorState ) {
        window.AgentshellConfigState = window.AgentshellConfiguratorState.make_state_model();
    } else if ( typeof console !== 'undefined' ) {
        console.error( 'AgentShell: configurator-state.js did not load; the zone editor is disabled.' );
    }

    const zoneModel = ( typeof window !== 'undefined' && window.AgentshellConfiguratorState )
        ? window.AgentshellConfiguratorState.make_zone_model()
        : null;

    const DEFAULTS = {
        '--zone-header-layout': 'space-between',
        '--zone-main-align': 'flex-start',
        '--zone-footer-layout': 'space-between',
        '--font-base': 'system-ui, -apple-system, sans-serif',
        '--font-mono': 'monospace',
        '--spacing-base': '1rem',
        '--border-width': '1px',
        '--border-style': 'solid',
        '--theme-shadow': '0 4px 6px -1px rgba(0,0,0,0.1)',
        '--theme-bg': '#ffffff',
        '--theme-surface': '#f4f4f5',
        '--theme-text': '#18181b',
        '--theme-accent': '#3b82f6',
        '--theme-border': '#e4e4e7',
        '--theme-header-bg': '#1a1a2e',
        '--theme-header-text': '#ffffff',
        '--theme-header-accent': '#3b82f6',
        '--theme-header-border': '#e4e4e7',
        '--theme-footer-bg': '#16213e',
        '--theme-footer-text': '#ffffff',
        '--theme-footer-accent': '#3b82f6',
        '--theme-footer-border': '#e4e4e7',
        '--zone-header-border-width': '1px',
        '--zone-header-radius': '12px',
        '--zone-main-border-width': '1px',
        '--zone-main-radius': '12px',
        '--zone-footer-border-width': '1px',
        '--zone-footer-radius': '12px'
    };

    const SHADOW_PRESETS = [
        { value: 'none', label: 'None' },
        { value: DEFAULTS['--theme-shadow'], label: 'Soft' },
        { value: '0 8px 20px -6px rgba(0,0,0,0.2)', label: 'Medium' },
        { value: '0 16px 40px -8px rgba(0,0,0,0.3)', label: 'Strong' }
    ];

    const META = {
        '--zone-header-layout': { type: 'select', section: 'Layout & Alignment', label: 'Header Layout', options: [{value: 'flex-start', label: 'Left'}, {value: 'center', label: 'Center'}, {value: 'space-between', label: 'Space Between'}] },
        '--zone-main-align': { type: 'select', section: 'Layout & Alignment', label: 'Main Content Align', options: [{value: 'flex-start', label: 'Top'}, {value: 'center', label: 'Middle'}] },
        '--zone-footer-layout': { type: 'select', section: 'Layout & Alignment', label: 'Footer Layout', options: [{value: 'flex-start', label: 'Left'}, {value: 'center', label: 'Center'}, {value: 'space-between', label: 'Space Between'}] },

        '--font-base': { type: 'text', section: 'Typography & Geometry', label: 'Base Font' },
        '--font-mono': { type: 'text', section: 'Typography & Geometry', label: 'Mono Font' },
        '--spacing-base': { type: 'text', section: 'Typography & Geometry', label: 'Base Spacing' },

        '--border-width': { type: 'text', section: 'Borders & Shadows', label: 'Global Border Width' },
        '--border-style': { type: 'select', section: 'Borders & Shadows', label: 'Border Style', options: [{value: 'solid', label: 'Solid'}, {value: 'dashed', label: 'Dashed'}, {value: 'none', label: 'None'}] },
        '--theme-shadow': { type: 'shadow', section: 'Borders & Shadows', label: 'Island Shadow' },

        '--theme-bg': { type: 'color', section: 'Global Colors', label: 'Background' },
        '--theme-surface': { type: 'color', section: 'Global Colors', label: 'Surface (Cards)' },
        '--theme-text': { type: 'color', section: 'Global Colors', label: 'Text' },
        '--theme-accent': { type: 'color', section: 'Global Colors', label: 'Accent' },
        '--theme-border': { type: 'color', section: 'Global Colors', label: 'Border' },

        '--theme-header-bg': { type: 'color', section: 'Header Settings', label: 'Background' },
        '--theme-header-text': { type: 'color', section: 'Header Settings', label: 'Text' },
        '--theme-header-accent': { type: 'color', section: 'Header Settings', label: 'Accent' },
        '--theme-header-border': { type: 'color', section: 'Header Settings', label: 'Border Line Color' },
        '--zone-header-border-width': { type: 'text', section: 'Header Settings', label: 'Border Width' },
        '--zone-header-radius': { type: 'text', section: 'Header Settings', label: 'Border Radius' },

        '--zone-main-border-width': { type: 'text', section: 'Main Layout', label: 'Border Width' },
        '--zone-main-radius': { type: 'text', section: 'Main Layout', label: 'Border Radius' },

        '--theme-footer-bg': { type: 'color', section: 'Footer Settings', label: 'Background' },
        '--theme-footer-text': { type: 'color', section: 'Footer Settings', label: 'Text' },
        '--theme-footer-accent': { type: 'color', section: 'Footer Settings', label: 'Accent' },
        '--theme-footer-border': { type: 'color', section: 'Footer Settings', label: 'Border Line Color' },
        '--zone-footer-border-width': { type: 'text', section: 'Footer Settings', label: 'Border Width' },
        '--zone-footer-radius': { type: 'text', section: 'Footer Settings', label: 'Border Radius' }
    };

    // Block type options for the add-block row
    const BLOCK_TYPES = [
        { value: 'wp_loop',         label: 'WP Loop (content)' },
        { value: 'wp_core',        label: 'WP Core Component' },
        { value: 'widget',          label: 'Widget' },
        { value: 'json_block',      label: 'HTML Block' },
        { value: 'wp_widget_area',  label: 'Widget Area' },
    ];

    let panel      = null;
    let trigger    = null;
    let initDone   = false;
    let liveState  = {};
    let availableWidgets     = []; // agent-defined widgets from blocks plugin
    let coreComponents       = []; // WP core components (site_title, nav_menu, etc.)
    let availableWidgetAreas = []; // registered WP sidebars for the wp_widget_area dropdown
    let txState    = 'idle';   // mirror of the pure state model
    let txInfo     = null;    // payload from GET /wp/v2/agentshell/transaction

    // ── DOM init ───────────────────────────────────────────────
    function initElements() {
        if (initDone) return;
        initDone = true;
        panel   = document.getElementById('agentshell-config-panel');
        trigger = document.getElementById('agentshell-config-trigger');
        if (trigger) trigger.addEventListener('click', togglePanel);
    }

    function readCssVar(name) {
        return getComputedStyle(document.documentElement)
            .getPropertyValue(name)
            .trim() || DEFAULTS[name];
    }

    function writeCssVar(name, value) {
        document.documentElement.style.setProperty(name, value);
    }

    function togglePanel() {
        if (!panel) return;
        panel.classList.toggle('is-open');
        document.body.classList.toggle('config-panel-open', panel.classList.contains('is-open'));
    }

    function closePanel() {
        if (!panel) return;
        panel.classList.remove('is-open');
        document.body.classList.remove('config-panel-open');
    }

    // ── Build state from REST API response ─────────────────────
    function buildState(response) {
        const cfg = response.config || response;
        const state = {};
        Object.keys(DEFAULTS).forEach(key => {
            state[key] = cfg[key] || readCssVar(key) || DEFAULTS[key];
        });
        state.custom_css = cfg.custom_css || '';
        state.custom_js  = cfg.custom_js  || '';
        // zones lives at the root of the response, not inside config/config
        state.zones = Array.isArray(response.zones) ? response.zones : Object.values(response.zones || {});
        // The slot grid and its alignment vocabulary come from the server,
        // which owns the canonical definition. The configurator never keeps
        // its own list of area names: a second list is a second answer.
        state.zone_areas = Array.isArray(response.zone_areas) ? response.zone_areas : [];
        state.zone_align = response.zone_align || { slot: [], row: [], rows: [] };
        // Menus come from WordPress (via the config response), never from a
        // list in this file: a menu created a minute ago in Appearance → Menus
        // is selectable immediately, and a menu that was deleted is shown as
        // unavailable rather than silently dropped.
        state.available_menus = Array.isArray(response.available_menus) ? response.available_menus : [];
        state.available_menu_locations = Array.isArray(response.available_menu_locations) ? response.available_menu_locations : [];
        return state;
    }

    // ── Render panel HTML ────────────────────────────────────────
    function renderPanel() {
        if (!panel) return;

        const sections = {};
        Object.keys(META).forEach(key => {
            const sec = META[key].section;
            if (!sections[sec]) sections[sec] = [];
            sections[sec].push({ key, label: META[key].label });
        });

        let html = `
            <div class="panel-header">
                <h2>Shell Config</h2>
                <span class="panel-tx-pill" data-tx="idle" id="agentshell-tx-pill" aria-live="polite">Idle</span>
                <button class="panel-close" aria-label="Close panel">&times;</button>
            </div>
            <div class="panel-section panel-tx-bar">
                <button type="button" class="panel-btn panel-btn-secondary" id="agentshell-snapshot-btn" title="Snapshot now">Snapshot now</button>
                <button type="button" class="panel-btn panel-btn-secondary" id="agentshell-discard-btn" disabled>Discard changes</button>
                <span class="panel-doctor" id="agentshell-doctor" aria-live="polite"></span>
            </div>
        `;

        // ── Zones section (editable composition builder) ──
        html += renderZonesSection();

        // ── Grouped design sections ──
        Object.keys(sections).forEach(secTitle => {
            html += `<div class="panel-section"><h3>${secTitle}</h3>`;
            sections[secTitle].forEach(({ key, label }) => {
                const metaInfo = META[key];
                const val = liveState[key] || DEFAULTS[key] || '';

                if (metaInfo.type === 'color') {
                    const safeColor = val.match(/^#[0-9a-fA-F]{3,8}$/) ? val : '#000000';
                    html += `
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">${metaInfo.label}</label>
                            <div class="color-field">
                                <input type="color" id="f-${cssVarToId(key)}" data-var="${key}" value="${safeColor}">
                                <input type="text" data-var="${key}" value="${val}" class="color-hex" maxlength="7" placeholder="#000000">
                            </div>
                        </div>`;
                } else if (metaInfo.type === 'shadow') {
                    const isPreset = SHADOW_PRESETS.some(opt => opt.value === val);
                    const optionsHtml = SHADOW_PRESETS.map(opt => `<option value="${escHtml(opt.value)}" ${val === opt.value ? 'selected' : ''}>${opt.label}</option>`).join('');
                    html += `
                        <div class="field-row">
                            <label for="f-shadow-preset">${metaInfo.label}</label>
                            <select id="f-shadow-preset" aria-describedby="shadow-help">
                                ${optionsHtml}
                                <option value="" ${!isPreset ? 'selected' : ''}>Custom</option>
                            </select>
                        </div>
                        <p id="shadow-help" class="field-help">Applies to the header, main, and footer. Preview changes before saving.</p>
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">Shadow CSS</label>
                            <input type="text" id="f-${cssVarToId(key)}" data-var="${key}" value="${escHtml(val)}" aria-describedby="shadow-help" spellcheck="false">
                        </div>`;
                } else if (metaInfo.type === 'select') {
                    const optionsHtml = metaInfo.options.map(opt => `<option value="${opt.value}" ${val === opt.value ? 'selected' : ''}>${opt.label}</option>`).join('');
                    html += `
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">${metaInfo.label}</label>
                            <select id="f-${cssVarToId(key)}" data-var="${key}">${optionsHtml}</select>
                        </div>`;
                } else {
                    html += `
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">${metaInfo.label}</label>
                            <input type="text" id="f-${cssVarToId(key)}" data-var="${key}" value="${val}">
                        </div>`;
                }
            });
            html += `</div>`;
        });

        // ── Custom Assets ──
        html += `
            <div class="panel-section"><h3>Custom Assets</h3>
                <div class="field-row" style="flex-direction:column;align-items:stretch;">
                    <label for="f-custom_css">Custom CSS</label>
                    <textarea id="f-custom_css" rows="5" placeholder="/* agents can add any CSS here */">${liveState.custom_css || ''}</textarea>
                </div>
                <div class="field-row" style="flex-direction:column;align-items:stretch;">
                    <label for="f-custom_js">Custom JavaScript</label>
                    <textarea id="f-custom_js" rows="5" placeholder="/* window.MyWidget = { init(el) { ... } }; */">${liveState.custom_js || ''}</textarea>
                </div>
            </div>`;

        // ── Save ──
        html += `
            <div class="panel-section">
                <button class="panel-save" id="agentshell-save">Save &amp; Reload</button>
            </div>`;

        panel.innerHTML = html;
        wireEvents();
    }

    // ── Zone area picker (nine areas, one open editor) ──────────
    // Which area of each structural zone is open in the editor. This is UI
    // state: it is not part of the configuration and is never saved.
    const zoneAreaSel = {};

    function areaDefs() {
        return Array.isArray(liveState.zone_areas) ? liveState.zone_areas : [];
    }

    function selectedArea(zone) {
        const ids = areaDefs().map(function(a) { return a.id; });
        const key = zone.id || 'zone';
        const resolved = zoneModel.resolve_area(areaDefs(), zoneAreaSel[key], 'middle-center');
        // An unchanged site opens on the middle row, centre column: where the
        // historical three slots live, so an operator sees the area they know.
        if (resolved && ids.indexOf(resolved) !== -1) {
            zoneAreaSel[key] = resolved;
        }
        return resolved;
    }

    function areaLabel(id) {
        const def = areaDefs().find(function(a) { return a.id === id; });
        return def ? def.label : id;
    }

    function renderAreaPicker(zoneIdx, zone, selected) {
        const defs  = areaDefs();
        const label = zone.label || zone.id;
        let html = `<div class="zone-area-picker" role="group" aria-label="${escAttr(label)} areas">`;

        defs.forEach(function(def) {
            const count = ((zone.slots || {})[def.id] || []).length;
            const isSel = def.id === selected;
            const name  = label + ' ' + def.label;
            html += `<button type="button" class="zone-area-btn${isSel ? ' is-selected' : ''}${count ? ' has-blocks' : ''}"`
                + ` data-zone-idx="${zoneIdx}" data-area="${escAttr(def.id)}"`
                + ` aria-pressed="${isSel ? 'true' : 'false'}"`
                + ` title="${escAttr(name)}">`
                + `<span class="zone-area-short" aria-hidden="true">${escHtml(def.short || def.id)}</span>`
                + `<span class="zone-area-count" aria-hidden="true">${count || ''}</span>`
                + `<span class="screen-reader-text">${escHtml(name)}${count ? ', ' + count + ' blocks' : ', empty'}</span>`
                + `</button>`;
        });

        html += `</div>`;

        // The expanded layout is a consequence of content, not a switch. Say
        // so where the operator is looking, so "why is my header three rows
        // tall?" has an answer on screen. The rule lives in the shared model,
        // which the Node tests exercise directly.
        const expanded = zoneModel.layout(defs, zone.slots || {}) === 'expanded';
        html += `<p class="zone-layout-note" data-layout="${expanded ? 'expanded' : 'standard'}">`
            + (expanded
                ? 'Nine-area layout: a top or bottom row has content.'
                : 'Three-slot layout. Add a block to a top or bottom area to expand it.')
            + `</p>`;

        return html;
    }

    function renderAreaEditor(zoneIdx, zone, area) {
        const blocks  = (zone.slots || {})[area] || [];
        const def     = areaDefs().find(function(a) { return a.id === area; });
        const row     = def ? def.row : '';
        const align   = (zone.slot_align || {})[area] || '';
        const ralign  = (zone.row_align || {})[row] || '';
        const zlabel  = zone.label || zone.id;
        const values  = (liveState.zone_align && liveState.zone_align.slot) || [];
        const rows    = (liveState.zone_align && liveState.zone_align.row) || [];

        let html = `<div class="zone-slot-builder" data-zone-idx="${zoneIdx}" data-slot="${escAttr(area)}">`;
        html += `<div class="slot-header">${escHtml(areaLabel(area))}<span class="zone-id-tag">${escHtml(area)}</span></div>`;
        html += `<div class="slot-align-row">`;

        html += `<label class="zb-align-label"><span>Align</span>`
            + `<select class="zb-align-select" data-zone-idx="${zoneIdx}" data-area="${escAttr(area)}"`
            + ` aria-label="${escAttr(zlabel + ' ' + areaLabel(area) + ' horizontal alignment')}">`
            + `<option value="">default</option>`
            + values.map(function(v) {
                return `<option value="${escAttr(v)}"${v === align ? ' selected' : ''}>${escHtml(v)}</option>`;
            }).join('')
            + `</select></label>`;

        html += `<label class="zb-align-label"><span>Row</span>`
            + `<select class="zb-row-align-select" data-zone-idx="${zoneIdx}" data-row="${escAttr(row)}"`
            + ` aria-label="${escAttr(zlabel + ' ' + row + ' row vertical alignment')}">`
            + `<option value="">default</option>`
            + rows.map(function(v) {
                return `<option value="${escAttr(v)}"${v === ralign ? ' selected' : ''}>${escHtml(v)}</option>`;
            }).join('')
            + `</select></label>`;

        html += `</div>`;
        html += `<div class="zone-blocks">`;
        if (blocks.length === 0) {
            html += `<div class="zone-block-empty">Empty</div>`;
        } else {
            blocks.forEach(function(block, blockIdx) {
                html += renderBlockItem(zoneIdx, area, blockIdx, block);
            });
        }
        html += `</div>`;
        html += renderAddBlockRow(zoneIdx, area);
        html += `</div>`;
        return html;
    }

    // Move a block between areas. The write itself is the same config the
    // server validates on save; this only moves the entry in the local draft,
    // and the rule for "how a move works" lives in the shared zone model.
    function moveBlockToArea(zoneIdx, fromArea, blockIdx, toArea) {
        const zone = liveState.zones[zoneIdx];
        if (!zone || !zone.slots || !toArea || toArea === fromArea) return;
        zone.slots = zoneModel.move_block(zone.slots, fromArea, toArea, blockIdx);
        // Follow the block, so the operator sees where it landed.
        zoneAreaSel[zone.id || 'zone'] = toArea;
        renderPanel();
    }

    // ── Render the Zones section ────────────────────────────────
    function renderZonesSection() {
        let html = `<div class="panel-section"><h3>Zones</h3>`;

        if (!zoneModel) {
            html += `<p class="zone-block-empty">Zone editor unavailable: configurator-state.js did not load.</p></div>`;
            return html;
        }

        if (!liveState.zones || !liveState.zones.length) {
            html += `<p style="font-size:0.8em;color:#888;margin:0;">No zones defined.</p></div>`;
            return html;
        }

        liveState.zones.forEach(function(zone, zoneIdx) {
            html += `<div class="zone-builder" data-zone-idx="${zoneIdx}">`;
            html += `<div class="zone-builder-header">${escHtml(zone.label || zone.id)} <span class="zone-id-tag">${escHtml(zone.id)}</span></div>`;

            const hasSlots = zone.slots && typeof zone.slots === 'object';

            if (hasSlots) {
                if (!areaDefs().length) {
                    // The server did not describe the grid (an old cached
                    // config response, or a theme/plugin version skew). Say so
                    // rather than offering an editor that writes nowhere.
                    html += `<p class="zone-block-empty">Zone editor unavailable: the server did not describe the slot grid.</p>`;
                } else {
                    // Nine areas in three rows. One area is edited at a time:
                    // the picker is the map, the panel below it is the
                    // selected area. Eighteen always-expanded panels would be
                    // unusable in a sidebar, and a 3×3 grid is the shape the
                    // operator is actually choosing between.
                    const area = selectedArea(zone);
                    html += renderAreaPicker(zoneIdx, zone, area);
                    html += renderAreaEditor(zoneIdx, zone, area);
                }
            } else {
                // Legacy composition: vertical list
                const composition = zone.composition || [];
                html += `<div class="zone-blocks">`;
                if (composition.length === 0) {
                    html += `<div class="zone-block-empty">Empty — this zone will not render.</div>`;
                } else {
                    composition.forEach(function(block, blockIdx) {
                        html += renderBlockItem(zoneIdx, null, blockIdx, block);
                    });
                }
                html += `</div>`;
                html += renderAddBlockRow(zoneIdx, null);
            }

            html += `</div>`;
        });

        html += `</div>`;
        return html;
    }

    // ── Render a single block item (type label + id + actions) ──
    function renderBlockItem(zoneIdx, slotKey, blockIdx, block) {
        const typeLabel = BLOCK_TYPES.find(t => t.value === block.type) || { label: block.type };
        let blockLabel = typeLabel.label;
        let detail = '';
        let areaSelect = '';
        const zone = liveState.zones[zoneIdx];
        if (block.type === 'wp_widget_area') {
            // Build a select listing the registered areas. The saved id is
            // preserved even when the area is no longer registered — a
            // synthetic "(unavailable)" option is appended so the value is
            // never silently erased.
            const registered = (availableWidgetAreas || []).filter(a => a && a.id);
            const savedId    = block.id || '';
            const savedKnown = registered.some(a => a.id === savedId);
            const areaSelId  = `zb-area-${zoneIdx}-${slotKey || 'c'}-${blockIdx}`;
            const zoneLabel  = zone ? (zone.label || zone.id) : 'zone';
            const slotLabel  = slotKey || 'composition';
            const opts = registered.map(a =>
                `<option value="${escAttr(a.id)}"${a.id === savedId ? ' selected' : ''}>${escHtml(a.name || a.id)}</option>`
            ).join('');
            const missing = (savedId && !savedKnown)
                ? `<option value="${escAttr(savedId)}" selected>${escHtml(savedId)} (unavailable)</option>`
                : '';
            areaSelect = `<label class="zb-area-label" for="${areaSelId}">Widget area</label>` +
                `<select id="${areaSelId}" class="zb-area-select" ` +
                `aria-label="Widget area for ${escAttr(zoneLabel)} ${escAttr(slotLabel)} block ${blockIdx + 1}" ` +
                `data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}" data-block-idx="${blockIdx}">` +
                `<option value="">— select area —</option>` +
                opts + missing +
                `</select>`;
        } else if (block.type === 'widget') {
            detail = escHtml(block.id || '');
        } else if (block.type === 'json_block') {
            detail = block.content ? escHtml(block.content.substring(0, 30)) + (block.content.length > 30 ? '…' : '') : '';
        } else if (block.type === 'wp_core') {
            const comp = coreComponents.find(c => c.id === block.id);
            blockLabel = escHtml(comp ? comp.name : (block.id || 'WP Core Component'));
            detail = block.id || '';
            if (block.id === 'nav_menu') {
                areaSelect = renderNavControls(zoneIdx, slotKey, blockIdx, block);
            }
        }

        const slotArr = slotKey ? (zone?.slots?.[slotKey] || []) : (zone?.composition || []);
        const canMoveUp   = blockIdx > 0;
        const canMoveDown = blockIdx < (slotArr.length - 1);

        // Moving between areas is a select rather than drag-and-drop: the
        // target list is nine named places, and a keyboard-reachable control
        // is the one that works for everybody.
        const areas = areaDefs();
        let moveSelect = '';
        if (slotKey && areas.length > 1) {
            // A plain-text name for the control: blockLabel may already carry
            // HTML escaping, and an escaped label inside an escaped attribute
            // reads as "&amp;amp;" to a screen reader.
            const moveName = (block.type === 'wp_core' || block.type === 'widget')
                ? String(block.id || blockLabel)
                : blockLabel;
            moveSelect = `<select class="zb-move-select" data-zone-idx="${zoneIdx}"`
                + ` data-slot="${escAttr(slotKey)}" data-block-idx="${blockIdx}"`
                + ` aria-label="${escAttr('Move ' + moveName + ' to another area')}" title="Move to another area">`
                + `<option value="">Move…</option>`
                + areas.filter(function(a) { return a.id !== slotKey; }).map(function(a) {
                    return `<option value="${escAttr(a.id)}">${escHtml(a.label)}</option>`;
                }).join('')
                + `</select>`;
        }

        let html = `<div class="zone-block-item" data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}" data-block-idx="${blockIdx}">`;
        html += `<div class="zone-block-info">`;
        html += `<span class="zone-block-type">${escHtml(blockLabel)}</span>`;
        if (areaSelect) {
            html += `<div class="zone-block-area">${areaSelect}</div>`;
        } else if (detail) {
            html += `<span class="zone-block-detail">${detail}</span>`;
        }
        html += `</div>`;
        html += `<div class="zone-block-actions">`;
        html += moveSelect;
        html += `<button class="zb-btn zb-move" data-action="moveUp" ${!canMoveUp ? 'disabled' : ''} title="Move up">&#9650;</button>`;
        html += `<button class="zb-btn zb-move" data-action="moveDown" ${!canMoveDown ? 'disabled' : ''} title="Move down">&#9660;</button>`;
        html += `<button class="zb-btn zb-delete" data-action="delete" title="Remove block">&#215;</button>`;
        html += `</div>`;
        html += `</div>`;
        return html;
    }

    // ── Navigation block controls ────────────────────────────────
    // A navigation block names a WordPress menu (or a registered location),
    // a presentation mode and an accessible label. WordPress remains the menu
    // editor; this is selection, not a second menu builder.
    function renderNavControls(zoneIdx, slotKey, blockIdx, block) {
        const menus     = liveState.available_menus || [];
        const locations = liveState.available_menu_locations || [];
        const modes     = ['horizontal', 'vertical', 'compact'];
        const savedMenu = block.menu || '';
        const savedLoc  = block.location || '';
        const mode      = block.mode || 'horizontal';
        const label     = block.label || '';
        const knownMenu = menus.some(m => m.slug === savedMenu);
        const knownLoc  = locations.some(l => l.slug === savedLoc);
        const uid       = `${zoneIdx}-${slotKey || 'c'}-${blockIdx}`;
        const data      = `data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}" data-block-idx="${blockIdx}"`;

        let html = '<div class="zb-nav-controls">';

        html += `<label class="zb-nav-label" for="zb-nav-menu-${uid}">Menu</label>`;
        html += `<select id="zb-nav-menu-${uid}" class="zb-nav-menu" ${data}>`;
        html += `<option value="">— none (use a location) —</option>`;
        menus.forEach(function(m) {
            html += `<option value="${escAttr(m.slug)}"${m.slug === savedMenu ? ' selected' : ''}>`
                + `${escHtml(m.name)}${m.count ? ' (' + m.count + ')' : ''}</option>`;
        });
        if (savedMenu && !knownMenu) {
            html += `<option value="${escAttr(savedMenu)}" selected>${escHtml(savedMenu)} (unavailable)</option>`;
        }
        html += `</select>`;

        html += `<label class="zb-nav-label" for="zb-nav-loc-${uid}">Location</label>`;
        html += `<select id="zb-nav-loc-${uid}" class="zb-nav-location" ${data}>`;
        html += `<option value="">— none (use a menu) —</option>`;
        locations.forEach(function(l) {
            const suffix = l.menu_id ? '' : ' (no menu assigned)';
            html += `<option value="${escAttr(l.slug)}"${l.slug === savedLoc ? ' selected' : ''}>`
                + `${escHtml(l.label || l.slug)}${escHtml(suffix)}</option>`;
        });
        if (savedLoc && !knownLoc) {
            html += `<option value="${escAttr(savedLoc)}" selected>${escHtml(savedLoc)} (not registered)</option>`;
        }
        html += `</select>`;

        html += `<label class="zb-nav-label" for="zb-nav-mode-${uid}">Mode</label>`;
        html += `<select id="zb-nav-mode-${uid}" class="zb-nav-mode" ${data}>`;
        modes.forEach(function(m) {
            html += `<option value="${escAttr(m)}"${m === mode ? ' selected' : ''}>${escHtml(m)}</option>`;
        });
        html += `</select>`;

        html += `<label class="zb-nav-label" for="zb-nav-label-${uid}">Label</label>`;
        html += `<input id="zb-nav-label-${uid}" class="zb-nav-label-input" type="text" ${data}`
            + ` maxlength="100" value="${escAttr(label)}"`
            + ` placeholder="Accessible name (defaults to the menu name)">`;

        if ((savedMenu && !knownMenu) || (savedLoc && !knownLoc)) {
            html += `<p class="zb-nav-warning">This block references something this site does not have. `
                + `Pick an existing menu or location; the saved reference is kept until you do.</p>`;
        }

        html += `</div>`;
        return html;
    }

    function navBlockFor(el) {
        const zone = liveState.zones[parseInt(el.dataset.zoneIdx, 10)];
        if (!zone) return null;
        const slotKey = el.dataset.slot || null;
        const arr = slotKey ? (zone.slots && zone.slots[slotKey]) || [] : (zone.composition || []);
        return arr[parseInt(el.dataset.blockIdx, 10)] || null;
    }

    function setNavField(el, field, value, rerender) {
        const block = navBlockFor(el);
        if (!block) return;
        if (value === '') {
            delete block[field];
        } else {
            block[field] = value;
        }
        // Menu and location are alternatives, not a precedence order: choosing
        // one clears the other so the block can never carry an ambiguous pair.
        if (field === 'menu' && value) delete block.location;
        if (field === 'location' && value) delete block.menu;
        if (rerender) renderPanel();
    }

    // ── Render the + Add Block row ────────────────────────────────
    function renderAddBlockRow(zoneIdx, slotKey) {
        let html = `<div class="zone-add-block" data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}">`;
        html += `<select class="zb-type-select" data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}">`;
        html += `<option value="">+ Add block…</option>`;
        BLOCK_TYPES.forEach(function(t) {
            html += `<option value="${t.value}">${t.label}</option>`;
        });
        html += `</select>`;

        // Secondary selector for widget — hidden until widget type selected
        const widgetOpts = availableWidgets.map(w =>
            `<option value="${escAttr(w.id)}">${escHtml(w.name || w.id)}</option>`
        ).join('');
        html += `
            <select class="zb-widget-select" data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}" style="display:none">
                <option value="">Select widget…</option>
                ${widgetOpts}
            </select>`;

        // Secondary selector for wp_core — hidden until wp_core type selected
        const coreOpts = coreComponents.map(c =>
            `<option value="${escAttr(c.id)}">${escHtml(c.name || c.id)}</option>`
        ).join('');
        html += `
            <select class="zb-core-select" data-zone-idx="${zoneIdx}" data-slot="${slotKey || ''}" style="display:none">
                <option value="">Select component…</option>
                ${coreOpts}
            </select>`;

        html += `</div>`;
        return html;
    }

    // ── Wire all interactive events ─────────────────────────────
    function wireEvents() {
        panel.querySelector('.panel-close')?.addEventListener('click', closePanel);

        panel.querySelector('#f-shadow-preset')?.addEventListener('change', e => {
            const input = panel.querySelector('[data-var="--theme-shadow"]');
            // Selecting Custom keeps the current value until it is edited.
            if (e.target.value) {
                input.value = e.target.value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                input.focus();
            }
        });

        // All CSS variable inputs — live preview
        panel.querySelectorAll('[data-var]').forEach(el => {
            el.addEventListener('input', onVarChange);
            if (el.tagName === 'SELECT') {
                el.addEventListener('change', onVarChange);
            }
        });

        function onVarChange(e) {
            const key = e.target.dataset.var;
            let val = e.target.value;

            if (key === '--theme-shadow') {
                const preset = panel.querySelector('#f-shadow-preset');
                preset.value = SHADOW_PRESETS.some(opt => opt.value === val) ? val : '';
            }

            const colorPicker = panel.querySelector(`input[type="color"][data-var="${key}"]`);
            const hexInput    = panel.querySelector(`.color-hex[data-var="${key}"]`);

            if (e.target.type === 'color') {
                val = e.target.value;
                if (hexInput) hexInput.value = val;
            } else if (e.target.classList.contains('color-hex')) {
                val = val.startsWith('#') ? val : '#' + val;
                if (/^#[0-9a-fA-F]{6}$/.test(val) && colorPicker) {
                    colorPicker.value = val;
                }
            }

            liveState[key] = val;
            writeCssVar(key, val);
        }

        // Custom CSS and JS textareas
        panel.querySelector('#f-custom_css')?.addEventListener('input', e => {
            liveState.custom_css = e.target.value;
        });
        panel.querySelector('#f-custom_js')?.addEventListener('input', e => {
            liveState.custom_js = e.target.value;
        });

        // ── Zone block actions (Up / Down / Delete) ──
        panel.querySelectorAll('.zone-block-actions').forEach(container => {
            container.addEventListener('click', e => {
                const btn = e.target.closest('.zb-btn');
                if (!btn || btn.disabled) return;

                const item     = btn.closest('.zone-block-item');
                const zoneIdx  = parseInt(item.dataset.zoneIdx, 10);
                const slotKey  = item.dataset.slot || null; // null = composition
                const blockIdx = parseInt(item.dataset.blockIdx, 10);
                const action   = btn.dataset.action;

                const zone = liveState.zones[zoneIdx];
                const targetArr = slotKey
                    ? (zone.slots?.[slotKey] || [])
                    : (zone.composition || []);

                if (action === 'delete') {
                    targetArr.splice(blockIdx, 1);
                    renderPanel();
                } else if (action === 'moveUp' && blockIdx > 0) {
                    [targetArr[blockIdx - 1], targetArr[blockIdx]] = [targetArr[blockIdx], targetArr[blockIdx - 1]];
                    renderPanel();
                } else if (action === 'moveDown') {
                    if (blockIdx < targetArr.length - 1) {
                        [targetArr[blockIdx], targetArr[blockIdx + 1]] = [targetArr[blockIdx + 1], targetArr[blockIdx]];
                        renderPanel();
                    }
                }
            });
        });

        // ── Area picker (which of the nine areas is open) ──
        panel.querySelectorAll('.zone-area-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const zoneIdx = parseInt(btn.dataset.zoneIdx, 10);
                const zone    = liveState.zones[zoneIdx];
                if (!zone) return;
                zoneAreaSel[zone.id || 'zone'] = btn.dataset.area;
                renderPanel();
                // Keep keyboard focus in the picker after the re-render: a
                // control that loses focus on use is a control that cannot be
                // operated without a mouse.
                const again = panel.querySelector(
                    `.zone-area-btn[data-zone-idx="${zoneIdx}"][data-area="${btn.dataset.area}"]`
                );
                if (again) again.focus();
            });
        });

        // ── Move a block to another area ──
        panel.querySelectorAll('.zb-move-select').forEach(sel => {
            sel.addEventListener('change', () => {
                if (!sel.value) return;
                moveBlockToArea(
                    parseInt(sel.dataset.zoneIdx, 10),
                    sel.dataset.slot,
                    parseInt(sel.dataset.blockIdx, 10),
                    sel.value
                );
            });
        });

        // ── Alignment: per area (horizontal) and per row (vertical) ──
        panel.querySelectorAll('.zb-align-select').forEach(sel => {
            sel.addEventListener('change', () => {
                const zone = liveState.zones[parseInt(sel.dataset.zoneIdx, 10)];
                if (!zone) return;
                const area = sel.dataset.area;
                if (!zone.slot_align) zone.slot_align = {};
                if (sel.value) {
                    zone.slot_align[area] = sel.value;
                } else {
                    delete zone.slot_align[area];
                }
                renderPanel();
            });
        });

        panel.querySelectorAll('.zb-row-align-select').forEach(sel => {
            sel.addEventListener('change', () => {
                const zone = liveState.zones[parseInt(sel.dataset.zoneIdx, 10)];
                if (!zone) return;
                const row = sel.dataset.row;
                if (!zone.row_align) zone.row_align = {};
                if (sel.value) {
                    zone.row_align[row] = sel.value;
                } else {
                    delete zone.row_align[row];
                }
                renderPanel();
            });
        });

        // ── Navigation block controls ──
        panel.querySelectorAll('.zb-nav-menu').forEach(sel => {
            sel.addEventListener('change', () => setNavField(sel, 'menu', sel.value, true));
        });
        panel.querySelectorAll('.zb-nav-location').forEach(sel => {
            sel.addEventListener('change', () => setNavField(sel, 'location', sel.value, true));
        });
        panel.querySelectorAll('.zb-nav-mode').forEach(sel => {
            sel.addEventListener('change', () => setNavField(sel, 'mode', sel.value, true));
        });
        panel.querySelectorAll('.zb-nav-label-input').forEach(inp => {
            // Update as it is typed, without re-rendering: a re-render would
            // take the caret away mid-word.
            inp.addEventListener('input', () => setNavField(inp, 'label', inp.value, false));
        });

        // ── Add block type selector ──
        panel.querySelectorAll('.zb-type-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx  = parseInt(sel.dataset.zoneIdx, 10);
                const slotKey  = sel.dataset.slot || null;
                const type     = sel.value;
                const widgetSel = panel.querySelector(`.zb-widget-select[data-zone-idx="${zoneIdx}"][data-slot="${slotKey || ''}"]`);
                const coreSel   = panel.querySelector(`.zb-core-select[data-zone-idx="${zoneIdx}"][data-slot="${slotKey || ''}"]`);

                // Hide both secondary selectors by default
                if (widgetSel) widgetSel.style.display = 'none';
                if (coreSel)   coreSel.style.display   = 'none';

                if (!type) { return; }

                if (type === 'widget') {
                    if (widgetSel) {
                        widgetSel.style.display = '';
                        widgetSel.focus();
                    }
                } else if (type === 'wp_core') {
                    if (coreSel) {
                        coreSel.style.display = '';
                        coreSel.focus();
                    }
                } else {
                    // Immediately add blocks that don't need a secondary selector
                    addBlockToZone(zoneIdx, slotKey, { type });
                    sel.value = '';
                }
            });
        });

        // ── Add wp_core block when secondary selector changes ──
        panel.querySelectorAll('.zb-core-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx  = parseInt(sel.dataset.zoneIdx, 10);
                const slotKey  = sel.dataset.slot || null;
                const coreId   = sel.value;
                if (!coreId) return;

                addBlockToZone(zoneIdx, slotKey, { type: 'wp_core', id: coreId });
                sel.value = '';
                sel.style.display = 'none';

                const typeSel = panel.querySelector(`.zb-type-select[data-zone-idx="${zoneIdx}"][data-slot="${slotKey || ''}"]`);
                if (typeSel) typeSel.value = '';
            });
        });

        // ── Add widget block when secondary selector changes ──
        panel.querySelectorAll('.zb-widget-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx  = parseInt(sel.dataset.zoneIdx, 10);
                const slotKey  = sel.dataset.slot || null;
                const widgetId = sel.value;
                if (!widgetId) return;

                addBlockToZone(zoneIdx, slotKey, { type: 'widget', id: widgetId });
                sel.value = '';
                sel.style.display = 'none';

                // Reset the type selector too
                const typeSel = panel.querySelector(`.zb-type-select[data-zone-idx="${zoneIdx}"][data-slot="${slotKey || ''}"]`);
                if (typeSel) typeSel.value = '';
            });
        });

        // ── Edit a wp_widget_area block's id (select stays visible inline) ──
        // The change handler updates the block's id to whatever the user
        // picked, including the empty string for the placeholder — the
        // displayed and saved values stay consistent. Picking the
        // "(unavailable)" option keeps the saved id (its value IS the
        // saved id), so the synthetic option re-renders selected.
        panel.querySelectorAll('.zb-area-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx  = parseInt(sel.dataset.zoneIdx, 10);
                const slotKey  = sel.dataset.slot || null;
                const blockIdx = parseInt(sel.dataset.blockIdx, 10);
                const zone     = liveState.zones[zoneIdx];
                if (!zone) return;
                const targetArr = slotKey
                    ? (zone.slots?.[slotKey] || [])
                    : (zone.composition || []);
                if (!targetArr[blockIdx]) return;
                targetArr[blockIdx].id = sel.value;
                // Re-render so the synthetic (unavailable) option is
                // re-inserted when needed, and the select reflects the
                // just-saved value across reloads.
                renderPanel();
            });
        });

        // Save button
        panel.querySelector('#agentshell-save')?.addEventListener('click', saveConfig);

        // Discard changes
        panel.querySelector('#agentshell-discard-btn')?.addEventListener('click', discardChanges);

        // Snapshot now
        panel.querySelector('#agentshell-snapshot-btn')?.addEventListener('click', async () => {
            const btn = panel.querySelector('#agentshell-snapshot-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Snapshoting…'; }
            try {
                const snap = await snapshotNow();
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Snapshotted as ' + (snap.name || '?');
                    setTimeout(() => { btn.textContent = 'Snapshot now'; }, 4000);
                }
            } catch (e) {
                if (btn) { btn.disabled = false; btn.textContent = 'Snapshot now'; }
                alert('Snapshot failed: ' + e.message);
            }
        });

        // Apply tx state to UI
        updateTxPill();
    }

    // ── Add a block to a zone's composition or slot ─────────────
    function addBlockToZone(zoneIdx, slotKey, block) {
        const zone = liveState.zones[zoneIdx];
        if (slotKey) {
            if (!zone.slots) zone.slots = {};
            if (!zone.slots[slotKey]) zone.slots[slotKey] = [];
            zone.slots[slotKey].push(Object.assign({}, block));
        } else {
            if (!zone.composition) zone.composition = [];
            zone.composition.push(Object.assign({}, block));
        }
        renderPanel();
    }

    // ── Load config from REST API ──────────────────────────────
    async function loadConfig() {
        try {
            const [configResp, txResp] = await Promise.all([
                fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                    headers: { 'X-WP-Nonce': AgentShellConfig.nonce }
                }),
                fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/transaction', {
                    headers: { 'X-WP-Nonce': AgentShellConfig.nonce }
                }),
            ]);
            if (!configResp.ok) throw new Error('Failed to load config: ' + configResp.status);
            const data = await configResp.json();
            liveState = buildState(data);
            availableWidgets     = data.available_widgets     || [];
            coreComponents       = data.core_components       || [];
            availableWidgetAreas = data.available_widget_areas || [];
            if (txResp.ok) {
                txInfo = await txResp.json();
                if (window.AgentshellConfigState) {
                    window.AgentshellConfigState.set_tx_snapshot(txInfo);
                    txState = window.AgentshellConfigState.get_state().mode;
                }
            }
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    async function loadTx() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/transaction', {
                headers: { 'X-WP-Nonce': AgentShellConfig.nonce }
            });
            if (!resp.ok) return;
            txInfo = await resp.json();
            if (window.AgentshellConfigState) {
                window.AgentshellConfigState.set_tx_snapshot(txInfo);
                txState = window.AgentshellConfigState.get_state().mode;
            }
            updateTxPill();
        } catch (e) {
            console.error('AgentShell: Failed to load tx state', e);
        }
    }

    function updateTxPill() {
        const pill = panel && panel.querySelector('#agentshell-tx-pill');
        if (!pill || !window.AgentshellConfigState) return;
        const label = window.AgentshellConfigState.status_label();
        const mode = window.AgentshellConfigState.get_state().mode;
        pill.textContent = label;
        pill.setAttribute('data-tx', mode);
        const saveBtn = panel.querySelector('#agentshell-save');
        const discardBtn = panel.querySelector('#agentshell-discard-btn');
        if (saveBtn) { saveBtn.disabled = !window.AgentshellConfigState.can_save(); }
        if (discardBtn) {
            discardBtn.disabled = mode !== 'editing_open';
        }
    }

    async function beginTx(label) {
        const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/transaction', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': AgentShellConfig.nonce,
            },
            body: JSON.stringify({ label: label || 'configurator edit' }),
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.message || 'Could not begin transaction (' + resp.status + ')');
        }
        return resp.json();
    }

    async function commitTx() {
        const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/transaction/commit', {
            method: 'POST',
            headers: { 'X-WP-Nonce': AgentShellConfig.nonce },
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.message || 'Could not commit transaction (' + resp.status + ')');
        }
        return resp.json();
    }

    async function rollbackTx() {
        const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/transaction', {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': AgentShellConfig.nonce },
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.message || 'Could not rollback transaction (' + resp.status + ')');
        }
        return resp.json();
    }

    async function snapshotNow() {
        const name  = 'snapshot-' + Math.floor(Date.now() / 1000);
        const label = 'configurator snapshot ' + new Date().toISOString();
        const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/snapshot', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': AgentShellConfig.nonce,
            },
            body: JSON.stringify({ name, label }),
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.message || 'Could not create snapshot (' + resp.status + ')');
        }
        return resp.json();
    }

    async function validateConfig(config) {
        // Call the existing MCP tool via the standard MCP endpoint.
        // We rely on the daemon's authenticated path; the response
        // shape mirrors agentshell_validate.
        const resp = await fetch(AgentShellConfig.restUrl + 'agentshell-mcp/v1/mcp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': AgentShellConfig.nonce,
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: 1,
                method: 'tools/call',
                params: { name: 'agentshell_validate', arguments: config || {} },
            }),
        });
        if (!resp.ok) return null;
        const body = await resp.json().catch(() => null);
        return body && body.result ? body.result : null;
    }

    // ── Save config to REST API ────────────────────────────────
    async function saveConfig() {
        if (window.AgentshellConfigState && !window.AgentshellConfigState.can_save()) {
            alert('Cannot save while another actor owns an open transaction.');
            return;
        }

        const payload = {};
        Object.keys(DEFAULTS).forEach(key => {
            payload[key] = liveState[key] || DEFAULTS[key];
        });
        payload.custom_css = liveState.custom_css || '';
        payload.custom_js  = liveState.custom_js  || '';
        payload.zones      = liveState.zones      || [];

        try {
            // If no tx is open, open one before the PUT so the write is
            // staged and the operator can rollback from the panel.
            let opened = null;
            if (window.AgentshellConfigState && window.AgentshellConfigState.get_state().mode === 'idle') {
                opened = await beginTx('configurator save');
                if (window.AgentshellConfigState.begin) {
                    window.AgentshellConfigState.begin('configurator save');
                }
            }

            const putResp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AgentShellConfig.nonce
                },
                body: JSON.stringify(payload)
            });
            if (!putResp.ok) {
                if (opened) await rollbackTx();
                throw new Error('Save failed: ' + putResp.status);
            }

            await commitTx();
            if (window.AgentshellConfigState && window.AgentshellConfigState.commit) {
                window.AgentshellConfigState.commit(opened && opened.id);
            }

            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to save config', e);
            alert('Failed to save. Check console for details.');
        }
    }

    async function discardChanges() {
        if (window.AgentshellConfigState && window.AgentshellConfigState.get_state().mode !== 'editing_open') {
            return;
        }
        if (!confirm('Discard staged changes? The open transaction will be rolled back.')) {
            return;
        }
        try {
            await rollbackTx();
            if (window.AgentshellConfigState && window.AgentshellConfigState.rollback) {
                window.AgentshellConfigState.rollback();
            }
            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to discard changes', e);
            alert('Failed to discard. Check console for details.');
        }
    }

    // ── Utilities ───────────────────────────────────────────────
    function cssVarToId(name) {
        return name.replace(/[^a-zA-Z0-9]/g, '_').replace(/^_+/, '');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return String(str)
            .replace(/"/g, '&quot;');
    }

    // ── Bootstrap ───────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { initElements(); loadConfig(); });
    } else {
        initElements();
        loadConfig();
    }
})();
