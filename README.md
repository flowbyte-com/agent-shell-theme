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

### Agent operations layer

The plugin implements the **observe → reason → change → verify** loop on top of the base tools. Everything below is deterministic, recorded, and reversible.

**Observe first.** `agentshell_inspect` returns the full machine-readable site model (shell, design, zones, widgets, content, capabilities, state, warnings); `agentshell_explain` renders it as human-readable text; `agentshell_get_capabilities` reports what the installation supports.

| Tool | What it does |
|------|-------------|
| `agentshell_inspect` | Full machine-readable site model — call this first on an unfamiliar site |
| `agentshell_explain` | Human-readable site description generated from live state |
| `agentshell_validate` | Deterministic site doctor — errors + warnings with stable codes |
| `agentshell_get_capabilities` | What this installation supports |
| `agentshell_get_audit_log` | Config mutation history: actor, operation, changed tokens, revision |
| `agentshell_get_design_system` | Structured design tokens (palette, typography, geometry) |
| `agentshell_set_palette` / `set_typography` / `set_spacing` / `set_shape` | Semantic design API — no raw CSS variables needed |
| `agentshell_list_revisions` / `diff_revisions` / `restore_revision` | Config history: every mutation is a revision, all reversible |
| `agentshell_create_snapshot` / `list_snapshots` / `restore_snapshot` / `diff_snapshot` | Named, restorable config checkpoints |
| `agentshell_save_theme_profile` / `list_theme_profiles` / `apply_theme` / `preview_theme` | Named design profiles; preview renders as read-only URL overrides |
| `agentshell_export_theme` / `import_theme` | Portable theme packages (JSON manifest) between installations |
| `agentshell_enable_widget` / `disable_widget` / `remove_widget` | Widget lifecycle (remove = agent-defined widgets only) |
| `agentshell_screenshot` | Headless-browser screenshot of the site or a profile preview (desktop/mobile/tablet viewports) — closes the visual iteration loop |

**Content primitives — intent-level content operations.** Raw HTML is preserved exactly (no `wpautop`/kses for admin agents), matching the REST API behaviour.

| Tool | What it does |
|------|-------------|
| `agentshell_create_page` / `create_post` | Create with raw HTML content: `{ "title", "content", "status", "slug", "parent" }` |
| `agentshell_update_content` | Update title / content / status / slug / parent of an existing post or page |
| `agentshell_publish` / `unpublish` | Flip a post/page to `publish` or back to `draft` |
| `agentshell_search_content` | Search posts/pages; empty query lists most recently modified |
| `agentshell_get_content` | Fetch one record: raw + rendered HTML, status, slug, link, edit URL |

**Transactions — the safe change loop.** Run `agentshell_begin_transaction` → make mutations (staged, nothing persisted — the live site is untouched) → `agentshell_preview_transaction` (token-level diff) → `agentshell_validate_transaction` (doctor on the staged config) → `agentshell_commit_transaction` (one atomic, validated write, recorded as a revision) or `agentshell_rollback_transaction` (discard everything). Transactions persist across MCP requests and are visible via `agentshell_get_transaction`.

**Multi-agent safety.** Transactions are locked to the actor that opened them: a different agent calling `begin_transaction` (or `stage`/`commit`/`rollback` on someone else's transaction) gets a clear error naming the owner, the transaction ID, and when it started. Same-actor re-`begin` is idempotent. Read-only calls (`preview_transaction`, `validate_transaction`, `get_transaction`) work on any open transaction so agents can coordinate.

**Example session** — restyle the site to a terminal look without risking the live site:

```text
agentshell_inspect
agentshell_save_theme_profile(name="current", description="backup")
agentshell_begin_transaction(label="terminal restyle")
agentshell_set_palette({ colors: { background: "#0a0a0a", text: "#00ff88", accent: "#00ff88", surface: "#101010" } })
agentshell_set_typography({ fontFamily: "monospace" })
agentshell_set_shape({ radius: "0px" })
agentshell_preview_transaction
agentshell_validate_transaction
agentshell_commit_transaction
agentshell_get_audit_log
```

**Example session** — draft a page, publish it, and screenshot the result:

```text
agentshell_create_page({ title: "About", content: "<p>...</p>", status: "draft" })
agentshell_publish({ id: 102 })
agentshell_search_content({ type: "page", status: "publish" })
agentshell_get_content({ id: 102 })
agentshell_screenshot({ viewport: "desktop" })        # renders the live site
agentshell_screenshot({ viewport: "mobile", profile: "terminal" })  # profile preview
```

## Screenshot Loop

`agentshell_screenshot` captures the site with the server's headless Chrome/Chromium via CLI flags (`--headless --screenshot`). Images land in `wp-content/uploads/agentshell-screenshots/` and are returned as URLs for the agent to evaluate.

- Backend auto-detected: `google-chrome`, `google-chrome-stable`, `chromium`, `chromium-browser`, `headless_shell` — or set `AGENTSHELL_CHROME_BIN` in `wp-config.php` for an explicit path. `agentshell_get_capabilities` reports `screenshot: true` and the backend only when one is available.
- Viewports: `desktop` (1280×800), `mobile` (390×844), `tablet` (768×1024), or explicit `width`/`height` (320–4096).
- `profile` screenshots render a saved theme profile's tokens as read-only `:root` overrides — the preview URL is signed with an HMAC derived from `AGENTSHELL_REST_TOKEN`, so `AGENTSHELL_REST_TOKEN` must be defined in `wp-config.php` for profile previews. No browser session required.

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
- Widgets have a lifecycle: `active` by default, `disabled` renders nothing (via `agentshell_enable_widget` / `agentshell_disable_widget`), and agent-defined widgets can be removed (`agentshell_remove_widget`)
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
│   ├── agentshell-mcp.php     # Tool registry + version
│   └── includes/
│       ├── class-server.php / class-transport.php
│       ├── class-store.php    # Revisions, audit, snapshots, profiles
│       ├── class-transactions.php  # Safe change loop (begin/stage/commit/rollback, per-actor locks)
│       ├── class-doctor.php   # Deterministic site validator
│       ├── class-content.php  # Content primitives (create/update/status/search)
│       ├── class-screenshot.php    # Headless-browser backend (Chrome/Chromium)
│       └── tools/             # 45+ MCP tools
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
| `A transaction is already open` | Previous transaction was never committed/rolled back — check `agentshell_get_transaction` |
| `Another actor (...) has an open transaction` | A different agent (app-password user) owns the open transaction — wait or coordinate; commit/rollback is locked to the opener |
| `No headless browser found` | `agentshell_screenshot` needs Chrome/Chromium on the server — install it or set `AGENTSHELL_CHROME_BIN` |
| `Commit blocked by validation errors` | Staged config fails the site doctor — fix and re-validate |
| D3/Math.js not available | No registered widget declares the lib in its `libs` array |
| Header logo missing | No custom logo set under **Appearance → Customize → Site Identity** |

## Roadmap

- **Remote screenshot fallback** — use an external browserless/screenshot service when no headless browser is installed on the server
- **Multi-site operations** — run one agent across several AgentShell installations from a single session (import/export already provide the package format)
- **Agent-to-agent messaging** — leave notes/messages for other agents on the site (audit log already records who did what)
