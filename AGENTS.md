# AgentShell — Agent Guide (Canonical)

> **This file is the only normative agent contract for AgentShell.** Every other markdown in this theme is a pointer, a historical archive, or a non-normative note. If a conflict exists, this file wins. Agents do not maintain duplicate MCP tool tables, architecture diagrams, or operation loops in any other file.

---

## 0. Quick start (the only thing an agent must read first)

1. Connect via the daemon at `~/.agentshell-mcp.json` (mode `0600`). The daemon exposes the **entire** AgentShell surface as MCP tools.
2. Run `agentshell_inspect` to observe the live site model (zones, widgets, design, capabilities, state, warnings).
3. Run `agentshell_validate` to check health.
4. For any multi-step work: `agentshell_begin_transaction` → mutations → `agentshell_preview_transaction` / `agentshell_validate_transaction` → `agentshell_commit_transaction` (or `agentshell_rollback_transaction`).
5. Run `agentshell_create_snapshot` before risky work; `agentshell_restore_revision` or `agentshell_restore_snapshot` to undo.

There is no other top-level control flow. Do not invent a second loop.

---

## 1. Architecture (the only diagram you need)

```
Agent (Claude Code, etc.)
    ↕ stdio (MCP JSON-RPC)
Daemon (agentshell-mcp-daemon)         PHP CLI proxy
    ↕ HTTP (MCP over REST)
WordPress plugin (agentshell-mcp)      Filter-based tool registry
    ↕ reads / writes
Bilateral widget registry
    = file-based stable widgets (themes/agentshell/widgets/*.php, declared in widgets/.index.json)
    merged with config-registered widgets (wp_options['agentshell_config']['widgets'])
    → config entry with the same id overrides the file-based widget
    ↕ rendered into
Shell (header.php, footer.php, style.css) — immutable
```

**Source of truth precedence (authoritative):**

1. The **bilateral widget registry** above is the *union* source of truth. Tools that read widgets (`agentshell_list_widgets`, `agentshell_get_widget`, `agentshell_get_config`, the doctor, and the `agentshell_render_widget` action) must always consult the merge. Any file or skill claiming `wp_options` is the *sole* source of truth is deprecated and is not to be reproduced.
2. `wp_options['agentshell_config']` is the *configuration* source of truth (zones, design tokens, custom CSS/JS, widget registry overrides, snapshot/profile metadata). It is seeded once from `default-config.json` on theme activation.
3. The physical shell files (`header.php`, `footer.php`, `style.css` Sections 3–4, `template-parts/shell-render.php`, `template-parts/widgets.php`) are **read-only at runtime** — agents must not edit them.

No other document is allowed to redefine these three statements.

---

## 2. MCP tools (authoritative count: **45+**)

The plugin ships **45+ `agentshell_*` tools** (50 in `agentshell-mcp` + 4 in `agentshell-blocks` = 54 at the time of writing; growth is expected). Earlier counts cited in other files ("10 tools", "11 tools", "12 tools") are deprecated and must not be reproduced.

**Categories (call by category; the names below are exhaustive at the time of writing — discover additions with `agentshell_get_capabilities`):**

