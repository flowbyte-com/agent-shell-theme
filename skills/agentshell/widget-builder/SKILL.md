---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget — phrases like "build me a calculator", "add a latest posts carousel", "create a sales dashboard". Teaches the agent to choose between two tracks (Interactive or WordPress Decorator), how to compose zone blocks, how to verify the result, and the security boundary that prohibits client-side fetch() and <script type="application/json"> hydration.
---

# agentshell-widget-builder (operative skill)

> **The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the 45+ tool count, the working pattern — is in [`AGENTS.md`](../../../AGENTS.md). This skill does not redefine any of those. It only specifies the task contract: build a custom widget using the existing tool surface.**

## Inheritance by reference

Before doing anything, read and obey `AGENTS.md` in full. In particular:

- **Bilateral widget registry** (`AGENTS.md` §4.1) — file-based + config-registered, merged, with config overriding file by id.
- **Data-* hydration only** (`AGENTS.md` §4.2) — scalar fields as `data-*` attributes, structured payloads as a single `data-agentshell-data` JSON attribute. `<script type="application/json">` is forbidden.
- **No client-side `fetch()`** (`AGENTS.md` §4.3) — absolute. Covers WP REST, external APIs, and any URL.
- **Two tracks** (`AGENTS.md` §4.4) — Interactive and WordPress Decorator. Snapshot is a pattern inside Interactive, not a third track.
- **Unbreakable Grid protocol** (`AGENTS.md` §5) — never edit the grid; widget templates and `init_js` never set `position: fixed` on a zone container.
- **Working pattern / transactions** (`AGENTS.md` §6) — every mutation runs inside an open transaction.
- **Tool surface** (`AGENTS.md` §2) — only existing `agentshell_*` tools. This skill does not propose new tools.

## Two tracks (operative model)

There are exactly **two tracks**. Snapshot-style widgets are a pattern inside Track 1, not a third track.

### Track 1 — Interactive

Self-contained applications: calculators, simulators, visualizers, configurators. No WordPress data dependency. Uses local state, `window.math`, `window.d3`. `init_js` reads from `data-*` attributes on its own element only.

A "snapshot widget" (frozen Q2 figures, a curated post list, a one-off dashboard) is an Interactive widget whose initial state was seeded by the agent during construction. The execution model is identical.

### Track 2 — WordPress Decorator

Progressively enhances server-rendered content (`wp_loop`, `wp_core`, `wp_widget_area`). The widget colocates with the source block in the same zone. The widget locates its source by walking up to its zone scope (`el.closest('.zone-main, [data-zone]') || el.parentElement`) and selecting the nearest preceding `wp_loop` within that zone. Blind document scanning (`querySelectorAll('article')`) is forbidden.

## Track selection

```
Does the widget require WordPress / site data?

├── No
│   └── Interactive
│       └── local state + math / d3 + data-* attributes on its own element
│
└── Yes
    │
    ├── Must it reflect current site content (changes between page loads)?
    │   └── Decorator
    │       └── server-rendered DOM + colocated widget + data-* attributes
    │
    └── Is point-in-time data acceptable?
        └── Interactive (snapshot-seeded)
            └── agent reads data → embeds in data-* attributes
            └── leaves a refresh-anchor comment for future agents
```

Decisive question: **does the widget need to remain correct when the underlying data changes without the agent rebuilding it?** If yes → Decorator. If no → snapshot-seeded Interactive. If no data at all → plain Interactive.

When ambiguous, choose the least powerful track that satisfies the requirement. The agent must report the chosen track with a one-line justification:

```
Track: Decorator
Reason: Widget displays WordPress posts that may change between page loads.
```

User keywords — "snapshot" / "frozen" / "static" / "embedded" route to snapshot-seeded Interactive; "interactive" / "calculator" / "self-contained" route to plain Interactive; "live" / "decorator" / "current posts" route to Decorator. The skill never uses `fetch()` regardless of what the user says.

## Track 2 — the colocation contract

The primary Track 2 pattern is **colocation**: the decorator widget lives in the same zone as the `wp_loop` it enhances. The agent places both blocks in the same zone composition, in the order `[wp_loop, decorator-widget]`.

