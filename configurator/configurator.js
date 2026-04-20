(function() {
    'use strict';

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

    const META = {
        '--zone-header-layout': { type: 'select', section: 'Layout & Alignment', label: 'Header Layout', options: [{value: 'flex-start', label: 'Left'}, {value: 'center', label: 'Center'}, {value: 'space-between', label: 'Space Between'}] },
        '--zone-main-align': { type: 'select', section: 'Layout & Alignment', label: 'Main Content Align', options: [{value: 'flex-start', label: 'Top'}, {value: 'center', label: 'Middle'}] },
        '--zone-footer-layout': { type: 'select', section: 'Layout & Alignment', label: 'Footer Layout', options: [{value: 'flex-start', label: 'Left'}, {value: 'center', label: 'Center'}, {value: 'space-between', label: 'Space Between'}] },

        '--font-base': { type: 'text', section: 'Typography & Geometry', label: 'Base Font' },
        '--font-mono': { type: 'text', section: 'Typography & Geometry', label: 'Mono Font' },
        '--spacing-base': { type: 'text', section: 'Typography & Geometry', label: 'Base Spacing' },

        '--border-width': { type: 'text', section: 'Borders & Shadows', label: 'Global Border Width' },
        '--border-style': { type: 'select', section: 'Borders & Shadows', label: 'Border Style', options: [{value: 'solid', label: 'Solid'}, {value: 'dashed', label: 'Dashed'}, {value: 'none', label: 'None'}] },
        '--theme-shadow': { type: 'text', section: 'Borders & Shadows', label: 'Box Shadow' },

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
    let availableWidgets = []; // agent-defined widgets from blocks plugin
    let coreComponents   = []; // WP core components (site_title, nav_menu, etc.)

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
                <button class="panel-close" aria-label="Close panel">&times;</button>
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
            const composition = zone.composition || [];
            html += `<div class="zone-builder" data-zone-idx="${zoneIdx}">`;
            html += `<div class="zone-builder-header">${escHtml(zone.label || zone.id)} <span class="zone-id-tag">${escHtml(zone.id)}</span></div>`;

            // Block list
            html += `<div class="zone-blocks">`;
            if (composition.length === 0) {
                html += `<div class="zone-block-empty">Empty — this zone will not render.</div>`;
            } else {
                composition.forEach(function(block, blockIdx) {
                    html += renderBlockItem(zoneIdx, blockIdx, block);
                });
            }
            html += `</div>`;

            // Add block row
            html += renderAddBlockRow(zoneIdx);
            html += `</div>`;
        });

        html += `</div>`;
        return html;
    }

    // ── Render a single block item (type label + id + actions) ──
    function renderBlockItem(zoneIdx, blockIdx, block) {
        const typeLabel = BLOCK_TYPES.find(t => t.value === block.type) || { label: block.type };
        let detail = '';
        if (block.type === 'widget' || block.type === 'wp_widget_area') {
            detail = escHtml(block.id || '');
        } else if (block.type === 'json_block') {
            detail = block.content ? escHtml(block.content.substring(0, 30)) + (block.content.length > 30 ? '…' : '') : '';
        }
        const canMoveUp   = blockIdx > 0;
        const canMoveDown = blockIdx < (liveState.zones[zoneIdx].composition.length - 1);

        let html = `<div class="zone-block-item" data-zone-idx="${zoneIdx}" data-block-idx="${blockIdx}">`;
        html += `<div class="zone-block-info">`;
        html += `<span class="zone-block-type">${escHtml(typeLabel.label)}</span>`;
        if (detail) {
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
    function renderAddBlockRow(zoneIdx) {
        let html = `<div class="zone-add-block" data-zone-idx="${zoneIdx}">`;
        html += `<select class="zb-type-select" data-zone-idx="${zoneIdx}">`;
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
            <select class="zb-widget-select" data-zone-idx="${zoneIdx}" style="display:none">
                <option value="">Select widget…</option>
                ${widgetOpts}
            </select>`;

        // Secondary selector for wp_core — hidden until wp_core type selected
        const coreOpts = coreComponents.map(c =>
            `<option value="${escAttr(c.id)}">${escHtml(c.name || c.id)}</option>`
        ).join('');
        html += `
            <select class="zb-core-select" data-zone-idx="${zoneIdx}" style="display:none">
                <option value="">Select component…</option>
                ${coreOpts}
            </select>`;

        html += `</div>`;
        return html;
    }

    // ── Wire all interactive events ─────────────────────────────
    function wireEvents() {
        panel.querySelector('.panel-close')?.addEventListener('click', closePanel);

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

                const item = btn.closest('.zone-block-item');
                const zoneIdx = parseInt(item.dataset.zoneIdx, 10);
                const blockIdx = parseInt(item.dataset.blockIdx, 10);
                const action = btn.dataset.action;

                if (action === 'delete') {
                    liveState.zones[zoneIdx].composition.splice(blockIdx, 1);
                    renderPanel();
                } else if (action === 'moveUp' && blockIdx > 0) {
                    const comp = liveState.zones[zoneIdx].composition;
                    [comp[blockIdx - 1], comp[blockIdx]] = [comp[blockIdx], comp[blockIdx - 1]];
                    renderPanel();
                } else if (action === 'moveDown') {
                    const comp = liveState.zones[zoneIdx].composition;
                    if (blockIdx < comp.length - 1) {
                        [comp[blockIdx], comp[blockIdx + 1]] = [comp[blockIdx + 1], comp[blockIdx]];
                        renderPanel();
                    }
                }
            });
        });

        // ── Add block type selector ──
        panel.querySelectorAll('.zb-type-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx = parseInt(sel.dataset.zoneIdx, 10);
                const type    = sel.value;
                const widgetSel = panel.querySelector(`.zb-widget-select[data-zone-idx="${zoneIdx}"]`);
                const coreSel   = panel.querySelector(`.zb-core-select[data-zone-idx="${zoneIdx}"]`);

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
                    addBlockToZone(zoneIdx, { type });
                    sel.value = '';
                }
            });
        });

        // ── Add wp_core block when secondary selector changes ──
        panel.querySelectorAll('.zb-core-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx  = parseInt(sel.dataset.zoneIdx, 10);
                const coreId   = sel.value;
                if (!coreId) return;

                addBlockToZone(zoneIdx, { type: 'wp_core', id: coreId });
                sel.value = '';
                sel.style.display = 'none';

                const typeSel = panel.querySelector(`.zb-type-select[data-zone-idx="${zoneIdx}"]`);
                if (typeSel) typeSel.value = '';
            });
        });

        // ── Add widget block when secondary selector changes ──
        panel.querySelectorAll('.zb-widget-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const zoneIdx = parseInt(sel.dataset.zoneIdx, 10);
                const widgetId = sel.value;
                if (!widgetId) return;

                addBlockToZone(zoneIdx, { type: 'widget', id: widgetId });
                sel.value = '';
                sel.style.display = 'none';

                // Reset the type selector too
                const typeSel = panel.querySelector(`.zb-type-select[data-zone-idx="${zoneIdx}"]`);
                if (typeSel) typeSel.value = '';
            });
        });

        // Save button
        panel.querySelector('#agentshell-save')?.addEventListener('click', saveConfig);
    }

    // ── Add a block to a zone's composition ─────────────────────
    function addBlockToZone(zoneIdx, block) {
        if (!liveState.zones[zoneIdx].composition) {
            liveState.zones[zoneIdx].composition = [];
        }
        liveState.zones[zoneIdx].composition.push(Object.assign({}, block));
        renderPanel();
    }

    // ── Load config from REST API ──────────────────────────────
    async function loadConfig() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                headers: { 'X-WP-Nonce': AgentShellConfig.nonce }
            });
            if (!resp.ok) throw new Error('Failed to load config: ' + resp.status);
            const data = await resp.json();
            liveState = buildState(data);
            availableWidgets = data.available_widgets || [];
            coreComponents   = data.core_components || [];
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    // ── Save config to REST API ────────────────────────────────
    async function saveConfig() {
        const payload = {};
        Object.keys(DEFAULTS).forEach(key => {
            payload[key] = liveState[key] || DEFAULTS[key];
        });
        payload.custom_css = liveState.custom_css || '';
        payload.custom_js  = liveState.custom_js  || '';
        payload.zones      = liveState.zones      || [];

        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AgentShellConfig.nonce
                },
                body: JSON.stringify(payload)
            });
            if (!resp.ok) throw new Error('Save failed: ' + resp.status);
            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to save config', e);
            alert('Failed to save. Check console for details.');
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