- **Configuration read & write** — `agentshell_get_config`, `agentshell_get_design_system`, `agentshell_set_design`, `agentshell_set_css_var`, `agentshell_set_palette`, `agentshell_set_typography`, `agentshell_set_spacing`, `agentshell_set_shape`, `agentshell_set_layout`, `agentshell_list_zones`, `agentshell_update_zone_composition`, `agentshell_update_zone_slots`, `agentshell_inject_json_block`.
- **Site model & observation** — `agentshell_inspect`, `agentshell_explain`, `agentshell_get_capabilities`, `agentshell_validate`, `agentshell_get_audit_log`.
- **Revision history & diffs** — `agentshell_list_revisions`, `agentshell_diff_revisions`, `agentshell_restore_revision`.
- **Snapshots** — `agentshell_create_snapshot`, `agentshell_list_snapshots`, `agentshell_restore_snapshot`, `agentshell_diff_snapshot`.
- **Theme profiles** — `agentshell_save_theme_profile`, `agentshell_list_theme_profiles`, `agentshell_apply_theme`, `agentshell_preview_theme`, `agentshell_export_theme`, `agentshell_import_theme`.
- **Widget lifecycle** (bilateral registry) — `agentshell_list_widgets`, `agentshell_get_widget`, `agentshell_register_widget`, `agentshell_unregister_widget`, `agentshell_enable_widget`, `agentshell_disable_widget`, `agentshell_remove_widget`.
- **Content primitives** — `agentshell_create_page`, `agentshell_create_post`, `agentshell_update_content`, `agentshell_update_post_content`, `agentshell_publish`, `agentshell_unpublish`, `agentshell_search_content`, `agentshell_get_content`.
- **Transactions (the safe change loop)** — `agentshell_begin_transaction`, `agentshell_get_transaction`, `agentshell_preview_transaction`, `agentshell_validate_transaction`, `agentshell_commit_transaction`, `agentshell_rollback_transaction`.
- **Site meta & visuals** — `agentshell_get_site_info`, `agentshell_screenshot`.

**Hard rules about tool use:**

- Call every tool with the `agentshell_` prefix. No other tool names are recognized.
- Discover runtime capability with `agentshell_get_capabilities` before depending on `agentshell_screenshot`, transaction locks, or theme profile preview.
- Mutations that touch the live site *must* run inside a transaction opened by the same actor. Direct mutation without a transaction is a `agentshell_*` mis-use and is documented as forbidden in the widget-builder skill.
- Transactions are locked to the opening actor; another agent cannot commit or rollback your transaction. Read-only tools (`agentshell_get_transaction`, `agentshell_preview_transaction`, `agentshell_validate_transaction`) work on any open transaction so peers can coordinate.

**Other docs may not list or define MCP tools.** Skills, READMEs, plans, specs, and briefs may *reference* tool names by string only. They may not redefine behavior, add tools, or re-describe the loop.

---

## 3. Zone composition (the only contract agents need)

- **Three zones**, fixed IDs: `header`, `main`, `footer`. Zone IDs are declared in `header.php` and registered in `agentshell_get_zones()`.
- **Block types** (the full vocabulary): `wp_loop`, `wp_core`, `widget`, `json_block`, `wp_widget_area`.
- **Header/footer** are tri-slot: `slots: { left: [block, …], center: [block, …], right: [block, …] }`. Edit with `agentshell_update_zone_slots`.
- **Main** is a vertical `composition: [block, …]` array. Edit with `agentshell_update_zone_composition`.
- **Sanitization is absolute**: `<style>` tags, `style=""` attributes, and inline event handlers (`onclick`, `onerror`) are stripped from `json_block` and from `agentshell_update_post_content` payloads for untrusted users. Use class-based CSS, registered widgets, or Web Components. For trusted users (`unfiltered_html` / `manage_options`) `<script>` is preserved — see the trust section in the `agentshell-mcp` plugin tool descriptions, not in any skill.

The doctor (`agentshell_validate`) is the source of truth for what a "valid" block is. Never pre-empt it in skill text.

---

## 4. Widgets (bilateral registry + data-* hydration only)

### 4.1 Bilateral registry — the merge is the registry

The widget registry an agent sees is the result of this merge, in order:

1. **Stable file widgets** — entries declared in `widgets/.index.json`, each pointing to a `widgets/*.php` file that returns `{ id, name, libs?, template?, init_js?, css? }`.
2. **Config-registered widgets** — `wp_options['agentshell_config']['widgets']`, written via `agentshell_register_widget`.
3. **Lifecycle overrides** — `wp_options['agentshell_config']['widget_overrides']` sets `{ status: 'active' | 'disabled' }` per id.

A config entry with the same id **overrides** the file-based entry. Removal is asymmetric: `agentshell_remove_widget` only removes config-registered widgets; file-based widgets must be disabled via `agentshell_disable_widget`.

This merge is implemented by `agentshell_get_widget_registry()` in the theme and by the tool classes in `agentshell-mcp/includes/tools/` and `agentshell-blocks/includes/tools/`. Do not describe the registry as "wp_options-only" anywhere.

