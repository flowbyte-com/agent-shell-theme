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

