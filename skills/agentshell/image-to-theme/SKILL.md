---
name: agentshell-image-to-theme
description: Use when the user provides a reference image (file path or URL) and asks to theme their AgentShell site to match — phrases like "use agentshell to build something a bit like that", "match this image", "theme it like the reference". Routes visual extraction through the calling agent's vision capability, then applies the result via the existing agentshell MCP tools (no image bytes ever cross the daemon). For design tokens only (colors, typography, shape, spacing) — not structural layout.
---

## When to use this skill

**Trigger phrases:**
- "make it look like this image"
- "theme the site based on this"
- "match the colors of X"
- "use agentshell to build something a bit like that"
- "make my site look like the reference"

**Out of scope (do not invoke this skill for these):**
- Structural composition, zone layout, or widget selection — this skill handles design tokens only
- Content generation that mimics the image (e.g. "write copy like a cyberpunk cafe")
- Image editing or generation
- "Just look at this image and tell me what you see" — that's a general vision task, not a theming task

If the user's request mixes theming with another intent (e.g. "theme the site AND pick a layout that matches"), do the theming part and stop — ask the user before attempting the rest.

## Inputs

The skill accepts a reference image. Pick the most direct path the user provides.

### Primary: local file path

If the user gives a path (e.g. `~/refs/cyberpunk-cafe.png`), read it directly with the `Read` tool — vision analysis runs in this session's context.

### Fallback: URL

If only a URL is given, download it with the bash tool into a deterministic temp path:

```bash
curl -fsSL -o /tmp/agentshell-reference-<hash>.<ext> "<URL>"
```

Derive `<hash>` from a stable hash of the URL:

```bash
HASH=$(echo -n "<URL>" | md5sum | cut -c1-12)
```

Pick `<ext>` from the URL or the `Content-Type` header (`.png`, `.jpg`, `.webp`, `.gif`). Then `Read` the local file. The agent's vision capability analyzes the file regardless of which path produced it.

Do NOT rely on a generic "web fetch" abstraction — be explicit with `curl` so the action is reproducible across runs and visible in transcripts.

### Optional hints

The user may pass freeform guidance that biases the extraction. Surface these to the extraction step as named biases; never as hard overrides that would invent tokens the image can't support:

- "make the header dark like this"
- "match the accent only, keep the rest"
- "airy, lots of whitespace"
- "match the mood, not the literal colors"

If hints contradict the image gestalt, hint wins — but apply the hint narrowly (Section 6.5 in the spec covers the priority rules).

### Strictness knob

Default: `pragmatic`. The user can override via natural-language phrases parsed by the agent:

- "strict" / "match only what's clearly visible" → `strict`
- (no specification, or "match the gestalt") → `pragmatic` (default)
- "expressive" / "use your judgment" → `expressive`

How strictness maps to heuristic behavior:

- **`strict`** — Skip the contrast-pair fallback. If the image has no clearly visible text/surface/border, those tokens are LEFT UNCHANGED rather than derived. Only background and accent (if visible) are set. This produces the most literal match and the most "flat" theme — warn the user.
- **`pragmatic`** (default) — Apply the contrast-pair rule fully. Best balance for most runs.
- **`expressive`** — Loosen the "what NOT to invent" rules: allow font names outside the standard CSS stack, allow radius up to `2rem`, allow spacing.base up to `3rem`. Document the loosened values in the audit summary so the user can spot them.

If the user does not specify, use `pragmatic`.
