# agentshell-mcp-daemon

> **Pointer document.** The daemon is a transport; it does not define the agent contract. The canonical guide is [`AGENTS.md`](../../AGENTS.md).

PHP CLI daemon that proxies MCP clients (Claude Code, etc.) to the AgentShell MCP WordPress plugin.

```
Agent (MCP client, stdio)
  → Daemon (PHP CLI, stream_context)
  → WordPress REST endpoint
  → agentshell-mcp plugin
  → agentshell-blocks plugin (widget tools + bilateral registry)
```

## Installation

1. Copy `agentshell-mcp-daemon/` to a permanent location.
2. No external dependencies — uses PHP's native `stream_context` for HTTP.

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

- `--config` — path to config JSON (default `~/.agentshell-mcp.json`).
- `--verbose` — print JSON-RPC messages to stderr for debugging.

## Claude Code configuration

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

## Behaviour

- Launches on demand when the connecting agent starts.
- Dies when the agent disconnects (no persistent process).
- Proxies all MCP JSON-RPC messages stdio ↔ HTTP.
- Returns connection errors as JSON-RPC error responses.

## Troubleshooting

| Error | Cause |
|---|---|
| `No route was found` | The `agentshell-mcp` WordPress plugin is not activated. |
| `Authentication failed` | Wrong username or application password. |
| `HTTP request failed` | The URL is not reachable from the host running the daemon. |

For the tool surface, the bilateral widget registry contract, the data-* / no-fetch laws, the Unbreakable Grid protocol, and the working pattern, see `AGENTS.md`.
