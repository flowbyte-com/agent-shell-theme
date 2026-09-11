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

