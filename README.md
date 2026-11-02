# AgentShell

> **Pointer document.** The canonical agent contract is [`AGENTS.md`](./AGENTS.md). This file is human-facing orientation only — setup, screenshots loop, and a quick tour. If anything here disagrees with `AGENTS.md`, `AGENTS.md` wins.

**Repository:** `flowbyte-com/agentshell` — a WordPress platform: one theme, three
plugins and an MCP daemon. A checkout lives at `~/projects/agentshell` by
convention; nothing depends on that path.

AgentShell is a WordPress platform for human–agent collaboration, built as a
theme plus three plugins and an MCP daemon. The shell is static and immutable;
agents work through the MCP daemon (JSON-RPC over stdio) to style the theme,
arrange zones, publish content, build forms and take screenshots.

The parts:

| Part | What it is | Lives in |
|---|---|---|
| **Theme** | The immutable shell, the design tokens, the zone renderer, the visual configurator | `wordpress/themes/agentshell/` |
| **MCP plugin** | The `agentshell_*` tool surface — configuration, transactions, content, screenshots | `wordpress/plugins/agentshell-mcp/` |
| **Blocks plugin** | The bilateral widget registry and its three registry tools | `wordpress/plugins/agentshell-blocks/` |
| **Forms plugin** | A native forms engine: schema, builder, submissions, Turnstile, notifications, and the `agentshell_form` zone block. Optional — it is never activated by the theme | `wordpress/plugins/agentshell-forms/` |
| **MCP daemon** | A PHP CLI proxy that carries MCP over stdio to an agent and HTTP to the site. Runs on the operator's machine, never uploaded to WordPress | `agentshell-mcp-daemon/` |

## What AgentShell gives you

- **Immutable shell** — `header.php` / `footer.php` / the grid never change at runtime; agents can't break the layout.
- **JSON-driven zones** — `header` / `main` / `footer` rendered from `wp_options['agentshell_config']`.
- **Design tokens as CSS variables** — every color, font, and radius is configurable via `:root` vars and the `agentshell_set_*` MCP tools.
- **Bilateral widget registry** — file-based stable widgets (`widgets/*.php` + `widgets/.index.json`) merged with config-registered widgets. Config entries with the same id override the file entry. See `AGENTS.md` §4.
- **Web Component widgets** — charts, calculators, terminals via Shadow DOM. D3 and Math.js load only when a widget declares them.
- **Browser configurator** — logged-in users get a live-editing panel for design tokens, zones, CSS/JS.
- **Three skills** — `agentshell-image-to-theme` (theme to a reference image), `agentshell-widget-builder` (build a custom widget), and `agentshell-site-from-brief` (build a whole site from a project brief).

## Where things live

The checkout is one repository with three kinds of thing in it. Only the first
is loaded by WordPress.

```
wordpress/
  themes/agentshell/          the theme WordPress activates
  plugins/agentshell-mcp/     the MCP tool surface
  plugins/agentshell-blocks/  the widget registry tools
  plugins/agentshell-forms/   forms: builder, submissions, zone block
tests/                        the test suite
tools/                        release packaging, install helper, sync guard
docs/                         design records
skills/                       agent skills
agentshell-mcp-daemon/        the MCP daemon (a CLI app, not a plugin)
```

`agentshell-mcp-daemon/` is the odd one out on purpose: it runs on the
operator's machine and speaks MCP over stdio to an agent, then talks HTTP to
the site. It is never uploaded to WordPress.

## Setup for humans

### Option A — link a local site to this checkout (recommended)

A LocalWP (or any) site does not need a copy of this repository; it needs the
four directories in the places WordPress looks. One script makes the links and
touches nothing else:

```bash
tools/install-localwp.sh --wp-content "/path/to/site/app/public/wp-content"
tools/install-localwp.sh --wp-content "..." --dry-run   # see first, change nothing
```

It links the theme and the three plugins, refuses to overwrite anything that
already exists, never deletes, and prints exactly what it linked. It does not
activate anything — whether a site runs the forms plugin is your decision.
The script is idempotent, so running it twice is safe. It is excluded from
release archives: it is a development tool.

### Option B — copy the components in

1. Copy `wordpress/themes/agentshell/` to `wp-content/themes/agentshell/`.
2. Copy `wordpress/plugins/agentshell-mcp/`, `agentshell-blocks/` and
   (optionally) `agentshell-forms/` to `wp-content/plugins/`.
3. Activate the theme in **Appearance → Themes**, and the plugins in
   **Plugins**. Activate `agentshell-forms` only if the site serves forms.
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

Three plugins publish the `agentshell_*` tools listed in [`AGENTS.md` §2](./AGENTS.md) — configuration, observation, transactions, revisions, snapshots, profiles, content, widgets, forms and screenshots. Discover them at runtime with `agentshell_get_capabilities`.

