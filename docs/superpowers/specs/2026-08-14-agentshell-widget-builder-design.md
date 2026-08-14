# Widget Builder Skill — Design Spec

**Date:** 2026-08-14
**Status:** Approved by user via brainstorming Q&A
**Path:** `docs/superpowers/specs/2026-08-14-agentshell-widget-builder-design.md`

---

## 1. What this is

A skill file (`skills/agentshell/widget-builder/SKILL.md`) that teaches an agent how to build an AgentShell widget correctly given a user's high-level description ("mortgage calculator", "latest posts carousel", "weekly sales dashboard").

It is **not** a widget authoring tutorial. The mechanics — Web Components, Shadow DOM, scoped CSS, library policy, the `init_js` sandbox — already live in `AGENTS.md` and `skills/agentshell/SKILL.md`. The widget-builder skill adds the **decision-making layer**: given the user's description, which architecture should I compose, and how do I verify it?

---

## 2. The two-track model

There are exactly **two widget tracks**. Snapshot-style widgets are an authoring pattern inside the Interactive track, not a third track.

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

No WordPress data required. Self-contained applications: calculators, simulators, visualizers, code playgrounds, configurators. The agent reads no WP data and the widget performs zero network requests.

A "snapshot widget" (frozen Q2 figures, a curated post list, a one-off dashboard) is just an Interactive widget whose initial state was seeded by the agent during construction rather than being empty. The execution model is identical.

### Track 2 — WordPress Decorator

```text
wp_loop / wp_core / wp_widget_area
       ↓
server-rendered HTML
       ↓
data-* metadata
       ↓
decorator widget
       ↓
custom presentation
```

The widget progressively enhances already-rendered WordPress content. WordPress owns data; the widget owns presentation; the skill teaches the agent how to connect them.

**Hard rule:** Dynamic widgets MUST consume data supplied by server-rendered DOM. Client-side `fetch()` is prohibited under all circumstances. This is the security boundary, not a tunable preference.

**Hard rule:** A decorator widget MUST degrade to usable server-rendered content if JavaScript fails. If the carousel explodes, the user still has the posts.

---

## 3. Track selection

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
    │       └── server-rendered DOM + data-* metadata
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

If the user explicitly says "snapshot" / "frozen" / "static" / "embedded", honour that choice unless it conflicts with an architectural constraint (which it cannot — all three tracks are architecturally valid). The agent still picks the implementation; the user can override the track choice.

---

## 4. Track 2 — the data-* contract

### Sub-pattern A: scalar data

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

### Sub-pattern B: structured data

For collections, encode JSON into **one** `data-*` attribute:

```html
<div
  class="latest-posts"
  data-agentshell-data='{"posts":[{"id":1,"title":"..."}]}'
>
</div>
```

Consume via `el.dataset.agentshellData` in `init_js`. The skill explicitly requires the agent to handle malformed or missing payloads rather than assuming the data exists.

### What the skill MUST NOT do

The agent must NOT introduce `<script type="application/json">` for hydration. `wp_kses_post` strips script tags, so this convention doesn't survive sanitization without ripping a hole in the widget security boundary. Data attributes are the right primitive because they pass through the existing sanitization intact.

### The declared-relationship rule

Decorators MUST NOT blindly scan the document. A widget that runs `document.querySelectorAll('article')` will find articles in the main loop, the sidebar, related posts, and the footer — and confidently wire up the wrong data.

The convention is:

```text
data-agentshell-source="widget-id"
```

on the WordPress-rendered container. The decorator targets that specific source:

```js
const source = document.querySelector('[data-agentshell-source="latest-posts"]');
```

This is much less magical and survives multi-loop pages.

### How source data-* gets onto the DOM

Two acceptable paths, both already supported by AgentShell today:

1. **`wp_loop` with a filter.** Author or skill writes a small mu-plugin or theme filter that adds `data-agentshell-source` (and any scalar `data-post-*` attributes) to rendered posts. The decorator then enhances them.
2. **A standalone `json_block` rendering.** Less elegant — `json_block` is sanitized too — but workable for fixed-shape data the agent can render in one shot.