### 4.2 Data-* hydration (the only allowed widget payload)

- **Scalar fields** → one `data-*` attribute per field on the widget template's root.
- **Structured payloads** → JSON encoded into **one** attribute, conventionally `data-agentshell-data='{ "…" : … }'`, consumed via `el.dataset.agentshellData` in `init_js`.
- `<script type="application/json">` hydration is **prohibited**. `wp_kses_post` strips it. Don't write it; don't reference it as "cleaner"; don't argue for loosening it.
- `customElements.define` is the right primitive for interactive widgets that need Shadow DOM. Guard it with `if (!customElements.get(...))`. Use `var(--theme-*)` for theming. Prefix custom elements with `mpm-`.

### 4.3 No client-side `fetch()` — absolute

Widgets must not call `fetch()` for any reason. The boundary covers the WP REST API, external APIs, and any URL whatsoever. If data is required, it comes from server-rendered DOM (colocation with `wp_loop`) or from `data-*` hydration. There is no third option. This rule has no strictness knob and is not negotiable.

### 4.4 Track model (when to use what)

Two tracks. Snapshot-style widgets are a **pattern inside Track 1**, not a third track.

- **Track 1 — Interactive.** Self-contained applications (calculators, visualizers, simulators). No WordPress data dependency. Uses local state, `window.math`, `window.d3`. `init_js` reads from `data-*` attributes on its own element only.
- **Track 2 — WordPress Decorator.** Progressively enhances server-rendered content. The widget colocates with the `wp_loop` (or `wp_core` / `wp_widget_area`) it enhances — the agent places the blocks together in the same zone. The widget locates its source by walking up to its zone scope (`el.closest('.zone-main, [data-zone]') || el.parentElement`) and selecting the nearest preceding `wp_loop` within that zone. Blind document scanning (`querySelectorAll('article')`) is prohibited.

Decision rule: if the widget must stay correct as the underlying content changes between page loads, use Track 2. Otherwise, Track 1 (snapshot-seeded if point-in-time data is acceptable, plain otherwise). When ambiguous, pick the least powerful track that satisfies the requirement. Visible reasoning required (one line: "Track: X. Reason: Y.").

### 4.5 Refresh anchor (snapshot-seeded Track 1)

When embedding snapshot data in `init_js`, prefix the script with a single comment `/* agentshell-snapshot-source: <description-of-where-the-data-came-from> */`. The comment is the only provenance link a future agent will have. Be specific. Terminal snapshots end the comment with `, terminal (not refreshed)`.

### 4.6 Escape hatch (mu-plugin filter) — last resort only

Standard `wp_loop` markup exposes what most decorators need. When it does not, the *only* allowed PHP modification is a mu-plugin filter written to `/tmp/agentshell-widget-filter-<timestamp>.php`, linted with `php -l`, moved atomically to `wp-content/mu-plugins/`, and verified with a `curl` health check on the daemon. Recovery is a single `rm` on the file. **No `register_activation_hook`, no cron, no DB writes.** `agentshell_rollback_transaction` cannot help if the filter bricks the daemon — never rely on it for mu-plugin rollback.

There is no MCP tool for "register a mu-plugin." That is a deliberate architectural choice; do not propose one in any skill.

---

## 5. Theme — immutable CSS Grid protocol (absolute system law)

The CSS Grid layout is governed by three load-bearing rules. Every template, generator, and agent payload must obey them. Violations are layout breakages, not style choices.

