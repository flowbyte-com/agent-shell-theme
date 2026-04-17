# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture: Declarative JSON Registry OS

AgentShell is a **config-driven WordPress theme** where agents interact entirely through REST API — no PHP code generation, no template editing, no database migrations.

**Sole source of truth:** `wp_options['agentshell_config']` (seeded once from `default-config.json` on activation).

---

## What Agents Can Do

### Theme all zones via CSS variables
Edit any `--` variable through the REST API. Variables are injected as `:root` CSS custom properties and apply immediately.

```bash
curl -X PUT http://localhost:10003/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n '808:PASSWORD' | base64)" \
  -d '{ "--theme-accent": "#ff6600", "--spacing-base": "2rem" }'
```

### Inject custom CSS and JS
`custom_css` is injected as a `<style id='agentshell-custom-css'>` in `<head>`. `custom_js` is injected as a `<script>` before `</body>`. Both are trusted author context — raw output, no sanitization stripping.

```bash
curl -X PUT http://localhost:10003/wp-json/wp/v2/agentshell/config \
  -d '{ "custom_css": "#zone-main { border: 2px solid red; }", "custom_js": "console.log('init');" }'
```

### Register widgets via bilateral registry
Stable widgets live in `/widgets/*.php` (versioned, file-based). Agent-defined widgets live in `wp_options['widgets']` (JSON, mutable). They merge — JSON overrides file-defined widgets with the same ID.

### Render content via json_block
Agents can inject HTML into zones via the `json_block` source type. `<style>` tags and `style=""` attributes are stripped server-side via `wp_kses_post()` — agents must use class-based CSS or widget `init_js`.

### Use the live configurator
Logged-in users see a configurator trigger (⚙) in the bottom-right corner. It loads current config from the REST API and provides live-preview forms for all settings.

---

## What Agents Must NOT Do

- Modify `header.php` or `footer.php` directly
- Call `agentshell_render_zone()` or `agentshell_render_nav()`
- Inject inline JS (`<script>`, `onclick=""`) in json_block content — WP strips these
- Inject `<style>` tags or `style=""` attributes in json_block — these are stripped
- Break the fixed CSS Grid structure (see below)

---

## The Unbreakable Grid

The CSS Grid layout is split into two enforced layers:

**Static (style.css Sections 3 & 4 — DO NOT EDIT):**
- Grid container (`#agentshell-root`), zone mapping (`grid-area`), and the `.sidebar-enabled` breakpoint rules
- These rules use `.sidebar-enabled #agentshell-root` descendant selectors so sidebar state is always conditional

**Dynamic (template-parts/grid-areas.php — generated from config):**
- `grid-template-areas` and `grid-template-columns` per breakpoint
- When `cols > 1` (sidebar enabled at that breakpoint), rules are wrapped in `.sidebar-enabled #agentshell-root`
- When `cols = 1`, base `#agentshell-root` gets single-column areas
- Grid column setup: `1fr var(--sidebar-width, 320px)` — main fills remaining space, sidebar stays fixed

**Structural prohibition:** `agentshell_inject_saved_styles()` injects a `<style>` that resets `position`, `top`, `left`, `right`, `bottom`, `z-index` on all zone containers — prevents agents from breaking layout with `position: fixed`.

---

## REST API

### Config endpoint

```
GET /wp-json/wp/v2/agentshell/config
→ { schema, defaults, config }
   schema:   { sidebar_enabled, zones[], widgets[], custom_css, custom_js, design, layout }
   defaults: flattened defaults
   config:   flattened current values  ← use this for current state

PUT /wp-json/wp/v2/agentshell/config
← { ...flat keys... }
→ returns flattened config on success
```

**Auth:** Application Password via Basic Auth header (`Authorization: Basic $(echo -n 'user:pass' | base64)`)

### Content endpoint (zone-main)

| Method | Endpoint | Use |
|--------|----------|-----|
| GET | `/wp/v2/pages` | List pages |
| GET | `/wp/v2/pages/<id>` | Get a page |
| PUT | `/wp/v2/pages/<id>` | Edit page content (raw) |
| POST | `/wp/v2/posts` | Create a post |
| PUT | `/wp/v2/posts/<id>` | Edit post content (raw) |

