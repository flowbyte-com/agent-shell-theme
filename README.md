# AgentShell

A WordPress theme built for AI agent collaboration — strict separation between an **immutable static shell** and editable content. Agents modify the site by editing CSS variables and pushing content payloads via REST API, never touching PHP templates.

---

## Architecture

The shell is **static, immutable, and CSS-Grid-driven**. The layout geometry and all zone IDs are hardcoded. Agents must not attempt to render header or footer zones dynamically.

### Shell components (do not edit)

| File | Role |
|------|------|
| `header.php` | Hardcoded shell HTML, zone IDs, and structure |
| `footer.php` | Hardcoded shell HTML |
| `style.css` Sections 3 & 4 | Fixed CSS Grid layout — immutable |

### What agents may edit

1. **`sidebar_enabled`** — toggle via REST API (only layout control available)
2. **CSS variables** in `style.css :root` — design tokens (colors, typography, spacing)
3. **Content in `#zone-main`** — via standard WordPress Post/Page REST payloads

### What agents must NOT do

- Modify `header.php` or `footer.php`
- Implement `agentshell_render_zone()` or `agentshell_render_nav()`
- Inject HTML into `#zone-header`, `#zone-sidebar`, or `#zone-footer`
- Edit `style.css` Sections 3 or 4 (the grid layout)
- Set colors via REST API config — edit `style.css :root` directly

---

## Zone Map

| Zone ID | Semantic | Editable by agent? |
|---------|----------|-------------------|
| `#agentshell-root` | Grid container | No — structural |
| `#zone-header` | `<header>` | No — static HTML |
| `#zone-nav` | `<nav>` | No — WordPress menu system |
| `#zone-main` | `<main>` | **YES — via WP Post payloads** |
| `#zone-sidebar` | `<aside>` | No — widget area, `sidebar_enabled` only |
| `#zone-footer` | `<footer>` | No — static HTML |

---

## Layout

```
Default (sidebar off) — all sizes:
┌──────────────────────────────────┐
│            header                │
├──────────────────────────────────┤
│                                  │
│      main (75ch max width)       │
│                                  │
├──────────────────────────────────┤
│            footer                │
└──────────────────────────────────┘

With sidebar enabled (≥1024px):
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

**Wide content escape hatch** — apply class `u-full-width` to any element inside `#zone-main` to break it out to full container width.

---

## CSS Design Tokens

Edit `:root` variables in `style.css` directly. No build step — WordPress serves it as-is.

```css
:root {
    /* Page */
    --theme-bg:           #ffffff;
    --theme-surface:      #f4f4f5;
    --theme-text:         #18181b;
    --theme-border:       #e4e4e7;
    --theme-accent:       #3b82f6;   /* buttons, links, hover */

    /* Header zone */
    --theme-header-bg:    #1a1a2e;
    --theme-header-text:  #ffffff;

    /* Footer zone */
    --theme-footer-bg:    #16213e;
    --theme-footer-text:  #ffffff;

    /* Typography */
    --font-base:  system-ui, sans-serif;
    --font-mono:  monospace;

    /* Spacing and shape */
    --spacing-base:  1rem;
    --radius-base:   8px;

    /* Container bounds (read-only) */
    --content-max-width:  1280px;
    --sidebar-width:      320px;
    --container-padding:  2rem;
}
```

---

## REST API

### Config endpoint

```
GET  /wp-json/wp/v2/agentshell/config   → full flattened config
PUT  /wp-json/wp/v2/agentshell/config   → update config
```

**Auth:** Application Password via Basic Auth header (`Authorization: Basic <base64(username:app_password)>`).

**Supported PUT keys:**
- `sidebar_enabled` (bool) — show/hide sidebar at ≥1024px

### Content in `#zone-main`

Standard WordPress REST endpoints — no special auth beyond WP login:

| Method | Endpoint | Use |
|--------|----------|-----|
| GET | `/wp/v2/pages` | List pages |
| GET | `/wp/v2/pages/<id>` | Get a page |
| PUT | `/wp/v2/pages/<id>` | Edit page content |
| POST | `/wp/v2/posts` | Create a post |
| PUT | `/wp/v2/posts/<id>` | Edit post content |

### Agent guide

```
GET /wp-json/wp/v2/agentshell/guide   → this guide as plain text
```

---

## Live Configurator

A floating button (bottom-right) opens a panel for logged-in users. Changes update CSS variables live and persist to `wp_options` on save.

The `design` object in the REST config is informational only — it drives the live configurator preview. CSS variables must be edited directly in `style.css`.

---

## Setup

1. Upload this theme to `wp-content/themes/agentshell/`
2. Activate in **Appearance → Themes**
3. On first activation, `wp_options` is seeded from `default-config.json`
4. After install, all shell changes go through the REST API or the built-in configurator

### Quick commands

```bash
# Toggle sidebar
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "sidebar_enabled": true }'

# Edit a page (content goes into #zone-main)
curl -X PUT https://example.com/wp-json/wp/v2/pages/7 \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "content": { "raw": "<p>Your content here</p>" } }'
```

---

## Widgets and Interactive Content

For complex interactive content (charts, calculators, visualizers), agents **must** use native Web Components with Shadow DOM. This is the only safe way to inject JS into `#zone-main`.

**Pre-loaded libraries:** `window.d3` (D3.js v7) and `window.math` (Math.js) are available globally — do not inject `<script src="">` tags for these.

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
                    /* Inherits shell theme: var(--theme-accent) etc. */
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
- Always guard `customElements.define` with `if (!customElements.get(...))`
- Place ALL widget CSS inside `shadowRoot.innerHTML` `<style>` — never in the main document
- Use `var(--theme-*)` in Shadow DOM styles to inherit shell theme
- Prefix all custom elements with `mpm-`
- Do NOT use `document.getElementById` or `document.querySelector` inside the widget — use `this.shadowRoot.querySelector` instead

---

## File Structure

```
agentshell/
├── style.css              # Theme declaration + CSS Grid layout + :root tokens
├── functions.php           # REST endpoint, config helpers, asset enqueue, wp_options bridge
├── default-config.json     # Seed file for wp_options (IDE reference, not read at runtime)
├── header.php             # Static shell HTML — zone IDs, WP loop, sidebar conditional
├── footer.php             # Static shell HTML — closes document, configurator trigger
├── AGENTS.md              # Agent protocol documentation (served via REST API _guide field)
├── configurator/
│   ├── configurator.js     # Live preview panel, color pickers, REST save
│   └── configurator.css    # Panel styles (hardcoded neutrals, never inherits theme)
└── template-parts/
    └── shell-render.php    # (unused in current static architecture)
```

---

## Requirements

- WordPress 5.8+
- No paid plugins or dependencies
- Pure PHP + vanilla JS (no frameworks, no build step)
