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

## Iteration controls

The pipeline has explicit iteration guards. This section explains the knobs and the rationale so you understand *why* the cap exists and how to tune it when the user asks.

### 5.1 — Default behavior

- Max attempts: **4**
- Stop condition: subjective match (Step 7A) OR budget reached, whichever first
- At attempt 4: mandatory pick-best-and-commit. Never loop a 5th time.

### 5.2 — Why a hard cap

Two reasons that compound:

1. **Token cost.** Each iteration is one vision read of the reference + one vision read of the screenshot + 4 MCP calls + reasoning. Beyond 4 rounds, the marginal quality gain is tiny relative to spend.
2. **Model overcorrection.** Vision LLMs tend to chase diminishing differences after the third pass — they tighten a hue, then re-tighten, then drift away from gestalt. The 4th attempt is usually worse than the 2nd on gestalt match, even if pixel-closer.

### 5.3 — Tuning knobs

The user can override at invocation via natural-language phrases parsed by the agent:

- `max_attempts: 2` — phrases like "just give me a quick theme", "two attempts max", "don't iterate much"
- `max_attempts: 4` — default; no special phrasing needed
- `max_attempts: 6` — phrases like "take your time", "iterate until close", "I have budget"
- `strict_budget: true` — phrases like "always run the full budget", "don't stop early" (rare; useful for A/B testing)

The agent must parse these from the user's invocation message. If ambiguous, default to `max_attempts: 4` and `strict_budget: false`.

### 5.4 — The profile undo tree

Each attempt produces a distinct named profile. The plugin stores profiles as a list keyed by name (not as a single mutating slot), so distinct names create distinct entries that coexist with prior profiles — `candidate_v1` and `candidate_v2` stay as separate entries you can switch between.

Saving convention:

- `agentshell_save_theme_profile("candidate_v1")` after attempt 1
- `agentshell_save_theme_profile("candidate_v2")` after attempt 2
- `agentshell_save_theme_profile("candidate_v3")` after attempt 3
- ...

Backtrack mechanic:

- `agentshell_apply_theme("candidate_v2")` restores v2's tokens into the live transaction — use this when attempt 3 regressed and you want to branch from v2 in a different direction.
- `agentshell_diff_snapshot` between any two profiles to inspect what changed.
- `agentshell_list_theme_profiles` to see the full list of saved attempts.

Keep at most the last 3 candidate profiles named. Older candidates may be left in the list harmlessly; the agent simply ignores them. If the user has unrelated named profiles (e.g. "terminal", "brutalist"), those are independent — do not overwrite or reuse those names.

### 5.5 — Mandatory commit at attempt 4

When the budget is reached without a clear subjective match:

1. Compare the last 3 screenshots to the reference.
2. Pick the one that best matches gestalt (palette mood + type feel + rhythm — not pixel match).
3. `agentshell_apply_theme("<best-profile>")` to restore that profile into the live transaction.
4. Proceed to `agentshell_commit_transaction`.
5. Report to the user: "Reached iteration budget. Committed best match: <profile>. If you want closer, re-run with `max_attempts: 6` or refine the reference image."

Do NOT silently pick. Do NOT keep iterating. Do NOT rollback without telling the user.

### 5.6 — Early abort conditions

Stop and consider rollback (without committing) if any of these occur:

- Screenshot backend is unavailable AND `preview_theme` URL is unreachable for 3 consecutive attempts
- The reference image is unreadable (corrupt, empty, <100px on either axis) — **PAUSE here, do NOT rollback immediately.** Ask the user for a new file path or URL. If the user provides one, restart from Step 3 with the new image — the open transaction remains valid. Only rollback if the user cannot provide a replacement.
- Every attempt since v1 has made the gestalt match worse — the agent is overcorrecting and there is no recoverable trajectory. After attempt 3, if match score is monotonically degrading, abort.

In all abort cases, report the cause and what was preserved (no live site mutation occurred — the transaction was rolled back, not committed, and the saved candidate profiles remain queryable).

### 5.7 — Per-iteration logging

Each iteration writes to the audit log via `agentshell_set_*` calls — these are automatically recorded. The agent does NOT need a separate logging tool. Just ensure each `set_palette` / `set_typography` / `set_shape` / `set_spacing` call happens inside the open transaction so the audit trail is atomic.

If the user asks "what changed across iterations", call `agentshell_list_revisions` scoped to the current transaction or call `agentshell_diff_snapshot` between two saved profiles.

## Failure modes and fallbacks

The pipeline assumes a best-case world: image is readable, screenshot backend is up, no concurrent transactions. Real runs hit edge cases. This section tells you exactly what to do when reality diverges.

