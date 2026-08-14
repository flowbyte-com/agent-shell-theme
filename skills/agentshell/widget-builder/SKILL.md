---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget — phrases like "build me a calculator", "add a latest posts carousel", "create a sales dashboard". Teaches the agent to choose between two tracks (Interactive or WordPress Decorator), how to compose zone blocks, how to verify the result, and the security boundary that prohibits client-side fetch().
---

## When to use this skill

**Trigger phrases:**
- "build me a widget that..."
- "create a [calculator | carousel | dashboard | visualizer]"
- "add a custom [header | sidebar | footer] widget"
- "make me a [latest posts | taxonomy cloud | recent comments] widget"

**Two tracks:**
- **Interactive** — self-contained applications: calculators, simulators, visualizers, configurators. No WordPress data required.
- **WordPress Decorator** — progressively enhances WordPress-rendered content (posts, pages, taxonomy) into a custom presentation.

**Out of scope (do not invoke this skill for these):**
- Building standard WP widgets via the Widgets admin UI — those don't need agent help
- Editing an existing widget's behavior — that's a code review task, not a builder task
- "Just look at this DOM and tell me what you see" — that's a general inspection task, not a builder task

If the user's request mixes widget-building with another intent (e.g. "build me a widget AND pick a theme that matches"), do the widget part and stop — ask the user before attempting the rest.

## Tracks

There are exactly **two tracks**. Snapshot-style widgets are an authoring pattern inside the Interactive track, not a third track.

### Track 1 — Interactive

```text
init(el)
   ↓
local state
   ↓
window.math / window.d3
   ↓
DOM
```

No WordPress data required. Self-contained applications. The agent reads no WP data and the widget performs zero network requests.

A "snapshot widget" (frozen Q2 figures, a curated post list, a one-off dashboard) is just an Interactive widget whose initial state was seeded by the agent during construction. The execution model is identical.

### Track 2 — WordPress Decorator

```text
wp_loop / wp_core / wp_widget_area
       ↓
server-rendered HTML
       ↓
widget init(el)
       ↓
progressive enhancement
```

The widget progressively enhances already-rendered WordPress content. WordPress owns data; the widget owns presentation.

**Hard rule:** Client-side `fetch()` is prohibited under any circumstances. This is the security boundary, not a tunable preference. No exceptions for "WordPress REST API only" — the prohibition is absolute.

**Hard rule:** A decorator widget MUST degrade to usable server-rendered content if JavaScript fails. If the carousel explodes, the user still has the posts.

## Track selection

### Decision tree

```text
Does the widget require WordPress/site data?

├── No
│   └── Interactive
│       └── local state + math/d3 + DOM
│
└── Yes
    │
    ├── Must it reflect current site content (changes between page loads)?
    │   └── Decorator
    │       └── server-rendered DOM + colocated widget
    │
    └── Is point-in-time data acceptable?
        └── Interactive (snapshot-seeded)
            └── agent reads data → embeds in init_js
            └── leaves a refresh-anchor comment for future agents
```

### The decisive question

> **Does the widget need to remain correct when the underlying data changes without the agent rebuilding it?**

- Yes → Decorator
- No, point-in-time is fine → Interactive with seeded snapshot
- No data needed → plain Interactive

### Least-powerful-track principle

When the requirements are ambiguous, choose the **least powerful track that satisfies the requirement**. Move right only when the requirements actually demand it.

This prevents "live" from becoming the default merely because it sounds more impressive.

### Visible reasoning

The agent must report the chosen track to the user with a one-line justification:

```
Track: Decorator
Reason: Widget displays WordPress posts that may change between page loads.
```

This gives the user visibility without forcing them to remember a `track:` prefix syntax.

### User override

If the user explicitly says "snapshot" / "frozen" / "static" / "embedded", the agent treats that as a request for the Interactive track with snapshot-seeded state — the architectural fit for frozen point-in-time data. If the user says "interactive" / "calculator" / "self-contained", route to plain Interactive. If the user says "live" / "decorator" / "current posts", route to Decorator.

