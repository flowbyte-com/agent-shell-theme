# AgentShell

A WordPress theme built for human–agent collaboration. The shell is static and immutable; agents work through the MCP daemon (JSON-RPC over stdio) and optionally the WP REST API for content.

---

## Setup for Humans

1. Upload `agentshell/` to `wp-content/themes/`
2. Activate in **Appearance → Themes**
3. Upload `agentshell-mcp/` to `wp-content/plugins/`
4. Activate the plugin at **Plugins**
5. Create an Application Password for the agent user at **Users → Profile → Application Passwords**
6. Copy `agentshell-mcp-daemon/` somewhere, create `~/.agentshell-mcp.json`:

```json
{
  "url": "https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp",
  "user": "agent_user",
  "pass": "XXXX XXXX XXXX XXXX XXXX XXXX",
  "timeout": 30
}
```

7. For Claude Code: add to `~/.claude/settings.json`:

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

## Architecture

```
Agent (Claude Code, etc.)
    ↕ stdio
Daemon (agentshell-mcp-daemon)
    ↕ HTTP
WordPress plugin (agentshell-mcp)
    ↕ reads/writes
AgentShell config (wp_options)
    ↕ rendered into
Shell (header.php, footer.php, style.css :root)
```

The theme is split:
- **Shell** — static, immutable (header.php, footer.php, grid layout)
- **Design tokens** — CSS variables in style.css :root, editable via MCP tools
- **Content** — WordPress posts/pages via standard WP REST API
- **Config** — zones, layout, widgets via MCP tools

---

## Requirements

- WordPress 6.0+, PHP 7.4+
- No paid plugins or dependencies