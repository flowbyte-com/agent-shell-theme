---
name: agentshell-site-from-brief
description: Use when the user asks to build a website from a project brief, README, or set of documents — phrases like "build a site from this README", "turn these project docs into a website", "create a site for this project", "make me a landing page for this product". Teaches the agent to derive information architecture, design direction, page hierarchy, navigation, and content from the supplied brief and to realise the site through the existing agentshell_* MCP tools, using a transaction for the configuration and the content and navigation lifecycles for the rest.
---

# agentshell-site-from-brief (operative skill)

> **The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the tool count, the working pattern, the transaction semantics — is in [`AGENTS.md`](../../../AGENTS.md). This skill does not redefine any of those. It only specifies the task contract: build a site from a brief.**

> **This build is not atomic.** The configuration half of a site build is
> transactional and reversible. The content half and the navigation half are
> immediate writes with narrower reversals of their own. The workflow below
> reflects that rather than papering over it.

## Inheritance by reference

Before doing anything, read and obey `AGENTS.md` in full. In particular:

- **Tool surface** (`AGENTS.md` §2). The skill uses only existing tools. It does not propose new ones.
- **Working pattern** (`AGENTS.md` §6). The **configuration** half of this build runs inside `agentshell_begin_transaction` … `agentshell_commit_transaction` (or `rollback_transaction`). The **content** and **navigation** halves run with **no transaction open**, because those tools refuse inside one. See "What runs where" below.
- **Zone composition / slots** (`AGENTS.md` §3). Three zones (header / main / footer). Header and footer carry a nine-area slot grid — three rows of three, `top-left` … `bottom-right`; the legacy `left` / `center` / `right` keys are accepted as aliases for the middle row. Main is a vertical composition. Edit with the matching tool.
- **Bilateral widget registry** (`AGENTS.md` §4.1). File widgets + config widgets, merged. File widgets are non-removable but disable-able.
- **Data-* hydration** (`AGENTS.md` §4.2) / **no fetch** (`AGENTS.md` §4.4). Only relevant if the build registers widgets; the same laws apply.
- **Unbreakable Grid protocol** (`AGENTS.md` §5). Never edit the grid. Custom CSS is `class`-based and may not set `position: fixed/absolute` on a zone container.
- **Capability boundary** (`agentshell_rest_auth_callback`). Mutating calls require `edit_theme_options`; raw-content bypass requires `unfiltered_html`; MCP transport requires `manage_options`. The skill operates as that user; failure to satisfy the check is reported, not worked around.

## Inputs

### Primary: a brief, README, or set of project documents

The brief arrives in one of three forms:

- **Inline text** — the user pastes it. Read it directly.
- **Local file path** — `~/projects/foo/README.md`, `/tmp/brief.md`. Open it with the file-reading tool of the host runtime.
- **URL** — fetch with `curl -fsSL` into `/tmp/agentshell-brief-<md5>.<ext>` and open the local copy. Match the extension from the URL or `Content-Type`.

Always read every input document end-to-end before inferring structure. Briefs that omit obvious things still imply them; the agent's job is to fill the gaps sensibly.

### Optional user hints

The user may add freeform guidance ("make it feel like Stripe's docs", "I want a dark hero", "minimalist"). Treat hints as **narrow overrides** applied to specific zones, sections, or tokens — never as a substitute for reading the brief.

### Clarifying questions

Ask **at most one** question before starting, and only when a missing fact is genuinely blocking. The shape of the missing fact should be small and answerable:

- "Should the project be presented as a product landing page or as developer documentation?"
- "Is the home page a single hero or a multi-section overview?"

Do not ask about colour preferences, font choices, or section ordering. These are inferable from the brief and the subject matter; asking would be theatre.

## What runs where

The tools do not share one lifecycle, and this is the single most important
thing to know before starting. Calling a tool in a lifecycle state it does not
support is a refusal, not a silent no-op — the refusal names the tool that
*would* work in that state.

