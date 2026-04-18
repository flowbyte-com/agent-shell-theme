# AgentShell MCP Plugin — Design Specification

## Overview

A standalone MCP server plugin for WordPress that exposes AgentShell-specific tools to AI agents. Provides a constrained, self-documenting interface that keeps agents focused on AgentShell's JSON Registry OS model rather than exploring generic WordPress APIs.

**Two components:**

1. **WP Plugin** (`agentshell-mcp/`) — WordPress plugin that registers a MCP JSON-RPC REST endpoint (`/wp-json/agentshell-mcp/v1/mcp`)
2. **PHP Daemon** (`agentshell-mcp-daemon/`) — stdio MCP client that proxies between an AI agent (Claude Code, etc.) and the WordPress REST endpoint

```
Agent (stdio)
    ↓
agentshell-mcp-daemon (PHP CLI, launched on demand)
    ↓ HTTP + Basic Auth
WordPress REST endpoint (/wp-json/agentshell-mcp/v1/mcp)
    ↓
agentshell-mcp plugin (auth + MCP handling)
    ↓
AgentShell config (wp_options['agentshell_config'])
```

---

## WP Plugin: agentshell-mcp

**Location:** `wp-content/plugins/agentshell-mcp/`

### REST Endpoint

```
POST /wp-json/agentshell-mcp/v1/mcp
Authorization: Basic <base64(user:app_password)>
Content-Type: application/json

{ jsonrpc, method, params, id }
→ { jsonrpc, result|error, id }
```

### Authentication

- WP Application Passwords (Basic Auth header)
- Authenticated user must have `manage_options` capability (only admins configure the shell)
- Audit log entry written on each tool call

### MCP Server Implementation

Extends the MCP protocol handler from `class-server.php` in Easy MCP AI, simplified for AgentShell-only tools. Reuses:
- `JSON_RPC::parse_request()` — JSON-RPC message parsing
- `Error_Codes` — standard error codes

New class: `class-agentshell-server.php` (standalone, no Easy MCP AI dependency):

```php
class AgentShell_Server {
    const PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME = 'agentshell-mcp';

    // Handles: initialize, ping, tools/list, tools/call
    // No resources/prompts (not needed for AgentShell)
}
```

### Tool Registry (10 tools)

All tools are read-only or validate+write through `agentshell_*` functions in `functions.php`. No direct DB writes.

#### 1. `agentshell_get_config`

**Description:** Get the full AgentShell configuration including zones, design, layout, widgets, and CSS variables.

**Arguments:** None

**Returns:** Full flattened config object

---

#### 2. `agentshell_set_css_var`

**Description:** Set a single CSS custom property (variable) on the theme. Variables are injected as `:root` CSS properties and apply immediately.

**Arguments:**
```json
{
  "name": "--theme-accent",
  "value": "#ff6600"
}
```

**Validation:**
- `name` must start with `--`
- `value` must be a valid CSS value (string, max 500 chars)

**Returns:** `{ "name": "--theme-accent", "value": "#ff6600" }`

---

#### 3. `agentshell_set_design`

**Description:** Update design system values (colors, typography). All fields optional — only provided fields are updated.

**Arguments:**
```json
{
  "colors": {
    "background": "#ffffff",
    "surface": "#f4f4f5",
    "text": "#18181b",
    "accent": "#3b82f6",
    "border": "#e4e4e7"
  },
  "typography": {
    "fontFamily": "system-ui, sans-serif",
    "baseSize": "1rem"
  }
}
```

**Returns:** Updated design object

---

#### 4. `agentshell_list_zones`

**Description:** List all declared zones with their current source type and configuration.

**Arguments:** None

**Returns:**
```json
{
  "zones": [
    { "id": "header", "label": "Header", "source": "wp_loop" },
    { "id": "main",   "label": "Main",   "source": "wp_loop" },
    { "id": "sidebar","label": "Sidebar","source": "wp_widget_area", "widget_area_id": "primary-sidebar" },
    { "id": "footer", "label": "Footer", "source": "wp_loop" }
  ]
}
```

---

#### 5. `agentshell_set_zone_source`

**Description:** Change a zone's content source type. Use to switch a zone from wp_loop to json_block, or to change widget_area_id.

**Arguments:**
```json
{
  "zone_id": "sidebar",
  "source": "wp_widget_area",
  "widget_area_id": "primary-sidebar"
}
```

