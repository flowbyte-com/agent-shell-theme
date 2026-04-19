# AgentShell — Agent Guide

> **Quick start:** Connect via the MCP daemon at `~/.agentshell-mcp.json`. The daemon exposes tools for all AgentShell operations. Run `agentshell_list_zones` first to see what's available.

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

The daemon is a PHP CLI process. It proxies MCP JSON-RPC stdio ↔ HTTP. WordPress plugin does the actual work.

---

## Tools (MCP)

All tools are prefixed `agentshell_`. Call with empty arguments unless noted.

| Tool | What it does |
|------|-------------|
| `agentshell_get_config` | Return full config (CSS vars, zones, layout, widgets) |
| `agentshell_set_css_var` | Set one CSS variable: `{ name: "--theme-accent", value: "#ff0000" }` |
| `agentshell_set_design` | Update colors/typography: `{ colors: { accent: "#ff0000" }, typography: { fontFamily: "serif" } }` |
| `agentshell_list_zones` | List all zones with IDs, labels, and current sources |
| `agentshell_set_zone_source` | Change zone source: `{ zone_id: "main", source: "json_block", config: { html: "..." } }` |
| `agentshell_inject_json_block` | Inject HTML into a zone (admin-only; strips style/script for safety) |
| `agentshell_list_widgets` | List registered widgets |
| `agentshell_register_widget` | Register a widget: `{ id, label, css, init_js }` |
| `agentshell_set_layout` | Update grid areas / breakpoints |
| `agentshell_get_site_info` | Get site name, URL, admin email |

---

## What You May Edit

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

WordPress post/page editing still uses the WP REST API directly:

```
PUT /wp/v2/pages/<id>  →  { "content": { "raw": "<p>...</p>" } }
POST /wp/v2/posts      →  create a post
```

WordPress auto-formatting (`wpautop`, etc.) is disabled — your raw HTML is preserved.

### Sidebar Toggle

```
agentshell_set_layout({ sidebar_enabled: true })
```

### Zones

Use `agentshell_set_zone_source` to route a zone to `json_block` (raw HTML, style/script stripped) or `widget` (registered widget). Default zones come from `default-config.json` on first activation.

---

## What You Must NOT Do

- Edit `header.php`, `footer.php`, or style.css Sections 3–4 (the grid)
- Use inline event handlers (`onclick=""`, `onerror=""`) or `<script>` tags in post content
- Set colors outside the CSS variable system
- Inject content outside `#zone-main` without going through the zone registry

---

## Web Component Widgets

For charts, calculators, terminals — anything requiring JS — use Web Components with Shadow DOM.

**Pre-loaded:** `window.d3` (D3.js v7), `window.math` (Math.js 11.8). Do NOT inject `<script src="">` for these.

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

Rules: guard `customElements.define` with `if (!customElements.get(...))`, put all CSS inside Shadow DOM, use `var(--theme-*)` for theming, prefix custom elements `mpm-`.

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

Claude Code: add to `~/.claude/settings.json`:

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
├── default-config.json        # Seed file for wp_options
├── AGENTS.md                  # This document
├── agentshell-mcp/            # WordPress plugin (JSON-RPC server)
│   ├── agentshell-mcp.php
│   └── includes/
│       ├── class-server.php
│       ├── class-transport.php
│       └── tools/             # 10 MCP tools
├── agentshell-mcp-daemon/     # PHP CLI proxy (stdio ↔ HTTP)
│   ├── daemon.php
│   ├── src/
│   │   ├── Client.php
│   │   ├── JsonRpc.php
│   │   └── Transport.php
│   └── README.md
└── template-parts/
    ├── shell-render.php       # Zone renderer
    ├── grid-areas.php         # Layout CSS generator
    └── widgets.php           # Widget CSS renderer
```

---

## Requirements

- WordPress 6.0+, PHP 7.4+
- agentshell-mcp plugin activated
- agentshell-mcp-daemon running (for MCP clients)
- No paid plugins or dependencies

---

## Troubleshooting

| Error | Cause |
|-------|-------|
| `No route was found` | Plugin not activated |
| `Authentication failed` | Wrong username or app password |
| `HTTP request failed` | Daemon can't reach the WP endpoint |