| Kind of change | Tools | Inside an open transaction? |
|---|---|---|
| Design tokens, CSS variables, custom CSS, layout | `agentshell_set_palette`, `set_typography`, `set_shape`, `set_spacing`, `set_css_var`, `set_custom_css`, `set_design`, `set_layout` | **Yes.** Staged. Committed or discarded with the transaction. |
| Zone composition and slots | `agentshell_update_zone_composition`, `agentshell_update_zone_slots` | **Yes.** Staged. |
| Widget registration and lifecycle | `agentshell_register_widget`, `enable_widget`, `disable_widget`, `remove_widget` | **Yes.** Staged. |
| Site identity and reading settings | `agentshell_set_site_settings` | **Yes.** Staged. |
| Journal-aware draft content | `agentshell_create_draft`, `agentshell_update_draft`, `agentshell_claim_existing_draft` | **Yes.** Recorded in the content journal, so a rollback can compensate them and a commit retains them. Each needs a client-supplied `idempotency_key`. |
| Page and post creation, content edits, publish and unpublish | `agentshell_create_page`, `create_post`, `update_content`, `update_post_content`, `publish`, `unpublish` | **No.** They refuse inside a transaction. Call them with **no transaction open**; they write immediately. |
| Trash and restore | `agentshell_trash_content`, `agentshell_restore_content` | **No.** They refuse while a transaction is open. |
| Navigation | `agentshell_register_navigation` | **No.** It refuses inside a transaction. Outside one it writes immediately, reports `staged: false`, and `agentshell_rollback_transaction` **will not undo it**. |

Two consequences worth stating plainly:

- **`agentshell_rollback_transaction` reverses the configuration.** It does not
  reverse pages, posts, menus, or anything else that was written outside the
  transaction.
- **`agentshell_create_snapshot` captures the configuration.** It is a restore
  point for design tokens, zones, widget registrations and site settings — not
  a backup of posts, pages, menus or media.

## Workflow

The build runs in **three phases**, because the tools do not all share one
lifecycle, and is then verified against the live site. Keep the phases in
order: the configuration names the pages and the menu, the content creates
them, and the navigation points at them.

### Phase A — the configuration, in one transaction

