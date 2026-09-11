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

