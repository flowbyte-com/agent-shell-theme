# AgentShell

> **Pointer document.** The canonical agent contract is [`AGENTS.md`](./AGENTS.md). This file is human-facing orientation only — setup, screenshots loop, and a quick tour. If anything here disagrees with `AGENTS.md`, `AGENTS.md` wins.

A WordPress theme built for human–agent collaboration. The shell is static and immutable; agents work through the MCP daemon (JSON-RPC over stdio) to style the theme, arrange zones, and publish content.

## What AgentShell gives you

- **Immutable shell** — `header.php` / `footer.php` / the grid never change at runtime; agents can't break the layout.
- **JSON-driven zones** — `header` / `main` / `footer` rendered from `wp_options['agentshell_config']`.
- **Design tokens as CSS variables** — every color, font, and radius is configurable via `:root` vars and the `agentshell_set_*` MCP tools.
- **Bilateral widget registry** — file-based stable widgets (`widgets/*.php` + `widgets/.index.json`) merged with config-registered widgets. Config entries with the same id override the file entry. See `AGENTS.md` §4.
- **Web Component widgets** — charts, calculators, terminals via Shadow DOM. D3 and Math.js load only when a widget declares them.
- **Browser configurator** — logged-in users get a live-editing panel for design tokens, zones, CSS/JS.
- **Two skills** — `agentshell-image-to-theme` (theme to a reference image) and `agentshell-widget-builder` (build a custom widget).

## Setup for humans

1. Upload `agentshell/` to `wp-content/themes/`.
2. Activate in **Appearance → Themes**.
3. Upload `agentshell-mcp/` to `wp-content/plugins/`. Activate it.
4. Assign a menu to **Primary Navigation** and set a Site Logo if desired.
5. Create an Application Password for the agent user.
6. Install the daemon: copy `agentshell-mcp-daemon/` somewhere permanent, then create `~/.agentshell-mcp.json` (mode `0600`):

   ```json
   {
     "url": "https://yourdomain.com/wp-json/agentshell-mcp/v1/mcp",
     "user": "agent_user",
     "pass": "XXXX XXXX XXXX XXXX XXXX XXXX",
     "timeout": 30
   }
   ```

7. For Claude Code, add to `~/.claude/settings.json`:

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

## Architecture (one line)

`Agent (MCP client) → agentshell-mcp-daemon (PHP CLI) → agentshell-mcp (WordPress plugin) → bilateral widget registry (file + config) → shell (header.php / footer.php / style.css)`.

Full diagram and authoritative architecture contract in `AGENTS.md` §1.

## MCP tools (count + pointer)

The plugin ships **45+ `agentshell_*` tools** across configuration, observation, transactions, revisions, snapshots, profiles, content, widgets, and screenshots. The full list and categories are in `AGENTS.md` §2. Discover at runtime with `agentshell_get_capabilities`. **The count "45+" is canonical**; older "10 / 11 / 12 tools" figures cited anywhere else are deprecated.

## Zones, slots & blocks

`header` / `footer` are tri-slot (`slots: { left, center, right }`); `main` is a vertical `composition[]` array. Block types: `wp_loop`, `wp_core`, `widget`, `json_block`, `wp_widget_area`. Edit via `agentshell_update_zone_slots` (header/footer) and `agentshell_update_zone_composition` (main). Full contract: `AGENTS.md` §3.

## Widgets

Two tracks (Interactive, WordPress Decorator) + an Interactive-track snapshot-seeded pattern. Data flows via `data-*` attributes only; **no `<script type="application/json">`, no client-side `fetch()`**. The bilateral registry merges file-based and config-registered widgets. Full contract: `AGENTS.md` §4. The `agentshell-widget-builder` skill is the operative guide for building widgets.

## The Unbreakable Grid (one paragraph)

The CSS Grid layout obeys three load-bearing rules — every grid container uses `grid-template-columns: 1fr` on the base rule (and only `1fr <sidebar-track>` inside the appropriate media query), every sidebar rule uses the descendant form `.sidebar-enabled #agentshell-root` (class is on `<body>`), and every `grid-template-areas` value uses individually-quoted rows (`"header" "main" "footer"`, never `"header main footer"`). The shell files and `agentshell_inject_saved_styles()` enforce a `position` reset on every zone container. **Full protocol: `AGENTS.md` §5.**

## Skills

| Skill | Purpose | Where |
|---|---|---|
| `agentshell-image-to-theme` | Theme the site to match a reference image | `skills/agentshell/image-to-theme/SKILL.md` |
| `agentshell-widget-builder` | Build a custom widget from a user's description | `skills/agentshell/widget-builder/SKILL.md` |

## Troubleshooting (one-liners)

| Error | Cause |
|---|---|
| `No route was found` | `agentshell-mcp` plugin not activated |
| `Authentication failed` | Wrong username / application password |
| `HTTP request failed` | Daemon can't reach the WP endpoint |
| 401 on token requests | `AGENTSHELL_REST_TOKEN` not defined in `wp-config.php` |
| `A transaction is already open` | Previous transaction not committed / rolled back |
| `Another actor (...) has an open transaction` | A different agent owns the open transaction |
| `No headless browser found` | `agentshell_screenshot` needs Chrome / Chromium on the server |
| `Commit blocked by validation errors` | Staged config fails the site doctor — fix and re-validate |
| D3 / Math.js not available | No registered widget declares the lib in its `libs` array |
| Header logo missing | No custom logo set under **Appearance → Customize → Site Identity** |

For the complete list, the bilateral widget contract, the grid law, and the data-* / no-fetch boundary, see `AGENTS.md`.