The skill teaches the agent to prefer path 1 (existing rendering + light filter) over path 2 (re-rendering from scratch).

---

## 5. Snapshot as an Interactive-track pattern

When the user wants a "frozen dashboard" or "Q2 sales figures" widget:

1. The agent reads the data during construction (using `agentshell_search_content` or equivalent existing tools).
2. The agent embeds the data as a static JavaScript object in `init_js`.
3. The widget executes as Interactive: no network, no DOM scanning, local state only.

### The Refresh Anchor

To prevent future agents from being unable to update the widget, the skill requires a standardized metadata comment at the top of `init_js`:

```js
/* agentshell-snapshot-source: <description-of-where-the-data-came-from> */
window.AgentshellWidgets['q2-sales-dashboard'] = {
    init: function(el) {
        const data = { ... };  // Seeded snapshot
        ...
    }
};
```

Examples:

- `/* agentshell-snapshot-source: WP_Query post_type=product date=Q2 */`
- `/* agentshell-snapshot-source: agentshell_get_design_system + manual palette, 2026-08-14 */`
- `/* agentshell-snapshot-source: external CSV uploaded 2026-08-14 */`

When a future agent is asked to update the widget, they read the anchor, re-run the same query inside a new transaction, and patch the new data into the same `init_js` without rebuilding the presentation.

---

## 6. Composition patterns the skill teaches

Beyond track selection, the skill teaches the agent **when** to use each AgentShell primitive:

| Scenario | Pattern |
|---|---|
| Pure calculator / simulator | Standalone Interactive widget, no WP composition needed |
| Latest posts carousel | `wp_loop` (with source marker) + decorator widget |
| Custom header nav menu | `wp_core` (`nav_menu`) — no widget, the core component already does this |
| Sidebar widget area | `wp_widget_area` zone source + decorators as needed |
| Frozen dashboard | Interactive widget with embedded data + refresh anchor |
| One-off styled list | `json_block` (where shape is fixed and small) |

The skill instructs the agent: prefer existing composition primitives (`wp_loop`, `wp_core`, `wp_widget_area`) over reimplementing them in a widget. Only build a standalone widget when the existing primitives can't express the behaviour.

---

## 7. Verification workflow

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

Specifically:

