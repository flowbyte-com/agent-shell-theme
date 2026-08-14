# Image-to-Theme Skill

A Claude Code skill (and any other agent-runtime that supports skill files) that themes an AgentShell site to match a reference image. Pure agent-side instructions — no PHP changes, no new MCP tools. The agent uses its native vision to read a local image file, maps the visible aesthetic to the existing design schema via deterministic heuristic rules, and applies the result through the existing `agentshell_set_*` MCP tools inside a transaction.

## When to invoke

Trigger phrases:

- "make it look like this image"
- "theme the site based on this"
- "match the colors of X"
- "use agentshell to build something a bit like that"
- "make my site look like the reference"

Out of scope: structural composition, content generation, image editing. See SKILL.md Section 1 for the full scope boundary.

## Quick start

```
User: "Theme the site like /tmp/refs/cyberpunk-cafe.png — keep it moody but readable."

Agent: (reads SKILL.md, follows the 9-step pipeline, applies the theme,
        reports back: "Moody dark navy with bright cyan accent — 2 of 4
        iterations used, full audit in agentshell_get_audit_log.")
```

## Architecture

```
Image (local file or URL)
       ↓
Claude Code session (Read + vision)
       ↓
Heuristic mapping (Section 4 in SKILL.md)
       ↓
MCP tools (agentshell_begin_transaction → set_palette / set_typography /
          set_shape / set_spacing → save_theme_profile → screenshot →
          commit_transaction)
       ↓
WordPress wp_options['agentshell_config']
```

The agent is the entire inference layer. AgentShell is the execution environment. No image bytes ever cross the daemon.

## For full reference

- [SKILL.md](./SKILL.md) — the complete skill file (Sections 1–7 + worked example)
- Spec: `docs/superpowers/specs/2026-08-14-agentshell-image-to-theme-design.md` — design rationale, open questions, related documents

## Knobs

- `strict` / `pragmatic` / `expressive` — strictness modes (see SKILL.md Section 2)
- `max_attempts: 2` / `4` / `6` — iteration budget (see SKILL.md Section 5)
- Optional hints: "make the header dark", "match the accent only", "airy", etc. — see SKILL.md Section 2