```js
init: function(el) {
    const zone = el.closest('.zone-main, [data-zone]') || el.parentElement;
    const loop = zone.querySelector('.wp-block-post, article.post, .entry');
    if (!loop) return; // source not found — degrade gracefully
    // extract from standard WP markup...
}
```

The widget is bound to its source **by composition**, not by a custom `data-*` attribute on the loop. The colocation rule is what prevents cross-loop mis-wiring on multi-loop pages.

Standard `wp_loop` markup the decorator reads:

| Need | Standard markup |
|---|---|
| Post title | `<h2 class="entry-title"><a>…</a></h2>` |
| URL | `<a class="entry-title-link" href="…">` |
| Date | `<time class="entry-date published" datetime="ISO8601">…</time>` |
| Excerpt | `<div class="entry-summary">…</div>` |
| Post ID | `<article id="post-123" class="post-123 …">` |
| Featured image | `<img class="attachment-post-thumbnail">` |
| Author | `<a class="entry-author">` or `<span class="byline">` |
| Categories | `<a class="entry-category" rel="category">` |

## Data-* hydration (the only allowed payload)

Widget templates use `data-*` attributes for all data. Two sub-patterns:

### Sub-pattern A — scalar fields (one attribute per field)

```html
<article
  data-post-id="123"
  data-post-title="Hello World"
  data-post-url="/hello-world/"
  data-post-date="2026-08-14"
>
  …native WP content…
</article>
```

Survives `wp_kses_post` unchanged. Excellent for simple widgets.

### Sub-pattern B — structured payloads (one JSON attribute)

For collections or larger structures, encode JSON into a single attribute, conventionally `data-agentshell-data`:

```html
<div
  class="latest-posts"
  data-agentshell-data='{"posts":[{"id":1,"title":"…"}]}'
></div>
```

Consume via `el.dataset.agentshellData` in `init_js`. Handle malformed or missing payloads by returning early from `init(el)`.

`<script type="application/json">` hydration is **prohibited**. `wp_kses_post` strips it; bypassing sanitization is not a permitted escape.

## The escape hatch (mu-plugin filter) — last resort only

When standard WordPress markup genuinely does not expose a field the widget needs (rare — most fields are in the standard markup), the skill permits a **mu-plugin filter** with a strict validation gate. This is an explicit, logged, rare action — not the default.

**Mandatory workflow:**

1. Write the filter PHP to `/tmp/agentshell-widget-filter-<timestamp>.php`. Never write directly to `wp-content/mu-plugins/`.
2. Run `php -l /tmp/agentshell-widget-filter-<timestamp>.php` via the Bash tool. Confirm clean lint output. If lint fails, fix and re-run until clean.
3. Inside the open transaction, after lint passes, move the file with `mv` (atomic on the same filesystem) to `wp-content/mu-plugins/`.
4. Verify the live site still responds: `curl -fsS -o /dev/null -w "%{http_code}" https://example.com/wp-json/agentshell-mcp/v1/...`. If non-2xx, the mu-plugin broke WordPress boot. Roll back by deleting the file (`rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`) **before** any other recovery. `agentshell_rollback_transaction` only handles `wp_options` state — it cannot un-fatal a PHP parse error.
5. Tell the user: "Wrote a mu-plugin filter to expose X. File: …. If you want to revert, run: `rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`."

**Hard rule for the escape hatch:** the mu-plugin MUST be removable by a single `rm`. No `register_activation_hook`, no DB writes, no cron registration. Anything that creates persistent state outside the file itself violates the rollback principle.

There is no MCP tool for filter registration, and none will be added. The mu-plugin path is the minimum-necessary PHP-modification surface that preserves the rollback principle.

## The refresh anchor (snapshot-seeded Track 1)

Snapshot-seeded Interactive widgets embed their data as a static JavaScript object in `init_js`. To prevent future agents from being unable to update the widget when the data changes, prefix `init_js` with a single comment:

```js
/* agentshell-snapshot-source: <description-of-where-the-data-came-from> */
window.AgentshellWidgets['q2-sales-dashboard'] = {
    init: function(el) {
        const data = { … };  // seeded snapshot
        // …
    }
};
```

