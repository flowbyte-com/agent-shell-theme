### Task 1: Scaffold (directory + frontmatter)

**Files:**
- Create: `skills/agentshell/widget-builder/SKILL.md` (frontmatter only, body empty)
- Create: `skills/agentshell/widget-builder/README.md` (heading + empty body — T12 fills it)

**Interfaces:**
- Produces: the directory `skills/agentshell/widget-builder/` exists with a valid YAML frontmatter at the top of SKILL.md.

**Setup worktree first (mandatory):**

```bash
cd /home/v/workspace/projects/agent-shell-theme
git worktree add .worktrees/widget-builder-skill -b feat/widget-builder-skill
cd .worktrees/widget-builder-skill
```

The worktree protects the user's pre-existing uncommitted changes on main. All subsequent tasks run from `.worktrees/widget-builder-skill/`. The plan itself commits to main directly (it's a docs file), but the skill files live on the feature branch.

- [ ] **Step 1: Create worktree**

```bash
cd /home/v/workspace/projects/agent-shell-theme
git worktree add .worktrees/widget-builder-skill -b feat/widget-builder-skill
cd .worktrees/widget-builder-skill
```

- [ ] **Step 2: Create directory and frontmatter**

```bash
mkdir -p skills/agentshell/widget-builder
cat > skills/agentshell/widget-builder/SKILL.md <<'EOF'
---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget — phrases like "build me a calculator", "add a latest posts carousel", "create a sales dashboard". Teaches the agent to choose between two tracks (Interactive or WordPress Decorator), how to compose zone blocks, how to verify the result, and the security boundary that prohibits client-side fetch().
---
EOF
```

- [ ] **Step 3: Create empty README scaffold**

```bash
cat > skills/agentshell/widget-builder/README.md <<'EOF'
# Widget Builder Skill

EOF
```

- [ ] **Step 4: Verify frontmatter**

```bash
head -5 skills/agentshell/widget-builder/SKILL.md
```

Expected:
```
---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget ...
---
```

- [ ] **Step 5: Commit**

```bash
git add skills/agentshell/widget-builder/SKILL.md skills/agentshell/widget-builder/README.md
git commit -m "feat(skill): scaffold widget-builder skill directory"
```

---

