# AgentShell — Agent Editing Guide

> **Quick start for agents:** `GET /wp-json/wp/v2/agentshell/config` — the `_guide` field contains this full document. No need to open files directly.

---

## REST API

```
GET  /wp-json/wp/v2/agentshell/config   → full config + this guide in _guide field
GET  /wp-json/wp/v2/agentshell/guide   → just this guide as plain text
PUT  /wp-json/wp/v2/agentshell/config   → update config (requires auth)
```

**For design changes:** edit `style.css` directly. The REST API is for structured config (sidebar_enabled, navigation items).

---

## Container Bounds (Read-Only)

Agents should read these from `style.css :root` to calculate safe content widths:

| Variable | Value | Meaning |
|----------|-------|---------|
| `--content-max-width` | `1280px` | The page grid never exceeds this |
| `--sidebar-width` | `320px` | Sidebar is always exactly 320px |
| `--container-padding` | `2rem` | Left/right padding on the page edge |

**Why 1280px?** Fits comfortably on 1080p screens with browser UI, and prevents code lines from becoming too long. The grid is always centered — it never stretches past 1280px even on ultrawide monitors.

---

## Zone Map

| Zone ID | Semantic | What lives here | How to edit |
|---------|----------|----------------|-------------|
| `#agentshell-root` | `<div>` | CSS grid container — bounded by `--content-max-width` | Do not edit |
| `#zone-header` | `<header>` | Logo, site title, nav | WP Admin → Customize → Site Identity / Menus |
| `#zone-nav` | `<nav>` | Primary navigation links | WP Admin → Appearance → Menus |
| `#zone-main` | `<main>` | Page/post content | WP Admin → Pages / Posts |
| `#zone-sidebar` | `<aside>` | Widgets (320px fixed) | WP Admin → Appearance → Widgets |
| `#zone-footer` | `<footer>` | Copyright text | style.css `--theme-footer-*` |

---

## Changing Design (style.css)

### CSS Variables

Edit the `:root` block in `style.css`. These control everything:

```css
:root {
    /* Colors */
    --theme-bg:           #ffffff;   /* page background */
    --theme-surface:       #f4f4f5;   /* zone card background */
    --theme-text:          #18181b;   /* body text */
    --theme-border:        #e4e4e7;   /* borders and dividers */
    --theme-accent:        #3b82f6;   /* buttons, links, hover highlights */

    /* Header */
    --theme-header-bg:     #1a1a2e;   /* header zone background */
    --theme-header-text:   #ffffff;   /* header zone text */

    /* Footer */
    --theme-footer-bg:     #16213e;
    --theme-footer-text:   #ffffff;

    /* Spacing and shape */
    --spacing-base:  1rem;   /* all padding/gaps scale from this */
    --radius-base:   8px;    /* border radius on zone cards */
}
```

### Quick reskin — dark theme

```css
:root {
    --theme-accent:      #e94560;
    --theme-header-bg:   #0f0f23;
    --theme-footer-bg:   #0f0f23;
    --radius-base:       0px;
}
```

---

## Enabling the Sidebar

Sidebar is **off by default**. To enable it:

```
PUT /wp-json/wp/v2/agentshell/config
Body: { "sidebar_enabled": true }
```

On next page load:
- `<aside id="zone-sidebar">` renders (320px fixed width)
- Grid switches to 2-column: `1fr 320px`

---

## Layout (Do Not Edit)

Sections 3 and 4 of `style.css` are the fixed grid.

```
Default (sidebar off) — all screen sizes:
┌──────────────────────────────────┐  ← max-width: 1280px, centered
│            header                 │
├──────────────────────────────────┤
│                                  │
│         main (75ch max)           │  ← readable line length
│                                  │
├──────────────────────────────────┤
│            footer                 │
└──────────────────────────────────┘

With sidebar enabled (≥ 1024px):
┌──────────────────────────┬─────────┐
│         header          │         │
├─────────────┬───────────┤ sidebar │
│             │           │ 320px   │
│    main     │           │         │
│  (1fr fill) │           │         │
├─────────────┴───────────┴─────────┤
│            footer                 │
└───────────────────────────────────┘
```

---

## Full-Width Escape Hatch

For wide content that should break out of the 75ch limit (code terminals, log viewers, wide tables), add class `u-full-width` to the element. This spans all grid columns:

```html
<div class="u-full-width">
    <!-- will stretch to full container width (1280px max) -->
</div>
```

---

## Adding Navigation Links

WP Admin → Appearance → Menus → assign to **"Primary"** location.

If no menu is assigned, `#zone-nav` renders empty.

---

## Adding Widgets

WP Admin → Appearance → Widgets → drag widgets into **"Primary Sidebar"**.
Sidebar must be enabled first: PUT `{ "sidebar_enabled": true }`.

---

## Common Mistakes

**Inline styles / inline JS** — `style=""`, `onclick=""`, `<script>` tags are stripped by WordPress. Use CSS classes and enqueued scripts.

**Editing header.php / footer.php for content** — These are structural HTML. Edit content in WP Admin (Pages/Posts/Menus/Widgets).

**Setting colors via REST API** — Edit `style.css :root` instead. The `design` object in REST config is for reference.

**Logo not showing** — Set in WP Admin → Customize → Site Identity. The theme does not hardcode a logo path.

**Lines of text too wide** — `#zone-main` is capped at 75ch for readability. Use `.u-full-width` for wide content like tables and code blocks. Do not override the max-width for normal paragraphs.
