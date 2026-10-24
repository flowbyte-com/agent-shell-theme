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

## MCP tools (pointer, and where the count lives)

The plugin publishes the `agentshell_*` tools listed in [`AGENTS.md` §2](./AGENTS.md) — configuration, observation, transactions, revisions, snapshots, profiles, content, widgets, and screenshots. Discover them at runtime with `agentshell_get_capabilities`.

**The count is in one place only: the `## 2. MCP tools (authoritative count: **N**` heading in `AGENTS.md`.** It is not repeated here on purpose: a number written down in several places can only be kept right by remembering to change it in all of them — and it had been wrong in all of them. `tests/tool-count-test.php` counts the tool names the two plugins actually publish and fails the build when that number and `AGENTS.md` §2's differ, so the count cannot go stale without failing. If you add or remove a tool, the test tells you the new number.

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

## Continuous integration

`.github/workflows/ci.yml` runs on every pull request and on every push to `main`. It has three jobs, and none of them is allowed to fail quietly — there is no `continue-on-error` and no step that reports success when the tests did not.

| Job | What it runs | Needs a database |
|---|---|---|
| `static` | `php -l` over every tracked PHP file, `node --check` over every tracked JavaScript file, the tool-registry checks (`tests/tool-count-test.php`, `tests/tool-schema-test.php`) and the documentation gates (`tests/doc-consistency-test.php`) | no |
| `unit` | `php tests/run-all.php` with no test-bed profile: every suite that does not need a live WordPress | no |
| `integration` | `php tests/run-all.php` against a MySQL database and a WordPress installation that the job creates for itself | yes |

**What CI covers.** The `unit` job runs the suites that do not need a live WordPress; the `integration` job runs **every** suite, against a MySQL database and a WordPress installation the job creates for itself. The split is visible in the report: a suite that needs a live installation is printed as `SKIPPED` with the reason (`no_test_bed_config`), never as a pass. The assertion totals move with the suite, so the runner's own `=== TOTAL ===` line is the number to read — not a figure quoted here.

**What still needs a separate environment.** The `integration` job provisions its own environment with `tests/ci/provision-integration-env.sh`: a MySQL database, a user whose only grant is that database, a WordPress install, the `agentshell_site_mode = disposable_test_bed` sentinel, and an HTTP server. The harness refuses to run when the configured database user can reach anything else, so "CI never touches a LocalWP database" is enforced rather than promised. The suites that need a *real browser* run in CI where the runner has one: the runner image ships Chrome, so the narrow-viewport suite captures real pages there — and it skips itself, with a reason, on a host that has no browser. The screenshot capture path against a *live site* is the exception: it is verified locally and against the showcase, because it needs a site to capture rather than a data URL.

**Secrets.** The integration job generates its database password and its MCP static token at run time, writes them into the disposable site's `wp-config.php` and into a gitignored profile, and deletes the profile when the job ends. No credential is stored in the workflow.

## Deploying

**A deployment is a build, not a copy of the checkout.** The repository is a theme *and* a development tree: it carries the test suite, the design records, the Git history and four other worktrees. None of that belongs on a server.

```bash
bash tools/package-release.sh --out dist                                   # build
bash tools/check-release-archive.sh --archive dist/agentshell-*.tar.gz      # check
```

The archive carries the theme, both plugins, the assets, the licence and the configuration defaults — about 1.4 MB — and refuses the Git history, the worktrees, the tests, the tooling and anything that looks like a credential. The two scripts share one list of exclusions, so an exclusion forgotten in one fails the build.

**Never copy the working tree to a server.** A checkout is 62 MB, 59 MiB of which is Git history and other checkouts — and the published history contains a revoked database password, so a copied `.git` is a credential on a public machine. The measurements, and what else was found, are in [`docs/RELEASE-SIZE-AUDIT.md`](docs/RELEASE-SIZE-AUDIT.md).

For moving the local showcase to `flowbyte.com`, see [`docs/WP-MIGRATE-CUTOVER.md`](docs/WP-MIGRATE-CUTOVER.md) — the cutover plan for WP Migrate, including the backup and recovery procedure for the existing public site.

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

## Full installation guide

For a complete end-to-end setup — LocalWP, development symlinks, theme and plugin activation, PHP CLI, Application Passwords, daemon configuration, Claude Code MCP registration, HTTPS certificate trust, connection verification, and troubleshooting — see [`CONFIG.md`](./CONFIG.md). It is a non-normative setup guide; every contract lives in `AGENTS.md`.