In all cases the agent still picks the implementation; the user can override the track choice. The skill never uses client-side `fetch()` to satisfy any of these requests — that path is closed regardless of what the user says.

## Track 2 — the colocation contract

### The colocation rule (primary)

The primary Track 2 pattern is **colocation**: the decorator widget lives in the same zone as the `wp_loop` it enhances. The agent places both blocks in the same zone composition, in the order `[wp_loop, decorator-widget]`.

```text
zone composition (main):
  [
    { type: "wp_loop" },
    { type: "widget", id: "latest-posts-carousel" }
  ]
```

When the widget's `init(el)` runs, it locates its source by walking up to its zone scope and finding the nearest preceding `wp_loop` block:

```js
init: function(el) {
    const zone = el.closest('.zone-main, [data-zone]') || el.parentElement;
    const loop = zone.querySelector('.wp-block-post, article.post, .entry');
    if (!loop) return; // source not found — degrade gracefully
    // extract from standard WP markup...
}
```

This is **declared by composition**, not by a custom data-* attribute on the loop. The widget knows its source because the agent placed them together. No PHP filter, no source attribute, no risk of mis-wiring to unrelated loops on the same page.

### What the widget reads

Standard WordPress `wp_loop` markup already exposes everything a decorator typically needs:

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

The decorator extracts these from the existing markup. No new attributes required.

### Why decorators MUST NOT blind-scan the document

A widget that runs `document.querySelectorAll('article')` will find articles in the main loop, the sidebar, related-posts, footer widgets, and admin-ajax embeds — and confidently wire up the wrong data.

The colocation rule fixes this without any custom attributes: the widget's scope is the zone it lives in, and within that zone the nearest preceding `wp_loop` is unambiguously its source.

### Optional data-* patterns

#### Sub-pattern A: scalar data

For single values, use one attribute per field:

```html
<article
  data-post-id="123"
  data-post-title="Hello World"
  data-post-url="/hello-world/"
  data-post-date="2026-08-14"
>
  ...native WP content...
</article>
```

Excellent for simple widgets. Survives `wp_kses_post` unchanged.

#### Sub-pattern B: structured data

For collections, encode JSON into **one** `data-*` attribute:

```html
<div
  class="latest-posts"
  data-agentshell-data='{"posts":[{"id":1,"title":"..."}]}'
>
</div>
```

Consume via `el.dataset.agentshellData` in `init_js`. The skill explicitly requires the agent to handle malformed or missing payloads rather than assuming the data exists.

The agent must NOT introduce `<script type="application/json">` for hydration. `wp_kses_post` strips script tags, so this convention doesn't survive sanitization without ripping a hole in the widget security boundary. Data attributes are the right primitive because they pass through the existing sanitization intact.

### The escape hatch — mu-plugin for genuinely missing data

When standard WordPress markup genuinely does not expose a field the widget needs (rare — most fields are in the standard markup), the skill permits a **mu-plugin filter** with a strict validation gate. This is an explicit, logged, rare action — not the default.

**Mandatory workflow for the mu-plugin path:**

1. Write the filter PHP to `/tmp/agentshell-widget-filter-<timestamp>.php`. Never write directly to `wp-content/mu-plugins/`.
2. Run `php -l /tmp/agentshell-widget-filter-<timestamp>.php` via the Bash tool. Confirm clean lint output. If lint fails, fix and re-run until clean.
3. **Inside the open transaction**, after lint passes, move the file to `wp-content/mu-plugins/`. Use `mv` (atomic on the same filesystem) rather than write-in-place.
4. Verify the live site still responds: `curl -fsS -o /dev/null -w "%{http_code}" https://example.com/wp-json/agentshell-mcp/v1/...`. If the daemon returns non-2xx, the mu-plugin broke WordPress boot. Roll back by deleting the file (`rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`) BEFORE attempting any other rollback. The standard `agentshell_rollback_transaction` only handles wp_options state — it cannot un-fatal a PHP parse error.
5. Tell the user: "Wrote a mu-plugin filter to expose X. File: … If you want to revert, run: `rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`."

