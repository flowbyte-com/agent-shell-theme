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
