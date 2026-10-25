# Navigation blocks — design record

**Status:** implemented (PR B). **Normative form:** `AGENTS.md` §3 (zone
composition) and §5 (grid protocol). Where this record and `AGENTS.md`
disagree, that file wins and this one is out of date.

## 1. The problem

The `nav_menu` core component rendered one thing: the menu assigned to the
`primary` location. A nine-area header and footer needs five or six different
menus — utility, primary, secondary, company, resources, legal — placed
independently, and the primary menu can only be in one place.

AgentShell does not own menu data. WordPress does. Whatever this feature is, it
must be a *selector* over the native menu registry, not a second one.

## 2. The schema: fields on the existing block, not a new block type

A navigation block is a `wp_core` block with `id: "nav_menu"` and up to four
additional fields:

```json
{ "type": "wp_core", "id": "nav_menu",
  "menu": "legal",             // menu slug (a unique name, or a numeric id, also resolve)
  "location": "footer-legal",  // OR a registered theme location
  "mode": "vertical",          // horizontal | vertical | compact
  "label": "Legal links" }     // accessible name
```

The alternative — a new `nav_menu` block *type* — was rejected. It would have
meant two block types rendering navigation (`wp_core/nav_menu` for every
existing site, the new type for new ones), two validation branches, two
configurator editors and two meanings of "a navigation block", in exchange for a
slightly tidier vocabulary. The existing type already means "render a WordPress
core component"; a menu is a core component with parameters.

**Compatibility falls out of this.** A block with none of the four fields is not
"an old-style block that needs upgrading" — it is a block with the defaults, and
it renders through the original code path: same `theme_location`, same
container, same `aria-label="Primary"`, same WordPress fallback. Existing sites
are not migrated, not rewritten, and not re-rendered differently. The parity
test asserts the exact markup.

`menu` and `location` are alternatives, not a precedence order. Both set is
refused at validation (`navigation_selection_ambiguous`) rather than resolved by
a rule nobody will remember.

## 3. Resolution: exact, ordered, and never a substitution

Resolution happens in `agentshell_resolve_nav_block()`, before `wp_nav_menu()`
is called:

1. `menu` — exact slug, then a **name that matches exactly one menu**, then a
   numeric term id (for legacy manifests). Two menus with the same name is a
   diagnostic, not a coin flip: `get_term_by('name')` would pick one
   arbitrarily, which is precisely the silent substitution this feature must
   not do.
2. `location` — must be registered, then must have a menu assigned.
3. neither — the historical `primary` location.

`wp_nav_menu()` has two substitution behaviours that matter here. With a `menu`
argument that does not resolve and no `theme_location`, it falls back to **the
first menu that has items**. With no menu at all it calls `fallback_cb`, which
defaults to `wp_page_menu()`. Both are suppressed for extended blocks: the menu
is resolved first (so a non-resolving reference never reaches
`wp_nav_menu()`), and `fallback_cb` is passed as `false`. A menu that exists but
has no items renders nothing and tells the editor why.

The legacy path keeps both behaviours, because changing them would change what
existing sites render.

Diagnostics are audience-aware. A visitor gets nothing — an unresolved menu is
a configuration problem, and a public error about a menu that does not exist
helps nobody. An editor (`edit_theme_options`) gets a notice naming the
reference and where to fix it. The doctor reports a **warning** for a missing
menu (the configuration is valid; the site lacks a dependency — exactly what an
imported manifest looks like) and an **error** for a malformed block (an
ambiguous selection, an unknown mode, navigation fields on a non-navigation
component). The stored block is never rewritten, so restoring the menu restores
the block.

## 4. Unique DOM ids are WordPress's job

`wp_nav_menu()` keeps a static list of the `<ul>` ids it has emitted in a
request and suffixes repeats (`menu-legal`, `menu-legal-1`). That is verified in
`wp-includes/nav-menu-template.php` and pinned by the live suite, which renders
the same menu twice against a real WordPress and asserts two distinct ids.

The renderer therefore passes **no** `menu_id`. Passing one would defeat the
dedupe and create the duplicate-id bug the tests exist to catch.

## 5. Presentation modes and nested menus

- `horizontal` — the historical row; submenus are dropdowns, the second level
  and deeper fly out to the side, the last top-level item's dropdown flips
  leftwards instead of leaving the viewport, and footer dropdowns open upwards
  (there is nothing below the footer to open into).
