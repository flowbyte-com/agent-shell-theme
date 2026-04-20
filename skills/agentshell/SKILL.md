---
name: agentshell
description: Use when working with the AgentShell WordPress theme. Covers MCP tools, config via REST API, safe HTML injection, Web Components, FSE zone composition, and the agentshell-blocks plugin.
---

# AgentShell Skill

Agents MUST use this skill when working with the AgentShell WordPress theme ecosystem.

---

## Architecture: Two-Plugin Split

```
Agent (Claude Code, etc.)
    ↕ stdio (MCP JSON-RPC)
Daemon (agentshell-mcp-daemon)
    ↕ HTTP (MCP over REST)
┌─────────────────────────────────────────┐
│ agentshell-mcp (WordPress plugin)        │
│   ↕ filter-based tool registry          │
│   Theme tools (layout, zones, design)   │
├─────────────────────────────────────────┤
│ agentshell-blocks (WordPress plugin)    │
│   ↕ filter hooks                         │
│   Widget tools + asset injection         │
└─────────────────────────────────────────┘
    ↕ reads/writes
AgentShell config (wp_options)
    ↕ sole source of truth after activation
Shell (header.php, style.css, footer.php)
    ↕ renders into FSE layout
```

**Sole source of truth:** `wp_options['agentshell_config']` — never the physical `default-config.json`.

**Clean blast radius:** If `agentshell-blocks` is deactivated, its widget tools silently disappear from the MCP tool list. The theme keeps rendering — orphaned widget blocks show `<!-- Widget not found -->` comments, never a fatal error.

---

## Tools (12 total)

All tools prefixed `agentshell_`. Empty arguments unless noted.

### Theme Tools (agentshell-mcp plugin)

| Tool | What it does | Key args |
|------|-------------|----------|
| `agentshell_get_config` | Return full flat config (CSS vars) | (none) |
| `agentshell_set_css_var` | Set one CSS variable | `name` (must start with `--`), `value` |
| `agentshell_set_design` | Update colors/typography | `colors{}`, `typography{}` |
| `agentshell_list_zones` | List all zones with IDs, labels, and composition/slots | (none) |
| `agentshell_update_zone_composition` | Set/replace a zone's ordered block composition (main zone) | `zone_id`, `composition[]` |
| `agentshell_update_zone_slots` | Set tri-slot blocks for header or footer zone | `zone_id`, `slots{left,center,right}` |
| `agentshell_set_layout` | Update breakpoints (sidebar removed in v2) | `breakpoints?` |
| `agentshell_inject_json_block` | Inject raw HTML into a zone | `zone_id`, `html` |
| `agentshell_update_post_content` | Update post/page HTML content | `post_id` (integer), `html_content` |
| `agentshell_get_site_info` | Get site name, URL, version | (none) |

### Widget Tools (agentshell-blocks plugin)

| Tool | What it does | Key args |
|------|-------------|----------|
| `agentshell_list_widgets` | List all agent-defined widgets | (none) |
| `agentshell_register_widget` | Register or update a widget | `id`, `name`, `init_js?`, `css?`, `template?` |
| `agentshell_unregister_widget` | Remove a widget — also cleans zone compositions | `id` |
| `agentshell_get_widget` | Get a single widget definition | `id` |

---

## FSE Zone Composition (v2)

Each zone has either a `slots` object or a `composition[]` array depending on the zone type.

**Tri-Slot Zones (header / footer):** Use a `slots` object for three-column horizontal placement:

```json
{
  "id": "header",
  "label": "Header",
  "slots": {
    "left":   [ { "type": "wp_core", "id": "site_logo" } ],
    "center": [ { "type": "wp_core", "id": "nav_menu" } ],
    "right":  [ { "type": "wp_core", "id": "search_form" } ]
  }
}
```

**Vertical Composition Zones (main):** Use an ordered `composition[]` array:

```json
{
  "id": "main",
  "label": "Main",
  "composition": [
    { "type": "widget",  "id": "ai-alert-banner" },
    { "type": "wp_loop" },
    { "type": "widget",  "id": "ai-newsletter-signup" }
  ]
}
```

**Block types:**

| Type | When to use |
|------|-------------|
| `wp_loop` | Standard WordPress content (posts, pages) |
| `wp_core` | WordPress native elements — site_title, site_tagline, site_logo, nav_menu, search_form |
| `widget` | Agent-built interactive components (chat, charts, calculators) |
| `json_block` | Raw HTML injected directly (stripped of `<style>` and `style=""`) |
| `wp_widget_area` | WordPress dynamic sidebar by ID |

**Setting slots via tool (header/footer):**

```json
{
  "zone_id": "header",
  "slots": {
    "left":   [ { "type": "wp_core", "id": "site_logo" } ],
    "center": [ { "type": "wp_core", "id": "nav_menu" } ],
    "right":  [ { "type": "wp_core", "id": "search_form" } ]
  }
}
```

**To place a widget in the header's right slot:**

```json
{
  "zone_id": "header",
  "slots": {
    "left":   [],
    "center": [ { "type": "wp_core", "id": "nav_menu" } ],
    "right":  [ { "type": "widget", "id": "sale-banner" } ]
  }
}
```

**Setting composition via tool (main zone only):**

```json
{
  "zone_id": "main",
  "composition": [
    { "type": "widget", "id": "pricing-table" },
    { "type": "wp_loop" }
  ]
}
```

