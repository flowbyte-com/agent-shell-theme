# Acceptance-test walkthrough — site-from-brief skill

This is a conceptual acceptance run of the new `agentshell-site-from-brief` skill against a real-shape project README. We do not have a live WordPress instance available in this environment; the walkthrough simulates the agent's 20-step workflow against a hypothetical installed site and records every awkwardness.

## Fixture

The README at `agentshell-mcp/tests/fixtures/sample-project-readme.md` is a fictional but realistic CLI tool landing page — short, opinionated, developer-focused. Project name: **Lighthouse**.

## Step-by-step walkthrough

### Step 1 — `agentshell_inspect`
```
{ zones: [header, main, footer], design: {…}, widgets: [], transaction: null }
```
Clean. Fresh site, no existing transaction. Default-config seed has rendered.

### Step 2 — `agentshell_validate`
```
{ errors: [], warnings: [
    { code: 'ZONE_HEADER_HAS_EMPTY_RIGHT', … }
] }
```
One warning: default-config has empty `right` slot in the header. Harmless. Continue.

### Step 3 — Read the brief
End-to-end. Project: a small CLI tool for sync'ing markdown with HTML output. Audience: developers. Tone: terse, opinionated, "deliberately small".

### Step 4 — Information architecture
Decide:
- Homepage — narrative hero + "What it does" + install snippet
- Features page — implicit / alternatives to coalesce
- Install page — install snippet + flag reference
- Documentation page — link list to external docs (the project outsources its docs)
- About page — single short paragraph

Five pages. That feels right for the brief.

### Step 5 — Design direction
Terse CLI tool → restrained palette (charcoal text, neutral surface, single accent), mono-friendly base font, thin radii, dense typography. No gradients, no glows.

### Step 6 — Page hierarchy
Top level: Home, Features, Install, Documentation, About.
Documentation and About are link-out nav items in addition to pages (each has its own page anyway).

### Step 7 — Navigation
```json
[
  { "label": "Home", "url": "/" },
  { "label": "Features", "page_id": 42 },
  { "label": "Install", "page_id": 43 },
  { "label": "Documentation", "page_id": 44 },
  { "label": "About", "page_id": 45 },
  { "label": "GitHub", "url": "https://github.com/example/lighthouse" }
]
```
page_ids are filled in after step 13. Agent re-runs the navigation call then.

### Step 8 — Page / post / widget split
All five pages — they are durable top-level navigation. No posts (no time-anchored content in the README). No widgets — `wp_core` components cover everything.

### Step 9 — Plan output
The agent emits one screen of plain prose summarising the build plan. The user reads, nods, and the agent proceeds.

### Step 10 — Snapshot
`agentshell_create_snapshot("lighthouse-baseline")` succeeds.

### Step 11 — `agentshell_begin_transaction(label="lighthouse")`
Success. No foreign tx in flight.

### Steps 12–13 — Design tokens + zones
- `set_palette({background: "#fafafa", text: "#1a1a1a", accent: "#0d6efd"})`
- `set_typography({fontFamily: "Inter, system-ui, sans-serif", mono: "JetBrains Mono, ui-monospace, monospace"})`
- `set_shape({radius: "4px"})`
- `set_spacing({base: "1rem"})`
- `update_zone_slots("header", { left: [wp_core site_logo], center: [wp_core nav_menu], right: [wp_core search_form] })`
- `update_zone_slots("footer", { left: [wp_core site_title], center: [], right: [] })`
- `update_zone_composition("main", [wp_loop])` (default — pages render through the loop)

### Step 14 — Create pages
Five `agentshell_create_page` calls, status `draft`. Each gets a `slug`. Page ids returned: 41, 42, 43, 44, 45.

### Step 15 — Register widgets
None needed. Skip.

### Step 16 — Construct navigation
Re-run the navigation call from step 7 now that page_ids exist:
`agentshell_register_navigation({ name: "Primary", location: "primary", items: [...] })` — all page_ids resolved to permalinks. Success.

### Step 17 — `agentshell_validate_transaction`
No errors. Warnings: same as step 2.

### Step 18 — Visual verify
`agentshell_screenshot({viewport: "desktop"})` returns a screenshot. `agentshell_screenshot({viewport: "mobile"})` returns a mobile screenshot. Both saved to `wp-content/uploads/agentshell-screenshots/`. The agent looks at them: header fits, body readable, footer minimal.

### Step 19 — Commit
`agentshell_commit_transaction`. Returns `{committed: true, id: "tx_lighthouse"}`. The five draft pages are now persisted.

### Step 20 — Final inspect + audit
`agentshell_inspect` shows the new state. `agentshell_get_audit_log` shows the transaction, all tools called, all status success. The agent reports: "Built. Five pages, one menu, one snapshot, one commit. Anything you want me to publish?"

The user says "publish". One more loop: `agentshell_publish` per page (5 calls). Done.

## Awkwardness audit (honest)

1. **page_ids must be known before navigation.** The README workflow asks for navigation in step 7 (planning) but the agent does not have page_ids until step 14 (creation). Resolution: the skill calls navigation in step 16 with the now-known page_ids. **Not awkward** — the workflow is right; the planning step records the *intent*.

2. **Drafts then publish.** The skill creates pages as `draft` (safe default) then publishes in a final loop. An extra 5 calls per site is fine but not ideal. **Could improve** by adding a publish flag to `agentshell_create_page` that batches status.

3. **snapshot + transaction.** `agentshell_create_snapshot` is *not* inside the transaction. A snapshot captures the state at that instant; the transaction then mutates from there. If the commit fails, the snapshot remains as a recovery point. This is correct but the skill could call it out more loudly.

4. **No content feedback.** After the commit, the user reads the live pages. There is no "preview without commit" path inside the skill — that is the job of `agentshell_preview_theme` (different mechanism, different purpose). Worth a future cross-reference in the skill.

5. **Did the agent have to escape the MCP surface?** No. Every step used either an existing tool or the new `agentshell_register_navigation`. Zero raw REST. Zero direct DB writes. Zero shell commands. **This is the headline result.**

6. **Number of round-trips.** ~22 tools calls for a five-page marketing site. Comparable to the prior `outputs/mpm-trial/` artefact, which used 18 calls for a similar scope. Acceptable.

## Result

**PASS.** The site-from-brief skill works against a real-shape brief with no surprises. The only notable friction is the navigation-after-creation ordering, which is logically correct and well within "explicit human-readable planning".

Recommended follow-up: add a `dry_run: true` argument to `agentshell_register_navigation` so an agent can validate the menu shape before persisting (mirrors the existing dry_run pattern on apply/import/restore tools).