**The escape hatch is logged in the audit summary** so future agents know a mu-plugin exists and can update or remove it.

**Hard rule for the escape hatch:** the mu-plugin MUST be removable by a single `rm`. No `register_activation_hook`, no database writes from inside the filter, no cron registration. Anything that creates persistent state outside the file itself violates the rollback principle.

### Why no MCP tool for filter registration

A tool that lets the agent write PHP into `wp_options` and `eval()` it during boot sounds tempting but is fundamentally unsafe: a fatal error in eval'd code is indistinguishable from a fatal error in core. PHP shutdown handlers run after the fatal and can log, but cannot restore state. The cost of a single bad eval is a bricked site with no automated recovery path — worse than the mu-plugin path, which is recoverable by `rm`.

The mu-plugin escape hatch is the minimum-necessary PHP-modification surface that preserves the rollback principle.

## The refresh anchor

Snapshot-seeded Interactive widgets (frozen Q2 figures, curated post lists, one-off dashboards) embed their data as a static JavaScript object in `init_js`. To prevent future agents from being unable to update the widget when the data changes, the skill requires a standardized metadata comment at the top of `init_js`:

```js
/* agentshell-snapshot-source: <description-of-where-the-data-came-from> */
window.AgentshellWidgets['q2-sales-dashboard'] = {
    init: function(el) {
        const data = { ... };  // Seeded snapshot
        // ...
    }
};
```

### Anchor examples

- `/* agentshell-snapshot-source: WP_Query post_type=product date=Q2 */`
- `/* agentshell-snapshot-source: agentshell_search_content type=post limit=5 */`
- `/* agentshell-snapshot-source: agentshell_get_design_system + manual palette, 2026-08-14 */`
- `/* agentshell-snapshot-source: external CSV uploaded 2026-08-14, filename=Q2-figures.csv */`

The description must be specific enough that a future agent can re-derive the data without guesswork. Generic anchors like `/* agentshell-snapshot-source: hardcoded data */` are useless — they force the next agent to start from zero.

### Future agent path

When a future agent is asked to update a snapshot-seeded widget:

1. Read the refresh anchor from `init_js`.
2. Re-run the same query or process inside a new transaction.
3. Patch the new data into the same `init_js` without rebuilding the presentation logic.

The presentation logic is preserved. Only the embedded data changes. This is the core value of the convention: a snapshot widget can be refreshed without a full rewrite.

### When NOT to use the refresh anchor

If the data is genuinely one-off and will never be refreshed (e.g., a static historical report, a one-time announcement), still leave an anchor — but mark it as terminal:

`/* agentshell-snapshot-source: Q2 2026 report, terminal (not refreshed) */`

Future agents reading a terminal anchor know not to attempt a refresh even if the user asks.

## Composition patterns

The skill teaches the agent **when** to use each AgentShell primitive. The decision rule is simple: prefer existing composition primitives over reimplementing them in a widget. Only build a standalone widget when the existing primitives can't express the behaviour.

### Pattern table

| Scenario | Composition |
|---|---|
| Pure calculator / simulator / visualizer | Standalone Interactive widget, no WP composition needed |
| Latest posts carousel | `wp_loop` + colocated Decorator widget in same zone |
| Custom header nav menu | `wp_core` (`nav_menu`) — no widget, the core component already does this |
| Sidebar widget area | `wp_widget_area` zone source + decorators as needed |
| Frozen dashboard (Q2 figures, monthly report) | Interactive widget with embedded data + refresh anchor |
| One-off styled list with fixed shape | `json_block` (where shape is fixed and small) |
| Decorator needing fields WP doesn't expose | `wp_loop` + colocated Decorator + mu-plugin escape hatch (Section 3) |
| Site-wide announcement banner | Interactive widget (no data) deployed to header zone via `agentshell_update_zone_slots` |

