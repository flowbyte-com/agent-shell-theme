(function() {
    'use strict';

    const DEFAULTS = {
        // Layout
        sidebar_enabled: false,
        // Typography & Geometry
        '--font-base':     'system-ui, -apple-system, sans-serif',
        '--font-mono':     'monospace',
        '--spacing-base':  '1rem',
        '--radius-base':   '8px',
        // Global Theme
        '--theme-bg':       '#ffffff',
        '--theme-surface':  '#f4f4f5',
        '--theme-text':     '#18181b',
        '--theme-accent':   '#3b82f6',
        '--theme-border':   '#e4e4e7',
        // Shell Zones
        '--theme-header-bg':  '#1a1a2e',
        '--theme-footer-bg':  '#16213e',
    };

    const META = {
        '--font-base':     { section: 'Typography & Geometry', label: 'Base Font' },
        '--font-mono':     { section: 'Typography & Geometry', label: 'Mono Font' },
        '--spacing-base':  { section: 'Typography & Geometry', label: 'Base Spacing' },
        '--radius-base':   { section: 'Typography & Geometry', label: 'Border Radius' },
        '--theme-bg':      { section: 'Global Theme', label: 'Background' },
        '--theme-surface': { section: 'Global Theme', label: 'Surface' },
        '--theme-text':    { section: 'Global Theme', label: 'Text' },
        '--theme-accent':  { section: 'Global Theme', label: 'Accent' },
        '--theme-border':  { section: 'Global Theme', label: 'Border' },
        '--theme-header-bg': { section: 'Shell Zones', label: 'Header Background' },
        '--theme-footer-bg': { section: 'Shell Zones', label: 'Footer Background' },
    };

    let panel     = null;
    let trigger   = null;
    let initDone  = false;
    // Holds live values: { sidebar_enabled: bool, '--css-var': value, ... }
    let liveState = {};

    // ── DOM init ───────────────────────────────────────────────
    function initElements() {
        if (initDone) return;
        initDone = true;
        panel   = document.getElementById('agentshell-config-panel');
        trigger = document.getElementById('agentshell-config-trigger');
        if (trigger) trigger.addEventListener('click', togglePanel);
    }

    // ── Read current value of a CSS custom property from :root ──
    function readCssVar(name) {
        return getComputedStyle(document.documentElement)
            .getPropertyValue(name)
            .trim() || DEFAULTS[name];
    }

    // ── Write a CSS custom property live to :root ──
    function writeCssVar(name, value) {
        document.documentElement.style.setProperty(name, value);
    }

    // ── Toggle panel ────────────────────────────────────────────
    function togglePanel() {
        if (!panel) return;
        panel.classList.toggle('is-open');
        document.body.classList.toggle('config-panel-open', panel.classList.contains('is-open'));
    }

    // ── Close panel ─────────────────────────────────────────────
    function closePanel() {
        if (!panel) return;
        panel.classList.remove('is-open');
        document.body.classList.remove('config-panel-open');
    }

    // ── Sync body class with sidebar state ──
    function syncSidebarBody(enabled) {
        document.body.classList.toggle('sidebar-enabled', !!enabled);
    }

    // ── Build the field value map from REST API config + CSS fallback ──
    // Priority: config value (just loaded from DB) > computed CSS > DEFAULTS
    function buildState(config) {
        const state = { sidebar_enabled: !!config.sidebar_enabled };
        Object.keys(DEFAULTS).forEach(key => {
            if (key !== 'sidebar_enabled') {
                state[key] = config[key] || readCssVar(key) || DEFAULTS[key];
            }
        });
        return state;
    }

    // ── Render panel HTML ────────────────────────────────────────
    function renderPanel() {
        if (!panel) return;

        // Group fields by section
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

        // ── Layout section ──
        html += `<div class="panel-section">`;
        html += `<h3>Layout</h3>`;
        html += `
            <div class="toggle-row">
                <label>Enable Sidebar</label>
                <label class="toggle-switch">
                    <input type="checkbox" id="f-sidebar" ${liveState.sidebar_enabled ? 'checked' : ''}>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <p class="read-only-info">Grid Limits: Max-Width 1280px | Sidebar 320px</p>
        `;
        html += `</div>`;

        // ── Grouped sections ──
        Object.keys(sections).forEach(secTitle => {
            html += `<div class="panel-section"><h3>${secTitle}</h3>`;
            sections[secTitle].forEach(({ key, label }) => {
                const val = liveState[key] || DEFAULTS[key];
                const isColor = val.match?.(/^#[0-9a-f]{6}$/i) || false;
                if (isColor) {
                    html += `
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">${label}</label>
                            <div class="color-field">
                                <input type="color" id="f-${cssVarToId(key)}" data-var="${key}" value="${val}">
                                <input type="text" data-var="${key}" value="${val}" class="color-hex" maxlength="7" placeholder="#000000">
                            </div>
                        </div>`;
                } else {
                    html += `
                        <div class="field-row">
                            <label for="f-${cssVarToId(key)}">${label}</label>
                            <input type="text" id="f-${cssVarToId(key)}" data-var="${key}" value="${val}">
                        </div>`;
                }
            });
            html += `</div>`;
        });

        // ── Save section ──
        html += `<div class="panel-section">`;
        html += `<button class="panel-save" id="agentshell-save">Save &amp; Reload</button>`;
        html += `</div>`;

        panel.innerHTML = html;

        // ── Wire events ────────────────────────────────────────
        panel.querySelector('.panel-close')?.addEventListener('click', closePanel);

        // Sidebar toggle
        panel.querySelector('#f-sidebar')?.addEventListener('change', e => {
            liveState.sidebar_enabled = e.target.checked;
            syncSidebarBody(e.target.checked);
        });

        // All CSS variable inputs — live preview
        panel.querySelectorAll('[data-var]').forEach(el => {
            el.addEventListener('input', e => {
                const key = e.target.dataset.var;
                let val = e.target.value;

                // Sync the companion field if it exists (color picker + hex text)
                const id = cssVarToId(key);
                const colorPicker = panel.querySelector(`input[type="color"][data-var="${key}"]`);
                const hexInput    = panel.querySelector(`.color-hex[data-var="${key}"]`);

                if (e.target.type === 'color') {
                    // Color picker changed — sync hex text
                    val = e.target.value;
                    if (hexInput) hexInput.value = val;
                } else if (e.target.classList.contains('color-hex')) {
                    // Hex text changed — validate and sync picker
                    val = val.startsWith('#') ? val : '#' + val;
                    if (/^#[0-9a-fA-F]{6}$/.test(val) && colorPicker) {
                        colorPicker.value = val;
                    }
                }

                liveState[key] = val;
                writeCssVar(key, val);
            });
        });

        // Save button
        panel.querySelector('#agentshell-save')?.addEventListener('click', saveConfig);
    }

    // ── Convert CSS var name to safe HTML id ──
    function cssVarToId(name) {
        return name.replace(/[^a-zA-Z0-9]/g, '_').replace(/^_+/, '');
    }

    // ── Load config from REST API ──────────────────────────────
    async function loadConfig() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                headers: { 'X-WP-Nonce': AgentShellConfig.nonce }
            });
            if (!resp.ok) throw new Error('Failed to load config: ' + resp.status);
            const config = await resp.json();
            liveState = buildState(config);
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    // ── Save config to REST API ────────────────────────────────
    async function saveConfig() {
        // Gather current live state (sidebar_enabled + all CSS vars)
        const payload = { sidebar_enabled: !!liveState.sidebar_enabled };
        Object.keys(DEFAULTS).forEach(key => {
            if (key !== 'sidebar_enabled') {
                payload[key] = liveState[key] || DEFAULTS[key];
            }
        });

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

    // ── Bootstrap ───────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { initElements(); loadConfig(); });
    } else {
        initElements();
        loadConfig();
    }
})();
