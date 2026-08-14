# AgentShell

A WordPress theme built for **human–agent collaboration**. The shell is static and immutable; agents work through the MCP daemon (JSON-RPC over stdio) or the WP REST API to style the theme, arrange zones, and publish content.

## Highlights

- **Immutable shell** — `header.php` / `footer.php` / the grid never change at runtime; agents can't break the layout
- **JSON-driven zones** — header/main/footer rendered from config, not template logic
- **Design tokens as CSS variables** — every color, font, and radius is configurable via `:root` vars
- **Tri-slot header & footer** — left / center / right slots, each a composition of blocks
- **Block vocabulary** — `wp_loop`, `wp_core` (site title, logo, nav, search…), `widget`, `json_block`, `wp_widget_area`
- **Web Component widgets** — charts, calculators, terminals via Shadow DOM; D3 and Math.js pre-approved and loaded on demand
- **Browser configurator** — logged-in users get a live-editing panel for design tokens, zones, CSS/JS

---

## Architecture

```
Agent (Claude Code, etc.)
    ↕ stdio (MCP JSON-RPC)
Daemon (agentshell-mcp-daemon)          ← PHP CLI proxy
    ↕ HTTP (MCP over REST)
WordPress plugin (agentshell-mcp)
    ↕ reads/writes
AgentShell config (wp_options)
    ↕ rendered into
Shell (header.php, footer.php, style.css :root)
```

| Layer | What it is | Editable by agents? |
|---|---|---|
| Shell | Static layout (`header.php`, `footer.php`, grid CSS) | No |
| Design tokens | CSS variables in `style.css` `:root`, persisted in config | Yes (via MCP / REST) |
| Config | zones, slots, layout, widgets in `wp_options` | Yes (via MCP / REST) |
| Content | posts/pages via the WP REST API | Yes (raw HTML preserved) |

---

## Requirements

- WordPress 6.0+, PHP 7.4+
- `agentshell-mcp` plugin activated
- `agentshell-mcp-daemon` running (for MCP clients)
- No paid plugins or dependencies

---

## Setup for Humans

1. Upload `agentshell/` to `wp-content/themes/`
2. Activate in **Appearance → Themes**
3. Upload `agentshell-mcp/` to `wp-content/plugins/`
4. Activate the plugin at **Plugins**
5. Assign a menu to **Primary Navigation** (Appearance → Menus) and set a Site Logo if desired
6. Create an Application Password for the agent user at **Users → Profile → Application Passwords**
7. Copy `agentshell-mcp-daemon/` somewhere permanent, then create `~/.agentshell-mcp.json` (mode `0600`):

```json
{
  "url": "https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp",
  "user": "agent_user",
  "pass": "XXXX XXXX XXXX XXXX XXXX XXXX",
  "timeout": 30
}
```

8. For Claude Code, add to `~/.claude/settings.json`:

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

## Authentication

Two methods, both fail closed — unauthenticated requests get a 401.

### 1. Application Passwords (recommended)

`Authorization: Basic <base64(username:app_password)>` or `X-App-Password` header. This is what the daemon config above uses.

### 2. Static token

Define in `wp-config.php`:

```php
define( 'AGENTSHELL_REST_TOKEN', 'a-long-random-string' );
```

Then send `X-AgentShell-Token: <token>` (or `?_agent_token=<token>`). The token authenticates as the site administrator (user ID 1). Without the constant, this method is **disabled entirely** — it never falls back to a default value. Generate one with `wp generate-rest-token`-style entropy, e.g. `openssl rand -hex 32`.

> Note: `X-WP-Nonce` cookie auth is not supported for headless clients (WP 5.7+ nonce verification requires session cookies).

---

## MCP Tools

All tools are prefixed `agentshell_` and available through the daemon.

| Tool | What it does |
|------|-------------|
| `agentshell_get_config` | Return full config (CSS vars, zones, layout, widgets) |
| `agentshell_set_css_var` | Set one CSS variable: `{ "name": "--theme-accent", "value": "#ff0000" }` |
| `agentshell_set_design` | Update colors/typography: `{ "colors": { "accent": "#ff0000" } }` |
| `agentshell_list_zones` | List zones with IDs, labels, and current sources |
| `agentshell_set_zone_source` | Change a zone source: `{ "zone_id": "main", "source": "json_block", "config": { "html": "..." } }` |
| `agentshell_inject_json_block` | Inject HTML into a zone (admin-only; strips `style`/`script` for safety) |
| `agentshell_list_widgets` | List registered widgets |
| `agentshell_register_widget` | Register a widget: `{ "id", "label", "css", "init_js" }` |
| `agentshell_set_layout` | Update grid areas / breakpoints |
| `agentshell_get_site_info` | Get site name, URL, admin email |

---

## Design Tokens (CSS Variables)

Set via `agentshell_set_css_var`, `agentshell_set_design`, the REST endpoint, or the browser configurator. Persisted to `wp_options` and injected as `:root` CSS on every page load.