### Concrete examples

**Latest posts carousel:**
```text
zone composition (main):
  [
    { type: "wp_loop" },                              // WP renders posts natively
    { type: "widget", id: "latest-posts-carousel" }    // Decorator enhances them
  ]
```
The decorator widget colocates with the wp_loop. No mu-plugin needed.

**Mortgage calculator (Interactive):**
```text
zone composition (sidebar):
  [
    { type: "widget", id: "mortgage-calculator" }      // Self-contained, no WP data
  ]
```
The widget runs on its own — no wp_loop, no decorator.

**Q2 sales dashboard (Interactive + snapshot):**
```text
zone composition (main):
  [
    { type: "widget", id: "q2-sales-dashboard" }      // Data embedded in init_js
  ]
```
Data seeded during construction. Refresh anchor at top of init_js.

### Why "prefer existing primitives"

A common agent failure mode is to build a widget that re-implements `wp_loop` or `nav_menu`. This bloats the widget, duplicates WordPress's responsibility, and creates two sources of truth. The skill's rule: if AgentShell already has a primitive that does the job, use it. Build a widget only when no primitive fits.

### Tools for composition

The agent uses:

- `agentshell_update_zone_composition` — for main zones (flat `composition[]` array)
- `agentshell_update_zone_slots` — for header/footer zones (`slots: { left, center, right }`)
- `agentshell_register_widget` — registers the widget itself with template, init_js, css, optional libs

All three are existing MCP tools — no new tools required.

## Verification workflow

```text
build
   ↓
place via agentshell_update_zone_composition or agentshell_update_zone_slots
   ↓
begin transaction (if not already open)
   ↓
agentshell_screenshot (if capability available)
   ↓
agentshell_validate (always)
   ↓
fix any issues
   ↓
commit
```

The verification workflow lives **inside** an open transaction so the live site is never mutated until commit.

### Step-by-step

1. **Build the widget.** Register via `agentshell_register_widget` with template, init_js, css. The widget is staged in the transaction, not yet live.
2. **Place the widget.** Use `agentshell_update_zone_composition` (main zones) or `agentshell_update_zone_slots` (header/footer) to position the widget in the zone composition.
3. **Capability check.** Call `agentshell_get_capabilities`. If `screenshot: true`, proceed to step 4. If not, skip to step 5 and tell the user that visual verification was skipped.
4. **Screenshot.** Call `agentshell_screenshot({ viewport: "desktop" })` and optionally `agentshell_screenshot({ viewport: "mobile" })`. Inspect the screenshots.
5. **Validate.** Call `agentshell_validate`. The doctor catches schema drift, missing fields, broken references. Screenshots don't.
6. **Progressive-enhancement check (decorator widgets only).** With JavaScript disabled or in the screenshot's static asset view, the server-rendered content must remain readable. The agent verifies this in the screenshot or explicitly acknowledges it cannot verify it.
7. **Fix issues.** If anything looks wrong (visual, validation error, degraded JS-off view), iterate. Re-screenshot. Re-validate. Until both pass.
8. **Commit.** `agentshell_commit_transaction`. The widget goes live.

### Screenshot vs. validate

| Backend | Visual verification | Schema check |
|---|---|---|
| `screenshot: true` | Yes — screenshot both viewports | Yes — `agentshell_validate` |
| `screenshot: false` | No — skipped, tell the user | Yes — `agentshell_validate` (always) |

`agentshell_validate` is **always** called. Screenshots are conditional. This means even on a server with no headless browser, the agent still catches schema problems before commit.

### What to look for in screenshots

