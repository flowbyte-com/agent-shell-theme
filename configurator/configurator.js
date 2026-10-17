(function() {
    'use strict';

    // Pure state model lives in a sibling file so Node tests can
    // exercise it without a browser. The model handles idle / editing_open
    // / locked transitions; the configurator wires the model to fetch
    // calls and DOM events.
    if ( typeof window !== 'undefined' && window.AgentshellConfiguratorState ) {
        window.AgentshellConfigState = window.AgentshellConfiguratorState.make_state_model();
    }

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

    // ── Render the Zones section ────────────────────────────────
    function renderZonesSection() {
        let html = `<div class="panel-section"><h3>Zones</h3>`;

        if (!liveState.zones || !liveState.zones.length) {
            html += `<p style="font-size:0.8em;color:#888;margin:0;">No zones defined.</p></div>`;
            return html;
        }

        liveState.zones.forEach(function(zone, zoneIdx) {
            html += `<div class="zone-builder" data-zone-idx="${zoneIdx}">`;
            html += `<div class="zone-builder-header">${escHtml(zone.label || zone.id)} <span class="zone-id-tag">${escHtml(zone.id)}</span></div>`;

            const hasSlots = zone.slots && typeof zone.slots === 'object';

            if (hasSlots) {
                // Tri-slot layout: left / center / right
                ['left', 'center', 'right'].forEach(function(slotKey) {
                    const slotBlocks = zone.slots[slotKey] || [];
                    html += `<div class="zone-slot-builder" data-zone-idx="${zoneIdx}" data-slot="${slotKey}">`;
                    html += `<div class="slot-header">${slotKey.charAt(0).toUpperCase() + slotKey.slice(1)}</div>`;
                    html += `<div class="zone-blocks">`;
                    if (slotBlocks.length === 0) {
                        html += `<div class="zone-block-empty">Empty</div>`;
                    } else {
                        slotBlocks.forEach(function(block, blockIdx) {
                            html += renderBlockItem(zoneIdx, slotKey, blockIdx, block);
                        });
                    }
                    html += `</div>`;
                    html += renderAddBlockRow(zoneIdx, slotKey);
                    html += `</div>`;
                });
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
        }

        const slotArr = slotKey ? (zone?.slots?.[slotKey] || []) : (zone?.composition || []);
        const canMoveUp   = blockIdx > 0;
        const canMoveDown = blockIdx < (slotArr.length - 1);

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
        html += `<button class="zb-btn zb-move" data-action="moveUp" ${!canMoveUp ? 'disabled' : ''} title="Move up">&#9650;</button>`;
        html += `<button class="zb-btn zb-move" data-action="moveDown" ${!canMoveDown ? 'disabled' : ''} title="Move down">&#9660;</button>`;
        html += `<button class="zb-btn zb-delete" data-action="delete" title="Remove block">&#215;</button>`;
        html += `</div>`;
        html += `</div>`;
        return html;
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