### 6.1 — Image issues

**Unreadable / corrupt file.** Detect by attempting Read — if the result is a binary blob or an error, the image is unusable. PAUSE and ask the user (Section 5.6). Do not invent a theme from a non-image.

**Too small (< 100px on either axis).** Same as unreadable — too low resolution to reason about aesthetic. PAUSE.

**Very dark / very bright dominant.** Not a failure — the contrast-pair rule (4.2) handles it. Just confirm the resulting text/background pair passes WCAG AA (4.3) before commit.

**Monochrome (no chromatic accent).** The accent extraction in 4.2 falls back to neutralizing the dominant hue at 40% saturation. Document this fallback in the audit summary so the user knows the theme is intentionally low-color.

**Photograph, not UI mockup.** If the reference is a photo (cityscape, portrait, landscape), do NOT attempt to treat the photo's natural colors as the UI palette. Ask the user: "This looks like a photograph rather than a UI mockup. Would you like me to extract a palette inspired by it (using the dominant hues), or do you have a UI reference you'd prefer?"

### 6.2 — Screenshot backend unavailable

If `agentshell_get_capabilities` reports `screenshot: false`:

- Continue the pipeline, but skip visual comparison (Step 6).
- Judge quality by reading the diff between candidate profiles via `agentshell_diff_snapshot` — tokens only, no visual.
- Apply the contrast-safety net (4.3) more aggressively — without visual confirmation, every WCAG pair must pass at AA, not just body text.
- Tell the user in the summary: "Screenshot backend unavailable on this server — theme applied without visual verification. Review the live site manually."

If `screenshot` returns an error (transient: Chrome crashed, OOM, etc.), retry once. If it fails again, fall back to diff-based judging.

### 6.3 — Off-schema tokens

The schema is strict. If your vision analysis produces a token not in the allowed list:

- For colors: map to the nearest allowed key. E.g. if you want to set "warning: yellow", that's not a valid key — drop it or absorb into accent.
- For typography: if you want `lineHeight`, that's not allowed — use `scale` to approximate.
- For shape: if you want `shadow`, that's not allowed — inject via `custom_css` if the user opted into it, otherwise drop.
- DO NOT silently coerce. If the rejection loses meaningful intent, retry the extraction with a stricter prompt: "Produce only these exact keys: <list>. Do not invent."

### 6.4 — Color rejection by validator

`agentshell_set_palette` rejects malformed hex values. If you produce `"#ff00"` (3-digit shorthand expanded incorrectly) or `"rgb(255,0,0)"` (not hex), the tool throws. You must:

1. Catch the error.
2. Normalize the value to `#rrggbb` form.
3. Retry the call.
4. If the same shape fails twice, the extraction heuristic has a bug — pause and report.

### 6.5 — Conflicting user hints

Example: image is bright/airy, user says "make the header dark like this".

Resolution priority:

1. User explicit instruction overrides image gestalt.
2. Apply the hint narrowly — only to the zone they named.
3. For zone hints, use `agentshell_set_css_var({ name: "--theme-header-bg", value: <hex> })` rather than mutating the global palette.
4. Note in the audit summary that the hint overrode the image-derived value for that zone.

### 6.6 — Transaction lock conflict

If `agentshell_begin_transaction` returns an error naming another actor:

- Do NOT force. Do NOT retry.
- Tell the user: "Another agent owns an open transaction on this site. Coordinate with them or wait. The skill cannot run while a transaction is held by another actor."
- Surface the actor name from the error so the user can reach out.

### 6.7 — Daemon / WP unreachable

If MCP calls return network errors:

