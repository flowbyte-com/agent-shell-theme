(function() {
    'use strict';

    const panel = document.getElementById('agentshell-config-panel');
    const trigger = document.getElementById('agentshell-config-trigger');
    const closeBtn = document.querySelector('.panel-close');

    let config = null;

    // Load current config from REST API
    async function loadConfig() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + '/wp/v2/agentshell/config', {
                headers: {
                    'X-WP-Nonce': AgentShellConfig.nonce
                }
            });
            config = await resp.json();
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    // Save config to REST API
    async function saveConfig(newConfig) {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + '/wp/v2/agentshell/config', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AgentShellConfig.nonce
                },
                body: JSON.stringify(newConfig)
            });
            if (!resp.ok) throw new Error('Save failed');
            config = await resp.json();
            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to save config', e);
            alert('Failed to save config. Please try again.');
        }
    }

    // Infer form field type from value
    function inferFieldType(key, value) {
        if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
            return 'color';
        }
        if (typeof value === 'string' && /^\d+(\.\d+)?(px|em|rem|%)$/.test(value)) {
            return 'text';
        }
        if (typeof value === 'string' || typeof value === 'number') {
            return 'text';
        }
        return 'text';
    }

    // Render the configurator panel form
    function renderPanel() {
        if (!config) return;

        const sections = [
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
            },
            {
                title: 'Layout (Desktop)',
                fields: [
                    {
                        path: ['layout', 'desktop'],
                        label: 'grid-template-areas rows',
                        type: 'textarea',
                        getValue: () => (config.layout?.desktop || []).join('\n'),
                        setValue: (v) => { config.layout.desktop = v.split('\n').map(s => s.trim()).filter(Boolean); }
                    }
                ]
            }
        ];

        let html = `
            <div class="panel-header">
                <h2>Shell Config</h2>
                <button class="panel-close" aria-label="Close">✕</button>
            </div>
        `;

        sections.forEach(sec => {
            html += `<div class="panel-section"><h3>${sec.title}</h3>`;
            sec.fields.forEach(field => {
                const value = field.getValue
                    ? field.getValue()
                    : field.path.reduce((o, k) => (o || {})[k], config);
                const type = field.type || inferFieldType(field.path.join('.'), value);

                if (type === 'color') {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="color" data-path="${field.path.join('.')}" value="${value || '#000000'}">`;
                } else if (type === 'textarea') {
                    html += `<label>${field.label}</label>`;
                    html += `<textarea data-path="${field.path.join('.')}">${value || ''}</textarea>`;
                } else {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="text" data-path="${field.path.join('.')}" value="${value || ''}">`;
                }
            });
            html += '</div>';
        });

        // Add save button
        html += `<div class="panel-section"><button id="agentshell-save" style="width:100%;padding:0.6rem;background:#e94560;color:#fff;border:none;border-radius:4px;cursor:pointer;">Save & Reload</button></div>`;

        panel.innerHTML = html;

        // Bind events
        trigger.addEventListener('click', openPanel);
        closeBtn.addEventListener('click', closePanel);

        document.getElementById('agentshell-save').addEventListener('click', () => {
            // Gather all field values back into config
            panel.querySelectorAll('input[type="text"], input[type="url"], textarea').forEach(el => {
                const path = el.dataset.path.split('.');
                if (el.type === 'number') {
                    setInConfig(path, parseFloat(el.value));
                } else {
                    setInConfig(path, el.value);
                }
            });
            saveConfig(config);
        });
    }

    // Helper: get nested config value by path array
    function getFromConfig(path) {
        return path.reduce((o, k) => (o || {})[k], config);
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

    function openPanel() {
        panel.classList.add('is-open');
        document.body.classList.add('config-panel-open');
    }

    function closePanel() {
        panel.classList.remove('is-open');
        document.body.classList.remove('config-panel-open');
    }

    // Make helper functions available
    window.getFromConfig = getFromConfig;
    window.setInConfig = setInConfig;

    // Initialize panel HTML (hidden)
    if (!panel) {
        const div = document.createElement('div');
        div.id = 'agentshell-config-panel';
        document.body.appendChild(div);
    }

    loadConfig();
})();
