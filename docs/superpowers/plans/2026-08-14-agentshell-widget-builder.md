# Widget Builder Skill — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `skills/agentshell/widget-builder/` — a skill that teaches an agent to compose AgentShell widgets correctly given a user's high-level description, with two tracks (Interactive + WordPress Decorator), colocation as the primary Track 2 pattern, and a strict mu-plugin escape hatch for rare cases.

**Architecture:** Pure agent-side markdown. No PHP changes, no new MCP tools. Two files only — SKILL.md (the skill instructions) and README.md (human-facing overview). Each section of the SKILL.md maps to a spec section; the worked examples mirror the spec's three worked examples.

**Tech Stack:** Markdown + YAML frontmatter (Claude Code skill format). Existing `agentshell_*` MCP tools only.

**Spec:** `docs/superpowers/specs/2026-08-14-agentshell-widget-builder-design.md`

---

## Global Constraints

- Touch ONLY `skills/agentshell/widget-builder/SKILL.md` and `skills/agentshell/widget-builder/README.md`. No PHP files. No `.json` config. No new MCP tools.
- All `agentshell_*` tool references must be existing tools, verifiable against `agentshell-mcp/includes/tools/class-*.php`.
- Frontmatter must be valid YAML — `name:` on line 2, `description:` on line 3, content begins after closing `---`.
- The skill does NOT duplicate `AGENTS.md` content (Web Components, Shadow DOM, init_js sandbox, library policy, scoped CSS, widget lifecycle all live in AGENTS.md).
- The skill does NOT permit client-side `fetch()` under any circumstance — absolute prohibition, repeated in multiple sections.
- The skill has exactly TWO tracks: Interactive and WordPress Decorator. Snapshot is an Interactive-track authoring pattern, not a third track.
- Track 2 primary path is colocation (decorator + wp_loop in same zone), NOT a mu-plugin filter. The mu-plugin path is an explicit, rare escape hatch with a strict validation gate.
- All `data-*` attribute values written into widget templates must use only `data-*` syntax (no `<script type="application/json">` — blocked by `wp_kses_post`).

---

## File Structure

```
skills/agentshell/widget-builder/
├── SKILL.md      # Frontmatter + 11 sections + 3 worked examples
└── README.md     # Human-facing overview
```

Mirror the `skills/agentshell/image-to-theme/` layout exactly.

---

## Task Decomposition

| Task | Section | Lines (approx) | Independently reviewable? |
|---|---|---|---|
| T1 | Scaffold (directory + frontmatter) | ~15 | ✓ |
| T2 | Sections 1-2 (Tracks + Track selection) | ~120 | ✓ |
| T3 | Section 3 (Track 2 contract: colocation + escape hatch) | ~150 | ✓ |
| T4 | Section 4 (Refresh anchor) | ~60 | ✓ |
| T5 | Section 5 (Composition patterns) | ~70 | ✓ |
| T6 | Section 6 (Verification workflow) | ~60 | ✓ |
| T7 | Section 7 (Prohibitions — what NOT to do) | ~50 | ✓ |
| T8 | Section 8 (Security boundary / no strictness modes) | ~30 | ✓ |
| T9 | Section 9 (Failure modes) | ~50 | ✓ |
| T10 | Section 10 (Known limitations) | ~40 | ✓ |
| T11 | Section 11 (Worked examples: A, B, C) | ~120 | ✓ |
| T12 | README.md | ~70 | ✓ |
| T13 | Final integration check | — | ✓ |

Total: 13 tasks. Each task appends to SKILL.md via `cat >>` (matching the image-to-theme pattern), so reviewer diffs are clean and self-contained.

---

### Task 1: Scaffold (directory + frontmatter)

**Files:**
- Create: `skills/agentshell/widget-builder/SKILL.md` (frontmatter only, body empty)
- Create: `skills/agentshell/widget-builder/README.md` (heading + empty body — T12 fills it)

**Interfaces:**
- Produces: the directory `skills/agentshell/widget-builder/` exists with a valid YAML frontmatter at the top of SKILL.md.

**Setup worktree first (mandatory):**

```bash
cd /home/v/workspace/projects/agent-shell-theme
git worktree add .worktrees/widget-builder-skill -b feat/widget-builder-skill
cd .worktrees/widget-builder-skill
```

The worktree protects the user's pre-existing uncommitted changes on main. All subsequent tasks run from `.worktrees/widget-builder-skill/`. The plan itself commits to main directly (it's a docs file), but the skill files live on the feature branch.

- [ ] **Step 1: Create worktree**

```bash
cd /home/v/workspace/projects/agent-shell-theme
git worktree add .worktrees/widget-builder-skill -b feat/widget-builder-skill
cd .worktrees/widget-builder-skill
```

- [ ] **Step 2: Create directory and frontmatter**

```bash
mkdir -p skills/agentshell/widget-builder
cat > skills/agentshell/widget-builder/SKILL.md <<'EOF'
---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget — phrases like "build me a calculator", "add a latest posts carousel", "create a sales dashboard". Teaches the agent to choose between two tracks (Interactive or WordPress Decorator), how to compose zone blocks, how to verify the result, and the security boundary that prohibits client-side fetch().
---
EOF
```

- [ ] **Step 3: Create empty README scaffold**

