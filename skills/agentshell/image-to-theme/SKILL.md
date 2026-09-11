---
name: agentshell-image-to-theme
description: Use when the user provides a reference image and asks to theme their AgentShell site to match. Routes visual extraction through the calling agent's vision capability, then applies the result via the existing agentshell_set_* MCP tools inside a transaction. Design tokens only — not structural layout.
---

# agentshell-image-to-theme (operative skill)

> **The canonical agent contract — tool surface, architecture, bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, the 45+ tool count, the working pattern — is in [`AGENTS.md`](../../../AGENTS.md). This skill does not redefine any of those. It only specifies the task contract: theme the site to a reference image.**

## Inheritance by reference

Before doing anything, read and obey `AGENTS.md` in full. In particular:

- **Tool surface** (`AGENTS.md` §2). The skill uses only existing tools. It does not propose new ones.
- **Working pattern** (`AGENTS.md` §6). All mutations run inside `agentshell_begin_transaction` … `agentshell_commit_transaction` (or `rollback_transaction`).
- **Unbreakable Grid protocol** (`AGENTS.md` §5). The skill never touches the grid; it only sets design tokens, which the protocol permits.
- **Bilateral widget registry** (`AGENTS.md` §4.1). The skill never registers or modifies widgets; widgets are out of scope for theming.
- **Data-* / no-fetch** (`AGENTS.md` §4.2 / §4.3). Out of scope for theming, but inherited by reference because the skill may later compose with widget code.

## Inputs

### Primary: local file path

If the user gives a path (e.g. `~/refs/cyberpunk-cafe.png`), read it directly with the `Read` tool. Vision analysis runs in the calling agent's session.

### Fallback: URL

Download the file with `curl` into a deterministic temp path:

```bash
curl -fsSL -o /tmp/agentshell-reference-<hash>.<ext> "<URL>"
HASH=$(echo -n "<URL>" | md5sum | cut -c1-12)
```

Pick the extension from the URL or `Content-Type` (`.png`, `.jpg`, `.webp`, `.gif`). Then `Read` the local file.

### Optional hints

The user may pass freeform guidance ("make the header dark", "match the accent only", "airy", …). Apply hints narrowly to named zones; for zone-specific hints, use `agentshell_set_css_var` rather than mutating the global palette.

### Strictness knob

Default: `pragmatic`. Override via natural-language phrases:

- `strict` / "match only what's clearly visible".
- `pragmatic` (default).
- `expressive` / "use your judgment".

Map strictness to the Section 4 heuristic rules below. If the user does not specify, use `pragmatic`.

## Pipeline (always inside a transaction)

1. **Observe** — `agentshell_inspect`; confirm `screenshot` capability. If screenshot is unavailable, fall back to `agentshell_preview_theme` in step 5.
2. **Begin transaction** — `agentshell_begin_transaction`. If another actor owns a transaction, stop and report the conflict.
3. **Vision extraction** — read the local file, reason over the visible aesthetic, produce a candidate design object.
4. **First attempt + profile** — apply via the semantic tools inside the open transaction, then `agentshell_save_theme_profile("candidate_v1")` (always — even on a mediocre first pass).
5. **Render + screenshot** — `agentshell_preview_theme("candidate_vN")`; if screenshot is available, additionally `agentshell_screenshot({ viewport: "desktop" })` and `agentshell_screenshot({ viewport: "mobile" })`.
6. **Compare to reference** — judge gestalt, not pixels.
7. **Decide** — match, improve (save `candidate_v(N+1)`), or backtrack (`agentshell_apply_theme("candidate_v(N-1)")`).
8. **Iteration guard** — default max attempts: 4. At attempt 4, pick the best profile, apply it, and proceed to step 9. Do not loop a 5th time.
9. **Commit or rollback** — `agentshell_commit_transaction` (chosen profile goes live) or `agentshell_rollback_transaction` (preserve candidates; nothing was committed).

After commit or rollback, call `agentshell_get_audit_log` and report the chosen profile, the mood summary, the iteration count, anything the user should review manually, and the preview URL.

## Heuristic mapping — image → design tokens

Apply these rules in order. Do not invent values outside the allowed tokens.

### 4.1 Allowed token space

- **Colors** (`agentshell_set_palette`): `background`, `surface`, `text`, `border`, `accent`, `primary`, `secondary`.
- **Typography** (`agentshell_set_typography`): `fontFamily`, `mono`, `baseSize`, `scale`.
- **Shape** (`agentshell_set_shape`): `radius`, `borderWidth`, `borderStyle`.
- **Spacing** (`agentshell_set_spacing`): `base`.

For values the schema does not accept, use `agentshell_set_css_var` with a `--theme-*` key (e.g. `--theme-header-bg`).

### 4.2 Color extraction (the contrast-pair rule)

Extract in order. Each step depends on the previous.

