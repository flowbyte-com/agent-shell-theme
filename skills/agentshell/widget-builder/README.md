# Widget Builder Skill

> **Pointer document.** The operative skill is [`SKILL.md`](./SKILL.md). The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the 45+ tool count — is in [`AGENTS.md`](../../../AGENTS.md) at the theme root. This README is human orientation only.

A Claude Code skill (and any other agent runtime that supports skill files) that teaches an agent how to build a custom AgentShell widget correctly given a user's high-level description. Pure agent-side instructions — no PHP changes, no new MCP tools. The agent reads existing MCP tools and WordPress primitives, picks the right widget track, composes the result, and verifies it inside a transaction.

## When to invoke

Trigger phrases:

- "build me a widget that …"
- "create a [calculator | carousel | dashboard | visualizer]"
- "add a custom [header | sidebar | footer] widget"
- "make me a [latest posts | taxonomy cloud | recent comments] widget"

Out of scope: standard WP widgets via the Widgets admin UI, editing existing widgets, non-widget tasks. See `SKILL.md` for the full scope boundary.

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
        ▼                                 ▼
   Interactive                    WordPress Decorator
        │                                 │
   local state                   wp_loop + colocated widget
   math.js / D3                  progressive enhancement
   data-* attributes only        data-* attributes only
        │                                 │
        └────────────┬────────────────────┘
                     │
            Snapshot-seeded Interactive
                  (frozen point-in-time data)
                  refresh anchor in init_js
```

Both tracks obey the data-* hydration law and the no-fetch boundary — see `AGENTS.md` §4.2 / §4.3 and the operative skill.

## Escape hatch (last resort)

For rare cases where standard WordPress markup does not expose a needed field, the skill permits a mu-plugin filter with a strict validation gate: write to `/tmp/`, lint with `php -l`, atomic `mv`, verify daemon health, single-`rm` recovery. This is the only path that touches PHP, and it is gated precisely because a fatal error there bricks the daemon before `agentshell_rollback_transaction` can fire. Use sparingly. See `SKILL.md` for the full workflow.

## For full reference

- [`SKILL.md`](./SKILL.md) — the operative skill file (full track model, colocation, refresh anchor, composition, verification, prohibitions, failure modes, known limitations, worked examples).
- `AGENTS.md` at the theme root — canonical contracts: tool surface, bilateral widget registry, data-* / no-fetch, grid protocol, working pattern.

## What the skill guarantees

- **Two tracks, no third.** Snapshot is a pattern inside Interactive.
- **Data-* hydration only.** No `<script type="application/json">`.
- **No `fetch()`.** Absolute prohibition, inherited from `AGENTS.md` §4.3.
- **Bilateral widget registry.** File + config; config overrides file by id. See `AGENTS.md` §4.1.
- **Verification inside transactions.** Build, place, screenshot-if-available, validate, commit. No live-site mutation until commit.
- **Honest limitations.** The skill documents what it cannot do (multi-source decorators, fresh DOM diff, `<script type="application/json">`).

For the canonical contracts — including the 45+ MCP tool surface, the architecture, and the Unbreakable Grid protocol — see `AGENTS.md`.
