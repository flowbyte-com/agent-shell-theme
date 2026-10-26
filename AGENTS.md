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

## 2. MCP tools (authoritative count: **69**)

The plugin ships **69 `agentshell_*` tools** (66 in `agentshell-mcp` + 3 in `agentshell-blocks`; growth is expected). The number is kept honest by a test — `tests/tool-count-test.php` counts the tools the plugins publish and fails if it differs from the number written here — so a count that goes stale fails the build instead of misleading an agent. Earlier counts cited in other files ("10 tools", "11 tools", "12 tools") are deprecated and must not be reproduced.

The count is the number of tools an agent can **call**: the registry is keyed by tool name and keeps the first registration of each name, so two plugins publishing the same name produce one tool, not two. The test counts distinct tool names for that reason, and separately asserts that no two plugins publish the same name — a duplicated name means one implementation is silently unreachable, and which of the two serves depends on a filter priority rather than on anything a reader of the code would notice.

**Categories (call by category; the names below are exhaustive at the time of writing — discover additions with `agentshell_get_capabilities`):**

- **Configuration read & write** — `agentshell_get_config`, `agentshell_get_design_system`, `agentshell_set_design`, `agentshell_set_css_var`, `agentshell_set_custom_css`, `agentshell_set_palette`, `agentshell_set_typography`, `agentshell_set_spacing`, `agentshell_set_shape`, `agentshell_set_layout`, `agentshell_list_zones`, `agentshell_update_zone_composition`, `agentshell_update_zone_slots`, `agentshell_inject_json_block`.
- **Site model & observation** — `agentshell_inspect`, `agentshell_explain`, `agentshell_get_capabilities`, `agentshell_validate`, `agentshell_get_audit_log`.
- **Revision history & diffs** — `agentshell_list_revisions`, `agentshell_diff_revisions`, `agentshell_restore_revision`.
- **Snapshots** — `agentshell_create_snapshot`, `agentshell_list_snapshots`, `agentshell_restore_snapshot`, `agentshell_diff_snapshot`.
- **Theme profiles** — `agentshell_save_theme_profile`, `agentshell_list_theme_profiles`, `agentshell_apply_theme`, `agentshell_preview_theme`, `agentshell_export_theme`, `agentshell_import_theme`.
- **Widget lifecycle** (bilateral registry) — `agentshell_list_widgets`, `agentshell_get_widget`, `agentshell_register_widget`, `agentshell_unregister_widget`, `agentshell_enable_widget`, `agentshell_disable_widget`, `agentshell_remove_widget`.
- **Content primitives** — `agentshell_create_page`, `agentshell_create_post`, `agentshell_create_draft`, `agentshell_update_content`, `agentshell_update_draft`, `agentshell_update_post_content`, `agentshell_publish`, `agentshell_unpublish`, `agentshell_search_content`, `agentshell_get_content`. `agentshell_create_draft` and `agentshell_update_draft` are the only two that may run inside an open transaction: they are the production journal writers (`create_draft` always writes a draft, `update_draft` only edits drafts the current transaction created; both require an `idempotency_key`). The other mutating tools (`create_page`, `create_post`, `update_content`, `update_post_content`, `publish`, `unpublish`) refuse inside a transaction by name, telling the caller to close the transaction or use the journal-aware alternative. Outside a transaction, all of them write immediately, are not transaction-staged (§6.1), and each returns an `atomicity` block saying what it could guarantee (§6.3).
- **Reversible content deletion** — `agentshell_trash_content`, `agentshell_restore_content`. Moving content to the Trash and bringing it back. **There is no permanent deletion and no `force` argument anywhere**: `wp_delete_post()` is permanent, skips the Trash and rewrites the slug, so the object left behind is not the object the caller was talking about. Both operations are idempotent — trashing a trashed post, or restoring one that was never trashed, reports what it found and changes nothing — so a retried call is safe. Both require the post type's `delete_post` capability **for that post** (not merely `manage_options`), both refuse inside an open transaction (they are not journal-aware status changes), and both refuse content that a journal, a published-edit shadow or a live claim is holding. A refused operation leaves the content exactly as it was. Each write runs inside `Content_Write_Guard`, and each records an audit row (`content_trashed` / `content_restored`).
- **Site identity & reading settings** — `agentshell_get_site_settings`, `agentshell_set_site_settings`. A **bounded** subset of WordPress's own options: the site title, the tagline, the front page, the posts page, and the reading options (posts per page, default comment and ping status, comment registration, date and time format, start of week). The surface is an explicit allowlist — the site URL, the administrator email, the registration policy, the plugin set and the role assignments are **not** writable, and a key outside the list is refused by name. The settings live in the configuration (`site` section), so they are staged with every other configuration change and are rolled back by `agentshell_rollback_transaction`; when the configuration is written, the changed settings are projected onto the WordPress options inside the same guarded write. A front page or posts page must be an existing, published page.
- **Navigation management** — `agentshell_register_navigation` (create or replace a WordPress nav menu by name and optionally assign to a registered theme location). Reconciles the requested items against the menu in place, so item ids are stable across repeated runs. **Not transaction-staged**: writes take effect immediately and `agentshell_rollback_transaction` will not undo them.
- **Transactions (the safe change loop)** — `agentshell_begin_transaction`, `agentshell_get_transaction`, `agentshell_preview_transaction`, `agentshell_validate_transaction`, `agentshell_commit_transaction`, `agentshell_rollback_transaction`. A transaction is **leased**: it carries a 60-minute inactivity TTL that only its owner renews, it stops staging once that lease expires, and an abandoned config-only transaction is reclaimed (and audited) when the next one begins. See §6.2.
- **Content journal & recovery** — `agentshell_get_journal`, `agentshell_recover_transaction`. Read-only inspection of a transaction's durable content journal, and the resolution of an expired transaction that holds one. Two distinct authorities gate the tools: read authority (the transaction's owner with `manage_options`, or a recovery administrator holding `manage_options` and passing `authorize_inspection: true`) is what `agentshell_get_journal` requires; **destructive** authority (the owner of the transaction re-checked at the moment of compensation via the per-post `delete_post` capability, or the recovery-administrator path with explicit confirmation) is what `agentshell_recover_transaction` requires — `manage_options` alone is not enough to delete a post the actor no longer holds the per-post cap on. **Recovery compensates drafts only**: updates, navigation, taxonomy and permanent deletion are refused by name, not approximated. See §6.4.
- **Site meta & visuals** — `agentshell_get_site_info`, `agentshell_screenshot`, `agentshell_get_screenshot`.
  `agentshell_screenshot` returns a native MCP **image content block** by default (`inline: true`), so no
  credential handoff and no second request is needed to see the result. `inline: false` stores the capture
  outside the document root and returns an opaque id instead; `agentshell_get_screenshot` retrieves it.
  Retrieval is authorised by **capture ownership**, not merely by authentication — a second administrator
  passing `manage_options` is refused. Never treat a returned retrieval URL as deliverable without fetching
  it. Full contract: [`docs/SCREENSHOT-PRIVACY-DESIGN.md`](docs/SCREENSHOT-PRIVACY-DESIGN.md).

  **The reported dimensions are the ones the image has, not the ones that were asked
  for.** A capture drives the browser through the DevTools protocol, sets the viewport
  it was asked for, and then measures the viewport the page actually got
  (`window.innerWidth`), so a 320-pixel request is laid out at 320 pixels and the PNG is
  320 pixels wide. The result reports the requested width, the measured viewport width
  and height, and the capture mode (`devtools` when the browser was driven through the
  protocol, `window` when the fallback was used).

  The fallback exists: if the browser cannot be driven through the DevTools protocol,
  the capture falls back to the window-based path, and *that* path inherits the browser's
  minimum window width (around 485 CSS pixels on the hosts this was measured on) — the
  page is then laid out wider than it was requested and the PNG is a crop of that wider
  layout. The fallback says so: `capture_mode` is `window`, and `viewport_adjusted` is
  set with a note when the measured dimensions differ from the requested ones. A
  "mobile" screenshot that was really a 485-pixel layout is a finding an agent must be
  able to see, and it stays visible in the result. `full_page: true` captures the whole
  page at the viewport's width: the image is as tall as the page, and its width is still
  the viewport's width.

  **A capture may ask for one thing only: the site's own origin — and the check
  happens before the request is sent.** The unit of permission is the **origin**
  (scheme, host and port), not the host: the site's own `127.0.0.1:8080` and another
  service on `127.0.0.1:8099` share a host and share nothing else, and the second one is
  exactly what the guard exists to keep out of a capture. Two layers enforce it: the
  DevTools protocol's **Fetch** domain pauses every request the page makes — redirects,
  scripts, frames and subresources — and refuses the ones the policy does not permit
  *before they are sent*; and the browser's own resolver rules make every name that is
  not permitted fail to resolve. A refusal of the **page itself** fails the capture and
  names the reason; a refusal of something **inside** the page fails that request and is
  reported in the result, by host and by kind, with no path and no query — that is where
  a preview credential rides.

  **There is no automatic fallback to the legacy window-based capture.** That path cannot
  enforce an origin-level policy, and a capture that quietly changed its guarantee would
  be worse than a capture that failed and said why. When the browser cannot be driven
  through the DevTools protocol, the capture is **refused** and the refusal explains
  itself. The window path still exists, is still reachable by name, carries the resolver
  rules, and reports `capture_mode: window` — a coarser guarantee, labelled as one.

  The policy's allowlist is **empty by default**; a site whose pages legitimately load
  from a third-party origin declares it with `AGENTSHELL_SCREENSHOT_ALLOWED_HOSTS` in
  `wp-config.php` or with the `agentshell_screenshot_allowed_hosts` filter. Design,
  rationale and the adversarial reproductions:
  [`docs/SCREENSHOT-REQUEST-POLICY.md`](docs/SCREENSHOT-REQUEST-POLICY.md).

  **`published_preview: true` captures the staged edit of a published page.** The page
  stays published and stays what visitors see; the capture is served with the staged
  title, body, excerpt and slug through the published-preview overlay, under a credential
  bound to the staging — it carries the original, the shadow, the transaction and the
  op, it expires, and it stops working the moment the transaction is committed or rolled
  back. It is a capability for one request, not a publication, and the credential never
  appears in the response. Refusals are explicit: no post id, a page that is not
  published, no open transaction holding a staged edit for that page, or a caller who
  may not edit the page.
  **A staged edit may only be previewed by the actor whose transaction holds it.**
  The staging is another actor's uncommitted work, and the gate for reading it is the
  transaction's ownership, not the page's edit permission: an editor who can edit the
  page can publish their own version of it, but that is not a reason to be shown
  somebody else's draft. The credential is signed with the caller's own identity, so
  once minted it is the caller's to use — which is why the minting step is the gate and
  not the later verification. A refusal names both actors.
  Each capture runs in its own throwaway browser profile with `HOME` and `TMPDIR` inside it, so a site
  on a private certificate authority must name that authority in `wp-config.php` as
  `AGENTSHELL_TRUSTED_CA_FILES` (a path, a list of paths, or a directory of certificates). Certificate
  verification is never disabled; an unnamed certificate still fails. Design and rationale:
  [`docs/DRAFT-CAPTURE-DESIGN.md`](docs/DRAFT-CAPTURE-DESIGN.md).

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
- **Header/footer have a nine-area slot grid**: three rows of three, named by row then column — `top-left`, `top-center`, `top-right`, `middle-left`, `middle-center`, `middle-right`, `bottom-left`, `bottom-center`, `bottom-right`. Each area is an ordered `[block, …]` array and may be empty. Edit with `agentshell_update_zone_slots`, which **replaces** the grid (an area that is not named is emptied). Optional `slot_align` sets horizontal alignment per area (`start|center|end|stretch`, default by column: left/centre/right) and `row_align` sets vertical alignment per row (`start|center|end`, default `center`). Alignment is emitted as classes, never as inline styles.
- **The historical three-slot keys are aliases for the middle row**: `left` → `middle-left`, `center` → `middle-center`, `right` → `middle-right`. A payload written against the old contract is accepted by every entry point (stored config, `PUT /wp/v2/agentshell/config`, `agentshell_import_theme`, `agentshell_update_zone_slots`) and translated by `agentshell_normalize_zone()`. The translation is idempotent and independent of key order; canonical keys win when a payload carries both. Writes store the canonical shape, and reads normalise whatever is stored, so the renderer, the doctor, the configurator and the tools all see one shape.
- **The layout mode is derived from content, never stored.** A structural zone renders the nine-area grid exactly when an area outside the middle row holds a block; otherwise it renders the historical tri-slot markup byte for byte (`<div class="zone-slot slot-left">…`). There is deliberately no separate flag: a stored flag can disagree with the content, and the content cannot disagree with itself. Empty areas are still emitted so their column position survives, and CSS collapses them (`.zone-slot:empty`), so an entirely empty row consumes no vertical space.
- **Navigation blocks are `wp_core` blocks with id `nav_menu` plus optional navigation fields**: `menu` (a menu slug; a name that matches exactly one menu, or a numeric term id, also resolves), `location` (a registered theme location), `mode` (`horizontal` | `vertical` | `compact`), and `label` (the accessible name). At most one of `menu` / `location` may be set — a block that names both is refused as ambiguous. With none of the fields, the block is the historical primary-location nav and renders byte for byte as it always has, including WordPress's own fallback.
  A block that names a menu renders **that menu or a diagnostic**: `wp_nav_menu()`'s "first menu that has items" fallback is suppressed by resolving the menu first, and `fallback_cb` is passed as `false`, so `wp_page_menu()` can never stand in. A missing menu, an unregistered location, an unassigned location or two menus with the same name produces an editor-visible notice and a doctor warning/error — the stored block is never rewritten. Menu items, order and hierarchy are edited in WordPress (Appearance → Menus); AgentShell selects a menu, it does not keep a second menu database.
  `horizontal` is the historical row with dropdown submenus; `vertical` is a column whose nested items are always visible (the footer-column shape); `compact` collapses behind one disclosure button, and without JavaScript renders as a plain list. Nested menus get a disclosure button per parent level (injected by the theme script, so no-JS visitors keep the CSS hover/:focus-within path), `aria-expanded`, Escape-to-close with focus restored to the button, and a click-outside close.
