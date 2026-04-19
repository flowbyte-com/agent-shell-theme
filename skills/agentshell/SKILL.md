---
name: agentshell
description: Use when working with the AgentShell WordPress theme. Covers MCP tools, config via REST API, safe HTML injection, Web Components, and the Unbreakable Grid protocol.
---

# AgentShell Skill

Agents MUST use this skill when working with the AgentShell WordPress theme. All operations flow through the MCP daemon (`~/.agentshell-mcp.json`) which proxies JSON-RPC to the WordPress plugin.

---

## Architecture

```
Agent (Claude Code, etc.)
    ↕ stdio (MCP JSON-RPC)
Daemon (agentshell-mcp-daemon)
    ↕ HTTP (MCP over REST)
WordPress plugin (agentshell-mcp)
    ↕ reads/writes
AgentShell config (wp_options)
    ↕ rendered into
Shell (header.php, footer.php, style.css)
```

The daemon is a PHP CLI process. It proxies MCP JSON-RPC stdio ↔ HTTP. The WordPress plugin does the actual work. The sole source of truth after activation is `wp_options['agentshell_config']` — not the physical `default-config.json`.

---

## Tools (11 total)

All tools are prefixed `agentshell_`. Call with empty arguments unless noted.

| Tool | What it does | Key args |
|------|-------------|----------|
| `agentshell_get_config` | Return full config (CSS vars, zones, layout, widgets) | (none) |
| `agentshell_set_css_var` | Set one CSS variable | `name` (must start with `--`), `value` |
| `agentshell_set_design` | Update colors/typography | `colors{}`, `typography{}` |
| `agentshell_list_zones` | List all zones with IDs, labels, and current sources | (none) |
| `agentshell_set_zone_source` | Change zone source type | `zone_id`, `source` (`wp_loop`/`wp_widget_area`/`json_block`/`widget`), `widget_area_id?` |
| `agentshell_inject_json_block` | Inject raw HTML into a zone | `zone_id`, `html` |
| `agentshell_update_post_content` | Update a post/page HTML content | `post_id` (integer), `html_content` |
| `agentshell_list_widgets` | List all registered widgets (stable + agent) | (none) |
| `agentshell_register_widget` | Register or update an agent-defined widget | `id`, `name`, `init_js?`, `css?`, `template?` |
| `agentshell_set_layout` | Update grid areas/breakpoints/gap/padding | `breakpoints?`, `grid_areas?`, `grid_gap?`, `grid_padding?` |
| `agentshell_get_site_info` | Get site name, URL, version | (none) |

---

## Trust Model

Every tool requires `manage_options` capability (WordPress admin). There is one exception to standard stripping:

**Trusted users** (those with `unfiltered_html` OR `manage_options` capability):
- `<script>` tags pass through intact in `agentshell_inject_json_block` and `agentshell_update_post_content`
- No `wp_kses_post()` sanitization is applied
- This allows custom Web Components using `customElements.define`

**Untrusted users**:
- `<script>` tags are stripped
- Content is sanitized via `wp_kses_post()`

The trust check:
```php
$trusted = current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );
```

---

## The Unbreakable Grid

Regardless of trust level, two things are ALWAYS stripped before any other processing:

1. **Standalone `<style>` tags and their contents** — prevents CSS Grid overrides
2. **Inline `style=""` attributes** — prevents per-element layout breaking

```php
$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
$html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );
```

This rule applies to `agentshell_inject_json_block` and `agentshell_update_post_content`.

---

## What You May Do

### CSS Variables (Design Tokens)

Edit via `agentshell_set_css_var` or `agentshell_set_design`. Changes persist to `wp_options` and are injected as `:root` CSS on every page load.

Available variables:
```css
--theme-bg, --theme-surface, --theme-text, --theme-border, --theme-accent
--theme-header-bg, --theme-header-text
--theme-footer-bg, --theme-footer-text
--font-base, --font-mono
--spacing-base, --radius-base
--content-max-width, --sidebar-width, --container-padding
```

### Content in `#zone-main`

Use `agentshell_update_post_content({ post_id, html_content })` to update post/page HTML directly. This uses `wp_update_post()`.

