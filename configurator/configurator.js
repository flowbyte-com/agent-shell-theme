(function() {
    'use strict';

    let panel = null;
    let trigger = null;
    let config = null;
    let initDone = false;

    // Initialize DOM elements (call once, idempotent)
    function initElements() {
        if (initDone) return;
        initDone = true;

        panel = document.getElementById('agentshell-config-panel');
        trigger = document.getElementById('agentshell-config-trigger');

        if (trigger) {
            trigger.addEventListener('click', togglePanel);
        }
    }

    // Load current config from REST API
    async function loadConfig() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                headers: {
                    'X-WP-Nonce': AgentShellConfig.nonce
                }
            });
            if (!resp.ok) throw new Error('Failed to load config: ' + resp.status);
            config = await resp.json();
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    // Save config to REST API
    async function saveConfig(newConfig) {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + 'wp/v2/agentshell/config', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AgentShellConfig.nonce
                },
                body: JSON.stringify(newConfig)
            });
            if (!resp.ok) throw new Error('Save failed: ' + resp.status);
            config = await resp.json();
            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to save config', e);
            alert('Failed to save config. Please try again.');
        }
    }

    // Toggle panel open/closed
    function togglePanel() {
        if (!panel) return;
        const isOpen = panel.classList.toggle('is-open');
        document.body.classList.toggle('config-panel-open', isOpen);
    }

    // Infer form field type from value
    function inferFieldType(key, value) {
        if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
            return 'color';
        }
        if (typeof value === 'string' && /^\d+(\.\d+)?(px|em|rem|%)$/.test(value)) {
            return 'text';
        }
        return 'text';
    }

    // Render the configurator panel form
    function renderPanel() {
        if (!config || !panel) return;

        const sections = [
            {
                title: 'Layout',
                fields: [
                    {
                        path: ['sidebar_enabled'],
                        label: 'Enable Sidebar',
                        type: 'toggle',
                        getValue: () => !!config.sidebar_enabled,
                        setValue: (v) => { config.sidebar_enabled = v; }
                    }
                ]
            },
            {
                title: 'Logo',
                fields: [
                    { path: ['design', 'logo', 'url'], label: 'Logo URL' },
                    { path: ['design', 'logo', 'width'], label: 'Width' },
                    { path: ['design', 'logo', 'height'], label: 'Height' },
                ]
            },
            {
                title: 'Colors',
                fields: Object.entries(config.design?.colors || {}).map(([name, value]) => ({
                    path: ['design', 'colors', name],
                    label: name.charAt(0).toUpperCase() + name.slice(1),
                    type: 'color'
                }))
            },
            {
                title: 'Typography',
                fields: [
                    { path: ['design', 'typography', 'fontFamily'], label: 'Font Family' },
                    { path: ['design', 'typography', 'baseSize'], label: 'Base Size' },
                ]
            }
        ];

        let html = `
            <div class="panel-header">
                <h2>Shell Config</h2>
                <button class="panel-close" aria-label="Close panel">&times;</button>
            </div>
        `;

        sections.forEach(sec => {
            html += `<div class="panel-section"><h3>${sec.title}</h3>`;
            sec.fields.forEach(field => {
                const value = field.getValue
                    ? field.getValue()
                    : field.path.reduce((o, k) => (o || {})[k], config);

                if (field.type === 'toggle') {
                    const checked = value ? 'checked' : '';
                    html += `
                        <div class="toggle-row">
                            <label>${field.label}</label>
                            <label class="toggle-switch">
                                <input type="checkbox" data-path="${field.path.join('.')}" ${checked}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>`;
                } else if (field.type === 'color') {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="color" data-path="${field.path.join('.')}" value="${value || '#000000'}">`;
                } else {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="text" data-path="${field.path.join('.')}" value="${value || ''}">`;
                }
            });
            html += '</div>';
        });

        html += `<div class="panel-section"><button class="panel-save">Save & Reload</button></div>`;

        panel.innerHTML = html;

        // Bind close button
        const closeBtn = panel.querySelector('.panel-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', togglePanel);
        }

        // Bind save button
        const saveBtn = panel.querySelector('.panel-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                panel.querySelectorAll('input[type="text"], input[type="url"], input[type="color"], input[type="checkbox"]').forEach(el => {
                    const path = el.dataset.path.split('.');
                    if (el.type === 'checkbox') {
                        setInConfig(path, el.checked);
                    } else {
                        setInConfig(path, el.value);
                    }
                });
                saveConfig(config);
            });
        }
    }

    // Helper: set nested config value by path array
    function setInConfig(path, value) {
        let o = config;
        for (let i = 0; i < path.length - 1; i++) {
            if (!o[path[i]]) o[path[i]] = {};
            o = o[path[i]];
        }
        o[path[path.length - 1]] = value;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initElements();
            loadConfig();
        });
    } else {
        initElements();
        loadConfig();
    }
})();