```bash
cat > skills/agentshell/widget-builder/README.md <<'EOF'
# Widget Builder Skill

EOF
```

- [ ] **Step 4: Verify frontmatter**

```bash
head -5 skills/agentshell/widget-builder/SKILL.md
```

Expected:
```
---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget ...
---
```

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md skills/agentshell/widget-builder/README.md
git commit -m "feat(skill): scaffold widget-builder skill directory"
```

---

### Task 2: Sections 1-2 — Tracks + Track selection

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Sections 1 and 2 (frontmatter already present)

**Interfaces:**
- Consumes: spec Sections 1, 2, 3
- Produces: two sections the agent reads to understand the two-track model and pick the right track

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Sections 1, 2, 3. Section 1 must:
- Describe when to invoke (trigger phrases)
- Define the two-track model (Interactive + WordPress Decorator)
- State the absolute prohibition on client-side fetch()
- State the progressive-enhancement requirement for Track 2

Section 2 (track selection) must:
- Contain the decision tree
- State the decisive question ("must it remain correct when data changes without rebuild?")
- State the least-powerful-track principle
- Mandate visible reasoning (one-line track report)
- Define user override behavior

- [ ] **Step 2: Append Sections 1 and 2**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify section count**

```bash
grep -cE "^## (When to use|Tracks|Track selection)" skills/agentshell/widget-builder/SKILL.md
```

Expected: 3 (one H2 each for "When to use", "Tracks", "Track selection")

- [ ] **Step 4: Verify prohibitions present**

```bash
grep -cE "fetch\(\).*prohibited|degrade to usable|absolute" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 3 (the two hard rules plus the security boundary phrasing)

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Sections 1-2 — tracks and track selection"
```

---

### Task 3: Section 3 — Track 2 contract (colocation + escape hatch)

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 3

**Interfaces:**
- Consumes: spec Section 4 (the Track 2 contract — colocation, sub-patterns, escape hatch, no MCP tool for filter registration)
- Produces: the section that teaches the agent how a decorator widget locates its source and extracts data

This is the most consequential section. It defines the primary pattern (colocation) and the escape hatch (mu-plugin with validation gate).

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 4. The section must contain:
- The colocation rule (primary)
- What the widget reads (table of standard WP markup fields)
- Why decorators must not blind-scan the document
- The escape hatch (mu-plugin workflow with validation gate)
- Why no MCP tool for filter registration (ruling)
- Sub-pattern A (scalar data) and Sub-pattern B (structured data) — even though colocation is primary, these are useful for snapshot-seeded widgets that want to embed small data inline

- [ ] **Step 2: Append Section 3**

Append the following verbatim:

```markdown
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
    const zone = el.closest('[data-zone]') || el.parentElement;
    const source = zone && zone.querySelector('.wp-block-post, article.post, .entry');
    if (!source) return; // source not found — degrade gracefully
    // extract from standard WP markup...
}
```

This is **declared by composition**, not by a custom data-* attribute. The widget knows its source because the agent placed them together. No PHP filter, no source attribute, no risk of mis-wiring to unrelated loops on the same page.

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

A widget that runs `document.querySelectorAll('article')` will find articles in the main loop, the sidebar, related posts, footer widgets, and admin-ajax embeds — and confidently wire up the wrong data.

The colocation rule fixes this without any custom attributes: the widget's scope is the zone it lives in, and within that zone the nearest preceding `wp_loop` is unambiguously its source.

### Optional data-* patterns (when colocation doesn't suffice)

For Interactive widgets with small inline data (e.g., a configuration form with prefilled values, a static lookup table), the widget template may carry scalar `data-*` attributes. Two sub-patterns:

**Sub-pattern A — scalar data:**

```html
<article
  data-post-id="123"
  data-post-title="Hello World"
  data-post-url="/hello-world/"
  data-post-date="2026-08-14"
>
```

Excellent for simple values. Survives `wp_kses_post` unchanged.

**Sub-pattern B — structured data:**

```html
<div
  class="latest-posts"
  data-agentshell-data='{"posts":[{"id":1,"title":"..."}]}'
>
```

Consume via `el.dataset.agentshellData` in `init_js`. The widget must handle malformed or missing payloads gracefully — assume the data may be absent and degrade.

`<script type="application/json">` is rejected: `wp_kses_post` strips script tags, and loosening sanitization would compromise the widget security boundary. Data attributes are the right primitive because they pass through existing sanitization intact.

### The escape hatch — mu-plugin for genuinely missing data

When standard WordPress markup genuinely does not expose a field the widget needs (rare — most fields are in the standard markup), the skill permits a **mu-plugin filter** with a strict validation gate. This is an explicit, logged, rare action — not the default.

**Mandatory workflow for the mu-plugin path:**

1. Write the filter PHP to `/tmp/agentshell-widget-filter-<timestamp>.php`. Never write directly to `wp-content/mu-plugins/`.
2. Run `php -l /tmp/agentshell-widget-filter-<timestamp>.php` via the Bash tool. Confirm clean lint output. If lint fails, fix and re-run until clean.
3. **Inside the open transaction**, after lint passes, move the file to `wp-content/mu-plugins/`. Use `mv` (atomic on the same filesystem) rather than write-in-place.
4. Verify the live site still responds: `curl -fsS -o /dev/null -w "%{http_code}" https://example.com/wp-json/agentshell-mcp/v1/mcp`. If the daemon returns non-2xx, the mu-plugin broke WordPress boot. Roll back by deleting the file (`rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`) **before** attempting any other rollback. The standard `agentshell_rollback_transaction` only handles `wp_options` state — it cannot un-fatal a PHP parse error.
5. Tell the user: "Wrote a mu-plugin filter to expose X. File: `…`. If you want to revert, run: `rm wp-content/mu-plugins/agentshell-widget-filter-<timestamp>.php`."