Alternatively use the WP REST API:
```
PUT /wp/v2/pages/<id>  →  { "content": { "raw": "<p>...</p>" } }
POST /wp/v2/posts      →  create a post
```

WordPress auto-formatting (`wpautop`, etc.) is disabled — raw HTML is preserved.

### Sidebar Toggle
```
agentshell_set_layout({ sidebar_enabled: true })
```

### Zones

Use `agentshell_set_zone_source` to route a zone to `json_block` (raw HTML) or `widget` (registered widget). Default zones come from `default-config.json` on first activation.

---

## What You Must NOT Do

- Edit `header.php`, `footer.php`, or `style.css` Sections 3–4 (the fixed CSS Grid)
- Use inline event handlers (`onclick=""`, `onerror=""`) or `<script>` tags in post content (unless you are a trusted user)
- Set colors outside the CSS variable system
- Inject content outside declared zones without going through the zone registry
- Use `<style>` tags or `style=""` attributes in `json_block` content — these are always stripped
- **Do not write client-side `fetch()` calls to load config or data.** You are an agent: use `agentshell_get_config` to read state first, then hardcode/pre-compile those specific values into your HTML/CSS payload before pushing it. SPA-style data fetching leaks credentials and bypasses the audit log.

---

## Registered Widgets

When using `agentshell_register_widget`, the HTML is injected directly into the normal DOM — **not** into a Shadow DOM. This is Light DOM.

Do NOT use `:host` or assume Shadow DOM isolation in the `css` field. You MUST scope all CSS rules to a specific wrapper class to prevent styles from bleeding into the shell.

**Correct:**
```css
.mpm-my-widget .card { background: var(--theme-surface); padding: 1rem; }
.mpm-my-widget .title { color: var(--theme-accent); }
```

**Wrong (will bleed):**
```css
.card { background: var(--theme-surface); }          /* no wrapper — hits everything */
:host { display: block; }                           /* :host is Shadow DOM only */
```

The `template` field renders inside `<div data-widget="id">`. Wrap every CSS selector with the widget ID class (e.g., `.mpm-my-widget`) as shown above.

---

## Web Component Widgets

**CRITICAL: Your payload MUST include BOTH the `<script>` block defining the component AND the actual physical HTML tag (e.g., `<mpm-my-widget></mpm-my-widget>`) to mount it on the page. Defining the class without the tag results in a blank page.**

For charts, calculators, terminals — anything requiring JS — use Web Components with Shadow DOM.

**Pre-loaded in the theme:** `window.d3` (D3.js v7), `window.math` (Math.js 11.8). Do NOT inject `<script src="">` for these.

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
                <style>.chart { color: var(--theme-text); }</style>
                <div class="chart">...</div>
            `;
        }
    }
    customElements.define('mpm-memory-chart', MemoryChart);
}
</script>
```

**Rules:**
- Guard `customElements.define` with `if (!customElements.get(...))`
- Put all CSS inside Shadow DOM (not in `style=""` attributes)
- Use `var(--theme-*)` for theming
- Prefix custom elements `mpm-`

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
agentshell/
├── style.css                  # Theme declaration + :root tokens + grid
├── functions.php              # Config helpers, asset enqueue, widget libs
├── header.php                 # Static shell HTML
├── footer.php                 # Static shell HTML + custom_js + widget init
├── default-config.json        # Seed file for wp_options (read once on activation)
├── skills/agentshell/SKILL.md # This skill
├── agentshell-mcp/           # WordPress plugin (JSON-RPC server)
│   ├── agentshell-mcp.php
│   └── includes/tools/       # 11 MCP tools
└── agentshell-mcp-daemon/    # PHP CLI proxy (stdio ↔ HTTP)
    └── daemon.php
```

---

## Troubleshooting

| Error | Cause |
|-------|-------|
| `No route was found` | Plugin not activated |
| `Authentication failed` | Wrong username or app password |
| `HTTP request failed` | Daemon can't reach the WP endpoint |
| `<script>` tags missing in output | User lacks `unfiltered_html` capability — scripts were stripped |
