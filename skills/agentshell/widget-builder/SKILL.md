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
