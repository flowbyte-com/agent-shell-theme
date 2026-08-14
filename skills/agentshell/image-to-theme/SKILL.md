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

## Pipeline

Always run the full pipeline inside an `agentshell_begin_transaction` so the live site is never mutated until commit. Use the profile system as a per-attempt undo tree.

### Step 1 — Observe

Call `agentshell_inspect` to confirm current state, capabilities, and that `screenshot` is available. If screenshot is unavailable, note it — Step 5 falls back to `agentshell_preview_theme`.

### Step 2 — Begin transaction

Call `agentshell_begin_transaction`. Note the actor lock — if another agent owns an open transaction, stop and tell the user.

### Step 3 — Vision extraction

Read the local image file. Reason over the visible aesthetic and produce a candidate design object containing:

- `colors` — the 7 allowed keys: background, surface, text, border, accent, primary, secondary
- `typography` — fontFamily (CSS stack), mono, baseSize, scale
- `shape` — radius, borderWidth, borderStyle
- `spacing` — base unit

Apply the Section 4 heuristic rules. Honor any user hints from the Inputs.

### Step 4 — First attempt commit + profile

- Apply the extracted design via the semantic tools, all inside the open transaction:
  - `agentshell_set_palette({ colors: {...} })`
  - `agentshell_set_typography({ fontFamily, baseSize, scale, mono? })`
  - `agentshell_set_shape({ radius, borderWidth, borderStyle })`
  - `agentshell_set_spacing({ base })`
- Call `agentshell_save_theme_profile("candidate_v1")` — this is the recovery anchor. Always save, even if the design is mediocre.

### Step 5 — Render + screenshot

- Call `agentshell_preview_theme("candidate_vN")` to get a read-only preview URL. This always works, even without a headless browser.
- If `agentshell_get_capabilities` reports `screenshot: true`, additionally call:
  - `agentshell_screenshot({ viewport: "desktop" })`
  - `agentshell_screenshot({ viewport: "mobile" })`
- Both viewport captures matter. The reference is likely desktop-biased, and the mobile check verifies the theme doesn't break on small screens.

### Step 6 — Compare to reference

Inspect the captured images side-by-side with the reference. Judge:

- Palette closeness (does the dominant color match? does accent feel right?)
- Typography mood (does the type feel like the reference — serif/sans, weight, density?)
- Shape rhythm (does the radius feel right — sharp/minimal/rounded?)
- Spacing density (compact vs airy?)

Do NOT obsess over pixel-exact matches. Judge gestalt.

### Step 7 — Decide

Three outcomes per iteration:

- **A. Subjective match achieved.** Skip to Step 9.
- **B. Improvement possible.** Return to Step 3 with refined values. Save the new attempt as a new named profile `candidate_v(N+1)` — distinct names create distinct entries that coexist with prior profiles (the plugin stores profiles as a name-keyed list, not a mutating slot). Continue.
- **C. Regression or stuck.** Apply the previous best profile via `agentshell_apply_theme("candidate_vN-1")`, then return to Step 3 with a different angle.

### Step 8 — Iteration guard

- Default max attempts: **4**.
- If attempt 4 is reached without a clear subjective match: pick the best profile from your undo tree, apply it via `agentshell_apply_theme`, and proceed to Step 9. Do NOT loop a 5th time — the token cost outweighs the marginal improvement, and the model is likely overcorrecting.
- Early-abort conditions (Section 6) override the cap. If any abort condition fires, jump straight to Step 9 with rollback.

### Step 9 — Commit or rollback

- **Commit:** Call `agentshell_commit_transaction`. The chosen profile becomes live.
- **Rollback:** Call `agentshell_rollback_transaction` if total failure. Tell the user what went wrong and what was preserved.

### Audit + summary

After commit (or rollback), call `agentshell_get_audit_log` to surface the final change set. Report to the user:

- The chosen profile name (e.g. `candidate_v2`)
- One-line summary of the theme mood (e.g. "Moody dark navy with bright cyan accent — generous spacing")
- Number of iterations used (e.g. "2 of 4 budget")
- Anything the user should review manually
- The preview URL so the user can verify the result themselves
