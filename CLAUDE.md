# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Note:** `AGENTS.md` in the theme root is the authoritative, always-current agent guide — it documents the full `agentshell_*` MCP toolset (observe → reason → change → verify loop, transactions, revisions, snapshots, profiles, content primitives, screenshots). Prefer the MCP tools over raw REST calls; the REST endpoints below remain valid fallbacks.

## Architecture: Declarative JSON Registry OS

AgentShell is a **config-driven WordPress theme** where agents interact entirely through the MCP plugin (JSON-RPC over the WordPress REST API, proxied by `agentshell-mcp-daemon`) — no PHP code generation, no template editing, no database migrations.

```
Agent (Claude Code)  ↔  stdio  ↔  daemon (PHP CLI)  ↔  HTTP  ↔  WordPress plugin  ↔  wp_options['agentshell_config']
```

**Sole source of truth:** `wp_options['agentshell_config']` (seeded once from `default-config.json` on activation). Every mutation is recorded as a revision + audit entry; multi-step work should run inside a transaction (staged, committed atomically, locked per actor).

---

## What Agents Can Do

### Drive everything through MCP tools
`agentshell_inspect` (site model), `agentshell_validate` (site doctor), `agentshell_set_palette` / `set_typography` / `set_spacing` / `set_shape` (semantic design API), `agentshell_set_css_var` (raw tokens), `agentshell_set_zone_source` (zones), `agentshell_register_widget` (widgets), `agentshell_create_page` / `update_content` / `publish` (content), `agentshell_screenshot` (visual verification), and the transaction loop (`begin` → mutate → `preview` → `validate` → `commit`/`rollback`). Full reference in `AGENTS.md`.

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
  -d '{ "custom_css": "#zone-main { border: 2px solid red; }", "custom_js": "console.log(\"init\");" }'
