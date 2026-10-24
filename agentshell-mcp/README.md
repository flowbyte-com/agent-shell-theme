# agentshell-mcp (WordPress plugin)

> **Pointer document.** The plugin ships **69 `agentshell_*` MCP tools** (66 in this plugin + 3 in `agentshell-blocks`). The full authoritative list is in [`AGENTS.md` §2](../../AGENTS.md). Older counts ("10 tools", "11 tools", "12 tools") in any document are deprecated. This README is install + auth only; it does not redefine the tool surface.

## Installation

1. Copy `agentshell-mcp/` to `wp-content/plugins/agentshell-mcp/`.
2. Activate in WordPress admin at `/wp-admin/plugins.php`.
3. Create an Application Password for the agent user.
4. Note the endpoint URL: `https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp`.

## Authentication

The MCP transport supports two authentication methods. Both are
gated by the same per-tool capability checks (manage_options for the
MCP transport, edit_theme_options for the theme REST endpoint).

### Method 1 — Application Password (Basic Auth)

The default, recommended method. Issue a WordPress Application
Password for the agent user from `/wp-admin/profile.php` and pass it
via Basic Auth:

```
Authorization: Basic <base64(user:application_password)>
```

The authenticated user must have `manage_options` capability.
Application Password authentication is unchanged from prior releases.

### Method 2 — Static token (X-AgentShell-Token header)

For headless daemons or environments where Basic Auth is awkward. Both
constants below **must** be defined in `wp-config.php`; static-token
authentication fails closed if either is missing.

```php
// Shared secret. Treat like a database root password.
define( 'AGENTSHELL_REST_TOKEN', '<long-random-string>' );

// Explicit WordPress actor id the token resolves to. The user must
// exist. The token no longer silently becomes database user ID 1 —
// configuring the actor is mandatory going forward.
define( 'AGENTSHELL_REST_TOKEN_USER_ID', 7 );
```

Then send the header on each request:

```
X-AgentShell-Token: <long-random-string>
```

**Operator checklist**

- The configured user id must reference an existing WordPress user.
- The configured user must hold the capabilities required by the
  operations you intend to run (manage_options for MCP tools,
  edit_theme_options for theme REST endpoints, unfiltered_html for
  raw content).
- Token comparison is timing-safe (constant-time); do not substitute
  your own comparison.
- A pre-fix installation that defined only `AGENTSHELL_REST_TOKEN`
  will see every static-token request denied after this release.
  Adding `AGENTSHELL_REST_TOKEN_USER_ID` (with a real user id) is
  the only remediation. The `error_log` line on a denied request
  tells the operator exactly which constant is missing.

Application Password authentication remains the recommended default
where practical. Use the static token only when Basic Auth is genuinely
unavailable (e.g. some reverse-proxy configurations strip the
Authorization header on cached routes).

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

For the static-token path, replace the `-u` Basic Auth with
`-H 'X-AgentShell-Token: <token>'`.

## Audit log

Tool calls are logged to `{wp_prefix}agentshell_mcp_audit_log` table with
bounded retention (30 days / 10000 rows; see
`Agentshell_Audit_Log::prune()`). Fetch the option-based history with
`agentshell_get_audit_log`.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- AgentShell theme (recommended — tools work independently but the canonical contract is in the theme)