The human WP content (`wp_loop`) is preserved — authors editing in Gutenberg are never displaced.

---

## agentshell-blocks Plugin

The blocks plugin owns the widget registry and shortcode bridge.

**Widget registry store:** `wp_options['agentshell_widgets']` — agent-defined widgets only. Stable widgets live in `themes/agentshell/widgets/*.php`.

**Widget shortcode:** Human editors can embed widgets in WordPress content:

```
[agent_block id="hello-world"]
```

**Asset injection:** All widget CSS (`<style id="agentshell-widgets-css">`) and JS (`<script id="agentshell-widgets-js">`) are injected automatically. No build step, no physical files.

**Ghost widget cleanup:** `agentshell_unregister_widget` automatically strips any zone composition blocks referencing the deleted widget ID. No orphaned references.

---

## Trust Model

Every tool requires `manage_options` capability. Exception:

**Trusted users** (`unfiltered_html` or `manage_options` capability):
- `<script>` tags pass through intact in `agentshell_inject_json_block` and `agentshell_update_post_content`
- Enables custom Web Components with `customElements.define`

**All users — always stripped:**
- `<style>` tags and `style=""` attributes — prevents layout breaking

---

## What You May Do

### CSS Variables (Design Tokens)

```bash
agentshell_set_css_var({ name: "--theme-accent", value: "#ff6600" })
```

Available: `--theme-bg`, `--theme-surface`, `--theme-text`, `--theme-border`, `--theme-accent`, `--theme-header-bg`, `--theme-header-text`, `--theme-footer-bg`, `--theme-footer-text`, `--font-base`, `--font-mono`, `--spacing-base`, `--content-max-width`, `--container-padding`

### Register a Widget

```json
{
  "id": "memory-summary",
  "name": "Memory Summary",
  "init_js": "window.AgentshellWidgets = window.AgentshellWidgets || {};\nwindow.AgentshellWidgets['memory-summary'] = {\n  init: function(el) {\n    el.innerHTML = '<div class=\"mem-summary\">3 memories active</div>';\n  }\n};",
  "css": ".mem-summary { color: var(--theme-accent); font-weight: bold; }"
}
```

Then place it in a zone:
```json
{
  "zone_id": "main",
  "composition": [
    { "type": "widget", "id": "memory-summary" },
    { "type": "wp_loop" }
  ]
}
```

### Web Components (Light DOM)

Widget JS runs in the page context — not Shadow DOM. All CSS must be scoped to a class:

**Correct:**
```css
.memory-summary .card { background: var(--theme-surface); }
```

**Wrong (bleeds site-wide):**
```css
.card { background: var(--theme-surface); }   /* no wrapper */
:host { display: block; }                    /* Shadow DOM only */
```

Pre-loaded libraries: `window.d3` (D3.js v7), `window.math` (Math.js 11.8). Do NOT inject `<script src="">` for these.

---

## What You Must NOT Do

- Edit `header.php`, `footer.php`, or `style.css` Sections 3–4 (the fixed FSE grid)
- Use inline event handlers (`onclick=""`, `onerror=""`) or `<script>` in post content (unless trusted user)
- Set colors outside the CSS variable system
- Inject `<style>` tags or `style=""` attributes in `json_block` content — always stripped
- **Do not write client-side `fetch()` calls.** Use `agentshell_get_config` to read state first.
- Use `agentshell_set_zone_source` — that tool was removed in v2. Use `agentshell_update_zone_composition` instead.

---

## Daemon Configuration

`~/.agentshell-mcp.json` (mode `0600`):
```json
{
  "url": "https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp",
  "user": "agent_user",
  "pass": "XXXX XXXX XXXX XXXX XXXX XXXX",
  "timeout": 30
}
```

Claude Code `settings.json`:
```json
{
  "mcpServers": {
    "agentshell": {
      "command": "php",
      "args": ["/path/to/daemon.php", "--config", "/home/user/.agentshell-mcp.json"]
    }
  }
}
```

---

## File Structure

```
agent-shell-theme/
├── style.css                  # Theme + :root tokens + FSE grid
├── functions.php              # Config helpers, asset enqueue
├── header.php                # Hardcoded FSE shell (header/main/footer zones)
├── footer.php                # Custom JS injection, configurator trigger
├── default-config.json       # Seed file (v2: header/footer use slots{}, main uses composition[])
├── template-parts/
│   └── shell-render.php     # agentshell_render_zone(), agentshell_render_block()
├── configurator/
│   ├── configurator.js      # Zone Builder UI (⚙ panel)
│   └── configurator.css
├── widgets/                  # Stable widgets (file-based)
│   └── hello-world.php
├── agentshell-mcp/           # WordPress plugin — theme MCP tools
│   └── includes/tools/       # 9 theme tools
├── agentshell-blocks/        # WordPress plugin — widget MCP tools
│   └── includes/tools/       # 4 widget tools
└── agentshell-mcp-daemon/    # PHP CLI proxy (stdio ↔ HTTP)
```

---

## Troubleshooting

| Error | Cause |
|-------|-------|
| `No route was found` | Plugin not activated |
| `Authentication failed` | Wrong username or app password |
| `Unknown tool: agentshell_...` | Plugin with that tool not activated |
| `<script>` tags missing | User lacks `unfiltered_html` capability |
| `<!-- Widget not found -->` | Widget deleted but zone composition still references it |