- **Main** is a vertical `composition: [block, …]` array. Edit with `agentshell_update_zone_composition`.
- **Sanitization is absolute**: `<style>` tags, `style=""` attributes, and inline event handlers (`onclick`, `onerror`) are stripped from `json_block` and from `agentshell_update_post_content` payloads for untrusted users. Use class-based CSS, registered widgets, or Web Components. For trusted users (`unfiltered_html` / `manage_options`) `<script>` is preserved — see the trust section in the `agentshell-mcp` plugin tool descriptions, not in any skill.

The doctor (`agentshell_validate`) is the source of truth for what a "valid" block is. Never pre-empt it in skill text.

### 3.1 The human editor (the configurator)

- **The human editor is a view of the same configuration, not a second store.** It reads and writes the same flat design variables, the same `custom_css` / `custom_js` and the same zone structure, through the same REST endpoint, as the tools in §2. There is one configuration, one validator and one doctor.
- **It adds no MCP tool and changes no MCP tool's behaviour.** The routes it needs beyond the configuration endpoint are theme REST routes under `wp/v2/agentshell/`, all behind the same permission callback as the configuration endpoint. The tool list in §2 is unchanged.
- **It obeys §6.1–§6.2.** It opens a transaction, stages into it, and commits it; it rolls back a transaction it opened for a save that failed; and it neither saves nor discards while another actor holds the open transaction.
- **An editor session is not a WordPress account.** A configurator-created transaction carries a server-issued editor-session capability: the token is returned once, stored by the browser in `sessionStorage` (one tab, one editing operation), and only its SHA-256 is kept in the transaction row. The account remains the outer boundary — nobody else may touch the row at all — but a mutation on a session-bound row requires the matching capability. A second tab, a second browser, or an agent using the same administrator account is therefore *not* the same editing operation: it sees that the transaction exists, sees whether it holds staged work, and cannot read, overwrite, commit or roll back it. Reload recovery works because the same tab still holds the token; a lost token is recovered only by the explicit, confirmed, audited recovery action (same account, no durable content work), which rotates the capability and immediately stops the previous session from acting. Agent-opened transactions are unbound and keep the historical actor-only semantics; a browser cannot silently stage into, commit or roll back one. A transaction opened before this capability existed is reported as unbound and is recovered the same explicit way.
- **Preview stages; it does not commit.** Preview opens an editor-owned transaction (or reuses the live one), PUTs the current draft through the canonical validator, and mints the existing signed preview URL. The transaction is left open for Save or Discard, and the public configuration is unchanged. A later edit is restaged by the next Preview.
- **A failed Preview never leaves an unexplained lock.** If the PUT is refused, the empty transaction this Preview opened is rolled back; if the PUT's outcome is unknown, the client asks the transaction's outcome endpoint before deciding, and rolls back only a transaction this operation opened. If staging succeeded and only the URL request failed, the staged work is kept and can be saved or discarded. If the transaction expired or changed ownership, nothing is rolled back and the draft is kept.
- **Save commits the preview transaction it owns**, re-reading committed state before reporting Saved. **Discard** rolls back only an editor-owned transaction, then refreshes from the server. Both re-read the server's ownership answer instead of trusting a local flag; a foreign actor's or another session's transaction is never taken over, committed or rolled back.
- **Save distinguishes six outcomes, and never reports Saved before the commit is confirmed.** Validation before staging, failed staging, confirmed commit failure, confirmed commit success, an unknown commit outcome, and a confirmed commit whose configuration refresh failed are different states. An unknown outcome is reconciled against `GET /wp/v2/agentshell/transaction/outcome`, which reads the audit log's transaction id rather than comparing raw JSON; no second commit is sent and no rollback follows a commit. A confirmed commit with a failed refresh is reported as *Saved, but the editor could not refresh*, never as "Not saved". The messages are "Validation failed; changes remain editable", "Changes staged but not committed", "Save outcome could not be verified", "Saved successfully", and "Saved, but the editor could not refresh".
- **The status chip distinguishes** Saved, Unsaved changes, Staged for preview, Changes since preview, Locked by another actor, Locked — staged in another editor session, Locked — opened outside this editor, Not saved — validation/connection error, and Save outcome could not be verified. Staged work is never labelled Saved.
- **History is manageable without becoming a second store.** The History view lists snapshots and revisions with a descriptive label, the date and time, the actor and the record id; snapshots and revisions are visibly distinguished, and Restore is visually separate from Delete. Individual and bulk deletion go through `POST /wp/v2/agentshell/snapshots/delete` and `POST /wp/v2/agentshell/revisions/delete`, which call `Store::delete_snapshots()` / `Store::delete_revisions()` under the same `edit_theme_options` permission callback as the rest of the editor. Deleting history **never changes the committed configuration** and is allowed while a transaction is open; restoring remains refused while one is open. Revision deletion writes a tombstone map instead of rewriting the `parent` pointer of surviving revisions, so lineage is not falsified; it does not erase audit rows that mention the removed id; and it appends one `revision_deleted` audit row naming the actor, the ids and the reason. Snapshot deletion appends `snapshot_deleted`. A new revision records a `description` derived from the actual diff when the change is identifiable (a single token, a zone whose blocks changed, custom CSS/JS), replaces a generic `transaction: configurator save` label with it, and records nothing when the change cannot be identified reliably. Existing revisions without a description derive one from the stored parent config when it is still present. The 25-revision, 20-snapshot and 100-audit bounds are unchanged, and no new MCP tool was added.
- **A history deletion and its audit record are one operation.** The audit record is written *before* the deletion and finalised after it, and all three writes run inside one SQL transaction over the options table (`Content_Write_Guard::run_on()`), so a failed audit write stops the deletion rather than leaving deleted history behind. Where the options table cannot be transactional, the write-ahead order still holds. A failure between the deletion and the audit confirmation is reconciled by re-reading both the history and the audit log; the result reports one of four outcomes — `deleted` (confirmed), `unchanged` (nothing matched), `failed` (nothing was deleted, and the reason is known) or `uncertain` (the server could not establish whether the write landed) — and an `atomicity` block says what may be believed about the write. A caller is never told a deletion failed after it had already happened, and never told it succeeded when the server cannot prove it. The committed configuration is never written by a deletion.
- **It does not pre-empt the doctor.** A configuration the server refuses is refused by the editor too, with the server's own message, and the operator's input is kept so it can be corrected.
- **Its assets are enqueued only for `edit_theme_options`**, and the panel and its trigger are printed only for that capability. An ordinary visitor is sent no configurator bytes at all. The trigger's visibility is a convenience for the owner, never the authorisation boundary.
- **Opening the editor writes nothing.** A legacy three-slot configuration stays valid and is stored in the canonical shape by the existing normalisation; the editor does not rewrite a site's layout because it was opened.

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