1. **Observe** — `agentshell_inspect`. Note current zones, design tokens, registered widgets, registered pages, open transaction state.
2. **Validate** — `agentshell_validate`. Surface any pre-existing Doctor issues so they are visible before any change.
3. **Brief pass** — read the brief end-to-end. Extract: subject matter, audience, primary value proposition, supporting features, install / quickstart content, references, project name, tagline.
4. **Information architecture** — decide the page set, hierarchy, and what each page owns. Do not mechanically turn every heading into a page.
5. **Design direction** — pick a visual mood that fits the subject (a security tool reads differently from a children's story app). Use existing AgentShell tokens (`palette`, `typography`, `shape`, `spacing`).
6. **Page hierarchy and navigation plan** — list the pages in the order they will appear in the navigation, and prepare the `{ label, url }` items for each menu. Distinguish top-level from secondary. The menu itself is built in Phase C, once the pages exist.
7. **Page / post / widget split** — for each piece of content, decide whether it is a page, a post, or a widget. Default: page. Use a post for time-anchored content (changelog, blog, release notes). Use a widget for derived or interactive surfaces (latest items, search, hero recap).
8. **Plan output** — write a brief build plan as part of the agent's response to the user (page list, nav, design tokens, widget list). One screen of text, not twenty.
9. **Snapshot the configuration** — `agentshell_create_snapshot('brief-<slug>-baseline')`. **This captures the configuration only.** It is a restore point for the configuration half of this build, not a backup of the site: it cannot restore a deleted page, an unpublished menu or a missing image.
10. **Begin transaction** — `agentshell_begin_transaction(label='brief-<slug>')`. If another actor already owns one, surface the conflict and stop; do not force.
11. **Configure design tokens** — `agentshell_set_palette` / `set_typography` / `set_shape` / `set_spacing`. Use `agentshell_set_css_var` for anything outside the allowed token space.
12. **Compose zones** — `agentshell_update_zone_slots` (header / footer) and `agentshell_update_zone_composition` (main). Prefer the existing `wp_core` components (`site_logo`, `site_title`, `site_tagline`, `nav_menu`, `search_form`) over custom widgets.
13. **Register widgets** — `agentshell_register_widget` for any custom interactive surface. Default: zero. Most sites need no widgets beyond `wp_core`.
14. **Validate** — `agentshell_validate_transaction`. If errors: investigate. If only warnings: continue. **A `nav_menu` block whose menu does not exist yet is a warning, not an error** — the configuration is valid and the site is missing a dependency that Phase C supplies. Commit is blocked by errors, never by warnings.
15. **Commit or roll back** — `agentshell_commit_transaction` on success. On a failure you cannot resolve, `agentshell_rollback_transaction` discards the staged configuration; at this point **nothing else has changed on the site**, so the site is exactly as it was.

### Phase B — the content, with no transaction open

The content tools refuse to run inside a transaction, so this phase runs with
none open. Their writes take effect immediately.

16. **Create pages and posts** — `agentshell_create_page` per page in the IA; `agentshell_create_post` per post. Raw HTML is preserved. Status `draft` is the safe default; publish at the end of this phase, not per page, so a failure leaves the published set consistent rather than half-published.

    *Recoverable variant.* If the content should be undoable with the configuration, create the pages with `agentshell_create_draft` **inside** the Phase A transaction instead, one `idempotency_key` per page. The drafts are journal-aware: a rollback compensates them (removing them), and the commit retains them for publishing here. The cost is that every page needs a key and a follow-up publish.

17. **Publish** — `agentshell_publish` per page and post.
18. **Verify the content** — `agentshell_search_content` returns each page by title.

### Phase C — navigation, with no transaction open

19. **Construct navigation** — `agentshell_register_navigation` with the `{ label, url }` list prepared in step 6. The pages must already exist (Phase B) for their items to resolve. The tool reconciles the requested items against the menu in place, so item ids are stable across repeated runs, and it compensates its own partial writes if it fails. **There is no AgentShell tool that deletes a menu** — removing one is a WordPress-admin action.

### Phase D — verify the live site

20. **Visual verify** — when `agentshell_screenshot` is available, capture desktop + mobile. When not, surface that the visual loop was skipped.
21. **Final inspect + audit** — `agentshell_inspect` to confirm the live state; `agentshell_get_audit_log` to confirm what was recorded; report the chosen profile, page list, and anything the user should review manually.

## Partial-failure recovery

The phases do not share an undo, so what can be reversed depends on how far
the build got. The agent must say **which half** it is reporting on, rather
than describing the build as a single reversible operation.

| What failed | What is already true | What to do |
|---|---|---|
| Phase A — validation, or any step before the commit | Nothing has changed on the site. The staged configuration exists only in the transaction row. | `agentshell_rollback_transaction`. The site is exactly as it was. |
| Phase A — the commit itself fails | The staged configuration was never written. | Read the refusal and fix the reported problem, then **begin a new transaction** and re-stage. A rolled-back transaction's row is gone, and the staged buffer does not survive it. |
| Phase B — a page or post fails part-way | The configuration is already committed. Some pages exist and others do not; some are published and others are drafts. | The pages already created are **real, and nothing later in this build will undo them.** Continue with the remaining pages. If one must go, `agentshell_trash_content` moves it to the Trash — there is no permanent delete. Report exactly which pages exist and which do not. |
| Phase C — navigation fails | The configuration and the content are already live. | `agentshell_register_navigation` compensates its own partial writes when it fails, and it reconciles the requested items in place, so re-running it with the corrected list converges rather than duplicating. If the menu itself must be removed, that is a WordPress-admin action: **AgentShell has no menu-deletion tool.** |
| Phase D — the visual check fails | Everything is live. | Fix and re-run the affected phase. A visual failure is a finding about the committed result, not a reason to roll back the configuration. |

**Two rules that follow from this, and are not optional:**

1. **A configuration snapshot is not a site backup.** `agentshell_create_snapshot`
   captures the configuration. It cannot restore a deleted page, an unpublished
   menu, or a missing image. Never describe it as a backup.
2. **Never tell the user the build is atomic.** It is not. The configuration
   half is transactional; the content and navigation halves are immediate
   writes with their own, narrower reversals. Say which half succeeded and
   which did not.

## Content reasoning

Map the brief into a small, navigable set of pages. The exact set depends on the brief, but the typical patterns:

| Source content | Destination |
|---|---|
| Project / product one-line description, tagline | Site title + tagline (`wp_core`) |
| "What is this?" / elevator pitch | Homepage hero + intro paragraphs |
| Long-form feature list, capability matrix | Dedicated Features page |
| Install / quickstart / "how to use" | Dedicated Getting Started page (or first child page) |
| Detailed reference docs (CLI flags, API methods, etc.) | Dedicated Documentation page; consider children if long |
| Configuration / environment variables | Children of Documentation, or single long page |
| About the team / contact / contributing | About page |
| Releases, changelog, news | Blog (`create_post`, dated) |
| External links (repo, sponsor, etc.) | Navigation items, not pages |

Do not turn every README heading into a webpage. Headings like "Implementation notes" or "Tests" are usually better as a single Documentation page than five pages.

Do not invent content that the brief does not support. If the brief does not give a tagline, write a one-sentence tagline that is the literal product description and move on; do not pad with marketing prose.

## Design reasoning

Pick a visual mood that fits the subject:

- **Security / devtools / CLI / API products**: restrained palette, mono font stack, dense typography, thin radii, low contrast borders. Easy to read at small sizes.
- **Consumer products / apps**: warmer palette, larger radii, generous spacing, friendlier type stack.
- **Documentation sites / blogs**: classical palette, generous line-height, narrow content column. Generous side gutters.
- **Marketing landing pages**: bold hero, accent colour, generous whitespace, clear CTA.

Use existing AgentShell tokens. Do not invent CSS variables outside the design system unless `agentshell_set_css_var` is the cleanest way to express the intent.

Avoid generic AI-site tropes (gradient mesh backgrounds, glowing orbs, "transform your workflow" hero copy) unless the brief specifically calls for them.

## Verification

The skill is complete when **all** of the following hold:

- `agentshell_validate_transaction` returned no errors, and the configuration was committed.
- Every page in the IA exists and is reachable from the navigation.
- `agentshell_search_content` returns each page by title.
- Visual check (when screenshot is available) shows a coherent header / main / footer across desktop and mobile viewports.
- All links resolve (no orphan internal links).
- A configuration snapshot exists, so the configuration half can be restored.
- Audit log (callable via `agentshell_get_audit_log`) shows the transaction was opened, the mutations were recorded, and the commit fired cleanly.

**Completeness is not the same as atomicity.** Each of these can hold while the build as a whole was interrupted half-way — which is why the recovery section above is part of the contract and not an afterthought.

## Failure modes (in addition to `AGENTS.md` §6)

| Failure | Resolution |
|---|---|
| Brief is empty, image-only, or unreadable | `PAUSE` — ask the user for a usable brief. Do not invent content. |
| Another actor owns an open transaction | Stop. Surface the actor from the error. Do not force. |
| A content or navigation tool refuses because a transaction is open | Close the transaction first — `agentshell_commit_transaction` or `agentshell_rollback_transaction` — then call the tool. The configuration half is staged; the content half is not. |
| A page was created but the build then failed | The page is real and nothing later will undo it. Use `agentshell_trash_content` if it must go, and report the page as existing. |
| `agentshell_register_navigation` is not available | Leave navigation as a documented follow-up for the human. Note the navigation list in the final report. |
| `agentshell_screenshot` unavailable | Note the visual loop was skipped. Inspect text content + transaction validation instead. |
| Brief requests a page that requires an unaudited third-party API | Refuse to embed client-side `fetch()`. Build a server-side page snapshot instead, with the `/* agentshell-snapshot-source: ... */` provenance anchor. |
| Off-schema design tokens (e.g. an exotic typeface the doctor rejects) | Use `agentshell_set_css_var` with a `--theme-*` key. Document the deviation. |

## What this skill explicitly does NOT do

- It does not propose new MCP tools. (`AGENTS.md` §2 is the surface.)
- It does not edit the grid or the shell. (`AGENTS.md` §5 is the law.)
- It does not bypass the capability boundary. (`agentshell_rest_auth_callback` enforces it.)
- It does not mechanically map README headings to pages.
- It does not require the user to answer 20 design questions. The skill favours reasonable autonomous decisions.
- It does not write `<script type="application/json">` or call `fetch()`. (`AGENTS.md` §4.2 / §4.4.)
- It does not claim `wp_options` is the sole source of truth. (`AGENTS.md` §1 and §4.1.)
- **It does not claim the build is atomic.** The configuration half is transactional; the content and navigation halves are immediate writes with narrower reversals. A configuration snapshot is not a site backup.

For everything else — including the tool list, the architecture, the bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the working pattern, the transaction semantics, and the capability boundary — see `AGENTS.md`.