- **Widget renders correctly** — no broken layout, no overflow, no missing CSS
- **Zone structure intact** — the widget hasn't broken the surrounding `wp_loop` or other zones
- **No CSS bleed** — widget's scoped CSS doesn't affect other zones
- **Mobile viewport works** — the widget doesn't break on small screens
- **JS-off view readable (decorators)** — server-rendered content remains usable

### Verification of the mu-plugin escape hatch

After writing a mu-plugin filter (Section 3 escape hatch), the verification workflow gains one extra step:

```bash
curl -fsS -o /dev/null -w "%{http_code}" https://example.com/wp-json/agentshell-mcp/v1/mcp
```

If the daemon returns non-2xx, the mu-plugin broke WordPress boot. Recovery is `rm` on the mu-plugin file — `agentshell_rollback_transaction` cannot help. See Section 3 for the full workflow.

## What the agent must NOT do

These are hard rules. Violating any of them is an architectural failure, not a stylistic choice.

### 1. No client-side `fetch()`

```js
// FORBIDDEN — no exceptions, no workarounds
fetch('/wp-json/wp/v2/posts');
fetch('https://external-api.example.com/data');
```

The widget does not possess network capabilities. This applies to:

- The WordPress REST API
- External APIs
- Any URL whatsoever

If the widget needs data, it gets it from server-rendered DOM (colocation) or from data embedded in `init_js` (snapshot-seeded Interactive). There is no third option.

### 2. No `<script type="application/json">` for hydration

```html
<!-- FORBIDDEN — stripped by wp_kses_post -->
<script type="application/json" class="agentshell-widget-data">
  {"posts": [...]}
</script>
```

`wp_kses_post` strips `<script>` tags. Even if it didn't, the convention would compromise the widget security boundary. Use `data-*` attributes instead.

### 3. No blind DOM scanning

```js
// FORBIDDEN — wires up wrong data on multi-loop pages
const articles = document.querySelectorAll('article');
articles.forEach(a => enhance(a));
```

Use the colocation rule. The widget's scope is its zone; within that scope the nearest preceding `wp_loop` is its source.

### 4. No inventing WordPress APIs

The agent must not invent a live-data path that doesn't exist. If the data isn't reachable through documented patterns (colocation, wp_loop, wp_core, snapshot), stop and explain — don't invent a `fetch` workaround, don't synthesize a fake REST endpoint, don't assume undocumented WP behavior.

### 5. No reimplementing `wp_loop` or `wp_core` in a widget

```js
// FORBIDDEN — duplicates WP's responsibility
init: function(el) {
    // Re-render posts from scratch via custom logic
    // when wp_loop would have done this for free
}
```

If AgentShell has a primitive that does the job, use it. Build a widget only when no primitive fits.

### 6. No bare-metal mu-plugins

The escape hatch is gated by:

- Write to `/tmp/`, lint with `php -l`, atomic `mv`
- Verify daemon health post-move
- Single-`rm` recoverability

A mu-plugin that violates any of these (e.g., registers cron jobs, writes to the database on activation, depends on a non-removable companion file) violates the rollback principle and is forbidden.

### 7. No bypassing transactions

All composition mutations must run inside an open transaction. Direct calls to `agentshell_update_zone_composition` or `agentshell_register_widget` without a transaction are forbidden — they would mutate the live site without the rollback safety net.

## Security boundary (no strictness modes)

The widget-builder skill has **no strictness knob**. Unlike the image-to-theme skill (which has strict / pragmatic / expressive modes for heuristic mapping flexibility), this skill has one mode: **safe**.

Every output complies with the security boundary:

- No client-side `fetch()` — ever, in any mode
- No `<script>` injection — ever, in any mode
- No blind DOM scanning — ever, in any mode

There is no "expressive" escape hatch. The boundary is absolute and non-tunable. If the user asks for a widget that requires network access, the skill refuses and explains why — there is no setting that flips that off.

This is a deliberate divergence from image-to-theme. Heuristic mapping (colors, fonts, spacing) has a wide valid solution space and benefits from expressiveness. Widget construction has a narrow valid space constrained by the security boundary, and expressiveness in that space means bugs, not flexibility.

