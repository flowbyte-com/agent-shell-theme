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

## Heuristic mapping: image → design tokens

Translate what you see into the exact shape of `config['design']` consumed by the existing MCP tools. Apply these rules in order. Do not invent values outside the allowed tokens.

### 4.1 — Allowed token space

These are the only keys the schema accepts. Any other key in your output is an error:

**Colors** (`agentshell_set_palette`): `background`, `surface`, `text`, `border`, `accent`, `primary`, `secondary`

**Typography** (`agentshell_set_typography`): `fontFamily` (CSS font stack), `mono`, `baseSize`, `scale`

**Shape** (`agentshell_set_shape`): `radius`, `borderWidth`, `borderStyle`

**Spacing** (`agentshell_set_spacing`): `base`

If you need a value the schema does not accept, use `--theme-*` CSS variables via `agentshell_set_css_var` instead (e.g. `--theme-header-bg`, `--theme-footer-bg`, `--theme-header-text`).

### 4.2 — Color extraction (the contrast-pair rule)

Extract colors in this order. Each step depends on the previous.

**Step A — background.**
The dominant non-text, non-figure color covering the largest contiguous area. For a full-bleed hero this is the page background. For a UI mockup it's the canvas color.

**Step B — surface.**
The second-most-prominent flat color, OR a deterministic offset of background:

```
luminance = 0.2126·R_lin + 0.7152·G_lin + 0.0722·B_lin
where component_lin = ((c/255 + 0.055)/1.055)^2.4  if c/255 > 0.03928
                     else (c/255)/12.92

if luminance < 0.4:        surface = background.lightness(+6%)
else if luminance > 0.6:   surface = background.lightness(-4%)
else:                      surface = background (no offset, mid-tone)
```

Use whichever is more visible in the reference image. If both are present, the explicit one wins.

**Step C — text.**
The most readable color against background. If the reference shows legible text, use its color. Otherwise:

```
if background luminance < 0.4:  text = #f8fafc  (slate-50)
else:                            text = #0f172a  (slate-900)
```

**Step D — border.**
A subtle separator tone. Default to a deterministic offset of background:

```
if background luminance < 0.4:  border = background.lightness(+12%)
else:                            border = background.lightness(-16%)
```

Cap border saturation at 10% to avoid clashing separators.

**Step E — accent.**
The most saturated hue in the image that isn't already assigned to background, surface, text, or border. Compute saturation as `max(R,G,B) - min(R,G,B)` per pixel, find the peak-saturated pixel cluster, take its median hue. If no clearly saturated color exists, neutralize the dominant hue by reducing saturation to 40% and use that. Never invent a hue not present in the image.

**Step F — primary.**
The dominant brand-like color if visible (logo, hero accent). If absent, reuse accent.

**Step G — secondary.**
A complementary or muted partner to primary. If absent, reuse primary at 60% lightness.

### 4.3 — Contrast safety net

Before committing, run this check on every color pair that will be adjacent on screen:

```
contrast_ratio = (L_lighter + 0.05) / (L_darker + 0.05)
```

Pairs that must pass WCAG AA (4.5:1 for body text, 3:1 for large text/UI):

- text on background
- text on surface
- accent on background (for links/buttons — 3:1 acceptable)

If a pair fails, adjust the failing token using the same offset logic (lighter text on dark bg, darker text on light bg) and re-validate. Do NOT commit a theme where body text fails 4.5:1.

### 4.4 — Typography inference

**fontFamily.** Categorize the visible type as one of:

- Sans-serif humanist (e.g. Inter, SF Pro) → `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
- Sans-serif geometric (e.g. Futura, Avenir) → `"Futura", "Avenir Next", system-ui, sans-serif`
- Serif transitional (e.g. Times-like) → `Georgia, "Times New Roman", serif`
- Serif modern (e.g. Didone-like) → `"Bodoni Moda", "Didot", serif`
- Mono (e.g. code/terminal aesthetic) → `"JetBrains Mono", "Fira Code", ui-monospace, monospace`

Pick the closest match. When uncertain between humanist and geometric, default to humanist — it pairs better with body text.

**mono.** If the reference shows any mono-styled text (code blocks, captions, terminal), set mono to a clear monospace stack. Otherwise leave unchanged.

**baseSize.** 16px default. Bias up to 18px if the reference is generous/airy. Bias down to 14px if it's compact/dense.

**scale.** 1.25 default. Higher (1.333, 1.414) for editorial/serif. Lower (1.125) for compact/dense.

### 4.5 — Shape inference

**radius.** Classify the corners in the reference:

- All sharp (no visible rounding) → `0`
- Slight rounding (chips, cards) → `0.25rem`
- Medium rounding (modern web) → `0.5rem`
- Heavy rounding (friendly/playful) → `1rem`
- Pill/circular (callouts, badges) → `9999px`

Pick the mode that dominates. One outlier does not change the rule.

**borderWidth.** `1px` default. Visible hairlines → `0.5px`. Heavy borders → `2px`.

**borderStyle.** `solid` default. Dashed/dotted only if explicitly visible.

### 4.6 — Spacing density

Classify the whitespace pattern:

- Compact (tight grids, dashboards) → base = `0.5rem`
- Normal → base = `1rem` (default)
- Generous (editorial, landing pages) → base = `1.5rem`
- Airy (luxury, sparse) → base = `2rem`

### 4.7 — What NOT to invent

- Never pick a hex value not derived from the image or the offset rules above
- Never invent font names that aren't in the standard CSS stack (unless `expressive` strictness is set)
- Never set radius above `1rem` unless the reference is explicitly pill-shaped throughout (or `expressive` is set, allowing up to `2rem`)
- Never set borderWidth above `2px` — heavy borders read as broken, not designed
- Never set spacing.base above `2rem` (or `3rem` under `expressive`) — produces layouts that feel broken
