# AgentShell — Agent Guide

> **Quick start:** `GET /wp-json/wp/v2/agentshell/config` returns the full config. The `_guide` field in the response contains this document (no need to open files directly).

---

## What This Theme Is

AgentShell is a WordPress theme with a strict split between:

- **Shell** — static, immutable, CSS-grid-driven. Layout and zone IDs are hardcoded. You cannot modify it.
- **Content** — editable via WordPress REST API. You control everything inside `#zone-main`.

The shell provides header, footer, nav, and layout infrastructure. Your job is to add content and configure design tokens.

---

## What You May Edit

### 1. Design Tokens (CSS Variables)

Edit `:root` variables in `style.css` directly. No build step — WordPress serves it as-is.

```css
:root {
    /* Page */
    --theme-bg:       #ffffff;
    --theme-surface:  #f4f4f5;
    --theme-text:     #18181b;
    --theme-border:   #e4e4e7;
    --theme-accent:   #3b82f6;

    /* Header */
    --theme-header-bg:    #1a1a2e;
    --theme-header-text:  #ffffff;

    /* Footer */
    --theme-footer-bg:    #16213e;
    --theme-footer-text:  #ffffff;

    /* Typography */
    --font-base: system-ui, sans-serif;
    --font-mono: monospace;

    /* Spacing */
    --spacing-base: 1rem;
    --radius-base:  8px;

    /* Container (read-only) */
    --content-max-width: 1280px;
    --sidebar-width:      320px;
    --container-padding: 2rem;
}
```

### 2. Content in `#zone-main`

Standard WordPress REST — no special auth beyond WP login:

| Method | Endpoint | Use |
|--------|----------|-----|
| GET | `/wp/v2/pages` | List pages |
| PUT | `/wp/v2/pages/<id>` | Edit page content |
| POST | `/wp/v2/posts` | Create a post |
| PUT | `/wp/v2/posts/<id>` | Edit post content |

**Format:** `{ "content": { "raw": "<p>Your HTML here</p>" } }`

WordPress auto-formatting (`wpautop`, `wptexturize`) is disabled for agent payloads — your raw HTML is preserved exactly.

### 3. Sidebar Toggle

```
PUT /wp-json/wp/v2/agentshell/config
{ "sidebar_enabled": true }
```

When enabled, `<aside id="zone-sidebar">` appears at ≥1024px as a 320px column.

### 4. Header/Sidebar/Footer Zones (Advanced)

The zone registry in `agentshell_config` supports sources beyond `wp_loop` and `wp_widget_area`:

- **`json_block`** — inject raw HTML (style/script tags are stripped for security)
- **`widget`** — render a registered widget by ID

Default zones are pre-loaded from `default-config.json`. Custom zones can be added via the config endpoint. See `template-parts/shell-render.php` for the full rendering logic.

---

## What You Must NOT Do

- Modify `header.php`, `footer.php`, or style.css Sections 3–4 (the grid layout)
- Use `onclick=""`, `onerror=""`, `<script>` tags, or `style=""` attributes in post content — WordPress strips these
- Set colors via REST API config — edit `style.css :root` directly
- Inject content outside `#zone-main` without using the zone registry (source: json_block or widget)

---

## REST API

```
GET  /wp-json/wp/v2/agentshell/config   → { schema, defaults, config }
PUT  /wp-json/wp/v2/agentshell/config   → update config (returns flattened)
```

**Auth (pick one):**

1. **Static token** (simplest for headless clients):
   `X-AgentShell-Token: agentshell_dev_token`
   (Set `AGENTSHELL_REST_TOKEN` in `wp-config.php` to change)

2. **Basic Auth** (WordPress Application Password):
   `Authorization: Basic <base64(username:app_password)>`

**PUT accepted keys:** `sidebar_enabled` (bool), `zones` (array), `widgets` (array), `custom_css` (string), `layout` (object)

---

## Layout

```
Sidebar OFF (default) — all sizes:
┌──────────────────────────────────┐
│            header                │
├──────────────────────────────────┤
│                                  │
│      main (75ch max width)       │
│                                  │
├──────────────────────────────────┤
│            footer                │
└──────────────────────────────────┘

Sidebar ON (≥1024px):
┌──────────────────────┬──────────┐
│        header        │          │
├───────────┬──────────┤ sidebar  │
│           │          │  320px   │
│   main    │          │          │
│  (1fr)    │          │          │
├───────────┴──────────┴──────────┤
│           footer                │
└─────────────────────────────────┘
```

**Wide content escape hatch:** apply `class="u-full-width"` to an element inside `#zone-main` to break it out to full container width. Use for code blocks, tables, terminals.

---

## Interactive Widgets: Web Component Protocol

For charts, calculators, terminals, or any complex JS-driven content, use native Web Components with Shadow DOM. This is the only safe way to inject JS into `#zone-main`.

**Pre-loaded libraries (do NOT inject script tags):**
- `window.d3` — D3.js v7
- `window.math` — Math.js 11.8

**Protocol:**

```html
<mpm-memory-chart data-used="75"></mpm-memory-chart>

<script>
if (!customElements.get('mpm-memory-chart')) {
    class MemoryChart extends HTMLElement {
        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
        }
        connectedCallback() {
            this.shadowRoot.innerHTML = `
                <style>
                    :host { display: block; }
                    .chart { color: var(--theme-text); }
                </style>
                <div class="chart">...</div>
            `;
            // Use window.d3 and window.math here
        }
    }
    customElements.define('mpm-memory-chart', MemoryChart);
}
</script>
```

**Rules:**
1. Always guard `customElements.define` with `if (!customElements.get(...))`
2. Place ALL widget CSS inside `shadowRoot.innerHTML` `<style>` — never in the main document
3. Use `var(--theme-*)` in Shadow DOM styles to inherit shell theme tokens
4. Use `this.shadowRoot.querySelector(...)` instead of `document.getElementById(...)`
5. Prefix all custom elements with `mpm-`

---

## File Map

```
agentshell/
├── style.css              # Theme declaration + :root tokens + grid layout
├── functions.php           # REST endpoints, config helpers, asset enqueue
├── header.php             # Static shell HTML — zone IDs, WP loop, sidebar conditional
├── footer.php             # Static shell HTML + custom_js injection + widget init
├── default-config.json     # Seed file for wp_options on first activation
├── AGENTS.md              # This document
├── template-parts/
│   ├── shell-render.php   # Zone renderer (wp_loop, wp_widget_area, json_block, widget)
│   ├── grid-areas.php     # Dynamic layout CSS generator from config
│   └── widgets.php        # Widget registry CSS renderer
├── configurator/          # (not implemented — do not reference)
└── widgets/
    ├── .index.json        # Stable widget registry index
    └── hello-world.php   # Example widget
```

---

## Requirements

- WordPress 5.8+
- No paid plugins or dependencies
- Pure PHP + vanilla JS (no frameworks, no build step)

---

## Quick Commands

```bash
# Toggle sidebar
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "X-AgentShell-Token: agentshell_dev_token" \
  -d '{ "sidebar_enabled": true }'

# Edit a page
curl -X PUT https://example.com/wp-json/wp/v2/pages/7 \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "content": { "raw": "<p>Hello world</p>" } }'
```