1. **`agentshell_get_capabilities`** first. If `screenshot: true`, use it. If not, fall back to `agentshell_validate` only and tell the user that visual verification was skipped.
2. **Always `agentshell_validate`** before commit. The doctor catches schema drift, missing fields, broken references. Screenshots don't.
3. **Progressive-enhancement check** for decorator widgets: with JS disabled (or in the screenshot's static asset view), the server-rendered content must remain readable. The agent must verify this in the screenshot or explicitly acknowledge it cannot.
4. **Transaction model** applies here exactly as in image-to-theme: begin → mutate → preview → validate → commit/rollback. The widget builder never calls commit until the agent has inspected the result.

The verification workflow lives **inside** an open transaction so the live site is never mutated until commit.

---

## 8. What the skill explicitly tells the agent NOT to do

- **No client-side `fetch()`** for any reason. No exceptions, no "just this once for WP REST", no clever workarounds. The widget does not possess network capabilities.
- **No `<script type="application/json">` for data.** Doesn't survive `wp_kses_post`. Use `data-*` attributes.
- **No blind DOM scanning** (`querySelectorAll('article')`). Use the declared `data-agentshell-source` convention.
- **No inventing WP APIs** the skill doesn't teach. If the data isn't reachable through the documented patterns, stop and explain.
- **No reimplementing `wp_loop` or `wp_core` in a widget.** Prefer the existing primitives.

---

## 9. Strictness

**No strictness knob.** The security boundary is absolute and non-tunable. Unlike the image-to-theme skill (which has strict/pragmatic/expressive modes for heuristic mapping flexibility), the widget-builder skill has one mode: **safe**. Every output complies with the security boundary; there is no "expressive" escape hatch.

---

## 10. Failure modes

The skill inherits the standard AgentShell failure-mode vocabulary from the image-to-theme skill's Section 6 (with widget-specific adaptations):

| Failure | Resolution |
|---|---|
| User requests client-side `fetch()` | Refuse, explain security boundary, suggest Decorator or snapshot-seeded Interactive |
| Data not available server-side | Stop and explain. Offer: (a) embed data yourself via Interactive, (b) point me at a `wp_loop`/`wp_core` block that has the data, (c) accept a snapshot |
| Widget template contains `<script>` | `wp_kses_post` strips it. Skill must use `data-*` only. |
| Screenshot backend unavailable | Skip visual verification, run `agentshell_validate`, tell user "couldn't capture a screenshot — visual verification skipped" |
| Transaction lock conflict | Standard AgentShell handling — do not force, surface actor |
| Daemon unreachable | Standard AgentShell handling — retry once with backoff, then abort |

---

## 11. Skill scope — what lives in the skill vs. AGENTS.md

| Concern | Lives in |
|---|---|
| Track selection (Interactive vs Decorator) | Skill |
| `data-*` hydration contract | Skill |
| Refresh anchor convention | Skill |
| Composition pattern recommendations (when to use `wp_loop` vs standalone widget) | Skill |
| Verification workflow with screenshot-or-validate fallback | Skill |
| Failure modes specific to widgets | Skill |
| **Web Component / Shadow DOM mechanics** | `AGENTS.md` |
| **`init_js` sandbox rules** | `AGENTS.md` |
| **Library policy (D3 / Math.js)** | `AGENTS.md` |
| **Scoped CSS conventions** | `AGENTS.md` |
| **Widget lifecycle (`active` / `disabled` / `remove`)** | `AGENTS.md` |

The skill does not duplicate `AGENTS.md`. It sits on top of it.

---

## 12. File layout

```
skills/agentshell/widget-builder/
├── SKILL.md          # The skill file — frontmatter + markdown instructions
└── README.md         # Human-facing overview
```

Mirrors the image-to-theme skill layout.

---

## 13. Open questions / known limitations

- **Refresh anchor is convention-only.** No MCP tool or PHP enforcement exists to keep the anchor comment in sync with reality. Future agents must trust the anchor and verify the query still returns the expected shape.
- **`<script type="application/json">` would be cleaner JSON-wise** but is blocked by current sanitization. If AgentShell ever loosens `wp_kses_post` for widget contexts, this skill can be updated to prefer the script convention. Until then, data-* is the right primitive.
- **No DOM diff between iterations.** If the decorator widget's source structure changes (e.g. WP core changes how posts are rendered), the widget will silently degrade. The skill instructs the agent to re-validate after any plugin update.
- **Track 2 source markers require filter code or theme cooperation.** Out of the box, vanilla `wp_loop` does not emit `data-agentshell-source`. The skill's path-1 implementation requires either a small mu-plugin or a one-time theme filter. The skill documents this requirement and instructs the agent to write the filter rather than working around it.

---

## 14. Worked example sketch

The full skill will include an end-to-end worked example. Sketch:

- **Input:** "Build me a latest posts carousel."
- **Track selection:** Decorator (live data, must reflect current posts).
- **Composition decision:** `wp_loop` in main zone + decorator widget that enhances it.
- **Author writes a tiny filter** that adds `data-agentshell-source="latest-posts"` and per-post scalar `data-*` attributes.
- **Decorator widget:** `init(el)` finds the source, reads posts, builds a carousel UI in the decorator's scoped DOM.
- **Verification:** screenshot shows the carousel styled correctly; with JS off, posts are still readable as a plain list (progressive enhancement).
- **Commit.**

A second worked example for an Interactive widget (a calculator with snapshot-seeded initial values) demonstrates the Refresh Anchor pattern.