- **background** — the dominant non-text, non-figure color covering the largest contiguous area.
- **surface** — the second-most-prominent flat color, or a deterministic offset of `background` using the WCAG luminance formula below.
- **text** — the most readable color against `background`. If the reference shows legible text, use that; otherwise default to `#f8fafc` (luminance < 0.4) or `#0f172a` (luminance > 0.6).
- **border** — a deterministic offset of `background` (lightness `+12%` if dark, `-16%` if light); cap saturation at 10%.
- **accent** — the most saturated hue not already used; neutralize the dominant hue at 40% saturation if no clear accent exists.
- **primary** — the dominant brand-like color if visible; otherwise reuse `accent`.
- **secondary** — a complementary or muted partner to `primary`; otherwise reuse `primary` at 60% lightness.

Luminance formula:

```
luminance = 0.2126·R_lin + 0.7152·G_lin + 0.0722·B_lin
where component_lin = ((c/255 + 0.055)/1.055)^2.4   if c/255 > 0.03928
                     else (c/255)/12.92
```

### 4.3 Contrast safety net

Before committing, validate WCAG AA contrast for every adjacent pair: `text` on `background` (4.5:1), `text` on `surface` (4.5:1), `accent` on `background` (3:1 for large text / UI). Adjust the failing token with the same offset logic and re-validate. Do not commit a theme where body text fails 4.5:1.

### 4.4 Typography inference

Pick the closest font stack to the visible type:

- Sans-serif humanist → `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
- Sans-serif geometric → `"Futura", "Avenir Next", system-ui, sans-serif`
- Serif transitional → `Georgia, "Times New Roman", serif`
- Serif modern → `"Bodoni Moda", "Didot", serif`
- Mono → `"JetBrains Mono", "Fira Code", ui-monospace, monospace`

When uncertain between humanist and geometric, default to humanist. Set `baseSize` 16px (default), 18px (generous), 14px (compact). Set `scale` 1.25 (default), 1.333 / 1.414 (editorial), 1.125 (compact).

### 4.5 Shape inference

- All sharp → `radius: 0`.
- Slight rounding → `0.25rem`.
- Medium rounding → `0.5rem`.
- Heavy rounding → `1rem`.
- Pill / circular → `9999px`.
- `borderWidth` default `1px`. Hairlines → `0.5px`. Heavy borders → `2px`.
- `borderStyle` default `solid`. Dashed / dotted only if explicitly visible.

### 4.6 Spacing density

- Compact → `base: 0.5rem`.
- Normal → `base: 1rem` (default).
- Generous → `base: 1.5rem`.
- Airy → `base: 2rem` (or `3rem` under `expressive`).

### 4.7 What NOT to invent

- Never pick a hex not derived from the image or the offset rules above.
- Never invent font names outside the standard CSS stack (unless `expressive` is set).
- Never set `radius` above `1rem` (or `2rem` under `expressive`).
- Never set `borderWidth` above `2px`.
- Never set `spacing.base` above `2rem` (or `3rem` under `expressive`).

## Iteration controls

- Default max attempts: **4**. At attempt 4, pick the best profile, apply it, and commit. Never loop a 5th time.
- Distinct profile names (`candidate_v1`, `candidate_v2`, …) coexist; do not reuse or overwrite unrelated user profiles.
- Early abort (roll back without committing) on: screenshot backend unreachable + preview URL unreachable for 3 attempts, unreadable image (PAUSE — ask the user first), monotonically-degrading match after attempt 3.

## Failure modes (in addition to `AGENTS.md` §6)

| Failure | Resolution |
|---|---|
| Image unreadable / too small (<100px) | PAUSE — ask the user for a replacement. Do not invent a theme. |
| Screenshot backend unavailable | Use `agentshell_preview_theme` for the read-only URL; tell the user that visual verification was skipped. |
| Off-schema tokens | Use `agentshell_set_css_var` with `--theme-*` keys instead. |
| `agentshell_set_palette` rejects a value | The validator catches malformed hex. Re-emit the failing token via the same offset rule; never commit a failing theme. |
| Conflicting user hints | Hint wins, applied narrowly. Note the override in the audit summary. |
| Transaction lock conflict | Stop. Surface the actor from the error. Do not force. |
| Daemon / WP unreachable | Standard handling — see `AGENTS.md` §6. |

## What this skill explicitly does NOT do

- It does not propose new MCP tools. (`AGENTS.md` §2 is the surface.)
- It does not modify the grid or the shell. (`AGENTS.md` §5 is the law.)
- It does not modify widgets or compose zones. (`AGENTS.md` §4 is the contract.)
- It does not write `<script type="application/json">` or call `fetch()`. (`AGENTS.md` §4.2 / §4.3.)
- It does not claim `wp_options` is the sole source of truth. (`AGENTS.md` §1 and §4.1.)

For everything else — including the tool list, the architecture, the bilateral widget registry, the data-* / no-fetch laws, the Unbreakable Grid protocol, and the working pattern — see `AGENTS.md`.