**Validation:**
- `zone_id` must exist in zones array
- `source` must be one of: `wp_loop`, `wp_widget_area`, `json_block`, `widget`
- `widget_area_id` required when `source` is `wp_widget_area`

**Returns:** Updated zone object

---

#### 6. `agentshell_inject_json_block`

**Description:** Inject raw HTML into a specific zone via the json_block source type. Server-side HTML sanitization applied (wp_kses_post). Script tags and style attributes are stripped.

**Arguments:**
```json
{
  "zone_id": "header",
  "html": "<div class=\"agent-shell\"><h1>Welcome</h1></div>"
}
```

**Validation:**
- `zone_id` must exist in zones array
- `html` max 10,000 characters
- `<script>` tags and `style=""` attributes stripped server-side

**Returns:** `{ "zone_id": "header", "source": "json_block", "html_length": 52 }`

---

#### 7. `agentshell_list_widgets`

**Description:** List all registered widgets — both stable (file-based) and agent-defined (from wp_options). Shows merged registry.

**Arguments:** None

**Returns:**
```json
{
  "widgets": [
    { "id": "hello-world", "name": "Hello World", "source": "stable", "version": "1.0.0" },
    { "id": "my-widget", "name": "My Widget", "source": "agent" }
  ]
}
```

---

#### 8. `agentshell_register_widget`

**Description:** Register or update an agent-defined widget. Persisted to `wp_options['agentshell_config']['widgets']`.

**Arguments:**
```json
{
  "id": "my-chart",
  "name": "My Chart",
  "init_js": "window.AgentshellWidgets = window.AgentshellWidgets || {}; window.AgentshellWidgets['my-chart'] = { init: function(el) { el.innerHTML = '<canvas id=\"chart\"></canvas>'; } };",
  "css": ".my-chart canvas { max-width: 100%; }",
  "template": "<div data-widget-id=\"my-chart\" class=\"my-chart\"></div>"
}
```

**Validation:**
- `id` required, alphanumeric + dash/underscore, max 50 chars
- `name` required, max 100 chars
- `init_js` optional, max 5,000 chars
- `css` optional, max 5,000 chars
- `template` optional, max 2,000 chars

**Returns:** Registered widget object

---

#### 9. `agentshell_set_layout`

**Description:** Update layout configuration including grid areas per breakpoint, gap, and padding.

**Arguments:**
```json
{
  "breakpoints": { "mobile": "0px", "tablet": "768px", "desktop": "1024px" },
  "grid_areas": {
    "mobile": ["header", "main", "footer"],
    "tablet": ["header header", "main sidebar", "footer footer"],
    "desktop": ["header header", "main sidebar", "footer footer"]
  },
  "grid_gap": "1rem",
  "grid_padding": "2rem"
}
```

**Validation:**
- `breakpoints` values must be valid CSS pixel values
- `grid_areas` values must reference only declared zone IDs
- `grid_gap` must be valid CSS value
- `grid_padding` must be valid CSS value

**Returns:** Updated layout object

---

#### 10. `agentshell_get_site_info`

**Description:** Get basic site information for agent context.

**Arguments:** None

**Returns:**
```json
{
  "name": "My WordPress Site",
  "url": "https://example.com",
  "agentshell_version": "1.0.0",
  "theme_name": "AgentShell",
  "plugin_url": "http://example.com/wp-content/plugins/agentshell-mcp"
}
```

---

### File Structure

```
agentshell-mcp/
├── agentshell-mcp.php              # Plugin bootstrap
├── includes/
│   ├── class-json-rpc.php          # JSON-RPC message handling (simplified)
│   ├── class-error-codes.php       # Error codes
│   ├── class-server.php            # MCP server (initialize, tools/list, tools/call)
│   ├── class-transport.php         # REST endpoint handler
│   ├── class-auth.php              # WP Application Password auth
│   └── tools/
│       ├── class-base-tool.php     # Base tool abstract class
│       ├── class-registry.php      # Tool registry
│       ├── class-get-config.php
│       ├── class-set-css-var.php
│       ├── class-set-design.php
│       ├── class-list-zones.php
│       ├── class-set-zone-source.php
│       ├── class-inject-json-block.php
│       ├── class-list-widgets.php
│       ├── class-register-widget.php
│       ├── class-set-layout.php
│       └── class-get-site-info.php
└── README.md
```

---

