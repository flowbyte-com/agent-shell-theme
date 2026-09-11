# CLAUDE.md

> **Pointer document.** The canonical agent contract is [`AGENTS.md`](./AGENTS.md). If anything here disagrees with `AGENTS.md`, `AGENTS.md` wins. This file is Claude Code orientation only.

## Read this first

1. Open `AGENTS.md` and treat it as the single source of truth.
2. Use the **45+ `agentshell_*` MCP tools** (full list in `AGENTS.md` §2). The plugin + daemon (`agentshell-mcp` + `agentshell-mcp-daemon`) expose every operation; do not call raw REST endpoints unless the daemon is unavailable.
3. Run the loop in `AGENTS.md` §6: `inspect` → `validate` → `begin_transaction` → mutations → `preview_transaction` / `validate_transaction` → `commit_transaction` (or `rollback_transaction`).

## Architecture (one line)

`Agent (MCP client) → agentshell-mcp-daemon → agentshell-mcp WordPress plugin → bilateral widget registry (file-based + config) → shell (header.php / footer.php / style.css)`. Full diagram: `AGENTS.md` §1.

## What Claude Code can do

- Drive everything through the `agentshell_*` MCP tools. The `AGENTS.md` reference lists every tool.
- Theme all zones via CSS variables: `agentshell_set_css_var`, `agentshell_set_design`, `agentshell_set_palette`, `agentshell_set_typography`, `agentshell_set_spacing`, `agentshell_set_shape`.
- Compose zones: `agentshell_update_zone_slots` (header/footer), `agentshell_update_zone_composition` (main).
- Register widgets: `agentshell_register_widget`. Lifecycle: `agentshell_enable_widget` / `agentshell_disable_widget` / `agentshell_remove_widget` (config-registered only).
- Create / publish / search content: `agentshell_create_page` / `create_post`, `update_content`, `publish` / `unpublish`, `search_content`, `get_content`.
- Verify visually: `agentshell_screenshot`. Validate: `agentshell_validate`. Audit: `agentshell_get_audit_log`. Time travel: `agentshell_restore_revision`, `agentshell_restore_snapshot`.

## Hard rules (cross-references to `AGENTS.md`)

- **No edits** to `header.php`, `footer.php`, `style.css` Sections 3 & 4, or `template-parts/*` (see `AGENTS.md` §5 and §7).
- **No client-side `fetch()`** in any widget. Data is `data-*` only. (`AGENTS.md` §4.2 / §4.3.)
- **No `<script type="application/json">` hydration** — `wp_kses_post` strips it. (`AGENTS.md` §4.2.)
- **Bilateral widget registry, not wp_options-only.** (`AGENTS.md` §4.1.)
- **No new MCP tools in skills.** Skills teach how to compose existing tools; they do not define tools. (`AGENTS.md` §2 / §8.)
- **45+ tools, not 10 / 11 / 12.** (`AGENTS.md` §2.)
- **Mutations inside transactions.** (`AGENTS.md` §6.)

## Local development

AgentShell needs a WordPress instance (VVV, Local, or Docker — e.g. `docker run -d --name wp -p 10003:80 -e WORDPRESS_DB_NAME=agentshell wordpress:latest`). Activate the theme, then verify the config seeded:

```bash
curl -s http://localhost:10003/wp-json/wp/v2/agentshell/config | jq '.config.sidebar_enabled'
# → false (sidebar is opt-in; default is single column)
```

## REST fallback

If the MCP daemon is down, the raw endpoints are still live:

- `GET/PUT /wp-json/wp/v2/agentshell/config` — flattened config + custom CSS/JS.
- `GET/POST/PUT /wp-json/wp/v2/pages`, `/wp-json/wp/v2/posts` — content. Admin agents bypass `wpautop` / `wp_kses_post` and keep raw HTML.

Auth: `Authorization: Basic $(echo -n 'user:app_password' | base64)`, or `X-AgentShell-Token: <token>` (only when `AGENTSHELL_REST_TOKEN` is defined in `wp-config.php`).

## Key files

| File | Role |
|---|---|
| `AGENTS.md` | **Authoritative agent guide** — full MCP tool reference, working pattern, hard rules. |
| `agentshell-mcp/agentshell-mcp.php` | MCP plugin entrypoint; filter-based tool registry. |
| `agentshell-mcp/includes/` | Core services: `class-store.php` (revisions / audit / snapshots / profiles), `class-transactions.php` (per-actor locks), `class-doctor.php` (validator), `class-content.php` (content primitives), `class-screenshot.php`, `tools/` (50 MCP tools). |
| `agentshell-blocks/agentshell-blocks.php` | Bilateral widget registry + 4 widget MCP tools. |
| `agentshell-mcp-daemon/daemon.php` | PHP CLI proxy: stdio (MCP JSON-RPC) ↔ HTTP (WP REST). |
| `functions.php` | Config helpers, `agentshell_inject_saved_styles` (CSS + structural prohibition), bilateral widget registry merge, REST auth, pre-approved library loader. |
| `header.php` / `footer.php` | Static FSE shell. Do not edit. |
| `style.css` | `:root` tokens (editable via MCP) + the immutable grid protocol (Sections 3 & 4). Do not edit Sections 3 & 4. |
| `template-parts/shell-render.php` | `agentshell_render_zone()` / `agentshell_render_block()`. |
| `template-parts/widgets.php` | Scoped widget CSS renderer. |
| `widgets/` | Stable file widget definitions (`.index.json` + `*.php`). |

## Sidebar behaviour (v2 single-column default)

- `style.css` uses `display: flex; flex-direction: column;` on `#agentshell-root` (single column by default).
- The `1fr` rule, descendant-scope rule, and quoted-rows rule in `AGENTS.md` §5 apply to any future `grid-template-areas` generator and to any `custom_css` agent payload. They are absolute regardless of the current generator.

Everything else is in `AGENTS.md`.