### 4.3 The widget runtime — the init function is called for you

A widget's `init_js` *registers* an initialiser and nothing else:

```js
window.AgentshellWidgets['<id>'] = { init: function(el) { /* … */ } };
```

The theme's widget runtime calls it. The runtime lives in the theme
(`agentshell_widget_runtime_js()`), and the blocks plugin delegates to it, so
there is one implementation of "what does a widget's init function get called
with". The contract it implements:

- `init(el)` is called once per element, with the element itself.
- An element is initialised at most once, however many times it is asked for.
- One widget's failure is contained: a widget whose `init` throws is logged, and
  every other widget still initialises. A rejected promise is caught the same way.
- Elements added after load are initialised.
- Both `data-widget` and `data-widget-id` mark a widget element, because a
  widget's own template may use either and the renderer emits the latter.
- An element whose widget has not registered yet is left unmarked, so a later
  registration still initialises it.

A widget that needs to initialise itself must not: the runtime already did it,
and a self-invoking script inside a widget's template is the workaround for a
runtime that did not exist.

### 4.4 No client-side `fetch()` — absolute

Widgets must not call `fetch()` for any reason. The boundary covers the WP REST API, external APIs, and any URL whatsoever. If data is required, it comes from server-rendered DOM (colocation with `wp_loop`) or from `data-*` hydration. There is no third option. This rule has no strictness knob and is not negotiable.

### 4.5 Track model (when to use what)

Two tracks. Snapshot-style widgets are a **pattern inside Track 1**, not a third track.

- **Track 1 — Interactive.** Self-contained applications (calculators, visualizers, simulators). No WordPress data dependency. Uses local state, `window.math`, `window.d3`. `init_js` reads from `data-*` attributes on its own element only.
- **Track 2 — WordPress Decorator.** Progressively enhances server-rendered content. The widget colocates with the `wp_loop` (or `wp_core` / `wp_widget_area`) it enhances — the agent places the blocks together in the same zone. The widget locates its source by walking up to its zone scope (`el.closest('.zone-main, [data-zone]') || el.parentElement`) and selecting the nearest preceding `wp_loop` within that zone. Blind document scanning (`querySelectorAll('article')`) is prohibited.

Decision rule: if the widget must stay correct as the underlying content changes between page loads, use Track 2. Otherwise, Track 1 (snapshot-seeded if point-in-time data is acceptable, plain otherwise). When ambiguous, pick the least powerful track that satisfies the requirement. Visible reasoning required (one line: "Track: X. Reason: Y.").

### 4.6 Refresh anchor (snapshot-seeded Track 1)

When embedding snapshot data in `init_js`, prefix the script with a single comment `/* agentshell-snapshot-source: <description-of-where-the-data-came-from> */`. The comment is the only provenance link a future agent will have. Be specific. Terminal snapshots end the comment with `, terminal (not refreshed)`.

### 4.7 Escape hatch (mu-plugin filter) — last resort only

Standard `wp_loop` markup exposes what most decorators need. When it does not, the *only* allowed PHP modification is a mu-plugin filter written to `/tmp/agentshell-widget-filter-<timestamp>.php`, linted with `php -l`, moved atomically to `wp-content/mu-plugins/`, and verified with a `curl` health check on the daemon. Recovery is a single `rm` on the file. **No `register_activation_hook`, no cron, no DB writes.** `agentshell_rollback_transaction` cannot help if the filter bricks the daemon — never rely on it for mu-plugin rollback.

There is no MCP tool for "register a mu-plugin." That is a deliberate architectural choice; do not propose one in any skill.

---

## 5. Theme — immutable CSS Grid protocol (absolute system law)

The CSS Grid layout is governed by three load-bearing rules. Every template, generator, and agent payload must obey them. Violations are layout breakages, not style choices.

1. **The 1fr rule.** `#agentshell-root` (or any grid container generated from `template-parts/grid-areas.php`) must declare `grid-template-columns: 1fr` on the base rule. When a multi-column breakpoint fires, the rule is wrapped in the breakpoint media query and the column template becomes `1fr <sidebar-track>` (e.g. `1fr var(--sidebar-width, 320px)`) — the main track always resolves to `1fr`; the sidebar track is fixed. The default `1fr` is what makes the main zone fill the available width when the sidebar is off.
2. **Descendant scoping.** The class that toggles the sidebar lives on `<body>` (`.sidebar-enabled`). Grid rules must use the descendant form `.sidebar-enabled #agentshell-root`. The reverse form (`#agentshell-root.sidebar-enabled`) breaks because the class is on the body, not the root. The same descendant discipline applies to any future toggles — class on body, descendant-scope the grid.
3. **Quoted rows in `grid-template-areas`.** Every row in a `grid-template-areas` value must be a quoted string separated by spaces. `"header main"` is a single cell of two tokens (wrong). `"header" "main"` is two cells of one token each (correct). Bad: `"header main footer"`. Good: `"header" "main" "footer"`. Apply the same rule to any generator output in `template-parts/grid-areas.php`, any inline style emitted by an agent, and any `custom_css` payload.

**Layout prohibition.** `agentshell_inject_saved_styles()` emits a `<style id='agentshell-grid-fix'>` rule that resets `position`, `top`, `left`, `right`, `bottom`, `z-index` on `#zone-header`, `#zone-main`, `#zone-footer`. That rule is part of the protocol. Agents must not produce CSS that depends on `position: fixed` or `position: absolute` on a zone container; the reset will neutralize it.

**The nine-area grid obeys the same three rules.** A structural zone that renders nine areas carries the modifier class `zone-grid--expanded` and uses `grid-template-columns: 1fr auto 1fr` (the main track is still `1fr`; no `grid-template-areas` is introduced), with each cell placed explicitly by `grid-area` so a collapsed empty cell cannot shift its neighbours. Placement is explicit for that reason, not for styling: `.zone-slot:empty` removes an empty cell from the grid, and auto-placement would then pull the remaining cells leftwards.

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

### 6.1 What a transaction actually covers — and what it does not

**The current contract (normative, unchanged):** a transaction stages **configuration only** — the `agentshell_config` option and everything derived from it (design tokens, zone composition, zone slots, widget registration and enablement, injected blocks). Mutations outside a transaction write through immediately, and that behaviour is **preserved deliberately**: this pass did not change it, and changing it is a separate, approved step.