- Retry once with exponential backoff (the daemon's `timeout` config controls this, but you should call again after a brief pause).
- If still failing, abort with: "Cannot reach WordPress via the daemon. Check `~/.agentshell-mcp.json` and that the WP site is responding."

### 6.8 — Over-budget mid-iteration

If per-call token spend is approaching the user's budget before reaching attempt 4:

- Stop immediately at the current attempt, regardless of quality.
- Pick the best so far, commit.
- Tell the user: "Stopped at attempt N due to budget. To continue, raise the token budget or re-run with a faster model on the iteration tier."

### 6.9 — Visual comparison hallucination

LLMs sometimes claim visual match when no real comparison happened (the screenshot failed silently, or the model "imagined" the comparison). Defenses:

- Always log the actual `agentshell_screenshot` URL returned in Step 5 in the audit summary.
- If the screenshot backend was unavailable for the iteration, you must say "no visual comparison performed" — not "looks good".
- The user can cross-check by visiting the `preview_theme` URL themselves.

## Worked example

End-to-end walkthrough of a single image-to-theme run. Use this as a reference for your expected behavior on a happy path.

### Input

```
User: "Theme the site like /tmp/refs/cyberpunk-cafe.png — keep it moody but readable."
Image: /tmp/refs/cyberpunk-cafe.png (1440x900 desktop screenshot of a UI mockup — dark canvas, teal/cyan accents, sans-serif type, generous spacing)
```

### Step 1 — Observe

```
agentshell_inspect  →  {
  "design": { "colors": { "background": "#ffffff", ... }, ... },
  "zones": [ "header", "main", "footer" ],
  "capabilities": { "screenshot": true, ... },
  "warnings": []
}
```

Screenshot available. Current theme is light. No concurrent transactions.

### Step 2 — Begin transaction

```
agentshell_begin_transaction  →  { "transaction_id": "tx_abc123", "actor": "claude-code" }
```

### Step 3 — Vision extraction (attempt 1)

Read the image. Reason:

- Background: deep navy `#0a0e27`
- Surface: slightly lighter navy `#151b3d` (offset of bg)
- Text: near-white `#f0f4ff`
- Border: subtle blue `#2a3160`
- Accent: bright cyan `#00d9ff` (most saturated hue)
- Primary: same as accent (no separate brand color)
- Secondary: muted purple `#7c3aed` (secondary highlight in image)
- fontFamily: humanist sans-serif
- mono: not visible in reference, leave unchanged
- baseSize: 16px (normal)
- scale: 1.25 (default — readable, not editorial)
- radius: 0.5rem (modern web — slight rounding on cards/buttons)
- borderWidth: 1px
- borderStyle: solid
- spacing.base: 1rem (normal)

User hint: "moody but readable" → aligns with extraction. No override needed.

### Step 4 — First attempt commit + profile

```
agentshell_set_palette({ colors: { background: "#0a0e27", surface: "#151b3d", text: "#f0f4ff", border: "#2a3160", accent: "#00d9ff", primary: "#00d9ff", secondary: "#7c3aed" } })
agentshell_set_typography({ fontFamily: "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif", baseSize: "1rem", scale: "1.25" })
agentshell_set_shape({ radius: "0.5rem", borderWidth: "1px", borderStyle: "solid" })
agentshell_set_spacing({ base: "1rem" })
agentshell_save_theme_profile("candidate_v1")

agentshell_set_spacing({ base: "1.25rem" })
agentshell_save_theme_profile("candidate_v2")
agentshell_screenshot({ viewport: "desktop" })
```

### Step 5 — Render + screenshot

```
agentshell_get_capabilities  →  { "screenshot": true }
agentshell_preview_theme("candidate_v1")  →  { "url": "https://example.com/?agentshell_preview=candidate_v1&key=..." }
agentshell_screenshot({ viewport: "desktop" })  →  { "url": "https://example.com/wp-content/uploads/agentshell-shots/2026-08-14-candidate_v1.png", ... }
agentshell_screenshot({ viewport: "mobile" })
```

### Step 6 — Compare to reference

Read both screenshots. Compare to `/tmp/refs/cyberpunk-cafe.png`:

- Palette: ✓ close match — dark moody, cyan accent reads
- Typography: ✓ humanist sans, generous — feels like reference
- Shape: ✓ medium rounding matches
- Spacing: ⚠ reference feels slightly more generous than what we set

Outcome B (improvement possible). Bump spacing.base from `1rem` to `1.25rem` for more generous feel. Re-apply, save as `candidate_v2`.

- Spacing now matches reference better. Other tokens unchanged. Gestalt match is high.
- Mobile screenshot: text still readable on dark bg (contrast check passed in 4.3). Layout reflows cleanly.

### Step 7 — Decide

Outcome A (subjective match achieved). Proceed to commit.

### Step 8 — Iteration guard

2 of 4 attempts used. No abort conditions triggered. Proceed to commit.

### Step 9 — Commit

```
agentshell_commit_transaction  →  { "committed": true, "revision": "rev_xyz789" }
```

### Audit + summary

Report to the user:

```
Theme applied: candidate_v2
Mood: "Moody dark navy with bright cyan accent — modern web geometry, generous spacing"
Iterations: 2 of 4 budget
Audit: see get_audit_log for token-level diff

If you want it warmer, more saturated, or with a different accent, say so and I'll iterate.
```

### Key takeaways from this run

- The first attempt was 80% there — only spacing needed adjustment.
- The contrast-safety net (4.3) caught no issues because the original extraction followed the contrast-pair rule.
- 2 iterations used, well under the 4-attempt budget.
- If the user had said "make it brighter" after commit, that would be a new run starting from the current committed state, not a continuation.