The mu-plugin MUST be removable by a single `rm`. No `register_activation_hook`, no database writes from inside the filter, no cron registration. Anything that creates persistent state outside the file itself violates the rollback principle.

The mu-plugin path is logged in the audit summary so future agents know it exists and can update or remove it.

### Why no MCP tool for filter registration

A tool that lets the agent write PHP into `wp_options` and `eval()` it during boot sounds tempting but is fundamentally unsafe: a fatal error in eval'd code is indistinguishable from a fatal error in core. PHP shutdown handlers run after the fatal — they can log, they cannot restore state. The cost of a single bad eval is a bricked site with no automated recovery path — worse than the mu-plugin path, which is recoverable by `rm`.

The mu-plugin escape hatch is the minimum-necessary PHP-modification surface that preserves the rollback principle.
```

- [ ] **Step 3: Verify section presence**

```bash
grep -cE "^### The colocation rule|^### What the widget reads|^### Why decorators MUST NOT|^### The escape hatch|^### Why no MCP tool" skills/agentshell/widget-builder/SKILL.md
```

Expected: 5 (one H3 each)

- [ ] **Step 4: Verify prohibitions**

```bash
grep -cE "fetch\(\)|<script type=\"application/json\"|blind-scan|querySelectorAll\('article'\)" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 4 (fetch prohibition, script rejection, blind-scan rejection, querySelectorAll example)

- [ ] **Step 5: Verify escape hatch workflow steps present**

```bash
grep -cE "/tmp/agentshell-widget-filter|php -l|atomic mv|daemon still responds|single \\\`rm\\\`|register_activation_hook" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 5

- [ ] **Step 6: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 3 — Track 2 colocation contract"
```

---

### Task 4: Section 4 — Refresh anchor

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 4

