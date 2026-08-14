---
name: agentshell-widget-builder
description: Use when the user asks to build a custom AgentShell widget — phrases like "build me a calculator", "add a latest posts carousel", "create a sales dashboard". Teaches the agent to choose between two tracks (Interactive or WordPress Decorator), how to compose zone blocks, how to verify the result, and the security boundary that prohibits client-side fetch().
---

## When to use this skill

**Trigger phrases:**
- "build me a widget that..."
- "create a [calculator | carousel | dashboard | visualizer]"
- "add a custom [header | sidebar | footer] widget"
- "make me a [latest posts | taxonomy cloud | recent comments] widget"

**Two tracks:**
- **Interactive** — self-contained applications: calculators, simulators, visualizers, configurators. No WordPress data required.
- **WordPress Decorator** — progressively enhances WordPress-rendered content (posts, pages, taxonomy) into a custom presentation.

**Out of scope (do not invoke this skill for these):**
- Building standard WP widgets via the Widgets admin UI — those don't need agent help
- Editing an existing widget's behavior — that's a code review task, not a builder task
- "Just look at this DOM and tell me what you see" — that's a general inspection task, not a builder task

If the user's request mixes widget-building with another intent (e.g. "build me a widget AND pick a theme that matches"), do the widget part and stop — ask the user before attempting the rest.

## Tracks

There are exactly **two tracks**. Snapshot-style widgets are an authoring pattern inside the Interactive track, not a third track.

### Track 1 — Interactive

```text
init(el)
   ↓
local state
   ↓
window.math / window.d3
   ↓
DOM
```

No WordPress data required. Self-contained applications. The agent reads no WP data and the widget performs zero network requests.

A "snapshot widget" (frozen Q2 figures, a curated post list, a one-off dashboard) is just an Interactive widget whose initial state was seeded by the agent during construction. The execution model is identical.

### Track 2 — WordPress Decorator

```text
wp_loop / wp_core / wp_widget_area
       ↓
server-rendered HTML
       ↓
widget init(el)
       ↓
progressive enhancement
```

The widget progressively enhances already-rendered WordPress content. WordPress owns data; the widget owns presentation.

**Hard rule:** Client-side `fetch()` is prohibited under any circumstances. This is the security boundary, not a tunable preference. No exceptions for "WordPress REST API only" — the prohibition is absolute.

**Hard rule:** A decorator widget MUST degrade to usable server-rendered content if JavaScript fails. If the carousel explodes, the user still has the posts.

## Track selection

### Decision tree

```text
Does the widget require WordPress/site data?

├── No
│   └── Interactive
│       └── local state + math/d3 + DOM
│
└── Yes
    │
    ├── Must it reflect current site content (changes between page loads)?
    │   └── Decorator
    │       └── server-rendered DOM + colocated widget
    │
    └── Is point-in-time data acceptable?
        └── Interactive (snapshot-seeded)
            └── agent reads data → embeds in init_js
            └── leaves a refresh-anchor comment for future agents
```

### The decisive question

> **Does the widget need to remain correct when the underlying data changes without the agent rebuilding it?**

- Yes → Decorator
- No, point-in-time is fine → Interactive with seeded snapshot
- No data needed → plain Interactive

### Least-powerful-track principle

When the requirements are ambiguous, choose the **least powerful track that satisfies the requirement**. Move right only when the requirements actually demand it.

This prevents "live" from becoming the default merely because it sounds more impressive.

### Visible reasoning

The agent must report the chosen track to the user with a one-line justification:

```
Track: Decorator
Reason: Widget displays WordPress posts that may change between page loads.
```

This gives the user visibility without forcing them to remember a `track:` prefix syntax.

### User override

If the user explicitly says "snapshot" / "frozen" / "static" / "embedded", the agent treats that as a request for the Interactive track with snapshot-seeded state — the architectural fit for frozen point-in-time data. If the user says "interactive" / "calculator" / "self-contained", route to plain Interactive. If the user says "live" / "decorator" / "current posts", route to Decorator.

In all cases the agent still picks the implementation; the user can override the track choice. The skill never uses client-side `fetch()` to satisfy any of these requests — that path is closed regardless of what the user says.