1. **The 1fr rule.** `#agentshell-root` (or any grid container generated from `template-parts/grid-areas.php`) must declare `grid-template-columns: 1fr` on the base rule. When a multi-column breakpoint fires, the rule is wrapped in the breakpoint media query and the column template becomes `1fr <sidebar-track>` (e.g. `1fr var(--sidebar-width, 320px)`) — the main track always resolves to `1fr`; the sidebar track is fixed. The default `1fr` is what makes the main zone fill the available width when the sidebar is off.
2. **Descendant scoping.** The class that toggles the sidebar lives on `<body>` (`.sidebar-enabled`). Grid rules must use the descendant form `.sidebar-enabled #agentshell-root`. The reverse form (`#agentshell-root.sidebar-enabled`) breaks because the class is on the body, not the root. The same descendant discipline applies to any future toggles — class on body, descendant-scope the grid.
3. **Quoted rows in `grid-template-areas`.** Every row in a `grid-template-areas` value must be a quoted string separated by spaces. `"header main"` is a single cell of two tokens (wrong). `"header" "main"` is two cells of one token each (correct). Bad: `"header main footer"`. Good: `"header" "main" "footer"`. Apply the same rule to any generator output in `template-parts/grid-areas.php`, any inline style emitted by an agent, and any `custom_css` payload.

**Layout prohibition.** `agentshell_inject_saved_styles()` emits a `<style id='agentshell-grid-fix'>` rule that resets `position`, `top`, `left`, `right`, `bottom`, `z-index` on `#zone-header`, `#zone-main`, `#zone-footer`. That rule is part of the protocol. Agents must not produce CSS that depends on `position: fixed` or `position: absolute` on a zone container; the reset will neutralize it.

**Edit boundaries.** The shell files are read-only at runtime:

- `header.php`, `footer.php`, `style.css` (Sections 3 & 4), `template-parts/grid-areas.php`, `template-parts/shell-render.php` — **DO NOT EDIT** from a customisation agent. The grid protocol is enforced by the files themselves; style changes belong in `custom_css` or in `agentshell_set_*` tool calls.
- `style.css` Sections 1, 2, 5, 6, 7 — editable for token work only via the MCP tools (`agentshell_set_palette`, `agentshell_set_typography`, `agentshell_set_spacing`, `agentshell_set_shape`, `agentshell_set_css_var`). Hand-editing these is allowed for the theme author but discouraged for runtime agents.
- `widgets/*.php`, `widgets/.index.json` — editable as part of the bilateral widget registry. New file widgets must register in `.index.json`.

This is the *complete* Unbreakable Grid protocol. The legacy `template-parts/grid-areas.php` (when present) was the canonical generator; the law applies regardless of which generator is currently shipping.

---

## 6. Working pattern (the only loop)

```
agentshell_inspect                # observe first
agentshell_validate               # check health
agentshell_create_snapshot(name)  # before risky work
agentshell_begin_transaction(label=…)
…mutations (staged; not yet live)…
agentshell_preview_transaction    # token-level diff
agentshell_validate_transaction   # doctor on staged
agentshell_commit_transaction     # or agentshell_rollback_transaction
agentshell_get_audit_log          # confirm
```

Two-phase: read-only calls (`inspect`, `explain`, `validate`, `get_*`, `list_*`, `diff_*`, `preview_*`) are always safe. Mutating calls (`set_*`, `update_*`, `create_*`, `publish`, `register_*`, `unregister_*`, `remove_*`, `enable_*`, `disable_*`, `save_*`, `apply_*`, `restore_*`, `commit_*`, `rollback_*`, `screenshot`, `export_*`, `import_*`) must run inside an open transaction or be flagged as needing recovery.

---

## 7. Files agents must not modify

- `header.php`, `footer.php`
- `style.css` Sections 3 and 4 (the FSE layout / zone styling layer)
- `template-parts/shell-render.php`, `template-parts/widgets.php`
- `agentshell-mcp/`, `agentshell-blocks/` plugin directories
- `agentshell-mcp-daemon/` daemon

These files are part of the system contract. Style changes belong in CSS variables (`agentshell_set_*` tools), in `custom_css`/`custom_js` config values, in registered widgets, or in `wp_options` (via tools). File edits are for the theme author and require a human review.

---

## 8. Other markdown files — pointer map

Every other markdown in this repository is a pointer, an archive, or a non-normative note. If it disagrees with this file, **this file wins** and the other file is wrong.