## PHP Daemon: agentshell-mcp-daemon

**Location:** `agentshell-mcp-daemon/` (sibling to `agentshell-mcp/` plugin directory, or standalone)

### Purpose

A PHP CLI process that acts as an MCP client. Connects Claude Code (via stdio) to the WordPress REST endpoint (via HTTP + Basic Auth).

```
stdin ← Claude Code MCP protocol
    ↓
Daemon (PHP CLI)
    ↓ HTTP POST + Basic Auth
WordPress /wp-json/agentshell-mcp/v1/mcp
    ↓
WordPress response
    ↓
Daemon
    ↓
stdout → Claude Code MCP protocol
```

### CLI Arguments

```
php daemon.php --url <wp_rest_url> --user <wp_user> --pass <app_password> [--timeout 30]
```

**Required:**
- `--url` — WordPress REST URL, e.g. `https://example.com/wp-json/agentshell-mcp/v1/mcp`
- `--user` — WordPress username
- `--pass` — Application password

**Optional:**
- `--timeout` — HTTP request timeout in seconds (default: 30)
- `--verbose` — Print JSON-RPC messages to stderr for debugging

### Protocol Details

- Uses `initialize` to announce server capabilities to Claude Code
- Single-message JSON-RPC over stdio (one request/response per line, newline-delimited)
- Supports batching (array of JSON-RPC messages)
- Handles `notifications/initialized` (no response expected)
- Daemon lifetime: runs until stdin closes (agent disconnects) or fatal error

### Error Handling

- Connection errors: return JSON-RPC error response with code `-32000`
- WP auth failure: return JSON-RPC error response with code `-32001`
- JSON parse errors on stdin: log to stderr, skip line
- Timeout: return JSON-RPC error response with code `-32002`

### File Structure

```
agentshell-mcp-daemon/
├── daemon.php               # Main entry point
├── src/
│   ├── Transport.php        # stdio read/write
│   ├── Client.php          # HTTP client wrapping WP REST endpoint
│   ├── JsonRpc.php          # JSON-RPC message builder/parser
│   └── ServerInfo.php      # Server capabilities announcement
├── composer.json           # Guzzle HTTP dependency
└── README.md
```

---

## MCP Configuration for Claude Code

Agents connect by adding to their MCP server config:

**`~/.claude/settings.json`** (or project-level):

```json
{
  "mcpServers": {
    "agentshell": {
      "command": "php",
      "args": ["/path/to/daemon.php", "--url", "https://example.com/wp-json/agentshell-mcp/v1/mcp", "--user", "agent", "--pass", "XXXX XXXX XXXX XXXX XXXX XXXX"],
      "env": {}
    }
  }
}
```

Alternatively, a small wrapper script:

```bash
#!/bin/bash
# agentshell-mcp — launch the daemon with credentials from env
exec php /path/to/daemon.php \
  --url "${AGENTSHELL_WP_URL}" \
  --user "${AGENTSHELL_WP_USER}" \
  --pass "${AGENTSHELL_WP_PASS}"
```

---

## Security Considerations

1. **Auth:** Only WP users with `manage_options` can access the MCP endpoint
2. **Token storage:** Application passwords stored by the agent (env var or config file), never in WordPress DB
3. **Content sanitization:** All json_block HTML sanitized via `wp_kses_post()` server-side before storage
4. **Structural prohibition:** AgentShell's existing CSS grid-fix rules prevent agents from breaking layout via injected CSS
5. **Rate limiting:** Plugin should implement per-user rate limiting (future enhancement)
6. **Audit logging:** Each tool call logged to a dedicated `agentshell_mcp_audit_log` table

---

## Installation

### WordPress Plugin

1. Copy `agentshell-mcp/` to `wp-content/plugins/agentshell-mcp/`
2. Activate in WordPress admin
3. Create an Application Password for the agent user at `/wp-admin/user/apasswords.php`
4. Note the URL: `https://example.com/wp-json/agentshell-mcp/v1/mcp`

### Daemon

1. Copy `agentshell-mcp-daemon/` to desired location
2. Run `composer install` to fetch Guzzle
3. Configure agent with `--url`, `--user`, `--pass` arguments

---

## Future Enhancements (Out of Scope for v1)

- Per-token tool restrictions (subset of AgentShell tools)
- SSE streaming transport option
- Webhook triggers for AgentShell config changes
- Integration with Easy MCP AI token management system