---

## Key Files

| File | Role |
|------|------|
| `functions.php` | REST API, config helpers (`agentshell_get_config`, `agentshell_flatten_config`, `agentshell_unflatten_config`), `agentshell_inject_saved_styles`, bilateral widget registry helpers |
| `header.php` | Hardcoded shell HTML — static zone IDs and structure |
| `footer.php` | Custom JS injection, widget init (MutationObserver), body class for sidebar |
| `style.css` | `:root` CSS variables (agents can edit any `--` key). Sections 3 & 4 = fixed grid — do not edit |
| `template-parts/grid-areas.php` | Generates grid CSS from config — handles sidebar-aware breakpoint rules |
| `template-parts/shell-render.php` | `agentshell_render_zone()` — renders zones by source type (wp_loop, wp_widget_area, json_block, widget) |
| `template-parts/widgets.php` | Widget registry scoped CSS renderer |
| `configurator/configurator.js` | Live preview panel — reads from GET /config, saves via PUT /config |
| `widgets/` | Stable widget definitions (`.index.json` + `*.php` files) |

---

## Config Schema

```json
{
  "sidebar_enabled": false,
  "zones": [
    { "id": "header",  "label": "Header",  "source": "wp_loop" },
    { "id": "main",    "label": "Main",    "source": "wp_loop" },
    { "id": "sidebar", "label": "Sidebar", "source": "wp_widget_area", "widget_area_id": "primary-sidebar" },
    { "id": "footer",  "label": "Footer",  "source": "wp_loop" }
  ],
  "widgets": [],
  "custom_css": "",
  "custom_js": "",
  "design": {
    "colors": { "background": "#ffffff", "surface": "#f4f4f5", "text": "#18181b", "accent": "#3b82f6", "border": "#e4e4e7", "primary": "#1a1a2e", "secondary": "#16213e" },
    "typography": { "fontFamily": "system-ui, -apple-system, sans-serif", "baseSize": "1rem", "scale": 1.25 }
  },
  "layout": {
    "breakpoints": { "mobile": "0px", "tablet": "768px", "desktop": "1024px" },
    "grid_areas": {
      "mobile":  ["header", "main", "footer"],
      "tablet":  ["header header", "main sidebar", "footer footer"],
      "desktop": ["header header", "main sidebar", "footer footer"]
    },
    "grid_gap": "1rem",
    "grid_padding": "2rem"
  }
}
```

---

## Zone Sources

| Source | Behavior |
|---------|----------|
| `wp_loop` | Renders current WP loop content |
| `wp_widget_area` | Renders a named WordPress sidebar via `dynamic_sidebar()` |
| `json_block` | Renders arbitrary HTML — `<style>`, `<script>`, and `style=""` stripped server-side |
| `widget` | Takes over the zone using the Widget Registry — renders widget template and initializes its `init_js` |

---

## Widget Registry

**Stable widgets** (file-based, versioned):
- `widgets/.index.json` — manifest of stable widgets
- `widgets/*.php` — each returns an array with `id`, `name`, `init_js`, `css`, `template`

**Agent widgets** (JSON, in `wp_options['widgets']`):
- Merged on top of stable widgets — same ID overrides
- Each has `id`, `name`, `init_js`, `css`, `template`

**Widget initialization:**
- `init_js` runs in a scoped sandbox that populates `window.AgentshellWidgets[id]`
- A `MutationObserver` in `footer.php` initializes `[data-widget]` elements on the DOM
- Both server-rendered and dynamically injected widgets are handled

---

## Sidebar Behavior

- `sidebar_enabled: true` adds `sidebar-enabled` class to `<body>`
- CSS: `.sidebar-enabled #agentshell-root` switches from single-column to `1fr 320px` two-column grid at `min-width: 1024px`
- Main zone explicitly placed in column 1 with `grid-column: 1; width: 100%` so it fills available space
- When sidebar OFF: content spans full page width (base `#agentshell-root { grid-template-columns: 1fr }` applies)
