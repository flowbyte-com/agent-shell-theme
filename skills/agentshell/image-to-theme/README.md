# Image-to-Theme Skill

> **Pointer document.** The operative skill is [`SKILL.md`](./SKILL.md). The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol — is in [`AGENTS.md`](../../../AGENTS.md) at the theme root. This README is human orientation only.

A Claude Code skill (and any other agent runtime that supports skill files) that themes an AgentShell site to match a reference image. Pure agent-side instructions — no PHP changes, no new MCP tools. The agent uses its native vision to read a local image file, maps the visible aesthetic to the existing design schema via deterministic heuristic rules, and applies the result through the existing `agentshell_set_*` MCP tools inside a transaction.

## When to invoke

Trigger phrases:

- "make it look like this image"
- "theme the site based on this"
- "match the colors of X"
- "use agentshell to build something a bit like that"
- "make my site look like the reference"

Out of scope: structural composition, content generation, image editing. See `SKILL.md` for the full scope boundary.

## Architecture (one line)

The agent is the entire inference layer. AgentShell is the execution environment. No image bytes ever cross the daemon. The skill uses only existing `agentshell_*` tools; it does not propose new tools.

## For full reference

- [`SKILL.md`](./SKILL.md) — the operative skill file.
- `AGENTS.md` §2 (tool surface), §4 (widget / data-* law), §5 (grid), §6 (working pattern) at the theme root.

## Knobs (inherited by reference, not redefined here)

- `strict` / `pragmatic` / `expressive` — strictness modes (full text in `SKILL.md`).
- `max_attempts: 2 | 4 | 6` — iteration budget.
- Optional natural-language hints ("make the header dark", "airy", etc.).

For the canonical list of MCP tools, the bilateral widget registry, the Unbreakable Grid protocol, and the data-* / no-fetch laws, see `AGENTS.md`.