- `vertical` — a column whose nested items are always visible and indented. This
  is the footer-column shape: a list of links needs no interaction to be read
  or to be reachable by keyboard. It stays visible on mobile for the same
  reason.
- `compact` — one disclosure button in front of the list. The collapse only
  applies once the script has marked the nav, so without JavaScript the links
  are simply there.

Nested menus are operable with the platform's own affordances first: `:hover`
and `:focus-within` open a dropdown in CSS, which means a keyboard user can Tab
from a parent link into its submenu with no JavaScript at all. The script adds
what CSS cannot: a disclosure `<button>` per parent level (the parent link keeps
its destination — hijacking it would make some menu items unreachable),
`aria-expanded`, Escape-to-close that restores focus to the button that opened
the submenu, a click-outside close, and one open branch at a time. The open
state rules are pure functions (`assets/theme.js` → `nav_model()`), tested in
Node; the wiring is asserted in a real browser.

No application-menu roles: this is ordinary website navigation, so it stays
`nav > ul > li > a` with buttons for disclosure. No `role="menu"`, no
`aria-haspopup="true"` promising a widget the page does not implement.

## 6. The footer-centre mobile bug

The mobile stylesheet hid `.slot-center` — unscoped, so it hid the **footer's**
centre slot as well as the header's. Any footer content placed there (a menu, a
social row, an address) was invisible on every phone.

The rule is now scoped to `#zone-header`. The header keeps its collapsible
centre (the historical behaviour, including the hamburger); the footer keeps its
content. Nothing about the desktop layout changes, and both the legacy
three-slot and the nine-area layouts were checked in a browser at 390px.

## 7. Configurator and MCP: one model, two interfaces

The configurator edits the same four fields on the same block. Its menu and
location lists come from the server, built from `wp_get_nav_menus()`,
`get_registered_nav_menus()` and `get_nav_menu_locations()` — the browser holds
no list of its own, so a menu created a minute ago is selectable immediately and
a deleted one is shown as "(unavailable)" with the saved value preserved.

`agentshell_update_zone_slots` was extended rather than duplicated: its block
validation calls the theme's canonical helper, so the tool cannot store a block
the renderer or the doctor would reject. No new tool, so the documented count
(§2) is unchanged. The tool's replace semantics are untouched: this PR did not
introduce a granular one-area update, and `agentshell_update_zone_slots` still
empties the areas a caller does not name.

`agentshell_export_theme` gained an advisory `dependencies` block (the menus and
locations the configuration expects). It is additive; importers that ignore it
are unaffected. Resolving those references on the destination is PR E's work.

## 8. Compatibility evidence

- `tests/navigation-blocks-test.php` — legacy parity (exact markup and exact
  fallback behaviour), resolution order, ambiguity, missing/unregistered/
  unassigned diagnostics, two menus side by side, the same menu twice with
  unique ids, both WordPress fallbacks disabled, escaping, validation, doctor
  warnings vs errors, configurator data, and MCP ↔ configurator equivalence.
- `tests/nav-model-test.js` — the open/close/Escape rules.
- `tests/navigation-blocks-live-test.php` — the same properties against a real
  WordPress: real `wp_nav_menu()` output, real nested lists, real id
  deduplication, real location resolution, real capability-gated diagnostics.
- A real browser (headless Chrome) against the real stylesheet and script:
  desktop dropdowns at three levels, mobile stacking and in-flow submenus,
  compact mode, Escape and focus restoration, footer-centre visibility, no
  horizontal overflow, no duplicate element ids.

## 9. Known limits

- **One open menu at a time, page-wide.** Clicking outside a menu closes it, and
  clicking into another menu closes the first. This is standard disclosure
  behaviour, not a limitation of the state model — each menu still owns its own
  open set.
- **No menu-item editing** and no menu creation from AgentShell. That is
  WordPress's editor, deliberately.
- **The last-item dropdown flip is a heuristic** (`li:last-child`): it prevents
  a menu half off the right edge, but it is not a measurement.
- **Import-side dependency resolution is not implemented** (PR E): the export
  names the menus it expects, the destination currently reports a missing one
  through the doctor and the editor diagnostic rather than offering to map it.
