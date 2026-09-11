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