```

### Register widgets via bilateral registry
Stable widgets live in `/widgets/*.php` (versioned, file-based). Agent-defined widgets live in `config['widgets']` (JSON, mutable). They merge — JSON overrides file-defined widgets with the same ID. Widgets have a lifecycle (`active` / `disabled` via `widget_overrides`, `remove` for agent-defined only) and may declare `libs: ['d3']` / `['mathjs']` — the only way those libraries load.

### Render content via json_block
Agents can inject HTML into zones via the `json_block` source type. `<style>` tags and `style=""` attributes are stripped server-side via `wp_kses_post()` — agents must use class-based CSS or widget `init_js`.

### Use the live configurator
Logged-in users see a configurator trigger (⚙) in the bottom-right corner. It loads current config from the REST API and provides live-preview forms for all settings.

---

## Local Development

AgentShell requires a WordPress instance. Use [VVV](https://varyingvagrantvagrants.org/), [Local](https://localwp.com/), or Docker:

```bash
# Example Docker setup
docker run -d --name wp -p 10003:80 -e WORDPRESS_DB_NAME=agentshell wordpress:latest
```

Activate the theme in WP admin, then verify config was seeded:
```bash
curl -s http://localhost:10003/wp-json/wp/v2/agentshell/config | jq '.config.sidebar_enabled'
# Should return: false
```

---

## What Agents Must NOT Do

- Modify `header.php`, `footer.php`, or `style.css` Sections 3–4 (the grid) directly
- Call shell render functions (`agentshell_render_zone()` in `template-parts/shell-render.php`) from content or widgets — the theme renders zones itself
- Inject inline JS (`<script>`, `onclick=""`) in json_block/post content — WP strips these
- Inject `<style>` tags or `style=""` attributes in json_block — these are stripped
- Set colors outside the CSS variable system, or inject content outside `#zone-main` without going through the zone registry
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

> Prefer the MCP content primitives: `agentshell_create_page` / `create_post`, `update_content`, `publish` / `unpublish`, `search_content`, `get_content`. Raw HTML is preserved exactly (no `wpautop`/kses for admin agents).

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
| `AGENTS.md` | **Authoritative agent guide** — full MCP tool reference, working pattern, constraints |
| `agentshell-mcp/agentshell-mcp.php` | MCP plugin: tool registry, JSON-RPC server, version |
| `agentshell-mcp/includes/` | Core services: `class-store.php` (revisions/audit/snapshots/profiles), `class-transactions.php` (safe change loop, per-actor locks), `class-doctor.php` (validator), `class-content.php` (content primitives), `class-screenshot.php` (headless backend), `tools/` (45+ tools) |
| `agentshell-mcp-daemon/daemon.php` | PHP CLI proxy: stdio (MCP JSON-RPC) ↔ HTTP (WP REST endpoint) |
| `functions.php` | REST API, config helpers (`agentshell_get_config`, `agentshell_flatten_config`, `agentshell_unflatten_config`), `agentshell_inject_saved_styles`, bilateral widget registry helpers, preview overrides |
| `header.php` | Hardcoded shell HTML — static zone IDs and structure |
| `footer.php` | Custom JS injection, widget init (MutationObserver), body class for sidebar |
| `style.css` | `:root` CSS variables (agents can edit any `--` key). Sections 3 & 4 = fixed grid — do not edit |
| `template-parts/grid-areas.php` | Generates grid CSS from config — handles sidebar-aware breakpoint rules |
| `template-parts/shell-render.php` | `agentshell_render_zone()` — renders zones by block type (wp_loop, wp_core, wp_widget_area, json_block, widget) |
| `template-parts/widgets.php` | Widget registry scoped CSS renderer |
| `configurator/configurator.js` | Live preview panel — reads from GET /config, saves via PUT /config |
| `widgets/` | Stable widget definitions (`.index.json` + `*.php` files) |

---

## Config Schema

```json
{
  "sidebar_enabled": false,
  "zones": [
    { "id": "header", "label": "Header", "slots": { "left": [ { "type": "wp_core", "id": "site_title" } ], "center": [], "right": [] } },
    { "id": "main",   "label": "Main",   "composition": [ { "type": "wp_loop" } ] },
    { "id": "footer", "label": "Footer", "slots": { "left": [], "center": [ { "type": "wp_loop" } ], "right": [] } }
  ],
  "widgets": [],
  "widget_overrides": {},
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

- **Header/footer zones** are tri-slot: `slots: { left, center, right }` (main zones use a flat `composition[]`).
- **Blocks** inside slots/composition: `{ "type": "wp_loop" }`, `{ "type": "wp_core", "id": "nav_menu" }`, `{ "type": "widget", "id": "hello-world" }`, `{ "type": "json_block", "content": "<p>…</p>" }`, `{ "type": "wp_widget_area", "id": "primary-sidebar" }`.
- Any key starting with `--` in the design tree is accepted as a CSS variable.

---

## Zone Blocks

| Block | Behavior |
|---------|----------|
| `wp_loop` | Renders current WP loop content (wpautop disabled — raw HTML preserved) |
| `wp_core` | Renders a theme component: `site_title`, `site_tagline`, `site_logo`, `nav_menu`, `search_form` |
| `wp_widget_area` | Renders a named WordPress sidebar via `dynamic_sidebar()` |
| `json_block` | Renders arbitrary HTML — `<style>`, `<script>`, and `style=""` stripped server-side (`wp_kses_post`) |
| `widget` | Takes over the slot using the Widget Registry — renders widget template and initializes its `init_js` |

---

## Widget Registry

**Stable widgets** (file-based, versioned):
- `widgets/.index.json` — manifest of stable widgets
- `widgets/*.php` — each returns an array with `id`, `name`, `init_js`, `css`, `template`, optional `libs`

**Agent widgets** (JSON, in `config['widgets']`):
- Merged on top of stable widgets — same ID overrides
- Each has `id`, `name`, `init_js`, `css`, `template`, optional `libs`

**Lifecycle** (`config['widget_overrides']`): `active` by default; `disabled` renders nothing; agent-defined widgets can be removed (file-based stable widgets cannot). Managed via `agentshell_enable_widget` / `disable_widget` / `remove_widget`.

**Libraries:** `libs: ['d3']` / `['mathjs']` is the only way pre-approved libraries load — fetched from CDNs with SRI + `defer`, only when a widget declares them.

**Widget initialization:**
- `init_js` runs in a scoped sandbox that populates `window.AgentshellWidgets[id]`
- A `MutationObserver` in `footer.php` initializes `[data-widget-id]` elements on the DOM
- Both server-rendered and dynamically injected widgets are handled
- Anything interactive should be a **Web Component with Shadow DOM** (`mpm-*` prefix, `var(--theme-*)` styling, `if (!customElements.get(...))` guard)

---

## Sidebar Behavior

- `sidebar_enabled: true` adds `sidebar-enabled` class to `<body>`
- CSS: `.sidebar-enabled #agentshell-root` switches from single-column to `1fr 320px` two-column grid at `min-width: 1024px`
- Main zone explicitly placed in column 1 with `grid-column: 1; width: 100%` so it fills available space
- When sidebar OFF: content spans full page width (base `#agentshell-root { grid-template-columns: 1fr }` applies)