| Variable | Meaning |
|---|---|
| `--theme-bg`, `--theme-surface`, `--theme-text`, `--theme-border`, `--theme-accent` | Global palette |
| `--theme-header-bg`, `--theme-header-text`, `--theme-header-accent`, `--theme-header-border` | Header palette |
| `--theme-footer-bg`, `--theme-footer-text` | Footer palette |
| `--font-base`, `--font-mono` | Typography |
| `--spacing-base`, `--radius-base` | Spacing and corner radius |
| `--content-max-width`, `--container-padding` | Layout geometry |
| `--zone-header-radius`, `--zone-main-radius`, `--zone-footer-radius` | Zone corner radii |

Any key starting with `--` is accepted as a CSS variable — agents can introduce custom tokens without touching the schema.

### REST endpoint

`GET/PUT /wp/v2/agentshell/config` — GET returns schema, defaults, flattened config, zones, widgets, and core components for introspection. PUT accepts flat `--var` key-value pairs, validates them, merges into the stored config, and persists.

---

## Zones, Slots & Blocks

Each zone is a `composition[]` (main) or `slots {left, center, right}` (header/footer) array of blocks:

```json
{ "type": "wp_loop" }
{ "type": "wp_core", "id": "nav_menu" }
{ "type": "widget", "id": "hello-world" }
{ "type": "json_block", "content": "<p>Hello</p>" }
{ "type": "wp_widget_area", "id": "primary-sidebar" }
```

`json_block` content is sanitized with `wp_kses_post` — `style`/`script` tags and inline `style=""` are stripped. Agents must use class-based CSS or registered widgets for anything interactive.

### WP core components

`site_title`, `site_tagline`, `site_logo`, `nav_menu`, `search_form` — rendered by the theme itself; no plugin required.

---

## Widgets

Widgets are registered in the **widget registry** (stable widgets in `widgets/` + agent widgets in config). A widget entry:

```php
return array(
    'id'       => 'hello-world',
    'name'     => 'Hello World',
    'libs'     => array(),          // 'd3', 'mathjs' — loaded only when declared
    'template' => '<div id="hello-widget"></div>',
    'init_js'  => "window.AgentshellWidgets = window.AgentshellWidgets || {}; ...",
    'css'      => ".hello-widget { color: var(--theme-accent); }",
);
```

- `init_js` is wrapped in a try/catch — a failing widget never breaks the page
- `css` is injected scoped to the page; use `var(--theme-*)` to inherit the shell's look
- `libs: ['d3']` / `libs: ['mathjs']` is the **only** way pre-approved libraries load. D3 (~280KB) and Math.js (~700KB) are fetched from CDNs with SRI integrity, `defer`, and only when at least one widget declares them — never for idle pages
- Widgets requiring JS should use **Web Components with Shadow DOM**, guarded with `if (!customElements.get('mpm-*'))` and styled from `var(--theme-*)`

---

## Browser Configurator

Logged-in users see a floating gear button that opens a live-editing panel: design tokens (color pickers, selects), zone composition builder, and custom CSS/JS textareas. Changes are written via REST and persisted to `wp_options`.

---

## File Structure

```
agentshell/
├── style.css                  # Theme declaration + :root tokens + grid
├── functions.php              # Config helpers, asset enqueue, REST auth, widget libs
├── header.php                 # Static shell HTML (zones: header, main, footer)
├── footer.php                 # Static shell HTML + custom_js + widget init
├── default-config.json        # Seed file for wp_options
├── assets/theme.js            # Mobile nav toggle
├── configurator/              # Browser configurator (logged-in users)
├── template-parts/
│   ├── shell-render.php       # Zone/block renderer
│   └── widgets.php            # Widget CSS renderer
├── widgets/                   # Stable widget registry
│   ├── .index.json
│   └── hello-world.php        # Example widget
├── agentshell-mcp/            # WordPress plugin (JSON-RPC server)
├── agentshell-mcp-daemon/     # PHP CLI proxy (stdio ↔ HTTP)
└── AGENTS.md                  # Agent-facing guide
```

---

## Development Notes

- **Never edit the grid**: `header.php`, `footer.php`, and style.css Sections 3–4 are the immutable contract. Style changes belong in tokens, `custom_css`, or widget CSS.
- **No inline handlers or `<script>` in post content** — use registered widgets or Web Components.
- Content saved via REST with `{ "content": { "raw": "..." } }` bypasses `wpautop` and kses — it's the agent's job to send safe HTML.
- `wp_loop` strips `wpautop` globally; authors keep raw HTML in posts.
- PHP 7.4 compatibility is maintained with polyfills (see top of `functions.php`).

---

## Troubleshooting

| Error | Cause |
|-------|-------|
| `No route was found` | Plugin not activated |
| `Authentication failed` | Wrong username or app password |
| `HTTP request failed` | Daemon can't reach the WP endpoint |
| 401 on token requests | `AGENTSHELL_REST_TOKEN` not defined in `wp-config.php` (auth is fail-closed) |
| D3/Math.js not available | No registered widget declares the lib in its `libs` array |
| Header logo missing | No custom logo set under **Appearance → Customize → Site Identity** |