| File | Status | Purpose |
|---|---|---|
| `README.md` | Pointer | Human-facing overview and setup; no normative contracts. |
| `CLAUDE.md` | Pointer | Claude Code orientation; "AGENTS.md is authoritative" stated up front. |
| `AGENT-PROMT-EXAMPLE.md` | **Archive / deprecated** | v1 prompt fragments. The legacy "Unbreakable Grid Protocol" wording it contains is captured above in Section 5; the file itself is not normative. |
| `agentshell-mcp/README.md` | Pointer | Plugin install + auth + capability hint; points to this file for the tool list. |
| `agentshell-mcp-daemon/README.md` | Pointer | Daemon config + transport; no normative agent contracts. |
| `skills/agentshell/SKILL.md` | Pointer | Namespaced skills index; delegates to this file for the tool surface. |
| `skills/agentshell/image-to-theme/SKILL.md` | Skill | A single task contract: theme the site to a reference image. No tool definitions, no duplicate architecture. |
| `skills/agentshell/image-to-theme/README.md` | Pointer | Human overview of the image-to-theme skill. |
| `skills/agentshell/widget-builder/SKILL.md` | Skill | A single task contract: build a custom widget. Defines the two tracks and the data-* / no-fetch law. |
| `skills/agentshell/widget-builder/README.md` | Pointer | Human overview of the widget-builder skill. |
| `docs/_archive/2026-08-widget-builder-sdd/` | Archive | Historical SDD artifacts, plans, and specs from the widget-builder initiative. Non-normative. |
| `docs/_archive/2026-08-widget-builder-sdd/agent-sdd/` | Archive | Sub-agent progress notes. Non-normative. |
| `docs/superpowers/` | **Removed** | The historical SDD plan/spec tree was moved into `docs/_archive/2026-08-widget-builder-sdd/superpowers/`. The pre-existing `docs/` directory has been replaced with `docs/_archive/` to make the archive boundary explicit. |
| `.superpowers/` | **Removed** | Moved into the archive above. The `.superpowers` directory at the project root no longer exists; the gitignore now points to `docs/_archive/`. |

**Hard rule about new docs.** Any new markdown file must do one of: (a) pointer to this file, (b) archive notice, (c) single-task skill. No new file may redefine MCP tools, the operation loop, the bilateral widget registry, the data-* hydration law, the no-fetch boundary, the bilateral source of truth, or the 45+ tool count. New skill files inherit the widget and tool contracts by reference; they may not amend them.

---

## 9. Validation checklist for documentation PRs

Before merging any markdown change in this repository, the change must pass these tests. They are the same gates the system uses to validate agent output.

1. **No duplicate MCP tool table.** Any file that lists MCP tools besides this one must either be deleted or contain only a link to Section 2.
2. **No duplicate architecture diagram.** Any file that draws the agent/daemon/plugin flow besides this one must either be deleted or contain only a link to Section 1.
3. **No duplicate agent loop.** Any file that describes the observe / mutate / commit loop besides this one must either be deleted or contain only a link to Section 6.
4. **No "wp_options is the sole source of truth" claim** anywhere in the repo. The bilateral registry in Section 4 is the only correct statement.
5. **No "10 tools" or "11 tools" or "12 tools" count** anywhere. The canonical count is "45+ tools" (see Section 2).
6. **No `<script type="application/json">` example or "data hydration" mention that doesn't use `data-*`.** Section 4.2 is the only pattern.
7. **No `fetch()` example in any widget skill that isn't an explicit "FORBIDDEN" callout.** Section 4.3 is the only law.
8. **No violation of the three grid rules** (1fr, descendant scope, quoted rows). Section 5 is the only law.

If a doc change fails any gate, the PR is rejected. If a doc change requires amending the contract, the change must be applied here in `AGENTS.md` *first* and any dependent skill or note updated by reference, not by duplication.

---

## 10. Failure behavior (if a contradiction cannot be resolved)

If two statements inside this repository conflict in a way this file's precedence rules do not resolve, the system **halts and surfaces the contradiction** rather than picking a winner. The error must include the file path, the line numbers, and the exact wording of both statements. The agent must not commit, register, or apply anything until the human author reconciles the conflict in this file.

This is the same posture the transaction system uses: refuse to commit a staged change that fails validation, surface the error, let the human resolve it.
