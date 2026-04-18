# AgentShell MCP Daemon

PHP CLI daemon that proxies MCP clients (Claude Code, etc.) to the AgentShell MCP WordPress plugin.

```
Agent (stdio) → Daemon (CLI) → WordPress REST endpoint → agentshell-mcp plugin
```

## Installation

1. Copy `agentshell-mcp-daemon/` to desired location
2. No external dependencies — uses PHP's native `stream_context` for HTTP

## Configuration

Create `~/.agentshell-mcp.json` (or any path):

```json
{
  "url": "https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp",
  "user": "agent_user",
  "pass": "XXXX XXXX XXXX XXXX XXXX XXXX",
  "timeout": 30
}
```

File mode must be `0600` to protect credentials.

## Usage

```bash
php daemon.php --config ~/.agentshell-mcp.json
```

Options:
- `--config` Path to config JSON (default: `~/.agentshell-mcp.json`)
- `--verbose` Print JSON-RPC messages to stderr for debugging

## Claude Code Configuration

Add to `~/.claude/settings.json`:

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

## Daemon Behavior

- Launches on demand when the connecting agent starts
- Dies when the agent disconnects (no persistent process)
- Proxies all MCP JSON-RPC messages stdio ↔ HTTP
- Returns connection errors as JSON-RPC error responses

## Troubleshooting

**"No route was found"** — The WordPress plugin is not activated. Activate at `/wp-admin/plugins.php`.

**"Authentication failed"** — Check username and application password are correct.

**"HTTP request failed"** — Check the URL is reachable from the host running the daemon.
