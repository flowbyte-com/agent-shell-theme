---
name: agentshell
description: Namespace index for AgentShell skills. Routes work to the canonical guide (AGENTS.md) and to the operative skills (image-to-theme, widget-builder). Use when an agent task touches the AgentShell WordPress theme.
---

# agentshell (skill index)

> **Pointer file.** This skill is a routing index, not a contract. The canonical agent guide is [`AGENTS.md`](../../../AGENTS.md) at the theme root. Every tool name, every architecture diagram, and every working-pattern line lives there. This skill never duplicates them.

## When to use this index

Use this skill only as a routing dispatch when the agent runtime supports skill files. The runtime will then pick up the operative skill for the specific task. If the runtime loads multiple skills at once, the canonical guide (`AGENTS.md`) wins on any conflict.

## Operative skills

| Skill | Triggers | Purpose | File |
|---|---|---|---|
| `agentshell-image-to-theme` | "theme this site to match an image", "match these colors" | Apply a reference image's design tokens to the live site | `skills/agentshell/image-to-theme/SKILL.md` |
| `agentshell-widget-builder` | "build me a widget that …", "add a custom carousel / calculator / dashboard" | Build a custom widget using the bilateral registry and the data-* / no-fetch laws | `skills/agentshell/widget-builder/SKILL.md` |

## What this index intentionally does not contain

- The MCP tool list (`AGENTS.md` §2 is the only source).
- The architecture diagram (`AGENTS.md` §1 is the only source).
- The working pattern / transaction loop (`AGENTS.md` §6 is the only source).
- A tool count. The count is "45+" — see `AGENTS.md` §2.
- A "wp_options is the sole source of truth" claim. The bilateral widget registry is the union source — see `AGENTS.md` §4.1.

## Hard rules (inherited by reference)

Any operative skill that runs under this namespace must inherit, without restating:

1. **Bilateral widget registry** — file-based + config-registered, merged, with config overriding file by id (`AGENTS.md` §4.1).
2. **Data-* hydration only** — `<script type="application/json">` is forbidden (`AGENTS.md` §4.2).
3. **No client-side `fetch()`** — absolute, no exceptions (`AGENTS.md` §4.3).
4. **Two widget tracks** — Interactive + WordPress Decorator; snapshot is a pattern inside Interactive, not a third track (`AGENTS.md` §4.4).
5. **Unbreakable Grid protocol** — 1fr rule, descendant scope on `<body>`, quoted rows in `grid-template-areas` (`AGENTS.md` §5).
6. **45+ MCP tools** — the canonical count (`AGENTS.md` §2).

For everything else, including install, auth, plugin internals, and the historical record, see `AGENTS.md` and the docs/_archive/ subdirectory.
