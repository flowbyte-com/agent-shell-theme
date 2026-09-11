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