The security boundary is not a tunable preference. It is a load-bearing architectural constraint.

## Failure modes

| Failure | Resolution |
|---|---|
| User requests client-side `fetch()` | Refuse, explain security boundary, suggest Decorator (colocation) or snapshot-seeded Interactive. Do not negotiate. |
| Data not available server-side | Stop and explain. Offer: (a) embed data yourself via Interactive with refresh anchor, (b) point me at a `wp_loop` / `wp_core` block that has the data, (c) accept a snapshot. |
| Widget template contains `<script>` | `wp_kses_post` strips it. The skill must use `data-*` only. If the agent produced a `<script>` block, rewrite to data attributes. |
| Screenshot backend unavailable | Skip visual verification, run `agentshell_validate`, tell user "couldn't capture a screenshot — visual verification skipped." |
| Transaction lock conflict | Standard AgentShell handling — do not force, surface actor from the error. |
| Daemon unreachable | Standard AgentShell handling — retry once with backoff, then abort with config-check message. |
| Mu-plugin bricks WordPress boot | Daemon returns non-2xx after the atomic `mv`. Recovery: `rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php` immediately. Do not attempt `agentshell_rollback_transaction` first — it cannot help with a fatal error. |
| Decorator finds no source `wp_loop` in its zone | The widget degrades gracefully — returns early from `init(el)` without enhancing. The user sees the plain content, not a broken widget. |

### Inheriting standard AgentShell failure modes

The image-to-theme skill's failure-mode vocabulary (Section 6) applies here too: backend unavailable, schema drift, daemon unreachable, transaction lock, over-budget. The widget-builder skill inherits these handling patterns by reference rather than duplicating them. When in doubt, follow the image-to-theme skill's guidance.

## Known limitations

- **Refresh anchor is convention-only.** No MCP tool or PHP enforcement exists to keep the anchor comment in sync with reality. Future agents must trust the anchor and verify the query still returns the expected shape. A bad anchor (or an anchor that references a query whose schema has since changed) silently produces stale data.

- **`<script type="application/json">` would be cleaner JSON-wise** but is blocked by current sanitization. If AgentShell ever loosens `wp_kses_post` for widget contexts, this skill can be updated to prefer the script convention. Until then, `data-*` is the right primitive.

- **No DOM diff between iterations.** If the decorator widget's source structure changes (e.g., WP core changes how posts are rendered, the active theme switches from a `.entry-title` to `.post-title` class), the widget will silently degrade. The skill instructs the agent to re-validate after any plugin or theme update.

- **The mu-plugin escape hatch is unsafe relative to everything else in the skill.** A fatal error in the mu-plugin bricks the daemon before `agentshell_rollback_transaction` can fire. Recovery is `rm` on the file. The skill requires the lint gate, an immediate daemon health check, and the single-`rm` recovery instruction in the audit summary — but it cannot make the operation fully safe. Use only when colocation cannot satisfy the data requirement.

- **Colocation depends on `wp_loop` being in the same zone.** If a future AgentShell feature allows `wp_loop` blocks to render into zones the widget doesn't colocate with (e.g., cross-zone composition), the colocation model breaks. The skill assumes the current zone-bounded rendering model.

- **No multi-source decorator support.** A decorator widget enhances one `wp_loop` — the nearest preceding one in its zone. If the user wants one widget to enhance multiple loops (e.g., a unified carousel that mixes posts from two queries), the skill has no answer. Recommend splitting into two colocated decorators or using a snapshot-seeded Interactive widget.

- **Decorator assumes standard `wp_loop` markup.** The standard-markup table in Section 3 covers the common cases (post, page, archive). Custom post types or heavily customised themes may emit different markup. The agent should adapt the CSS selectors in `init_js` based on what it actually finds, or fall back to the escape hatch.
