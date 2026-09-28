---
name: agentshell-site-from-brief
description: Use when the user asks to build a website from a project brief, README, or set of documents — phrases like "build a site from this README", "turn these project docs into a website", "create a site for this project", "make me a landing page for this product". Teaches the agent to derive information architecture, design direction, page hierarchy, navigation, and content from the supplied brief and to realise the site through the existing agentshell_* MCP tools inside a transaction.
---

# agentshell-site-from-brief (operative skill)

> **The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the 45+ tool count, the working pattern, the transaction semantics — is in [`AGENTS.md`](../../../AGENTS.md). This skill does not redefine any of those. It only specifies the task contract: build a site from a brief.**

## Inheritance by reference

Before doing anything, read and obey `AGENTS.md` in full. In particular:

- **Tool surface** (`AGENTS.md` §2). The skill uses only existing tools. It does not propose new ones.
- **Working pattern** (`AGENTS.md` §6). All mutations run inside `agentshell_begin_transaction` … `agentshell_commit_transaction` (or `rollback_transaction`).
- **Zone composition / slots** (`AGENTS.md` §3). Three zones (header / main / footer). Header and footer are tri-slot; main is vertical composition. Edit with the matching tool.
- **Bilateral widget registry** (`AGENTS.md` §4.1). File widgets + config widgets, merged. File widgets are non-removable but disable-able.
- **Data-* hydration** (`AGENTS.md` §4.2) / **no fetch** (`AGENTS.md` §4.3). Only relevant if the build registers widgets; the same laws apply.
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

## Workflow

Always run inside a single transaction (steps 4–17). On any unrecoverable validation error, `rollback_transaction`.

1. **Observe** — `agentshell_inspect`. Note current zones, design tokens, registered widgets, registered pages, open transaction state.
2. **Validate** — `agentshell_validate`. Surface any pre-existing Doctor issues so they are visible before any change.
3. **Brief pass** — read the brief end-to-end. Extract: subject matter, audience, primary value proposition, supporting features, install / quickstart content, references, project name, tagline.
4. **Information architecture** — decide the page set, hierarchy, and what each page owns. Do not mechanically turn every heading into a page.
5. **Design direction** — pick a visual mood that fits the subject (a security tool reads differently from a children's story app). Use existing AgentShell tokens (`palette`, `typography`, `shape`, `spacing`).
6. **Page hierarchy** — list the pages in the order they will appear in the navigation. Distinguish top-level from secondary.
7. **Navigation** — prepare a JSON list of `{ label, url }` items per menu. The companion `agentshell_register_navigation` tool (when available) creates the WordPress menu; otherwise note this step as out of scope.
8. **Page / post / widget split** — for each piece of content, decide whether it is a page, a post, or a widget. Default: page. Use a post for time-anchored content (changelog, blog, release notes). Use a widget for derived or interactive surfaces (latest items, search, hero recap).
9. **Plan output** — write a brief build plan as part of the agent's response to the user (page list, nav, design tokens, widget list). One screen of text, not twenty.
10. **Snapshot** — `agentshell_create_snapshot('brief-<slug>-baseline')` so the work is recoverable.
11. **Begin transaction** — `agentshell_begin_transaction(label='brief-<slug>')`. If another actor already owns one, surface the conflict and stop; do not force.
12. **Configure design tokens** — `agentshell_set_palette` / `set_typography` / `set_shape` / `set_spacing`. Use `agentshell_set_css_var` for anything outside the allowed token space.
13. **Compose zones** — `agentshell_update_zone_slots` (header / footer) and `agentshell_update_zone_composition` (main). Prefer the existing `wp_core` components (`site_logo`, `site_title`, `site_tagline`, `nav_menu`, `search_form`) over custom widgets.
14. **Create content** — `agentshell_create_page` per page in the IA; `agentshell_create_post` per post. Raw HTML is preserved. Status `draft` is the safe default; flip to `publish` only at the end.
15. **Register widgets** — `agentshell_register_widget` for any custom interactive surface. Default: zero. Most sites need no widgets beyond `wp_core`.
16. **Construct navigation** — call `agentshell_register_navigation` (when available) or document the menu for human follow-up.
17. **Validate** — `agentshell_validate_transaction`. If errors: investigate. If only warnings: continue. Commit when green.
18. **Visual verify** — when `agentshell_screenshot` is available, capture desktop + mobile. When not, surface that the visual loop was skipped.
19. **Commit or rollback** — `agentshell_commit_transaction` on success; `agentshell_rollback_transaction` on any failure you cannot resolve.
20. **Final inspect + audit** — `agentshell_inspect` to confirm the live state; report the chosen profile, page list, and anything the user should review manually.

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

- `agentshell_validate_transaction` returned no errors.
- Every page in the IA exists and is reachable from the navigation.
- `agentshell_search_content` returns each page by title.
- Visual check (when screenshot is available) shows a coherent header / main / footer across desktop and mobile viewports.
- All links resolve (no orphan internal links).
- Snapshot exists so the user can recover.
- Audit log (callable via `agentshell_get_audit_log`) shows the transaction was opened, the mutations were recorded, and the commit fired cleanly.

## Failure modes (in addition to `AGENTS.md` §6)

| Failure | Resolution |
|---|---|
| Brief is empty, image-only, or unreadable | `PAUSE` — ask the user for a usable brief. Do not invent content. |
| Another actor owns an open transaction | Stop. Surface the actor from the error. Do not force. |
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
- It does not write `<script type="application/json">` or call `fetch()`. (`AGENTS.md` §4.2 / §4.3.)
- It does not claim `wp_options` is the sole source of truth. (`AGENTS.md` §1 and §4.1.)

For everything else — including the tool list, the architecture, the bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the working pattern, the transaction semantics, and the capability boundary — see `AGENTS.md`.