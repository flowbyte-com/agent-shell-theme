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
