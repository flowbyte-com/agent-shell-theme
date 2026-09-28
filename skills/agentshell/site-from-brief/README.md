# agentshell-site-from-brief

> **Pointer document.** The operative skill is [`SKILL.md`](./SKILL.md). This file is a human overview of when to reach for the skill and what to expect.

## What this skill does

Guides an agent through taking a project brief, README, or set of documents and producing a finished AgentShell site — information architecture, navigation, design tokens, content pages, all in one transaction. It composes existing `agentshell_*` MCP tools; it does not add new tools.

## When to reach for it

- "Build a website from this README."
- "Turn these project docs into a website."
- "Create a site for this project."
- "Make me a landing page for this product."

## What it explicitly does not do

- Propose new MCP tools.
- Edit the grid or the shell.
- Bypass the capability boundary.
- Map every README heading to a page.
- Force the user to answer twenty design questions.
- Write `<script type="application/json">` or call `fetch()`.

## What it leans on

- The canonical agent contract in [`AGENTS.md`](../../../AGENTS.md).
- The existing tool surface (configuration / transactions / revisions / snapshots / design tokens / content primitives / widget lifecycle / screenshot).
- The bilateral widget registry (file widgets + config widgets, merged).

For the full task contract, the failure-mode table, and the verification checklist, see [`SKILL.md`](./SKILL.md).