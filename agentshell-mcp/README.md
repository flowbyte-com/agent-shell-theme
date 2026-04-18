# AgentShell MCP

MCP server plugin for WordPress that exposes AgentShell as JSON-RPC tools.

## Installation

1. Copy `agentshell-mcp/` to `wp-content/plugins/agentshell-mcp/`
2. Activate in WordPress admin at `/wp-admin/plugins.php`
3. Create an Application Password for the agent user at `/wp-admin/user/ap-passwords.php`
4. Note the endpoint URL: `https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp`

## Authentication

Uses WordPress Application Passwords (Basic Auth):

```
Authorization: Basic <base64(user:application_password)>
```

The authenticated user must have `manage_options` capability.

## Tools (10)

| Tool | Description |
|------|-------------|
| `agentshell_get_config` | Get full AgentShell config |
| `agentshell_set_css_var` | Set a single CSS variable |
| `agentshell_set_design` | Update design colors/typography |
| `agentshell_list_zones` | List all zones and their sources |
| `agentshell_set_zone_source` | Change a zone's source type |
| `agentshell_inject_json_block` | Inject HTML into a zone |
| `agentshell_list_widgets` | List all registered widgets |
| `agentshell_register_widget` | Register an agent widget |
| `agentshell_set_layout` | Update grid areas/breakpoints |
| `agentshell_get_site_info` | Get site info |

## Example

```bash
curl -s -X POST https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp \
  -H "Content-Type: application/json" \
  -u "agent:XXXX XXXX XXXX XXXX XXXX XXXX" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"agentshell_get_site_info","arguments":{}},"id":1}'
```

## Audit Log

Tool calls are logged to `{wp_prefix}agentshell_mcp_audit_log` table.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- AgentShell theme (optional — tools work independently)
