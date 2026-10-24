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

## Versioning and compatibility

Three version axes are kept apart, because conflating them is what made the daemon "scream about a 1.1.0 / 1.3.0 mismatch" on every boot:

| Axis | Value | Meaning |
|---|---|---|
| **Protocol version** | `2025-03-26` | The MCP wire format. Both ends declare it. It is negotiated in the `initialize` handshake: the daemon states the version it speaks, the server answers with the version it will use. |
| **Product version** | `1.3.0` | The AgentShell release the daemon and the `agentshell-mcp` plugin belong to. They are released together and carry the same number — a test keeps them equal so they cannot drift apart unnoticed. |
| **Tool surface** | implied by the product version | The set of `agentshell_*` tools an agent can call. A *major* version apart means the tool surface may differ. |

The compatibility rule:

| Situation | Result |
|---|---|
| Same major version | Compatible. A different minor is a `notice` (verbose only) — two working version numbers are not an operator's problem. |
| Different major version | **Incompatible.** The daemon refuses to serve requests and exits with code `4`. |
| Different protocol version | **Incompatible.** The two ends speak different dialects of MCP. Same refusal, same exit code. |
| Server reports no version / no protocol version | A warning on stderr. "The server did not say" is not "the server said something incompatible". |

A refusal is written to **stderr**, never to stdout: stdout carries the JSON-RPC stream and nothing else. The daemon also sets `display_errors=stderr` at startup, so a PHP notice can never land in the middle of the protocol.

If an operator knows the pairing works — a hot-fix window, a staged rollout — the refusal can be overridden **for one run**:

```bash
AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1 php daemon.php --config ~/.agentshell-mcp.json
```

The override is an environment variable rather than a config-file key on purpose: it is a decision about *this* run, made by a human at the console, and it must not be a setting that silently persists in a file nobody remembers writing.

## Behaviour

- Launches on demand when the connecting agent starts.
- Dies when the agent disconnects (no persistent process).
- Proxies all MCP JSON-RPC messages stdio ↔ HTTP.
- Returns connection errors as JSON-RPC error responses.
- Refuses to serve a server it is not compatible with (exit code `4`), unless the operator overrides it for the run.

## Troubleshooting

| Error | Cause |
|---|---|
| `No route was found` | The `agentshell-mcp` WordPress plugin is not activated. |
| `Authentication failed` | Wrong username or application password. |
| `HTTP request failed` | The URL is not reachable from the host running the daemon. |
| `INCOMPATIBLE: server major version …` / exit code `4` | The daemon and the plugin are a major version apart. Upgrade the daemon, or set `AGENTSHELL_ALLOW_INCOMPATIBLE_SERVER=1` for one run if you know the pairing works. |
| `INCOMPATIBLE: server protocol … does not match daemon protocol …` | The two ends speak different MCP protocol versions. Same remedy. |
| Static token: 401 with `AGENTSHELL_REST_TOKEN_USER_ID is not defined` in `error_log` | The static token is configured but the explicit actor is not. Add `AGENTSHELL_REST_TOKEN_USER_ID` to `wp-config.php`; the user id must reference an existing WordPress user. |

For the tool surface, the bilateral widget registry contract, the data-* / no-fetch laws, the Unbreakable Grid protocol, and the working pattern, see `AGENTS.md`.