Anchor examples:

- `/* agentshell-snapshot-source: WP_Query post_type=product date=Q2 */`
- `/* agentshell-snapshot-source: agentshell_search_content type=post limit=5 */`
- `/* agentshell-snapshot-source: agentshell_get_design_system + manual palette, 2026-08-14 */`
- `/* agentshell-snapshot-source: Q2 2026 report, terminal (not refreshed) */`

Be specific. Generic anchors like `/* agentshell-snapshot-source: hardcoded data */` force the next agent to start from zero.

## Composition patterns (when to use what)

| Scenario | Composition |
|---|---|
| Pure calculator / simulator / visualizer | Standalone Interactive widget, no WP composition needed |
| Latest posts carousel | `wp_loop` + colocated Decorator widget in same zone |
| Custom header nav menu | `wp_core` (`nav_menu`) — no widget, the core component already does this |
| Sidebar widget area | `wp_widget_area` zone source + decorators as needed |
| Frozen dashboard (Q2 figures, monthly report) | Interactive widget with embedded data + refresh anchor |
| One-off styled list with fixed shape | `json_block` (where shape is fixed and small) |
| Decorator needing fields WP doesn't expose | `wp_loop` + colocated Decorator + mu-plugin escape hatch |
| Site-wide announcement banner | Interactive widget (no data) deployed to header zone via `agentshell_update_zone_slots` |

Tools for composition (existing MCP tools only — `AGENTS.md` §2):

- `agentshell_update_zone_composition` — for main zones (flat `composition[]` array).
- `agentshell_update_zone_slots` — for header/footer zones (`slots: { left, center, right }`).
- `agentshell_register_widget` — registers the widget itself with `template`, `init_js`, `css`, optional `libs`.

This skill does not propose any new tools. The above three are existing.

## Verification workflow

Inside an open transaction:

1. `agentshell_register_widget` with `template`, `init_js`, `css`. The widget is staged in the transaction, not yet live.
2. Place the widget: `agentshell_update_zone_composition` (main) or `agentshell_update_zone_slots` (header/footer).
3. `agentshell_get_capabilities` to confirm `screenshot: true`. If false, skip step 4 and tell the user.
4. `agentshell_screenshot({ viewport: "desktop" })` and optionally `agentshell_screenshot({ viewport: "mobile" })`. Inspect the screenshots.
5. `agentshell_validate` — always. The doctor catches schema drift, missing fields, broken references; screenshots don't.
6. Progressive-enhancement check (decorator widgets only): with JavaScript disabled, the server-rendered content must remain readable. The agent verifies this in the screenshot or explicitly acknowledges it cannot.
7. Fix issues and iterate. Re-screenshot. Re-validate. Until both pass.
8. `agentshell_commit_transaction`. The widget goes live.

For the mu-plugin escape hatch, add a step 5.5: a `curl` health check on the daemon after the atomic `mv` (`AGENTS.md` §4.6).

## What the agent MUST NOT do (load-bearing rules)

1. **No client-side `fetch()`** — for any reason. No exceptions, no "just this once for WP REST", no clever workarounds. The widget does not possess network capabilities.
2. **No `<script type="application/json">` for hydration** — `wp_kses_post` strips it. Use `data-*` only.
3. **No blind DOM scanning** (`querySelectorAll('article')` etc.) — use the colocation rule.
4. **No inventing WordPress APIs** — if the data isn't reachable through documented patterns (colocation, `wp_loop`, `wp_core`, `data-*` snapshot), stop and explain. Don't invent a `fetch` workaround; don't synthesize a fake REST endpoint; don't assume undocumented WP behaviour.
5. **No reimplementing `wp_loop` or `wp_core` in a widget** — if AgentShell has a primitive that does the job, use it.
6. **No bare-metal mu-plugins** — escape-hatch mu-plugins must satisfy the single-`rm` recovery rule.
7. **No bypassing transactions** — every composition mutation runs inside an open transaction.
8. **No position-fixed/absolute on a zone container** — `agentshell_inject_saved_styles()` neutralises it. The grid protocol is absolute.