Two tools are outside even that, and their results say so:

- `agentshell_register_navigation` — writes immediately; `agentshell_rollback_transaction` does not undo it.
- Content (`agentshell_create_page`, `agentshell_create_post`, `agentshell_update_content`, `agentshell_publish`, `agentshell_unpublish`) — writes immediately, outside any transaction, and is **not** transaction-staged today.

An agent must therefore not describe a run as "transactional" if it used either of those. The accurate phrasing is "the configuration was transactional; content and navigation were immediate."

**The proposed contract (Stage 1b partially implemented):** the *tracked* part is true for `create_draft` and the *fail loudly* part is true for every other **content** tool — a content tool that has not yet been wired to the journal refuses inside a transaction rather than writing past it; a caller that needs the operation has the journal-aware path to take (`agentshell_create_draft` today; updates, taxonomy and permanent deletion are Stage 2). This is the smallest commitment that satisfies §10 of the design record: *unimplemented operations must not silently perform untracked mutations.* The full tool list inside an open transaction is: opening and closing the transaction, the content-journal read tools (`agentshell_get_transaction`, `agentshell_get_journal`), `agentshell_recover_transaction` (destructive, gated by authority), the staged config / widget / navigation tools (§below), `agentshell_screenshot` (mints its own credentials, unaffected by transaction state), and `agentshell_create_draft`. Every other content tool refuses at `execute()` entry, naming `agentshell_create_draft` in the refusal.

**Staged configuration, widget, navigation, and screenshot tools participate in the transaction lifecycle in their existing way — and that is not the same as the content tool's behaviour.** Config and widget writes inside a transaction are *staged*: visible only under the transaction preview credential, not to ordinary renders. `commit_transaction` promotes the staged state to effective; `rollback_transaction` discards it. Outside a transaction, they are immediate-write to the effective state. Navigation is verified at implementation time: `agentshell_register_navigation` carries a refusal at `execute()` entry today, named consistently with the content tools, and its result reports `transaction_note: 'not updated; agentshell_rollback_transaction will not undo this.'` so an agent reading its own output cannot mistake a navigation call for a journalled one. Screenshot mints its own credentials and is unaffected by transaction state; it is not part of the transaction lifecycle. None of these tools has identical semantics to `agentshell_create_draft`; each is described honestly with respect to the lifecycle, and the description matches the implementation.

**The proposed contract's *full* form (every content tool journals; a SQL transaction wraps the commit; navigation joins the transaction; preview sees staged content) is Stage 2+ and remains a proposal.** The truthful lifecycle for `create_draft` is the only production lifecycle today; everything else is configuration-staged or immediate-write as it has been since v1. Design and status: [`docs/CONTENT-TRANSACTIONS-DESIGN.md`](docs/CONTENT-TRANSACTIONS-DESIGN.md).

### 6.1a The secret a preview credential is signed with (normative, implemented)

**Every preview credential is signed with one secret, and one rule decides what it is.** `Transaction_Manager::preview_secret()` is the owner of that rule:

1. `AGENTSHELL_REST_TOKEN`, when it is set to a **non-empty** value.
2. `AUTH_SALT`, when it is set to a **non-empty** value.
3. Nothing at all — and then **no preview credential is offered at all**, because a credential signed with an empty secret is one anybody can compute.

**A constant that is defined but empty is not a secret.** A site that puts `AGENTSHELL_REST_TOKEN` in `wp-config.php` with no value must not be treated as having one.

Every path that mints or verifies a preview credential uses this rule. The theme asks for the answer through `agentshell_preview_secret()`, which asks the owner when the MCP plugin is loaded and applies the same rule when it is not; the screenshot tool asks the owner directly. The two agree by construction, so **a key the tool produces is a key the theme accepts — and only that key**.

### 6.2 Transaction lease and stale recovery (normative, implemented)

A transaction lives in one option row. Nothing removes that row except a `commit` or `rollback` the owner actually reached, so before this lease existed a crashed session wedged the site permanently. The lease is the fix, and it is part of the contract:

- **Every transaction carries a lease**: `created`, `last_activity`, `expires`, `lease_ttl_seconds` (60 minutes), `seconds_remaining`. `agentshell_get_transaction` reports it for a live transaction and reports an expired one under `stale` — never as `open`.
- **Only owner activity renews it** — `stage`, `commit`, `rollback`, `pre_update_option`, and re-beginning your own transaction. A peer's `preview` or `validate` does not, or any reader anywhere could pin a transaction open forever.
- **An expired transaction is not a transaction.** It no longer stages, no longer shapes `effective_config`, cannot be previewed, and cannot be committed or rolled back. A mutation arriving after expiry **writes to the database** rather than into a buffer nobody can commit.
- **Config-only transactions are reclaimed automatically** — on the next `agentshell_begin_transaction` — and the reclaim is **named in the result** (`reclaimed`, `note`) and **written to the audit log** as `transaction_reclaimed`. A silent fix is the same as no fix when somebody later asks where their work went.
- **A transaction holding durable work is never reclaimed.** A journal whose only copy is that row is preserved, `begin` refuses with an explicit "unrecovered work" message, and `get_transaction` reports `recovery: required`. This guard is written and enforced today, while no transaction can yet hold such work, so that the change which makes it load-bearing does not have to invent it.
- **Every write to the row is a compare-and-swap**, guarded on the row's current bytes. WordPress's `update_option()` is a bare `UPDATE … WHERE option_name = ?` and `delete_option()` is an unguarded read-then-delete; both lose a race, and the delete destroys whatever now occupies that name. Opening a transaction uses `add_option()` — an `INSERT` against the `UNIQUE` key on `option_name` — so two actors racing to open one produce a loser that is told, not two owners who both think they won.
- **Preview isolation is not weakened, and the two clocks stay separate.** The lease reads a freezable clock used only for expiry; the preview credential's own window keeps reading real `time()`, so no test seam can stretch a bearer capability. An expired transaction refuses a preview **before** the signature is checked, which closes a window that existed before this change: a credential minted in minute 1 of an hour-long lease was still inside its own 900-second window when the lease died.

Regressions: [`tests/transaction-lease-test.php`](tests/transaction-lease-test.php), with [`tests/transaction-lease-mutation.php`](tests/transaction-lease-mutation.php) removing each of these guarantees one at a time.

### 6.3 Content write atomicity (normative, implemented)

Content writes are **immediate and outside every transaction** (§6.1). What they *are* guaranteed is narrower and worth stating precisely, because the two are easily confused.

`wp_insert_post()` is not all-or-nothing: it writes the post row, its slug, its categories, its other default terms, `meta_input`, its GUID and its status transitions as independent steps, and one of its branches returns `WP_Error` *after* some of them — without ever naming the post it wrote. So "the tool reported failure" did not, on its own, mean "nothing was written", and the caller had no id with which to find the residue.

**Every content write therefore runs inside a bounded SQL transaction** (`Content_Write_Guard`) that rolls back on any failure: a `WP_Error`, a `false`/`0` result, or a thrown exception. An agent can rely on this:

- **A failed content write leaves nothing behind** — no post row, no metadata, no cached copy of the post. If a content tool reports failure, there is no orphan to reconcile.
- **A failed content write never damages existing content.** A failed update leaves every field of the target exactly as it was.
- **A content tool that reports failure does not return a post id**, because there is nothing to return one for.
- **A titled page with an empty body is valid** and is created. A page with *neither* a title nor content is refused before anything is written — WordPress would otherwise store an untitled draft that no listing, edit screen or preview can identify.
- **The transaction is bounded by the single call.** It is never held across MCP calls, screenshots or agent reasoning.

Every content result carries an `atomicity` block stating what actually happened, and agents should read it rather than assume:

| Field | Meaning |
|---|---|
| `database_transaction` | `true` when the write really was inside a transaction on a transactional engine |
| `engine` | the engine actually backing `wp_posts` / `wp_postmeta`, or `null` |
| `on_failure` | what happens to the write if it fails — rolled back, or **not** rolled back |
| `not_covered` | the side effects a rollback cannot undo, disclosed rather than assumed away |

Two honest limits, which the `atomicity` block exists to make visible:

- **Non-database side effects are not undone.** Email, files and outbound HTTP survive a rollback.
- **A plugin's own database writes during `save_post` *are* rolled back.** They are inside the transaction. A plugin cannot rely on a failed `create_page` leaving its bookkeeping intact — deliberate, because a half-applied third-party write is worse than none.

This is **per write, not per workflow.** `create_page` followed by a failed `register_navigation` is still two outcomes, not one. Design and the full findings: [`docs/CONTENT-TRANSACTIONS-DESIGN.md`](docs/CONTENT-TRANSACTIONS-DESIGN.md) §5.1.

Regressions: [`tests/content-write-test.php`](tests/content-write-test.php) and [`tests/content-write-live-test.php`](tests/content-write-live-test.php) (the real reproduction against a real WordPress), with [`tests/content-write-mutation.php`](tests/content-write-mutation.php) removing each of these guarantees one at a time.

### 6.4 Content journal and expired-transaction recovery (normative, implemented)