**The count is in one place only: the `## 2. MCP tools (authoritative count: **N**` heading in `AGENTS.md`.** It is not repeated here on purpose: a number written down in several places can only be kept right by remembering to change it in all of them — and it had been wrong in all of them. `tests/tool-count-test.php` counts the tool names the plugins actually publish and fails the build when that number and `AGENTS.md` §2's differ, so the count cannot go stale without failing. If you add or remove a tool, the test tells you the new number.

## Zones, slots & blocks

Three zones: `header`, `main`, `footer`. `main` is a vertical `composition[]` array. `header` and `footer` carry a **nine-area slot grid** — three rows of three (`top-left` … `bottom-right`); the historical `left` / `center` / `right` keys are still accepted as aliases for the middle row, and a zone renders the expanded grid exactly when an area outside the middle row holds a block. Block types: `wp_loop`, `wp_core`, `widget`, `json_block`, `wp_widget_area`, `agentshell_form`. Edit via `agentshell_update_zone_slots` (header/footer) and `agentshell_update_zone_composition` (main). Full contract: `AGENTS.md` §3.

## Widgets

Two tracks (Interactive, WordPress Decorator) + an Interactive-track snapshot-seeded pattern. Data flows via `data-*` attributes only; **no `<script type="application/json">`, no client-side `fetch()`**. The bilateral registry merges file-based and config-registered widgets. Full contract: `AGENTS.md` §4. The `agentshell-widget-builder` skill is the operative guide for building widgets.

## The Unbreakable Grid (one paragraph)

The CSS Grid layout obeys three load-bearing rules — every grid container uses `grid-template-columns: 1fr` on the base rule (and only `1fr <sidebar-track>` inside the appropriate media query), every sidebar rule uses the descendant form `.sidebar-enabled #agentshell-root` (class is on `<body>`), and every `grid-template-areas` value uses individually-quoted rows (`"header" "main" "footer"`, never `"header main footer"`). The shell files and `agentshell_inject_saved_styles()` enforce a `position` reset on every zone container. **Full protocol: `AGENTS.md` §5.**

## Skills

| Skill | Purpose | Where |
|---|---|---|
| `agentshell-image-to-theme` | Theme the site to match a reference image | `skills/agentshell/image-to-theme/SKILL.md` |
| `agentshell-widget-builder` | Build a custom widget from a user's description | `skills/agentshell/widget-builder/SKILL.md` |
| `agentshell-site-from-brief` | Build a whole site from a project brief or README — configuration transactionally, content and navigation by their own lifecycles | `skills/agentshell/site-from-brief/SKILL.md` |

`skills/agentshell/SKILL.md` is the namespace index that routes to all three.

## Testing

```bash
php tests/run-all.php
```

That is the whole command. The runner spawns each suite as its own process, so a suite that exits, dies or skips cannot take the run with it, and a suite that stops without reporting a result is reported as **incomplete** — a failure, not a silent omission. The exit status is non-zero if anything failed or was incomplete. The report ends with a `=== TOTAL ===` line: `passed`, `failed`, `skipped`.

**Suites that need a live WordPress skip themselves**, with a reason, when there is no test bed — a skip is never counted as a pass. To enable them, point the runner at a test bed:

```bash
cp tests/config/test-bed.example.json tests/config/test-bed.json   # then fill it in
# or: export AGENTSHELL_TEST_BED_CONFIG=/absolute/path/to/test-bed.json
```

The profile is gitignored and must never be committed: it holds database and application passwords. Suites that *write* to the test bed additionally require a fail-closed identity check, so they cannot run against a site they have not been given.

Single suites and the individual gates can also be run directly — every file in `tests/` is a standalone PHP script that prints its own verdict:

```bash
php tests/tool-count-test.php        # the documented tool count vs the registry
php tests/doc-consistency-test.php    # the documentation gates (AGENTS.md §9)
php tests/doc-links-test.php          # links, anchors, and §2's tool coverage
```

**CI runs the same runner.** See the job table below.

**One known gap in the runner, recorded and not repaired here.** The PHP runner detects a suite that reports no verdict at all, and a suite that stops before the end of its file — both are reported as `incomplete`, and both fail the run. It does **not** detect a nested Node/browser child that reports a well-formed but *empty* count. The PHP wrappers that drive those children parse the child's `N passed, M failed` line and add the numbers to the aggregate; a child that died before its first assertion can print `0 passed, 0 failed`, and the aggregate reads that as a suite that passed with nothing to assert.

