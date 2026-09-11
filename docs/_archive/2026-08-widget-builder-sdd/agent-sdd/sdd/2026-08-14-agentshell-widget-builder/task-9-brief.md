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

