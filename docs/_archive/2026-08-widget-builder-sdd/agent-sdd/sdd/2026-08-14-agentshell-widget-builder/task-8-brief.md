### Task 8: Section 8 — Security boundary (no strictness modes)

**Files:**
- Modify: `skills/agentshell/widget-builder/SKILL.md` — append Section 8

**Interfaces:**
- Consumes: spec Section 9
- Produces: the section that explicitly diverges from image-to-theme by having no strictness modes

- [ ] **Step 1: Verify spec requirements checklist**

Re-read spec Section 9. The section must:
- State that no strictness knob exists
- Contrast with the image-to-theme skill's strict/pragmatic/expressive modes
- Explain why: the security boundary is absolute

- [ ] **Step 2: Append Section 8**

Append the following verbatim:

```markdown
## Security boundary (no strictness modes)

The widget-builder skill has **no strictness knob**. Unlike the image-to-theme skill (which has strict / pragmatic / expressive modes for heuristic mapping flexibility), this skill has one mode: **safe**.

Every output complies with the security boundary:

- No client-side `fetch()` — ever, in any mode
- No `<script>` injection — ever, in any mode
- No blind DOM scanning — ever, in any mode

There is no "expressive" escape hatch. The boundary is absolute and non-tunable. If the user asks for a widget that requires network access, the skill refuses and explains why — there is no setting that flips that off.

This is a deliberate divergence from image-to-theme. Heuristic mapping (colors, fonts, spacing) has a wide valid solution space and benefits from expressiveness. Widget construction has a narrow valid space constrained by the security boundary, and expressiveness in that space means bugs, not flexibility.

The security boundary is not a tunable preference. It is a load-bearing architectural constraint.
```

- [ ] **Step 3: Verify section content**

```bash
grep -cE "no strictness knob|absolute and non-tunable|deliberate divergence" skills/agentshell/widget-builder/SKILL.md
```

Expected: ≥ 3

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md
git commit -m "feat(skill): add widget-builder Section 8 — security boundary"
```

---