A journal is the transaction row's record of durable content work, and it lives inside that row — which is why §6.2's rule that a transaction holding durable work is never reclaimed is now load-bearing rather than vacuous. Two tools: `agentshell_get_journal` (read-only) and `agentshell_recover_transaction` (destructive, confirmed).

**What exists today, and nothing more:**

- **The journal is durable before anything depends on it.** Recording an intent is a compare-and-swap on the transaction row, and it returns `false` rather than reporting success when the row has moved underneath it. A write is never attempted unless its intent is already recorded.
- **A recorded intention is not proof that the write happened.** An operation left at `intended` is resolved from the post's own marker meta (`_agentshell_journal_op`, whose value *is* the operation id) — not from the journal's own account of itself. No marker and no lookalike ⇒ "no evidence the write ever happened", and nothing is deleted. A lookalike without a marker is a **conflict**, never a deletion by guess.
- **Only `create_draft` and `update_draft` are supported.** Other content tools (`create_page`, `create_post`, `update_content`, `update_post_content`, `publish`, `unpublish`, `trash_content`, `restore_content`), navigation, taxonomy, and permanent deletion are refused by name — at validation, at record time and again inside compensation. They are not approximated and not silently skipped. `agentshell_update_draft` is the *only* journal-aware content-update tool: it edits a draft the current transaction created via `agentshell_create_draft`; non-draft content and drafts owned by a different transaction are refused by name.
- **Compensation verifies before it destroys.** Marker identity, `post_type`, `post_status`, `post_name` and (when recorded) `post_modified_gmt` must all match, or the operation is recorded as a **conflict** and the post is left alone.
- **Compensation deletes; it does not trash.** `wp_delete_post()`'s default branch trashes, gated on `EMPTY_TRASH_DAYS` — which would leave a row that is itself content, and `wp_trash_post()` rewrites the slug so the object would no longer be the one the journal described. The delete is wrapped in `Content_Write_Guard`, so a delete that fails part-way is rolled back and the draft is left whole.
- **Concurrency is a claim, not a hope.** Each operation is claimed by a compare-and-swap on the transaction row (`intended`/`applied` → `compensating`, with `{actor, at}`), and released only when it settles. Two callers computing the same claim means exactly one lands. A claim older than 120 seconds is taken over, which is what makes a crash mid-recovery resumable.
- **Recovery is repeatable and resumable, and never erases anybody else's transaction.** A settled operation is reported as settled; a failed delete leaves the operation owed with its claim released so a retry is immediate; and the transaction row is released by a guarded delete, only after nothing is outstanding, so a transaction opened in the meantime is left alone and the result says `contended`.
- **A conflict keeps the row.** Only `compensated` is settled; a conflicted operation stays outstanding, so `begin` keeps refusing and the record of what a human still has to decide is never discarded to free the slot.
- **Inspection is read-only with respect to the transaction row and the journal.** `agentshell_get_journal` may be called by the transaction's owner (with `manage_options`) without an opt-in. Any other actor must hold `manage_options` **and** pass `authorize_inspection: true` on the call. The two roles — `owner` and `recovery_administrator` — are the same two roles `agentshell_recover_transaction` already returns for **read** authority. An unauthorised inspection is answered with the same shape an absent transaction is (`{transaction: null, journal: null, recoverable: false, reason: 'No transaction is open.'}`); the existence of another actor's row is not disclosed. Inspection appends one row to the audit log (`transaction_journal_inspected`) on success; an unauthorised inspection writes no audit row, so an unauthenticated read is indistinguishable from no read. The capability is re-checked on every call, so an actor whose role has been revoked since the transaction opened can no longer read or resolve.
- **Destructive authority is re-checked at the moment of compensation, not only at recovery start.** `agentshell_recover_transaction` calls `Content_Recovery::authorize_destruction()` at the tool boundary; the per-post `delete_post` capability is then re-checked **inside** `compensate()` immediately before each `wp_delete_post()`. The owner-of-the-transaction needs `delete_post` on the target post at the moment compensation runs; a user who opened a transaction and has since lost the cap (e.g. demoted from editor to subscriber, or the draft was reassigned to another author) cannot compensate that post. The recovery-administrator path (an explicit `confirm: true` with `manage_options` and `authorize_recovery: true`) does not require the per-post cap, because the point of the recovery-administrator path is to compensate when the owner no longer can. The audit row records `actor_capable: true|false` so a refused compensation is distinguishable from one that never tried. **An owner with `manage_options` but no per-post `delete_post` on the target is refused for compensation, not for inspection** — the two authorities are not interchangeable.
- **Authority is two-part for anyone but the owner.** A non-owner needs `manage_options` **and** an explicit `authorize_recovery` in the same call. `agentshell_recover_transaction` requires `confirm: true` from the owner too.
- **Every outcome is audited**, refusals included, naming the actor and the role they acted under — a recovery that quietly did nothing is otherwise indistinguishable from one that was never run.

**Four things this is not, stated so they are not assumed:** it is not a general force-delete; there is no `force` argument anywhere in it. It is not a background scheduler — recovery is something an actor asks for. It does not make a *workflow* atomic. And it compensates drafts only: a transaction holding an operation type this build cannot compensate is refused whole, with nothing deleted.

What is **not** yet implemented, and must not be described as if it were: no content update tool *other than `agentshell_update_draft`* writes a journal today (`agentshell_update_content` and `agentshell_update_post_content` are still Stage 2+ and refuse inside a transaction by name), permanent deletion does not exist — the reversible family is `agentshell_trash_content` / `agentshell_restore_content`, and neither is journal-aware, navigation staging does not exist, status transitions (publish / pending / private / future) are not journalled, taxonomy is not journalled, and this says nothing about atomicity across a multi-step workflow that mixes content with config. Prerequisites before the next journal-aware tool may land are listed in [`docs/CONTENT-TRANSACTIONS-DESIGN.md`](docs/CONTENT-TRANSACTIONS-DESIGN.md) §10.

**`agentshell_create_draft` is the only content-creation tool that journals.** It is one of two content tools that may be called inside an open transaction (the other is `agentshell_update_draft`). The non-journalled content tools (`agentshell_create_page`, `agentshell_create_post`, `agentshell_update_content`, `agentshell_update_post_content`, `agentshell_publish`, `agentshell_unpublish`, `agentshell_trash_content`, `agentshell_restore_content`) refuse to run inside an open transaction, naming the journal-aware alternative in the refusal. Outside a transaction, every content tool keeps its existing immediate-write behaviour. `agentshell_create_draft` always creates a draft — the `status` argument is fixed to `'draft'` in its input schema. Outside a transaction, the tool writes a draft with no recovery marker; inside a transaction, the tool writes a draft with the journal's `_agentshell_journal_op` marker so recovery can find it. **The strong after-image the journal records is captured in the same SQL transaction as the post insert and the marker write** — `post_type`, `post_status`, `post_name`, `post_date_gmt`, `post_modified_gmt`, and a SHA-256 of `post_content` plus a content length field. A `post_content_sha256` mismatch is the evidence that the post has been edited, including edits that leave the slug unchanged and edits that occur inside one second. The tool requires an `idempotency_key` argument (client-supplied, e.g. a UUID, minted before the first attempt) for retry idempotency: a retry that supplies the same key as a previous `applied`-state op returns the original post id with `resumed: true` and does not create a second post. The key is bound to (actor, transaction, content fingerprint); a retry with the same key but different content, actor, or transaction is refused with `transaction_create_draft_idempotency_*`; a retry of an in-flight or unrecoverable-state op is refused with `transaction_create_draft_in_progress` or `transaction_create_draft_unrecoverable`. The key is never silently replaced by a server-minted value; a missing key is a schema-level refusal. The tool's result does not include preview credentials; the screenshot tool mints them when called with `post_id` and `transaction: true`. The CAS-failure re-read is the truthful path: when `mark_applied` returns null, the tool re-reads the journal row and reports the actual state (`applied`, `partial-applied`, `intended`), not a synthetic `journal_applied: false`.

**`agentshell_update_draft` is the only content-update tool that journals.** It edits a draft the current transaction created via `agentshell_create_draft`, recording an `OP_UPDATE_DRAFT` op whose before-image is the post's state immediately before the edit and whose after-image is the post's state after the edit. Refusal conditions, in evaluation order: (1) not inside a transaction — outside a transaction the caller should use `agentshell_update_content` directly, and the refusal names that tool; (2) missing or non-printable `idempotency_key` — schema-level refusal, never silently replaced; (3) post does not exist, is the wrong type, or is not a draft — status transitions are deliberately out of scope (use `agentshell_publish` / `agentshell_unpublish` outside a transaction, after commit) and the refusal names the right tool; (4) post is not transaction-owned — the post must carry the journal's `_agentshell_journal_op` marker, and that marker must match an `OP_CREATE_DRAFT` op in the current transaction's journal in `applied` (or `partial-applied`) state. A draft created outside any transaction, or by a different transaction, is refused by name; (5) no actual field to update — at least one of `title`, `content`, `slug` must be supplied; (6) title and content both empty — a draft with neither is not a draft anyone can find. The supported fields are deliberately narrower than `agentshell_update_content`: status, parent, and other lifecycle-changing fields are not journal-aware and would change what "recoverable" means. The post update, the strong after-image capture, and the `mark_applied()` call all run inside one `Content_Write_Guard::run()` SQL transaction, so a `wp_update_post` failure rolls back the write, the journal is not advanced, and a retry can re-attempt. A retry with the same `idempotency_key` as a previous `OP_UPDATE_DRAFT` op in `applied` state with matching (actor, transaction, post_id, content fingerprint) returns the original op with `resumed: true` and does not write to the post; a retry with the same key but a different fingerprint is refused with `transaction_update_draft_idempotency_conflict`; a retry with the same key but a different actor, post, or transaction is refused with `transaction_update_draft_idempotency_*_mismatch`; a retry against an in-flight or unrecoverable-state op is refused with `transaction_update_draft_in_progress` or `transaction_update_draft_unrecoverable`. Like `agentshell_create_draft`, the result does not include preview credentials; the screenshot tool mints them when called with `post_id` and `transaction: true`.