## Security boundary (no strictness knob)

This skill has **no strictness modes**. Unlike the image-to-theme skill (which has strict / pragmatic / expressive modes for heuristic mapping flexibility), this skill has one mode: **safe**. The data-* / no-fetch / no-script-hydration / no-blind-scan rules are absolute and non-tunable. If the user asks for a widget that requires network access, refuse and explain why — there is no setting that flips that off.

## Failure modes

| Failure | Resolution |
|---|---|
| User requests client-side `fetch()` | Refuse. Explain the security boundary. Suggest Decorator (colocation) or snapshot-seeded Interactive. Do not negotiate. |
| Data not available server-side | Stop and explain. Offer: (a) embed data yourself via Interactive with refresh anchor, (b) point at a `wp_loop` / `wp_core` block that has the data, (c) accept a snapshot. |
| Widget template contains `<script>` | `wp_kses_post` strips it. Use `data-*` only. If the agent produced a `<script>` block, rewrite to data attributes. |
| Screenshot backend unavailable | Skip visual verification, run `agentshell_validate`, tell the user. |
| Transaction lock conflict | Standard AgentShell handling — do not force, surface the actor from the error. |
| Daemon unreachable | Standard AgentShell handling — retry once with backoff, then abort with a config-check message. |
| Mu-plugin bricks WordPress boot | Daemon returns non-2xx after the atomic `mv`. Recovery: `rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php` immediately. Do not attempt `agentshell_rollback_transaction` first. |
| Decorator finds no source `wp_loop` in its zone | Degrade gracefully — return early from `init(el)` without enhancing. The user sees the plain content, not a broken widget. |

The general failure-mode vocabulary (backend unavailable, schema drift, daemon unreachable, transaction lock, over-budget) is the same as `AGENTS.md` §6 and `skills/agentshell/image-to-theme/SKILL.md`. When in doubt, follow those.

## Known limitations

- **Refresh anchor is convention-only.** No MCP tool or PHP enforcement keeps the anchor in sync with reality. Future agents must trust the anchor and verify the query still returns the expected shape.
- **`<script type="application/json">` would be cleaner JSON-wise** but is blocked by current sanitization. If AgentShell ever loosens `wp_kses_post` for widget contexts, this skill can be updated. Until then, `data-*` is the right primitive.
- **No DOM diff between iterations.** If the decorator widget's source structure changes (WP core changes how posts are rendered, theme switches `.entry-title` → `.post-title`), the widget silently degrades. Re-validate after any plugin or theme update.
- **The mu-plugin escape hatch is unsafe relative to everything else in the skill.** A fatal error bricks the daemon before `agentshell_rollback_transaction` can fire. Recovery is `rm`. Use only when colocation cannot satisfy the data requirement.
- **Colocation depends on `wp_loop` being in the same zone.** If a future AgentShell feature allows `wp_loop` to render into zones the widget doesn't colocate with, the colocation model breaks.
- **No multi-source decorator support.** A decorator widget enhances one `wp_loop` — the nearest preceding one in its zone. For multi-source compositions, recommend two colocated decorators or a snapshot-seeded Interactive widget.
- **Decorator assumes standard `wp_loop` markup.** Custom post types or heavily customised themes may emit different markup. Adapt the CSS selectors in `init_js` to what the page actually emits, or fall back to the escape hatch.

## Worked examples (illustrative, not prescriptive)

The three worked examples (Decorator latest-posts carousel, Interactive with snapshot Q2 dashboard, mu-plugin escape-hatch taxonomy cloud) are preserved in this skill only as **illustrative teaching patterns**. They show how to wire the rules; they are not the only correct answer. The tool names, the data-* patterns, the colocation rule, the no-fetch law, the verification workflow, and the mu-plugin gate are all inherited from `AGENTS.md` and the sections above. A worked example that contradicts this file is wrong.

---

**Final note for the agent runtime:** if any tool name, architecture diagram, or operation loop appears in this skill, it is for clarity only — `AGENTS.md` is the single source of truth. If this skill ever needs to amend the contract (new law, new tool, new track), the amendment must be made in `AGENTS.md` first, then referenced here.
