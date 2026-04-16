# AgentShell — Agent Editing Guide

> **Quick start for agents:** `GET /wp-json/wp/v2/agentshell/config` — the `_guide` field contains this full document. No need to open files directly.

---

## Architecture: Static Shell

The shell is **static, immutable, and CSS-grid-driven**. The layout geometry and all zone IDs are hardcoded in `header.php`, `footer.php`, and `style.css`. Agents must not attempt to render header or footer zones dynamically.

**Shell components (do not edit):**
- `header.php` — hardcoded shell HTML, zone IDs, and structure
- `footer.php` — hardcoded shell HTML
- `style.css` Sections 3 & 4 — fixed CSS Grid layout

---

## What Agents May Edit

### 1. Toggle Sidebar — ONLY UI control

```
PUT /wp-json/wp/v2/agentshell/config
{ "sidebar_enabled": true }
```

Sidebar is off by default. When enabled, `<aside id="zone-sidebar">` appears (≥1024px), grid switches to `1fr 320px`. No other layout changes are available.

### 2. Design Tokens in style.css

Edit `:root` variables in `style.css` directly (no build step required — WordPress serves it as-is):

```css
:root {
    /* Page */
    --theme-bg:           #ffffff;
    --theme-surface:      #f4f4f5;
    --theme-text:         #18181b;
    --theme-border:       #e4e4e7;
    --theme-accent:       #3b82f6;   /* buttons, links, hover */

    /* Header zone */
    --theme-header-bg:    #1a1a2e;
    --theme-header-text:  #ffffff;

    /* Footer zone */
    --theme-footer-bg:    #16213e;
    --theme-footer-text:  #ffffff;

    /* Spacing and shape */
    --spacing-base:  1rem;
    --radius-base:   8px;
}
```

### 3. Content in `#zone-main` — via WP Posts

The **only content injection point** for agents is `#zone-main`. Edit via standard WordPress:

- **Pages** — `PUT /wp-json/wp/v2/pages/<id>` with `{ "content": { "raw": "..." } }`
- **Posts** — `PUT /wp-json/wp/v2/posts/<id>` with `{ "content": { "raw": "..." } }`
- **New post** — `POST /wp-json/wp/v2/posts` with `status: publish|draft`

Content is rendered by WordPress's standard loop in `header.php` — no custom rendering.

---

## What Agents Must NOT Do

- Do not modify `header.php` or `footer.php`
- Do not implement `agentshell_render_zone()` or `agentshell_render_nav()`
- Do not attempt to render header/footer zones dynamically
- Do not inject HTML into `#zone-header`, `#zone-sidebar`, or `#zone-footer`
- Do not edit Sections 3 or 4 of `style.css` (the grid layout)
- Do not use `onclick=""`, `onerror=""`, `<script>` tags, or inline `style=""` attributes in post content — WordPress strips these
- Do not set colors via REST API config — edit `style.css :root` instead

---

## REST API

```
GET  /wp-json/wp/v2/agentshell/config   → full config + _guide field
GET  /wp-json/wp/v2/agentshell/guide     → this guide as plain text
PUT  /wp-json/wp/v2/agentshell/config   → update config (requires edit_theme_options)
     Supported keys: sidebar_enabled, design (informational only)
```

**Note:** The `design` object in the REST config exists for informational display by the live configurator (in WP Admin). It does NOT drive CSS variable output. CSS variables must be edited directly in `style.css`.

---

## Container Bounds (Read-Only)

| Variable | Value | Meaning |
|----------|-------|---------|
| `--content-max-width` | `1280px` | Page grid max width, always centered |
| `--sidebar-width` | `320px` | Sidebar zone width when enabled |
| `--container-padding` | `2rem` | Left/right page edge padding |

---

## Zone Map

| Zone ID | Semantic | Editable by agent? |
|---------|----------|-------------------|
| `#agentshell-root` | grid container | No — structural |
| `#zone-header` | `<header>` | No — static HTML |
| `#zone-nav` | `<nav>` | No — WP menu system |
| `#zone-main` | `<main>` | **YES — via WP Post payloads** |
| `#zone-sidebar` | `<aside>` | No — widget area, sidebar_enabled only |
| `#zone-footer` | `<footer>` | No — static HTML |

---

## Layout

```
Default (sidebar off) — all sizes:
┌──────────────────────────────────┐
│            header                │
├──────────────────────────────────┤
│                                  │
│      main (75ch max width)       │
│                                  │
├──────────────────────────────────┤
│            footer                │
└──────────────────────────────────┘

With sidebar enabled (≥1024px):
┌──────────────────────┬──────────┐
│        header        │          │
├───────────┬──────────┤ sidebar  │
│           │          │  320px   │
│   main    │          │          │
│  (1fr)    │          │          │
├───────────┴──────────┴──────────┤
│           footer                │
└─────────────────────────────────┘
```

**Wide content escape hatch** — to break `#zone-main` out to full container width (for code blocks, tables, terminals), apply class `u-full-width` to any element inside the main zone. Do not target `#zone-main` itself.

---

## Common Mistakes

- **Editing header.php or footer.php for content** — shell structure is immutable
- **Setting colors via REST API config** — edit `style.css :root` directly
- **Injecting HTML outside `#zone-main`** — only `#zone-main` accepts content
- **Inline JS or `<script>` in post content** — stripped by WordPress; use CSS classes + enqueued scripts
- **Exceeding 75ch in `#zone-main`** — normal paragraphs should stay within the 75ch cap; use `.u-full-width` for wide content