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