**Interfaces:**
- Consumes: spec Section 5
- Produces: the refresh anchor convention that preserves provenance for snapshot-seeded widgets

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 5. The section must contain:
- Why snapshot is an Interactive-track authoring pattern (not a third track)
- The 3-step construction workflow (read data, embed in init_js, leave anchor)
- The refresh anchor comment format with examples
- The "future agent path" (re-run query, patch data, don't rebuild presentation)

- [ ] **Step 2: Append Section 4**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify section content**

```bash
grep -cE "agentshell-snapshot-source|refresh anchor|future agent" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 5 (mention count across the section)

- [ ] **Step 4: Verify the exact anchor format is documented**

```bash
grep -E "agentshell-snapshot-source: <description-of-where-the-data-came-from>" skills/agentshell/widget-builder/SKILL.md | wc -l
```

Expected: ≥ 1

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 4 — refresh anchor"
```

---

### Task 5: Section 5 — Composition patterns

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 5

**Interfaces:**
- Consumes: spec Section 6
- Produces: the pattern table that teaches the agent when to use which composition primitive

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 6. The section must contain:
- The pattern table with at least 6 scenarios
- The "prefer existing primitives" rule
- Specific examples for each pattern

- [ ] **Step 2: Append Section 5**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify pattern table**

```bash
grep -cE "Pure calculator|Latest posts carousel|Custom header nav menu|Sidebar widget area|Frozen dashboard|One-off styled list|q2-sales-dashboard|mortgage-calculator" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 6 (matches the pattern table rows)

- [ ] **Step 4: Verify tool references**

```bash
grep -cE "agentshell_update_zone_composition|agentshell_update_zone_slots|agentshell_register_widget" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 3 (one per tool)

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 5 — composition patterns"
```

---

### Task 6: Section 6 — Verification workflow

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 6

**Interfaces:**
- Consumes: spec Section 7
- Produces: the verification workflow with screenshot-or-validate fallback and progressive-enhancement check

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 7. The section must contain:
- The 8-step verification flow
- The screenshot-or-validate rule
- The progressive-enhancement check for decorator widgets
- The transaction-model requirement (verification inside open transaction)

- [ ] **Step 2: Append Section 6**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify verification content**

```bash
grep -cE "agentshell_screenshot|agentshell_validate|progressive-enhancement|degraded JS-off|begin transaction" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 5

- [ ] **Step 4: Verify "always validate" rule**

```bash
grep -E "always.*called|always.*validate" skills/agentshell/widget-builder/SKILL.md | wc -l
```

Expected: ≥ 1

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 6 — verification workflow"
```

---

### Task 7: Section 7 — Prohibitions (what NOT to do)

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 7

**Interfaces:**
- Consumes: spec Section 8
- Produces: the consolidated list of prohibitions

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 8. The section must contain:
- 5 prohibitions: no fetch, no script type=json, no blind scanning, no inventing WP APIs, no reimplementing wp_loop/wp_core

- [ ] **Step 2: Append Section 7**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify all 7 prohibitions**

```bash
grep -cE "^### [1-7]\. " skills/agentshell/widget-builder/SKILL.md
```

Expected: 7 (one H3 per numbered prohibition)

- [ ] **Step 4: Verify "FORBIDDEN" callouts**

```bash
grep -c "FORBIDDEN" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 5

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 7 — prohibitions"
```

---

### Task 8: Section 8 — Security boundary (no strictness modes)

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 8

**Interfaces:**
- Consumes: spec Section 9
- Produces: the section that explicitly diverges from image-to-theme by having no strictness modes

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 9. The section must:
- State that no strictness knob exists
- Contrast with the image-to-theme skill's strict/pragmatic/expressive modes
- Explain why: the security boundary is absolute

- [ ] **Step 2: Append Section 8**

Append the following verbatim:

```markdown
## Security boundary (no strictness modes)

The widget-builder skill has **no strictness knob**. Unlike the image-to-theme skill (which has strict / pragmatic / expressive modes for heuristic mapping flexibility), this skill has one mode: **safe**.

Every output complies with the security boundary:

- No client-side `fetch()` — ever, in any mode
- No `<script>` injection — ever, in any mode
- No blind DOM scanning — ever, in any mode

There is no "expressive" escape hatch. The boundary is absolute and non-tunable. If the user asks for a widget that requires network access, the skill refuses and explains why — there is no setting that flips that off.

This is a deliberate divergence from image-to-theme. Heuristic mapping (colors, fonts, spacing) has a wide valid solution space and benefits from expressiveness. Widget construction has a narrow valid space constrained by the security boundary, and expressiveness in that space means bugs, not flexibility.

The security boundary is not a tunable preference. It is a load-bearing architectural constraint.
```

- [ ] **Step 3: Verify section content**

```bash
grep -cE "no strictness knob|absolute and non-tunable|deliberate divergence" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 3

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 8 — security boundary"
```

---

### Task 9: Section 9 — Failure modes

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 9

**Interfaces:**
- Consumes: spec Section 10
- Produces: 6 failure-mode rows adapted for widgets

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 10. The section must contain 6 failure-mode rows:
- User requests fetch() → refuse
- Data not available server-side → stop and explain
- Widget template contains `<script>` → kses strips, use data-* only
- Screenshot backend unavailable → skip visual, validate
- Transaction lock conflict → standard handling
- Daemon unreachable → standard handling

- [ ] **Step 2: Append Section 9**

Append the following verbatim:

```markdown
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
```

- [ ] **Step 3: Verify failure table rows**

```bash
grep -cE "^\| (User requests|Data not available|Widget template|Screenshot backend|Transaction lock|Daemon unreachable|Mu-plugin|Decorator finds)" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 8 (one per row)

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 9 — failure modes"
```

---

### Task 10: Section 10 — Known limitations

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 10

**Interfaces:**
- Consumes: spec Section 13
- Produces: honest documentation of the skill's gaps

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 13. The section must contain 5 limitations:
- Refresh anchor is convention-only
- `<script type="application/json">` blocked by kses
- No DOM diff between iterations
- mu-plugin escape hatch is unsafe relative to everything else
- Colocation depends on `wp_loop` being in the same zone

- [ ] **Step 2: Append Section 10**

Append the following verbatim:

```markdown
## Known limitations

- **Refresh anchor is convention-only.** No MCP tool or PHP enforcement exists to keep the anchor comment in sync with reality. Future agents must trust the anchor and verify the query still returns the expected shape. A bad anchor (or an anchor that references a query whose schema has since changed) silently produces stale data.

- **`<script type="application/json">` would be cleaner JSON-wise** but is blocked by current sanitization. If AgentShell ever loosens `wp_kses_post` for widget contexts, this skill can be updated to prefer the script convention. Until then, `data-*` is the right primitive.

- **No DOM diff between iterations.** If the decorator widget's source structure changes (e.g., WP core changes how posts are rendered, the active theme switches from a `.entry-title` to `.post-title` class), the widget will silently degrade. The skill instructs the agent to re-validate after any plugin or theme update.

- **The mu-plugin escape hatch is unsafe relative to everything else in the skill.** A fatal error in the mu-plugin bricks the daemon before `agentshell_rollback_transaction` can fire. Recovery is `rm` on the file. The skill requires the lint gate, an immediate daemon health check, and the single-`rm` recovery instruction in the audit summary — but it cannot make the operation fully safe. Use only when colocation cannot satisfy the data requirement.

- **Colocation depends on `wp_loop` being in the same zone.** If a future AgentShell feature allows `wp_loop` blocks to render into zones the widget doesn't colocate with (e.g., cross-zone composition), the colocation model breaks. The skill assumes the current zone-bounded rendering model.

- **No multi-source decorator support.** A decorator widget enhances one `wp_loop` — the nearest preceding one in its zone. If the user wants one widget to enhance multiple loops (e.g., a unified carousel that mixes posts from two queries), the skill has no answer. Recommend splitting into two colocated decorators or using a snapshot-seeded Interactive widget.

- **Decorator assumes standard `wp_loop` markup.** The standard-markup table in Section 3 covers the common cases (post, page, archive). Custom post types or heavily customised themes may emit different markup. The agent should adapt the CSS selectors in `init_js` based on what it actually finds, or fall back to the escape hatch.
```

- [ ] **Step 3: Verify all limitations**

```bash
grep -cE "^- \*\*" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 7 (one bullet per limitation in Section 10 plus possibly bullets from other sections; the count should include all `- **` items in this section, which is 7)

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 10 — known limitations"
```

---

### Task 11: Section 11 — Worked examples

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 11

**Interfaces:**
- Consumes: spec Section 14 (three worked examples)
- Produces: three end-to-end worked examples showing each pattern

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 14. Three worked examples required:
- Example A — Decorator (Track 2)
- Example B — Interactive with seeded snapshot (Track 1)
- Example C — Escape hatch (rare)

Each must walk through: input, track selection, composition/implementation, verification, audit notes.

- [ ] **Step 2: Append Section 11**

Append the following verbatim:

```markdown
## Worked examples

### Example A — Decorator: latest posts carousel

**Input:**
```
User: "Build me a latest posts carousel. Use the standard theme styling."
```

**Track selection:** Decorator (live data, must reflect current posts).

**Composition decision:** Place `wp_loop` followed by a `widget` block in the main zone's composition.

```bash
agentshell_begin_transaction({ label: "latest posts carousel" })
agentshell_update_zone_composition({
    zone_id: "main",
    composition: [
        { type: "wp_loop" },
        { type: "widget", id: "latest-posts-carousel" }
    ]
})
```

**Widget registration:**
```bash
agentshell_register_widget({
    id: "latest-posts-carousel",
    name: "Latest Posts Carousel",
    template: '<div class="latest-posts-carousel" data-agentshell-source="latest-posts"><div class="lp-track"></div></div>',
    css: '.latest-posts-carousel { overflow: hidden; } .latest-posts-carousel .lp-track { display: flex; gap: 1rem; transition: transform 0.3s; } .latest-posts-carousel .lp-card { flex: 0 0 300px; padding: 1rem; border: 1px solid var(--theme-border); border-radius: var(--radius-base); }',
    init_js: "window.AgentshellWidgets['latest-posts-carousel'] = { init: function(el) { const zone = el.closest('[data-zone]') || el.parentElement; const posts = zone && zone.querySelectorAll('article.post'); if (!posts || !posts.length) return; const track = el.querySelector('.lp-track'); posts.forEach(p => { const title = p.querySelector('.entry-title a'); const date = p.querySelector('.entry-date'); const excerpt = p.querySelector('.entry-summary'); if (!title) return; const card = document.createElement('div'); card.className = 'lp-card'; card.innerHTML = '<h3>' + title.textContent + '</h3>' + (date ? '<time>' + date.textContent + '</time>' : '') + (excerpt ? '<p>' + excerpt.textContent + '</p>' : ''); track.appendChild(card); }); } };"
})
```

**Verification:**
```bash
agentshell_get_capabilities  # confirm screenshot: true
agentshell_screenshot({ viewport: "desktop" })
agentshell_screenshot({ viewport: "mobile" })
agentshell_validate
```

Check screenshots: carousel styled correctly, mobile viewport works, no CSS bleed. Static-asset view (JS off): posts remain readable as a plain list — progressive enhancement preserved.

**Commit:**
```bash
agentshell_commit_transaction
```

**Audit summary:**
```
Track: Decorator (Track 2)
Reason: Latest posts reflect current content; user expects live data.
Composition: wp_loop + colocated decorator widget in main zone.
Verification: Screenshot both viewports + agentshell_validate. JS-off view readable.
No mu-plugin filter required.
```

### Example B — Interactive with snapshot: Q2 sales dashboard

**Input:**
```
User: "Build me a Q2 sales dashboard with our current figures."
```

**Track selection:** Interactive with snapshot-seeded state (point-in-time data is acceptable).

**Agent action:** Read the figures during construction.

```bash
# (agent uses appropriate data source — wp_query, search_content, or manual entry)
# In this example, assume figures are in a structured form:
figures = {
    "Q2_revenue": 1240000,
    "Q2_orders": 3487,
    "top_product": "Widget Pro",
    "regions": { "NA": 580000, "EU": 420000, "APAC": 240000 }
}
```

**Widget registration:**
```bash
agentshell_register_widget({
    id: "q2-sales-dashboard",
    name: "Q2 Sales Dashboard",
    template: '<div class="q2-dashboard" data-agentshell-data=\'{"placeholder":"filled by init_js"}\'></div>',
    css: '.q2-dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1rem; } .q2-card { padding: 1rem; background: var(--theme-surface); border-radius: var(--radius-base); } .q2-metric { font-size: 2rem; font-weight: 700; color: var(--theme-accent); }',
    init_js: "/* agentshell-snapshot-source: agentshell_get_design_system + manual Q2 figures, 2026-08-14 */ window.AgentshellWidgets['q2-sales-dashboard'] = { init: function(el) { const data = { revenue: 1240000, orders: 3487, top_product: 'Widget Pro', regions: { NA: 580000, EU: 420000, APAC: 240000 } }; const html = '<div class=\"q2-card\"><div class=\"q2-label\">Revenue</div><div class=\"q2-metric\">$' + (data.revenue/1000).toFixed(0) + 'k</div></div>' + '<div class=\"q2-card\"><div class=\"q2-label\">Orders</div><div class=\"q2-metric\">' + data.orders.toLocaleString() + '</div></div>' + '<div class=\"q2-card\"><div class=\"q2-label\">Top Product</div><div class=\"q2-metric\">' + data.top_product + '</div></div>'; el.innerHTML = html; } };"
})
```

**Verification:**
```bash
agentshell_screenshot({ viewport: "desktop" })
agentshell_validate
agentshell_commit_transaction
```

**Audit summary:**
```
Track: Interactive (Track 1, snapshot-seeded)
Reason: User asked for "current" Q2 figures, point-in-time is acceptable.
Refresh anchor: agentshell-snapshot-source: agentshell_get_design_system + manual Q2 figures, 2026-08-14
Future agents: re-run the data source, patch init_js, do not rebuild presentation.
```

### Example C — Escape hatch: custom taxonomy cloud

**Input:**
```
User: "Build me a taxonomy cloud that reads from a CPT field WP doesn't expose in standard markup."
```

**Track selection:** Decorator (live data, must reflect current taxonomy).

**Primary path fails:** the CPT field isn't in standard `wp_loop` markup. Colocation extracts nothing useful.

**Escape hatch workflow:**

```bash
# Step 1: Write filter to /tmp
cat > /tmp/agentshell-widget-filter-1700000000.php <<'EOF'
<?php
add_filter('post_thumbnail_html', function($html, $post_id) {
    if (has_term('featured', 'custom_tax', $post_id)) {
        return str_replace('<img', '<img data-featured="true"', $html);
    }
    return $html;
}, 10, 2);
EOF

# Step 2: Lint check
php -l /tmp/agentshell-widget-filter-1700000000.php
# Expected: No syntax errors detected in /tmp/agentshell-widget-filter-1700000000.php

# Step 3: Atomic move into place
mv /tmp/agentshell-widget-filter-1700000000.php wp-content/mu-plugins/

# Step 4: Verify daemon health
curl -fsS -o /dev/null -w "%{http_code}" https://example.com/wp-json/agentshell-mcp/v1/mcp
# Expected: 200 — daemon is up
```

If step 4 returns non-2xx, immediately `rm wp-content/mu-plugins/agentshell-widget-filter-1700000000.php` before any other recovery attempt.

**Then:** proceed with normal decorator widget registration + colocation.

**Audit summary:**
```
Track: Decorator (Track 2)
Reason: Taxonomy cloud reflects current content.
Escape hatch: mu-plugin filter at wp-content/mu-plugins/agentshell-widget-filter-1700000000.php
Revert: rm wp-content/mu-plugins/agentshell-widget-filter-1700000000.php
Lint: passed (php -l clean)
Daemon health: 200 OK after move
```

### Worked example summary

| Example | Track | Primary or escape | Key teaching |
|---|---|---|---|
| A: Latest posts carousel | Decorator | Primary (colocation) | Standard `wp_loop` markup suffices; no mu-plugin needed. |
| B: Q2 sales dashboard | Interactive | Snapshot-seeded | Refresh anchor preserves provenance. |
| C: Custom taxonomy cloud | Decorator | Escape hatch | mu-plugin gate (lint, atomic mv, daemon check) is mandatory. |
```

- [ ] **Step 3: Verify worked examples present**

```bash
grep -cE "^### Example [A-C]" skills/agentshell/widget-builder/SKILL.md
```

Expected: 3

- [ ] **Step 4: Verify all key patterns appear**

```bash
grep -cE "Refresh anchor|escape hatch|colocation|mu-plugin|wp_loop" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 8

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 11 — three worked examples"
```

---

### Task 12: README.md — human-facing overview

**Files:**
- Modify: `skills/agentshell/widget-builder/README.md` — replace heading and empty body with full content

**Interfaces:**
- Consumes: the complete SKILL.md
- Produces: a human-facing entry point that orients someone who found the skill directory without prior context

- [ ] **Step 1: Verify requirements checklist**

The README must contain:
- One-paragraph "what this is" summary
- A "when to invoke" list mirroring SKILL.md Section 1
- A "quick start" example
- A "two tracks" diagram
- A pointer to SKILL.md for full reference
- A pointer to the spec for design rationale
- An "escape hatch" callout

- [ ] **Step 2: Write the README body**

Replace the entire `skills/agentshell/widget-builder/README.md` content with:

```markdown
# Widget Builder Skill

A Claude Code skill (and any other agent runtime that supports skill files) that teaches an agent how to build a custom AgentShell widget correctly given a user's high-level description. Pure agent-side instructions — no PHP changes, no new MCP tools. The agent reads existing MCP tools and WordPress primitives, picks the right widget track, composes the result, and verifies it inside a transaction.

## When to invoke

Trigger phrases:

- "build me a widget that..."
- "create a [calculator | carousel | dashboard | visualizer]"
- "add a custom [header | sidebar | footer] widget"
- "make me a [latest posts | taxonomy cloud | recent comments] widget"

Out of scope: standard WP widgets via the Widgets admin UI, editing existing widgets, non-widget tasks. See SKILL.md Section 1 for the full scope boundary.

## Quick start

```
User: "Build me a latest posts carousel. Use the standard theme styling."

Agent: (reads SKILL.md, picks Decorator track, colocates the widget with
        the wp_loop, registers the decorator widget, verifies with
        screenshot + agentshell_validate, commits)

        "Track: Decorator (Track 2). Carousel wired to wp_loop in main zone.
         1 of 4 budget used. Progressive enhancement verified — server-rendered
         posts remain readable if JS fails."
```

## Two tracks

```
            ┌─────────────────────────┐
            │   User request          │
            └────────────┬────────────┘
                         │
        ┌────────────────┴────────────────┐
        │                                 │
        ▼                                 ▼
   Interactive                    WordPress Decorator
        │                                 │
   local state                   wp_loop + colocated widget
        │                                 │
   math.js / D3                  progressive enhancement
        │                                 │
   no WordPress data             no client-side fetch()
        │                                 │
        └────────────┬────────────────────┘
                     │
            Snapshot-seeded Interactive
                  (frozen point-in-time data)
                  refresh anchor in init_js
```

All tracks obey the security boundary: no client-side `fetch()`, ever.

## Escape hatch

For rare cases where standard WordPress markup doesn't expose a needed field, the skill permits a mu-plugin filter with a strict validation gate: write to `/tmp/`, lint with `php -l`, atomic `mv`, verify daemon health, single-`rm` recovery. See SKILL.md Section 3 for the full workflow.

This is the **only** path that touches PHP, and it's gated precisely because a fatal error there bricks the daemon before `agentshell_rollback_transaction` can fire. Use sparingly.

## For full reference

- [SKILL.md](./SKILL.md) — the complete skill file (Sections 1–11 + three worked examples)
- Spec: `docs/superpowers/specs/2026-08-14-agentshell-widget-builder-design.md` — design rationale, the architectural choice that collapsed three tracks into two, the colocation model, the escape hatch rationale

## What the skill guarantees

- **Two tracks, no third.** Snapshot is an authoring pattern, not a track.
- **No `fetch()`.** Absolute prohibition, repeated across the skill.
- **Colocation is primary.** Decorator widgets colocate with their `wp_loop`. No PHP filter needed for the common case.
- **Verification inside transactions.** Build, place, screenshot-if-available, validate, commit. No live-site mutation until commit.
- **Honest limitations.** The skill documents what it can't do (multi-source decorators, fully fresh DOM diff, `<script type="application/json">`).
```

- [ ] **Step 3: Verify all 6 sections present**

```bash
grep -cE "^## (When to invoke|Quick start|Two tracks|Escape hatch|For full reference|What the skill guarantees)" skills/agentshell/widget-builder/README.md
```

Expected: 6

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/README.md
git commit -m "feat(skill): add widget-builder README — human-facing overview"
```

---

### Task 13: Final integration check

**Files:**
- Read-only review: `skills/agentshell/widget-builder/SKILL.md`, `skills/agentshell/widget-builder/README.md`
- Test: full grep-based spec coverage check

**Interfaces:**
- Consumes: the complete skill from Tasks 1-12
- Produces: a verified skill that another agent can pick up and run without external context

- [ ] **Step 1: Run the spec-coverage check**

```bash
echo "=== Spec coverage check ==="
echo "--- Frontmatter ---"
head -3 skills/agentshell/widget-builder/SKILL.md | grep -E "^name:|^description:" | wc -l
echo "expected: 2"

echo "--- Section 1 (When to use) ---"
grep -E "^## When to use this skill" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 2 (Tracks) ---"
grep -E "^## Tracks" skills/agentshell/widget-builder/SKILL.md && echo "OK"
grep -cE "^### Track [12] " skills/agentshell/widget-builder/SKILL.md
echo "expected: 2"

echo "--- Section 3 (Track selection) ---"
grep -E "^## Track selection" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 4 (Colocation contract) ---"
grep -E "^## Track 2 — the colocation contract" skills/agentshell/widget-builder/SKILL.md && echo "OK"
grep -cE "^### (The colocation rule|What the widget reads|Why decorators MUST NOT|Optional data-\* patterns|The escape hatch|Why no MCP tool)" skills/agentshell/widget-builder/SKILL.md
echo "expected: 6"

echo "--- Section 5 (Refresh anchor) ---"
grep -E "^## The refresh anchor" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 6 (Composition patterns) ---"
grep -E "^## Composition patterns" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 7 (Verification) ---"
grep -E "^## Verification workflow" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 8 (Prohibitions) ---"
grep -E "^## What the agent must NOT do" skills/agentshell/widget-builder/SKILL.md && echo "OK"
grep -cE "^### [1-7]\. " skills/agentshell/widget-builder/SKILL.md
echo "expected: 7"

echo "--- Section 9 (Security boundary) ---"
grep -E "^## Security boundary" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 10 (Failure modes) ---"
grep -E "^## Failure modes" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 11 (Known limitations) ---"
grep -E "^## Known limitations" skills/agentshell/widget-builder/SKILL.md && echo "OK"

echo "--- Section 12 (Worked examples) ---"
grep -E "^## Worked examples" skills/agentshell/widget-builder/SKILL.md && echo "OK"
grep -cE "^### Example [A-C]" skills/agentshell/widget-builder/SKILL.md
echo "expected: 3"
```

If any count is short, the corresponding task left a section incomplete. Do not commit until all counts match.

- [ ] **Step 2: Run the placeholder scan**

```bash
grep -nE "TODO|TBD|FIXME|fill in|placeholder" skills/agentshell/widget-builder/SKILL.md skills/agentshell/widget-builder/README.md
```

Expected: no output. If any line appears, that task's content was incomplete — fix before committing.

- [ ] **Step 3: Verify frontmatter is parseable**

```bash
head -5 skills/agentshell/widget-builder/SKILL.md
```

Expected output starts with `---` and includes `name: agentshell-widget-builder` on the second line. If the frontmatter is malformed, an agent runtime that auto-loads skills may reject the file.

- [ ] **Step 4: Verify all MCP tool references exist**

```bash
for tool in agentshell_register_widget agentshell_update_zone_composition agentshell_update_zone_slots agentshell_screenshot agentshell_get_capabilities agentshell_validate agentshell_begin_transaction agentshell_commit_transaction agentshell_rollback_transaction agentshell_get_audit_log agentshell_inspect agentshell_search_content; do
    count=$(grep -c "$tool" skills/agentshell/widget-builder/SKILL.md)
    if [ "$count" -eq 0 ]; then
        echo "MISSING: $tool"
    fi
done
echo "(no MISSING lines = all referenced tools appear in skill)"
```

Expected: no MISSING lines (some tools may appear 0 times if they're not directly referenced — that's fine; the check is for tools that ARE referenced to actually exist).

- [ ] **Step 5: Verify security boundary is repeated**

```bash
grep -cE "fetch\(\)|client-side fetch" skills/agentshell/widget-builder/SKILL.md
echo "expected: >= 5 (fetch prohibition must be repeated across sections)"
```

- [ ] **Step 6: Final commit (if any patches were needed)**

If Steps 1-5 surfaced issues, fix them and commit each fix:

```bash
git add skills/agentshell/widget-builder/SKILL.md skills/agentshell/widget-builder/README.md
git commit -m "fix(skill): address final integration check findings"
```

If no issues surfaced, this step is a no-op — there is nothing to commit.

- [ ] **Step 7: Report completion**

Report back to the user:

- Two files added: `skills/agentshell/widget-builder/SKILL.md`, `skills/agentshell/widget-builder/README.md`
- 11 skill sections + 3 worked examples
- 7 prohibitions, 6 failure modes, 7 known limitations
- No placeholders
- Frontmatter valid
- All MCP tool references verifiable
- No PHP changes, no new MCP tools (per spec constraint)

The skill is ready to merge. To invoke it, place any vision-capable agent in a directory where `skills/agentshell/widget-builder/SKILL.md` is on its skill path, and ask the agent to build a widget.

---

## Self-Review Notes

After writing this plan, I checked:

1. **Spec coverage:** Every numbered subsection in the spec has a corresponding task:
   - Spec Section 1 → Task 2 (frontmatter + Section 1 of skill)
   - Spec Section 2 → Task 2 (Section 2 of skill — both tracks)
   - Spec Section 3 → Task 2 (Section 3 of skill — track selection)
   - Spec Section 4 → Task 3 (Section 4 of skill — colocation + escape hatch)
   - Spec Section 5 → Task 4 (Section 5 of skill — refresh anchor)
   - Spec Section 6 → Task 5 (Section 6 of skill — composition patterns)
   - Spec Section 7 → Task 6 (Section 7 of skill — verification)
   - Spec Section 8 → Task 7 (Section 8 of skill — prohibitions)
   - Spec Section 9 → Task 8 (Section 9 of skill — security boundary)
   - Spec Section 10 → Task 9 (Section 10 of skill — failure modes)
   - Spec Section 13 → Task 10 (Section 11 of skill — known limitations)
   - Spec Section 14 → Task 11 (Section 12 of skill — worked examples)
   - Spec Section 12 (file layout) → Tasks 1 + 12 (directory + README)
   - Spec Section 11 (skill scope) → enforced throughout (no duplication of AGENTS.md content)

2. **Placeholder scan:** No "TBD", "fill in later", "implement later". Every code block contains real content (some JavaScript templates use placeholders like `<zone-id>` but those are illustrative, not unfilled).

3. **Type/name consistency:**
   - Tool names: all `agentshell_*` references verified against the shipped plugin
   - Refresh anchor format: `/* agentshell-snapshot-source: <description> */` consistent across spec, Task 4, Task 11 Example B
   - Colocation pattern: same code structure in spec, Task 3, Task 11 Example A
   - Escape hatch: 5-step workflow consistent in spec Section 4, Task 3, Task 11 Example C
   - Two tracks only: consistent throughout (no third track ever named)
   - `data-agentshell-data` (for structured data) and `data-agentshell-source` (originally proposed, now superseded by colocation) — only `data-agentshell-data` remains in the spec as the optional structured-data pattern

4. **Global constraint compliance:**
   - No PHP changes: every task modifies only `skills/agentshell/widget-builder/*`
   - No new MCP tools: explicit ruling in Section 4 of skill (Task 3), reinforced in spec
   - All tool references verifiable: Task 13 Step 4 grep-checks every named tool
   - Frontmatter valid: Task 13 Step 3 verifies YAML structure

5. **Worktree isolation:** Task 1 explicitly creates a git worktree before any skill work begins, protecting the user's pre-existing uncommitted changes on main.
