# Nine-area header and footer zones — design record

**Status:** implemented (PR A). **Normative form:** `AGENTS.md` §3 (zone
composition) and §5 (grid protocol). This file records the investigation, the
decisions taken and their evidence; where it disagrees with `AGENTS.md`, that
file wins and this one is out of date.

## 1. The problem

Header and footer were tri-slot: `slots: { left, center, right }`, rendered as
three children of a `1fr auto 1fr` grid. A real site header needs more places
than that — a utility strip above the brand row, a secondary navigation row
below it, and the same on the footer — and four of the five slots people asked
for had nowhere to go.

Two constraints shaped everything else:

- **Existing sites must not move.** AgentShell is deployed. A theme update that
  regroups somebody's header is a defect, not an upgrade.
- **One configuration model.** The configurator and the MCP tools are two
  interfaces to `wp_options['agentshell_config']`. A second, parallel schema
  for "the new layout" would produce configurations that disagree depending on
  which interface wrote last.

## 2. The schema

Nine areas, named by row then column:

```
top-left      top-center      top-right
middle-left   middle-center   middle-right
bottom-left   bottom-center   bottom-right
```

`slots` stays a map of `area → [block, …]`. That shape was not changed,
because changing it would have changed every reader (renderer, doctor,
configurator, tools) at once for no benefit. Alignment is additive and lives
beside it:

- `slot_align: { "<area>": "start" | "center" | "end" | "stretch" }` —
  horizontal, defaulting by column (left / centre / right).
- `row_align: { "top" | "middle" | "bottom": "start" | "center" | "end" }` —
  vertical, defaulting to `center` (what `align-items: center` already did).

Two separate questions — "where across" and "where down" — rather than one
alignment control that has to mean both.

## 3. Compatibility: aliases, not a migration step

`left` → `middle-left`, `center` → `middle-center`, `right` → `middle-right`.

`agentshell_normalize_zone()` is the only translator. It runs on read
(`agentshell_get_config()`, so a stored config of any vintage renders) and on
write (`agentshell_validate_config()`, so a write stores the canonical shape).
Four properties are load-bearing and each has a test:

1. **Idempotent.** Normalising a canonical zone returns it unchanged.
2. **Order-independent.** Canonical wins when a payload carries both a canonical
   key and its alias, whichever came first in the JSON. A config that
   normalised differently depending on key order would be a config whose
   meaning depended on serialisation.
3. **One shape stored.** After one write, `left` does not exist.
4. **Main untouched.** Main is a vertical composition, not a slot grid.

No destructive migration, no version flag, no "migrated" state to get wrong.

## 4. Layout mode is derived from content

A zone renders the nine-area grid **exactly when** an area outside the middle
row holds a block. Otherwise it renders the three-slot markup byte for byte.

The alternative — a stored `layout: "expanded"` flag — was rejected: it can
disagree with the content (flag set, nothing in the top row: an empty strip;
flag unset, blocks in the top row: silently invisible content). Deriving the
mode removes that state entirely, and it gives the required behaviour for free:
an existing site has no top/bottom content, therefore it is never expanded.

## 5. Rendering

- Legacy path: the same three wrappers, same classes, same order as before.
  Asserted as an exact string, and separately against the shipped
  `default-config.json`.
- Expanded path: nine wrappers, each `zone-slot slot-<area> align-* valign-*`
  with a `data-slot` attribute, emitted in row order.
- **All nine cells are always emitted.** An empty cell is what holds its
  column; `.zone-slot:empty` removes it from the grid, and auto-placement would
  then pull the remaining cells leftwards. Placement is therefore explicit
  (`grid-area`), not flow-based — a correctness requirement rather than a style
  choice.
- **Empty rows cost no vertical space.** Rows are `auto`, so an empty row has
  no height, and there is no `row-gap` (a gap is charged between empty rows
  too). Spacing between occupied rows comes from `padding-block` on non-empty
  cells.
- **Alignment is classes, never inline styles.** `style=""` is stripped by the
  `json_block` sanitiser; a layout that depended on inline styles would break
  the first time a block passed through it.
- **Mobile:** the nine areas stack in one column in DOM order. The mobile
  navigation toggle matches `[data-slot="middle-center"]` as well as the
  historical `.slot-center`, so a navigation block in either layout behaves the
  same.

Verified in a headless browser against real `style.css`: three columns on
desktop, one column on a 390-pixel viewport, an empty middle row collapsing to
nothing, and the three-slot page unchanged.

## 6. The configurator

A 3×3 area picker plus one area editor — not eighteen expanded panels. The
picker is the map (selection, block count per area, `aria-pressed`), the editor
below it is the selected area's blocks, alignment controls, and add-block row.
Blocks move between areas with a select rather than drag-and-drop: nine named
destinations are a list, and a keyboard-reachable control works for everybody.

The **area list and the alignment vocabulary come from the server** (the config
response's `zone_areas` / `zone_align`). The browser keeps no list of its own,
so it cannot drift from what the server validates.

The half of the model that is not DOM — which area is open, what a move does,
when a zone is expanded — lives in `configurator/configurator-state.js` and is
exercised by Node tests.

**Boundary: there is no client-side visual preview.** Rendering the nine areas
live in the panel would mean a second renderer that can disagree with
`template-parts/shell-render.php`. The existing transaction preview (signed
credential, server-rendered) is the visual preview; the panel shows the
structure it is about to save and the transaction allows rollback. A
configurator "Preview" button that mints that credential is a candidate for PR
E, where the configurator integration work lives.

## 7. MCP

`agentshell_update_zone_slots` was extended, not duplicated: nine area keys plus
the three aliases, optional `slot_align` / `row_align`, replace semantics as
before, and a `layout` field in the result so a caller can see that its edit
switched the zone to nine areas. No new tool, so the documented count (§2) is
unchanged.

`agentshell_inject_json_block` now appends to `middle-right` (the canonical name
for the end of the middle row) and writes the canonical shape.

Equivalence is a test, not a claim: the same header edit through the REST /
configurator path and through the tool produces identical stored configuration.

## 8. Evidence

`tests/nine-area-zones-test.php` (32 assertions across parity, compatibility,
rendering, validation, readers and MCP equivalence),
`tests/configurator-state-test.js` (the zone model), plus the existing
render-block, doctor, inject-block and config-roundtrip suites, which now run
against the canonical shape.

## 9. Dependencies and known limits

- **PR B (menus)** needs a navigation block that names a WordPress menu. The
  slot grid is deliberately menu-agnostic: a block goes in an area, and what
  the block renders is PR B's problem.
- **PR C–D (forms, CAPTCHA)** place blocks in areas like any other block.
- **PR E (packaging)** must resolve a conflict found here: `configurator/` is in
  `tools/release-exclusions.sh`'s root exclusions (the size audit classifies it
  as "development"), yet the theme enqueues it for editors on the live site and
  this PR extends it. A deployment built from the release archive therefore
  ships a configurator trigger whose assets are absent. That is a packaging
  policy decision, not a layout one, and it belongs to PR E.
- **Not implemented on purpose:** per-area width controls, drag-and-drop,
  per-area vertical alignment (vertical alignment is per row), and a
  configurator-side visual preview.