Most Node suites are *also* run as their own CI step, where a crash fails the step and the job — so CI has a direct signal for those. But a crash that happens only inside the aggregate run, or in a suite with no direct step of its own, is not caught by the aggregate. **Do not read a green aggregate run as proof that every nested browser suite actually ran.** This is a separate corrective assignment with its own regression test; it is tracked, not fixed, in this change.

## Continuous integration

`.github/workflows/ci.yml` runs on every pull request and on every push to `main`. It has four jobs, and no job is allowed to fail quietly — there is no `continue-on-error`, and the `full-test-suite` gate reports a failure rather than being skipped when one of the other three did not succeed.

| Job | What it runs | Needs a database |
|---|---|---|
| `static` | `php -l` over every tracked PHP file, `node --check` over every tracked JavaScript file, the tool-registry checks (`tests/tool-count-test.php`, `tests/tool-schema-test.php`) and the documentation gates (`tests/doc-consistency-test.php`, `tests/doc-links-test.php`) | no |
| `unit` | `php tests/run-all.php` with no test-bed profile: every suite that does not need a live WordPress | no |
| `integration` | `php tests/run-all.php` against a MySQL database and a WordPress installation that the job creates for itself | yes |
| `full-test-suite` | A gate, not a fourth run: it re-asserts that the three jobs above all succeeded, and reports a failure rather than being skipped when one of them did not | no |

**What CI covers.** The `unit` job runs the suites that do not need a live WordPress; the `integration` job runs **every** suite, against a MySQL database and a WordPress installation the job creates for itself. The split is visible in the report: a suite that needs a live installation is printed as `SKIPPED` with the reason (`no_test_bed_config`), never as a pass. The assertion totals move with the suite, so the runner's own `=== TOTAL ===` line is the number to read — not a figure quoted here.

**What still needs a separate environment.** The `integration` job provisions its own environment with `tests/ci/provision-integration-env.sh`: a MySQL database, a user whose only grant is that database, a WordPress install, the `agentshell_site_mode = disposable_test_bed` sentinel, and an HTTP server. The harness refuses to run when the configured database user can reach anything else, so "CI never touches a LocalWP database" is enforced rather than promised. The suites that need a *real browser* run in CI where the runner has one: the runner image ships Chrome, so the narrow-viewport suite captures real pages there — and it skips itself, with a reason, on a host that has no browser. The screenshot capture path against a *live site* is the exception: it is verified locally and against the showcase, because it needs a site to capture rather than a data URL.

**Secrets.** The integration job generates its database password and its MCP static token at run time, writes them into the disposable site's `wp-config.php` and into a gitignored profile, and deletes the profile when the job ends. No credential is stored in the workflow.

## Deploying

**A deployment is a build, not a copy of the checkout.** The repository is a theme *and* a development tree: it carries the test suite, the design records, the Git history and any other worktrees a developer has checked out alongside it. None of that belongs on a server.

```bash
bash tools/package-release.sh --out dist                                   # build
bash tools/check-release-archive.sh --archive dist/agentshell-*.tar.gz      # check
```

The archive carries the theme, the three plugins, the assets, the licence and the configuration defaults — about 1.4 MB — and refuses the Git history, the worktrees, the tests, the tooling and anything that looks like a credential. The two scripts share one list of exclusions, so an exclusion forgotten in one fails the build.

**The archive layout is not the repository layout.** The packager stages
`wordpress/themes/agentshell/` as the archive's theme root and each
`wordpress/plugins/<name>/` as `<name>/` beside it, so what a server receives
is exactly the shape WordPress expects:

```
agentshell/                  the theme (style.css, functions.php, …)
agentshell/agentshell-mcp/   a plugin, activatable by that name
agentshell/agentshell-blocks/
agentshell/agentshell-forms/
agentshell/LICENSE
```

That is the distribution contract these scripts have always had; the `wordpress/`
directory is a repository convenience and never appears in a release.

**`agentshell-forms` ships in the archive but is not activated by the theme.** It is included so a site that wants forms has the engine available — the `agentshell_form` zone block, the `[agentshell_form]` shortcode and the widget all come from it — and it is left inactive until an operator activates it, because a form engine is a decision rather than a default. A site that never activates it renders exactly what it rendered before: a form block on such a site produces no markup and causes no error. The deployment checklist and the full contract are in [`AGENTS.md` §3](AGENTS.md).

**Never copy the working tree to a server.** A size audit measured a checkout at 62 MB, 59 MiB of which was Git history and other checkouts — and the published history contains a revoked database password, so a copied `.git` is a credential on a public machine. Those figures are **historical**: they were taken at revision `86d23a6` on 2026-10-24, and they move with every commit. The measurements, the commands that reproduce them, and what else was found are in [`docs/RELEASE-SIZE-AUDIT.md`](docs/RELEASE-SIZE-AUDIT.md).

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
