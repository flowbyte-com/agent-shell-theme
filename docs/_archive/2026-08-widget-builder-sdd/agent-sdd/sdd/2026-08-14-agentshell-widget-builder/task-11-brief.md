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

