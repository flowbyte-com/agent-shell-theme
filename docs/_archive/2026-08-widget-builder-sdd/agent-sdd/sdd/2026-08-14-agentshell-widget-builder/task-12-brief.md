### Task 12: README.md — human-facing overview

**Files:**
- Modify: `skills/agentshell/widget-builder/README.md` — replace heading and empty body with full content

**Interfaces:**
- Consumes: the complete SKILL.md
- Produces: a human-facing entry point that orients someone who found the skill directory without prior context

- [ ] **Step 1: Verify requirements checklist**

The README must contain:
- One-paragraph "what this is" summary
- A "when to invoke" list mirroring SKILL.md Section 1
- A "quick start" example
- A "two tracks" diagram
- A pointer to SKILL.md for full reference
- A pointer to the spec for design rationale
- An "escape hatch" callout

- [ ] **Step 2: Write the README body**

Replace the entire `skills/agentshell/widget-builder/README.md` content with:

```markdown
# Widget Builder Skill

A Claude Code skill (and any other agent runtime that supports skill files) that teaches an agent how to build a custom AgentShell widget correctly given a user's high-level description. Pure agent-side instructions — no PHP changes, no new MCP tools. The agent reads existing MCP tools and WordPress primitives, picks the right widget track, composes the result, and verifies it inside a transaction.

## When to invoke

Trigger phrases:

- "build me a widget that..."
- "create a [calculator | carousel | dashboard | visualizer]"
- "add a custom [header | sidebar | footer] widget"
- "make me a [latest posts | taxonomy cloud | recent comments] widget"

Out of scope: standard WP widgets via the Widgets admin UI, editing existing widgets, non-widget tasks. See SKILL.md Section 1 for the full scope boundary.

## Quick start

```
User: "Build me a latest posts carousel. Use the standard theme styling."

Agent: (reads SKILL.md, picks Decorator track, colocates the widget with
        the wp_loop, registers the decorator widget, verifies with
        screenshot + agentshell_validate, commits)

        "Track: Decorator (Track 2). Carousel wired to wp_loop in main zone.
         1 of 4 budget used. Progressive enhancement verified — server-rendered
         posts remain readable if JS fails."
```

## Two tracks

```
            ┌─────────────────────────┐
            │   User request          │
            └────────────┬────────────┘
                         │
        ┌────────────────┴────────────────┐
        │                                 │
        ▼                                 ▼
   Interactive                    WordPress Decorator
        │                                 │
   local state                   wp_loop + colocated widget
        │                                 │
   math.js / D3                  progressive enhancement
        │                                 │
   no WordPress data             no client-side fetch()
        │                                 │
        └────────────┬────────────────────┘
                     │
            Snapshot-seeded Interactive
                  (frozen point-in-time data)
                  refresh anchor in init_js
```

All tracks obey the security boundary: no client-side `fetch()`, ever.

## Escape hatch

For rare cases where standard WordPress markup doesn't expose a needed field, the skill permits a mu-plugin filter with a strict validation gate: write to `/tmp/`, lint with `php -l`, atomic `mv`, verify daemon health, single-`rm` recovery. See SKILL.md Section 3 for the full workflow.

This is the **only** path that touches PHP, and it's gated precisely because a fatal error there bricks the daemon before `agentshell_rollback_transaction` can fire. Use sparingly.

## For full reference

- [SKILL.md](./SKILL.md) — the complete skill file (Sections 1–11 + three worked examples)
- Spec: `docs/superpowers/specs/2026-08-14-agentshell-widget-builder-design.md` — design rationale, the architectural choice that collapsed three tracks into two, the colocation model, the escape hatch rationale

## What the skill guarantees

- **Two tracks, no third.** Snapshot is an authoring pattern, not a track.
- **No `fetch()`.** Absolute prohibition, repeated across the skill.
- **Colocation is primary.** Decorator widgets colocate with their `wp_loop`. No PHP filter needed for the common case.
- **Verification inside transactions.** Build, place, screenshot-if-available, validate, commit. No live-site mutation until commit.
- **Honest limitations.** The skill documents what it can't do (multi-source decorators, fully fresh DOM diff, `<script type="application/json">`).
```

- [ ] **Step 3: Verify all 6 sections present**

```bash
grep -cE "^## (When to invoke|Quick start|Two tracks|Escape hatch|For full reference|What the skill guarantees)" skills/agentshell/widget-builder/README.md
```

Expected: 6

- [ ] **Step 4: Commit**

```bash
git add skills/agentshell/widget-builder/README.md
git commit -m "feat(skill): add widget-builder README — human-facing overview"
```

---

