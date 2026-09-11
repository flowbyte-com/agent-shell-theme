# agentshell-mcp (WordPress plugin)

> **Pointer document.** The plugin ships **45+ `agentshell_*` MCP tools** (50 in this plugin + 4 in `agentshell-blocks` at the time of writing). The full authoritative list is in [`AGENTS.md` §2](../../AGENTS.md). Older counts ("10 tools", "11 tools", "12 tools") in any document are deprecated. This README is install + auth only; it does not redefine the tool surface.

## Installation

1. Copy `agentshell-mcp/` to `wp-content/plugins/agentshell-mcp/`.
2. Activate in WordPress admin at `/wp-admin/plugins.php`.
3. Create an Application Password for the agent user.
4. Note the endpoint URL: `https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp`.

## Authentication

Uses WordPress Application Passwords (Basic Auth):

```
Authorization: Basic <base64(user:application_password)>
```

The authenticated user must have `manage_options` capability.

## Tool surface

The plugin exposes the configuration, observation, transaction, revision, snapshot, profile, design, content, and screenshot tool families listed in `AGENTS.md` §2. Discover at runtime with `agentshell_get_capabilities`. Tool names are stable across releases; new tools only get added (never removed or renamed).

The bilateral widget registry is implemented jointly with `agentshell-blocks` (see `AGENTS.md` §4.1). Removing or "rewriting" the registry is not supported; the merge is the contract.

## Example

```bash
curl -s -X POST https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp \
  -H "Content-Type: application/json" \
  -u "agent:XXXX XXXX XXXX XXXX XXXX XXXX" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"agentshell_get_site_info","arguments":{}},"id":1}'
```

## Audit log

Tool calls are logged to `{wp_prefix}agentshell_mcp_audit_log` table. Fetch with `agentshell_get_audit_log`.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- AgentShell theme (recommended — tools work independently but the canonical contract is in the theme)