**The lifecycle is truthful for `create_draft`.** Successful commit: `create_draft` → `applied` → `commit_transaction` revalidates each `applied` op against current WP state (the post still exists and the six recorded fields — `post_type`, `post_status`, `post_name`, `post_date_gmt`, `post_modified_gmt`, and `post_content_sha256` — match; a post edited by an outside actor since `mark_applied` is detected as a content-hash divergence even when the slug is unchanged and the modification time is the same to the second, and the op transitions to `conflict`, refusing the commit; a post deleted by an outside actor is detected as gone and transitions the op to `compensated`, allowing the commit to proceed with a `transaction_committed_after_compensation` audit row), then audits `transaction_committed` with the op summary, retains the post, and **deletes the transaction row via `Transaction_Manager::cas_delete_row( $tx )`** — the value-guarded CAS `DELETE FROM wp_options WHERE option_name = ? AND option_value = ?`, not an unguarded `delete_option()`. The audit write and the value-guarded delete share one guarded SQL transaction so a failure between them leaves the option row intact and the operator sees the audit failure; a row that moved between the re-read and the delete is reported as `transaction_commit_refused_row_changed` with the new row bytes attached, and the operator can re-issue the commit against the new row. The success result is returned only after the SQL `COMMIT` has returned. The audit log is the durable record of what the transaction created. Successful rollback: `create_draft` → `applied` → `rollback_transaction` auto-compensates by invoking the recovery engine, which uses a **MySQL named advisory lock** (`GET_LOCK( 'agentshell_recovery_lock_<hash>', 0 )` on the post's hashed id, released with `RELEASE_LOCK` and implicitly released when the connection closes) to serialise destructive compensation across PHP processes, calls `Content_Recovery::compensate()` with the per-post `delete_post` capability check under the lock, revalidates the post against the journal's recorded evidence (six fields; a content-hash mismatch is `conflict`, a missing `post_id` in the journal is `conflict` because the engine cannot prove the post is unchanged), deletes the post inside `Content_Write_Guard::run()`, audits `transaction_rolled_back_after_compensation`, and **deletes the transaction row via `cas_delete_row( $tx )`** with the same value-guarded contract. **The advisory lock is connection-scoped, not transaction-scoped; a `KILL -9` of the owning process releases it via the server's connection-close handler, which is what an in-process `finally` block could not guarantee.** If the database does not support `GET_LOCK` (MySQL < 5.7 or MariaDB < 10.0.1), the recovery engine **fails closed** and refuses to compensate; the operator must reconcile manually. The engine probe runs once per process; the result is cached. Conflict rollback: if recovery finds a divergence (the post has been edited outside the transaction, or no recorded after-image was ever captured), the op transitions to `conflict`, the post is preserved, and `rollback_transaction` refuses with `transaction_rollback_refused_after_compensation` so the operator can reconcile. **There is no `force: true` rollback that discards journal evidence while abandoning content.** The `mark_applied()` failure case is recorded as `partial-applied` and blocks both commit and rollback until the operator runs recovery. The claim TTL is refreshed at the start of every op settlement inside `recover()` so the takeover window opens only between ops, not during a destructive call; the advisory lock is the second layer that holds throughout a destructive call regardless of its duration and is released by the server on connection close, not by a PHP `finally` block that a `KILL -9` would skip. Pathological long-running deletion hooks (above 120 seconds) are bounded by the advisory lock, not the claim TTL. The recovery engine's `verify_post` runs a six-field check on the live `get_post()` reading; there is no marker-lookup-then-delete path, because a marker alone does not prove the post is unchanged. When the journal has no recorded after-image, the engine finds the marked post and reports `conflict` — the engine cannot prove what the engine cannot verify, and the post is preserved.

Regressions: [`tests/journal-recovery-test.php`](tests/journal-recovery-test.php) and [`tests/journal-recovery-live-test.php`](tests/journal-recovery-live-test.php) (the real reproduction, including that a force delete really removes the row and that a trashed orphan is really found), with [`tests/journal-recovery-mutation.php`](tests/journal-recovery-mutation.php) removing each of these guarantees one at a time. The two-authority distinction (A1–A6, B1–B3), the per-post `delete_post` re-check at compensation time, the lifecycle wire-up (L1–L13), the value-guarded CAS finalisation (VG1–VG7), the MySQL advisory lock (LK1–LK9), the strong after-image (SA1–SA6), the claim-refresh overlap (C3), the OP_UPDATE_DRAFT compensation (UR1–UR8), the validator update-refusals (UV1–UV5), and the `agentshell_update_draft` tool contract (UD1–UD15) are covered in the same files.

### 6.5 Stage 2c — published content editing (normative, implemented; M5 closed)

Stage 2c extends the §6.4 journal from drafts to **published content** via a new `OP_EDIT_PUBLISHED` op type. The four-field staging model, the per-original claim row, the identity-preserving preview overlay, the post-COMMIT reconciliation tail, and the closed compensation paths are described in [`docs/STAGE-2C-DESIGN.md`](docs/STAGE-2C-DESIGN.md) §1–§3; this section names the AgentShell-contracted subset.

- **Staging is private-shadow, not in-place.** `agentshell_edit_published` creates a `post_status=private` shadow row bound to the original by `_agentshell_shadow_for` / `_agentshell_shadow_tx_id` / `_agentshell_shadow_op_id` post-meta. The four supported fields (`post_title`, `post_content`, `post_excerpt`, `post_name`) are written to the shadow. Protected fields (`post_status`, `post_author`, `post_parent`, `post_type`, plus the housekeeping fields the WordPress layer sets itself) are preserved on the original by construction — they are not read from the shadow, and the publish step never writes them. *Anonymous* requests to the original's permalink see the unchanged original; the shadow is invisible to the public.
- **Previews use HMAC credentials, not session cookies.** `agentshell_publish_edit`'s preview path mints a credential bound to `(original_id, shadow_id, tx_id, op_id, actor, exp)`. The `Published_Preview_Overlay` verifies the HMAC, then gates on the shadow's post-meta (the head-pointer `META_OP_ID` must equal the credential's op; the shadow must be `private`) before installing the verified tuple and substituting the main query's `WP_Post` with an identity-preserving overlay (original's identity fields, shadow's four staged fields). Wrong-actor, forged, expired, drifted-baseline and stale-head credentials are refused. The credential is **not** a wp_query_var (it lives in `$_GET`); the `wp_kses_post` sanitiser in the rendered body cannot strip it.
- **Publish is a short protected SQL transaction; reconciliation is post-COMMIT, not in-transaction.** `Content_Write_Guard::run()` holds the per-tx `tx_<tx_id>` MySQL named advisory lock from `FOR UPDATE` through the value-guarded `cas_write_row` that records `state: publication_committed`. A `wp_update_post` failure rolls back the write and the journal; the post is left whole. **The reconciliation tail — shadow delete, claim release, cache invalidation — runs after `COMMIT` returns**, so a crash between `COMMIT` and the reconciliation tail leaves a published post and a still-present shadow + claim; a fresh `agentshell_publish_edit_reconcile` (or recovery) completes the tail. The two phases are not equivalent to "two transactions" — the reconciliation runs once per op, the SQL guard runs once per op, and the in-memory state of the op's `state` field is the only thing that distinguishes them.
- **Compensation is closed and crash-resumable.** `agentshell_publish_edit` refuses a second publish against an op that has reached `publication_settled`; `agentshell_publish_edit_reconcile` refuses a second reconcile against the same op. A crash mid-publish leaves the op in `publication_committed` and a fresh process can finish it via reconcile. A crash mid-reconcile leaves the op in `reconciliation_pending` and a fresh process can finish it. Repeat recovery against a `publication_settled` op short-circuits with `outcome: already_settled`. The compensation paths' pre-publish claim release classifies a TOCTOU successor claim as `anomalous` and refuses to delete it, recording `transaction_claim_release_anomaly` in the audit log.
- **Audit hygiene is explicit.** The commit and rollback success-path audit rows (`Store::record_event`) are written **outside** `Content_Write_Guard::run()` so a `cas_delete_row` failure (the only realistic cas-delete failure mode) does not leave the audit row inside the rolled-back SQL transaction. The pre-publication rollback's audit row distinguishes "shadow already gone" from "shadow deleted by this compensation".
- **The `OP_EDIT_PUBLISHED` path is single-original, single-op per transaction.** Bulk publication, mixed published/draft commits, and per-transaction multi-original workflows remain out of scope. A transaction may carry at most one `OP_EDIT_PUBLISHED` op; `agentshell_publish_edit` and `agentshell_publish_edit_reconcile` refuse multi-op transactions with a `transaction_publish_refused_multiple_ops` / `transaction_reconcile_refused_multiple_ops` message naming the op ids.

**Documented limitations (S-3 / S-4 / S-5 / S-6 of the Stage 2c M5 audit):** the unpublished-post preview HMAC does not bind a revision id (a credential for revision N is still valid after an autosave to N+1 within the 300s window); the shadow's `post_author` is fixed to user 1 (test fixtures and the agentshell-test.local preflight provision user 1); journal CAS writes do not consult `Content_Journal::verify()` (the integrity gate is downstream at the destructive boundary in `class-content-recovery.php::compensate()`); and `Store::actor()` returns the literal string `'agent'` for any non-logged-in caller, so multiple REST-token callers share one audit identity (the token id is recorded separately on each audit row; the actor field is the agent's user_login when logged in). Each limitation is documented in the relevant class docstring; none is a security defect at the closed boundary.

**Regressions and acceptance evidence (M5 closed):**

- `tests/stage-2c-overlay-live-test.php` — the M3 overlay (E2-E14, M3 acceptance) — passes against the M5-corrected actor gate.
- `tests/stage-2c-publish-edit-live-test.php` and `tests/stage-2c-publish-edit-test.php` — the M4 unit and live coverage — pass with the M5 S-1 success-path audit fix.
- `tests/stage-2c-publish-edit-race-live-test.php` — M5-t1: same-op publication and reconciliation races (8 T-cases) pass with a fresh process per contender; outcomes `['committed', 'refused']` for the publication race, `['reconciled', 'refused']` for the reconciliation race.
- `tests/stage-2c-publish-edit-recovery-live-test.php` — M5-t2: post-COMMIT interrupt + fresh-process recovery (18 T-cases) pass; the reconciliation tail completes the published post, deletes the shadow, releases the claim, and is mutation-free on repeat.
- `tests/stage-2c-end-to-end-live-test.php` — M5-t3: full lifecycle against a real WordPress (19 T-cases) pass; E1 covers page → claim → shadow → stage → preview → publish → reconcile; E2 covers retry safety; E3 covers forged/expired/wrong-actor credentials, baseline drift, and outside-the-four-fields shadow changes.
- The full regression (`tests/run-all.php`) reports 1039 passed / 0 failed; the leak check reports 0 leaked posts, 0 leaked claim rows, 0 leaked shadow meta; the checkout integrity fingerprint is untouched.

### 6.6 The custom stylesheet (normative, implemented)

`custom_css` is free-form CSS the author trusts. It is rendered verbatim into `<style id='agentshell-custom-css'>`, with one hardening step: a literal `</style` in the payload is rewritten to `<\/style`, so the payload cannot close the wrapping element early.

**The size limit is 256 KiB — 262,144 bytes — measured in bytes of the stylesheet's UTF-8 encoding, not in characters.** A limit expressed in characters would admit a stylesheet several times larger than the limit (a quarter of a megabyte of CJK text is under 100,000 characters and nearly 300,000 bytes), and what is limited is what the server sends and the browser parses. The number lives in the theme (`agentshell_max_custom_css_bytes()`); the MCP plugin reads it from there and falls back to the same number when the theme is not loaded, and `tests/custom-css-limit-test.php` asserts that the two agree.

The limit is enforced at every point a configuration can change, and always **before** anything persistent changes:

| Path | Where it is refused |
|---|---|
| Theme REST `PUT /wp/v2/agentshell/config` | `agentshell_validate_config()` → `custom_css_too_large` |
| MCP staging (every configuration tool) | `Transaction_Manager::stage()` — the transaction row is left byte-identical |
| MCP writes with no transaction open | `Transaction_Manager::on_config_update()` — the write never reaches the database |
| Theme import (`agentshell_import_theme`) | the doctor's `CUSTOM_CSS_TOO_LARGE` error, before the import is applied |
| Commit of a staged configuration | the doctor again, at commit time |

Every refusal names the size, the limit and the excess, so it is actionable.

**A routine configuration read does not carry the stylesheet.** `agentshell_get_config` omits the `custom_css` body and reports `custom_css_bytes` and `custom_css_present` instead: a stylesheet can be a quarter of a megabyte, and every response that carried it would carry it into a transcript, a log and an agent's context window. The body is returned only when it is asked for by name — `agentshell_get_config( include_custom_css: true )`.

**The stylesheet is served as a static file, not inlined into every page.** A committed stylesheet is materialised into the uploads directory under a name that carries a hash of its own bytes — `agentshell-custom-<sha256-prefix>.css` — and the page carries a `<link>` instead of the bytes. The hash is over the bytes that are served, so the URL changes exactly when the content changes; that is what makes the file's one-year, immutable cache lifetime safe. The file is written atomically (a temporary file in the same directory, then a rename), so a reader can never fetch a half-written stylesheet.

Four properties of this are load-bearing:

- **One function produces the bytes.** `agentshell_custom_css_bytes()` is the single source of the served bytes, and both delivery paths call it: the inline block and the file carry the same string, character for character. A stylesheet that rendered differently depending on which path served it would be invisible until it was deployed.
- **A staged stylesheet is never written to a file.** The materialisation is driven by the `update_option_agentshell_config` action, and WordPress skips the write entirely while a transaction is open — so the action fires only for a *committed* write. A preview response inlines the staged stylesheet and links to nothing; uncommitted work is never published to anyone who guesses the URL.
- **A materialisation failure is not a failure of the site.** The configuration is the source of truth and the file is a cache of it. If the file cannot be written, the page inlines the stylesheet exactly as it did before the feature existed, the failure is recorded in the audit log (`custom_css_materialisation_failed`), and the doctor reports it (`CUSTOM_CSS_ASSET_MISSING` or `CUSTOM_CSS_ASSET_UNAVAILABLE`). It never fails a commit.
- **The directory serves nothing but the stylesheet.** It carries an `.htaccess` that disables the PHP engine, removes the CGI handlers, declares `text/css` and sets `Cache-Control: public, max-age=31536000, immutable`. Every one of those rules is inside the `<IfModule>` guard of the module that provides it, because a directive the server refuses is not a warning but a **500 for every request to that directory** — which would take the stylesheet away from every page at once. **Directory listing is deliberately not disabled**: the directory holds nothing but content-addressed stylesheets, which every visitor can fetch anyway, so a listing of them reveals nothing — and `Options -Indexes` is the directive most likely to be refused by a server that does not allow `Options` overrides. The rule is therefore stated by what is *not* claimed: on a server that honours the `.htaccess`, nothing in the directory can be executed and the file is cached for a year; on a server that ignores it (nginx, or Apache with `AllowOverride None`), the file is still served correctly, as a stylesheet, by name — and the URL still carries the content hash, so a stale stylesheet remains impossible. Files this feature wrote that are more than thirty days old are pruned; nothing else in the directory is ever touched.

**The doctor is the place to look when the stylesheet is not being served from a file**, and so is the audit log. A site whose stylesheet is served inline still renders correctly — it is simply heavier on every page — which is why this state is reported rather than merely tolerated.

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

Every other markdown in this repository is a pointer, an archive, a non-normative note, or a **design record**. If it disagrees with this file, **this file wins** and the other file is wrong. That includes design records: a design record records an investigation, a proposal, a decision and its evidence. It cannot amend this file, and it cannot make behaviour it has not implemented normative — see category (e) below.

| File | Status | Purpose |
|---|---|---|
| `README.md` | Pointer | Human-facing overview and setup; no normative contracts. |
| `CONFIG.md` | **Non-normative setup guide** | End-to-end installation and connection walkthrough for humans: LocalWP, development symlinks, activation, Application Passwords, daemon config, Claude Code registration, verification, troubleshooting. Defines no contract; every contract is cited by section to this file. |
| `CLAUDE.md` | Pointer | Claude Code orientation; "AGENTS.md is authoritative" stated up front. |
| `AGENT-PROMT-EXAMPLE.md` | **Archive / deprecated** | v1 prompt fragments. The legacy "Unbreakable Grid Protocol" wording it contains is captured above in Section 5; the file itself is not normative. |
| `agentshell-mcp/README.md` | Pointer | Plugin install + auth + capability hint; points to this file for the tool list. |
| `agentshell-mcp-daemon/README.md` | Pointer | Daemon config + transport, and the version-compatibility policy (protocol version, product version, and the refusal on an incompatible pairing). |
| `skills/agentshell/SKILL.md` | Pointer | Namespaced skills index; delegates to this file for the tool surface. |
| `skills/agentshell/image-to-theme/SKILL.md` | Skill | A single task contract: theme the site to a reference image. No tool definitions, no duplicate architecture. |
| `skills/agentshell/image-to-theme/README.md` | Pointer | Human overview of the image-to-theme skill. |
| `skills/agentshell/widget-builder/SKILL.md` | Skill | A single task contract: build a custom widget. Defines the two tracks and the data-* / no-fetch law. |
| `skills/agentshell/widget-builder/README.md` | Pointer | Human overview of the widget-builder skill. |
| `docs/_archive/2026-08-widget-builder-sdd/` | Archive | Historical SDD artifacts, plans, and specs from the widget-builder initiative. Non-normative. |
| `docs/_archive/2026-08-widget-builder-sdd/agent-sdd/` | Archive | Sub-agent progress notes. Non-normative. |
| `docs/_archive/AUDIT-2026-09-13.md` | Archive | Historical audit report, preserved as found. Non-normative and not a statement of current behaviour. |
| `docs/superpowers/` | **Removed** | The historical SDD plan/spec tree was moved into `docs/_archive/2026-08-widget-builder-sdd/superpowers/`. The pre-existing `docs/` directory has been replaced with `docs/_archive/` to make the archive boundary explicit. |
| `.superpowers/` | **Removed** | Moved into the archive above. The `.superpowers` directory at the project root no longer exists; the gitignore now points to `docs/_archive/`. |
| `docs/SCREENSHOT-PRIVACY-DESIGN.md` | **Design record** | The screenshot privacy investigation and the design adopted from it. The normative part — inline delivery, storage outside the document root, ownership-based retrieval authorisation, per-capture browser profiles — is restated in §2 and implemented. Anything else in it is a record. |
| `docs/SCREENSHOT-STORAGE.md` | **Design record** | On-disk layout and retention for the screenshot store. Companion to the above. |
| `docs/DRAFT-CAPTURE-DESIGN.md` | **Design record** | The draft-capture investigation behind the post-preview tools. |
| `docs/SCREENSHOT-REQUEST-POLICY.md` | **Design record** | The investigation behind the browser request policy: why a final-URL check cannot close the cross-host capture hole, what an origin-level policy is, the Fetch interception and the resolver rules, the fourteen adversarial reproductions, and the mutation harness. The normative part is restated in §2 and implemented. |
| `docs/RELEASE-SIZE-AUDIT.md` | **Design record** | The measured sizes of everything that could be deployed, what is required at runtime and what is development material, and the finding that the "60 MB theme" was a copy of the development repository — Git history and four other checkouts included. It also records that an export taken while the theme is a symlink would carry the Git history, and therefore a revoked credential, into a public installation. **A record of measurements, not a size target.** |
| `docs/WP-MIGRATE-CUTOVER.md` | **Plan** | The cutover procedure for moving the local showcase to `flowbyte.com` with WP Migrate: what the free edition of the installed plugin can and cannot do, the symlink precondition, the fifteen-item checklist, the backup and recovery procedure for the existing public site, and the post-migration verification. **A plan, not a permission** — the owner performs the migration, and nothing in it has been applied to `flowbyte.com`. |
| `docs/CUSTOM-CSS-STYLESHEET-DESIGN.md` | **Design record** | The evaluation of serving the custom stylesheet as a versioned, cacheable stylesheet instead of inlining it into every page: the argument, the shape of the change, the risks, and what to test. **Evaluated, not implemented** — the normative behaviour is §6.6. |
| `docs/TRANSACTION-PREVIEW.md` | **Design record** | Why transaction preview is rendered from a staged draft rather than from live config, and the credential model that follows from it. |
| `docs/NAVIGATION-BLOCKS-DESIGN.md` | **Design record** | Why navigation is a field set on the existing `wp_core`/`nav_menu` block rather than a new block type, the resolution order and the no-substitution guarantee, how unique DOM ids come from WordPress rather than from us, the disclosure-button design and its no-JS path, the footer-centre mobile fix, and what the later phases still owe (import-side dependency resolution). The normative part is §3. |
| `docs/NINE-AREA-ZONES-DESIGN.md` | **Design record** | The nine-area header/footer schema, the three-slot compatibility layer, why the expanded layout mode is derived from content rather than stored, the explicit-placement and empty-row decisions, the configurator's 3×3 picker and its deliberate lack of a client-side preview, and the dependencies this creates for the menu/form/CAPTCHA/packaging work. The normative part is §3 and §5. |
| `docs/CONFIGURATOR-REDESIGN-DESIGN.md` | **Design record** | The human editor's investigation: the measurement of the panel it replaced, what was kept and what could not be patched, the four-module architecture, the information architecture, the ownership and preview rules, the theme routes that were added, the evidence, and what was deliberately left undone. The normative part is §3.1. |
| `docs/CONTENT-TRANSACTIONS-DESIGN.md` | **Design record** | The transactional-content investigation, the approved decisions (persisted drafts + durable pre-image journal + short-lived SQL transactions, deliberately *not* one open DB transaction), the failure inventory, and the staged implementation plan. Stage 0 — the lease — is implemented; §6.2 is the normative form of it. The Stage 1a journal and its recovery engine are implemented for one operation type; §6.4 is the normative form of them, and its §4.1 sketch is superseded by the schema actually built. Stages 1b+ are proposals, not behaviour. |
| `docs/PRODUCT-ISSUES.md` | **Design record** | The issue backlog these designs answer. A backlog entry is a request, not a contract. |
| `docs/FLOWBYTE-CUTOVER-PLAN.md` | **Design record — superseded** | The migration, cutover and rollback plan for moving `flowbyte.com` onto AgentShell, with the rehearsal that was run against a disposable site and the measured timings. **Superseded** by `docs/WP-MIGRATE-CUTOVER.md`: the owner is migrating with WP Migrate, not with a symlink switch and a DNS change, and a database import replaces the destination's content where the symlink cutover preserved it. Kept as a historical record of the mechanism, the rehearsal and the reasoning. **A plan, not a permission** — nothing in it has been applied to `flowbyte.com`, and no step of it may be run without a separate approval. |

**Hard rule about new docs.** Any new markdown file must do one of: (a) pointer to this file, (b) archive notice, (c) single-task skill, (d) non-normative human setup guide, (e) **design record**.

(e) is a record of an architectural investigation, a proposal, the decision taken, and the evidence for it. It exists so that reasoning is not lost and cannot be quietly re-litigated. Three obligations come with it:

1. It **cannot override this file.** Where it disagrees, this file is right and the record is out of date — and the record must be corrected or marked superseded rather than left to mislead.
2. It **cannot make unimplemented behaviour normative.** A design record describes what was decided, not what exists. Anything in it that is merely planned must be marked as planned, and must point at the section of this file that governs the behaviour as it actually is.
3. It **inherits the hard rules** below by reference and adds none of its own.

A setup guide (d) documents installation and connection procedure only — it defines no contract, and cites this file by section for anything normative. No new file may redefine MCP tools, the operation loop, the bilateral widget registry, the data-* hydration law, the no-fetch boundary, the bilateral source of truth, or the tool count. New skill files inherit the widget and tool contracts by reference; they may not amend them.

---

## 9. Validation checklist for documentation PRs

Before merging any markdown change in this repository, the change must pass these tests. They are the same gates the system uses to validate agent output.

1. **No duplicate MCP tool table.** Any file that lists MCP tools besides this one must either be deleted or contain only a link to Section 2.
2. **No duplicate architecture diagram.** Any file that draws the agent/daemon/plugin flow besides this one must either be deleted or contain only a link to Section 1.
3. **No duplicate agent loop.** Any file that describes the observe / mutate / commit loop besides this one must either be deleted or contain only a link to Section 6.
4. **No "wp_options is the sole source of truth" claim** anywhere in the repo. The bilateral registry in Section 4 is the only correct statement.
5. **No "10 tools" or "11 tools" or "12 tools" count** anywhere. The canonical count is the one in Section 2, which `tests/tool-count-test.php` keeps equal to the registry's.
6. **No `<script type="application/json">` example or "data hydration" mention that doesn't use `data-*`.** Section 4.2 is the only pattern.
7. **No `fetch()` example in any widget skill that isn't an explicit "FORBIDDEN" callout.** Section 4.3 is the only law.
8. **No violation of the three grid rules** (1fr, descendant scope, quoted rows). Section 5 is the only law.
9. **No design record presented as normative.** A file in category (e) must not state a contract, must not claim that unimplemented behaviour exists, and must not contradict a section of this file without being updated in the same change. Where a record and this file disagree, the gate fails on the record.

If a doc change fails any gate, the PR is rejected. If a doc change requires amending the contract, the change must be applied here in `AGENTS.md` *first* and any dependent skill or note updated by reference, not by duplication.

---

## 10. Failure behavior (if a contradiction cannot be resolved)

If two statements inside this repository conflict in a way this file's precedence rules do not resolve, the system **halts and surfaces the contradiction** rather than picking a winner. The error must include the file path, the line numbers, and the exact wording of both statements. The agent must not commit, register, or apply anything until the human author reconciles the conflict in this file.

This is the same posture the transaction system uses: refuse to commit a staged change that fails validation, surface the error, let the human resolve it.
