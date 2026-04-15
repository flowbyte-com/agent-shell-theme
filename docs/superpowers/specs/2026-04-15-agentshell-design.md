# AgentShell — WordPress Theme Design Specification

## Overview

**AgentShell** is a WordPress theme designed for seamless human-agent collaboration. It uses a JSON-driven "Shell" architecture that decouples site-wide layout, navigation, and design from WordPress's native template and block systems.

**Core Principle:** The site Shell (header, footer, menus, sidebars, layout grid) is entirely JSON-driven and agent-editable. Gutenberg is restricted to post/page content only — it cannot touch Shell elements.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    WordPress Theme                       │
│                                                          │
│  ┌─────────────────────────────────────────────────┐    │
│  │  Shell: Controlled by shell-config.json         │    │
│  │  - Header / Footer / Sidebar / Navigation        │    │
│  │  - Layout grid (CSS grid-template-areas)         │    │
│  │  - Design tokens (colors, typography, logo)      │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  ┌─────────────────────────────────────────────────┐    │
│  │  Content: Standard WP Loop + Gutenberg           │    │
│  │  - the_content() for all post/page content       │    │
│  │  - Plugins work via wp_widget_area               │    │
│  └─────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────┘
```

---

## shell-config.json

**Storage:** WordPress `wp_options` table, key `agentshell_config`. JSON serialized.

**Collision avoidance:** The physical file `default-config.json` in the theme root is a **seed only** — it populates `wp_options` on first theme activation. After installation, **`wp_options` is the sole source of truth.** Agents always read and write to the live config via the REST API or PHP helpers. The `default-config.json` file exists for IDE reference and for users who want to reset to defaults, but it is never read at runtime.

### Root Schema

```json
{
  "zones": ["header", "main", "sidebar", "footer"],
  "design": {
    "breakpoints": { "mobile": "0px", "tablet": "768px", "desktop": "1024px" },
    "colors": { "primary", "secondary", "accent", "background", "text" },
    "typography": { "fontFamily", "baseSize", "scale" },
    "logo": { "url", "width", "height" },
    "favicon": "<url>"
  },
  "layout": {
    "mobile":  ["header", "main", "footer"],
    "tablet":  ["header header", "main main", "footer footer"],
    "desktop": ["header header", "main sidebar", "footer footer"]
  },
  "navigation": {
    "primary":      [{ "label", "url", "post_id", "children[]" }],
    "footer_links": [{ "label", "url", "post_id", "children[]" }]
  },
  "content_mapping": {
    "<zone_name>": { "source": "wp_loop | wp_widget_area | json_block", ... }
  }
}
```

`zones` is a strict whitelist — it defines the authoritative list of zone names in rendering order. The JS configurator iterates this array to build zone forms. Layout arrays derive from this list; adding a zone requires adding its name to `zones` first.

---

## Design System

### Colors

```json
"colors": {
  "primary":     "#1a1a2e",
  "secondary":   "#16213e",
  "accent":      "#e94560",
  "background":  "#ffffff",
  "text":        "#333333"
}
```

CSS variables are generated from these values and injected into `:root` in `header.php`.

### Typography

```json
"typography": {
  "fontFamily": "system-ui, sans-serif",
  "baseSize":   "16px",
  "scale":      "1.25"
}
```

Font scale is applied as a CSS custom property `--type-scale`. Agents can calculate heading sizes from baseSize × scale^n.

### Breakpoints

```json
"breakpoints": {
  "mobile":  "0px",
  "tablet":  "768px",
  "desktop": "1024px"
}
```

Extensible. Adding a `tablet` key adds a `@media (min-width: 768px)` block for tablet layouts.

---

## Layout System

Layout is defined as named grid areas per breakpoint:

```json
"layout": {
  "mobile":  ["header", "main", "footer"],
  "tablet":  ["header header", "main main", "footer footer"],
  "desktop": ["header header", "main sidebar", "footer footer"]
}
```

Each string is a CSS `grid-template-areas` row. Whitespace-separated identifiers are cell-spans. Parsed by PHP into inline `<style>` blocks per breakpoint.

### Layout Zone Rules

- `"main"` zone MUST exist in all breakpoints — it renders `the_content()`
- Named zones map directly to CSS grid area names
- Agents can add new zones (e.g., `"hero"`) by adding the area name to all breakpoint arrays

---

## Navigation

```json
"navigation": {
  "primary": [
    { "label": "Home",   "url": "/",          "post_id": null },
    { "label": "About",  "url": "/about",     "post_id": null, "children": [
      { "label": "Team", "url": "/about/team", "post_id": null }
    ]}
  ],
  "footer_links": [
    { "label": "Privacy Policy", "url": "/privacy", "post_id": null }
  ]
}
```

- `navigation.primary` renders in the header zone
- `navigation.footer_links` renders in the footer zone
- Nested `children` arrays render as `<ul>` dropdowns
- Agents append new nav items by pushing objects to the named menu array
- No WordPress menu taxonomy involved
- **Resilient links:** Each nav item accepts an optional `post_id`. If set and the post exists, the URL is resolved via `get_permalink($post_id)` — making nav links slug-change-safe. If `post_id` is absent or invalid, the explicit `url` field is used as a fallback.

---

## Content Mapping

```json
"content_mapping": {
  "header": {
    "source": "json_block",
    "html": "<span>© 2026</span>"
  },
  "sidebar": {
    "source": "wp_widget_area",
    "id": "primary-sidebar"
  },
  "main": {
    "source": "wp_loop"
  },
  "footer": {
    "source": "json_block",
    "html": "<p>Built with AgentShell</p>"
  }
}
```

### Source Types

| Source | Description |
|--------|-------------|
| `wp_loop` | Standard WordPress query loop. Renders `have_posts() / the_post()` |
| `wp_widget_area` | Renders `dynamic_sidebar($id)` — preserves plugin widget compatibility |
| `json_block` | Raw HTML string, sanitized with `wp_kses_post()` before output |

### json_block Sanitization

`wp_kses_post()` strips dangerous tags/attributes while preserving semantic HTML. All json_block HTML passes through this filter on render.

**Sanitization limits — agents must not emit inline JS:**

`wp_kses_post()` removes:
- `<script>` tags and inline event handlers (`onclick`, `onerror`, `onload`, `onmouseover`, etc.)
- `javascript:` URLs in href/src attributes
- `<style>` tags and inline `style=""` attributes

**Agent output guidelines for json_block:**
- Use CSS class-based JavaScript (e.g., `document.querySelectorAll('.my-class')` with event delegation) instead of inline onclick handlers
- Use `<link>` tags for stylesheets, not inline `<style>` blocks or style attributes
- Rely on semantic HTML for structure; styling must come from the theme's design token system or CSS classes
- Do not include `data:` URLs — these can be used for injection

---

## Live Preview Configurator

### Docked Push Sidebar

Triggered by a floating button in the frontend. On open:

- Main canvas: `width: calc(100vw - 350px)`
- Config panel: `width: 350px`, docked right
- No overlay — full site visibility at all times

### Auto-Adaptive Form Fields

Based on JSON value type:

| Value Type | Rendered As |
|------------|-------------|
| Hex color (`#rrggbb`) | `<input type="color">` |
| Number + unit (`16px`) | `<input type="range">` with preview |
| CSS string | `<input type="text">` |
| Array/Object | Collapsible section |

The JS configurator adapts form fields automatically as agents update the JSON schema. No custom field definitions required.

### Sections

1. **Logo** — URL input, width/height fields
2. **Navigation** — Nested list editor for primary and footer_links
3. **Layout** — Grid area arrays per breakpoint (editable as text)
4. **Content Zones** — Source selector + source-specific fields (WP widget dropdown, HTML textarea)
5. **Colors** — Color picker per token
6. **Typography** — Font family dropdown, base size slider

---

## PHP Template Structure

```
agentshell/
├── style.css              # Theme header only
├── functions.php          # Theme setup, enqueue, config read/write API
├── default-config.json    # Seed file for fresh installs (wp_options source)
├── front-page.php         # Static front page template
├── singular.php           # Single post/page template
├── index.php              # Fallback
├── header.php             # Renders Shell header zone
├── footer.php             # Renders Shell footer zone
├── template-parts/
│   ├── shell-render.php   # Reads config, renders CSS + Shell zones
│   └── gridAreas.php      # Parses layout arrays → grid-template-areas CSS
├── configurator/
│   ├── configurator.js    # Push sidebar live preview logic
│   └── configurator.css   # Panel styles
└── assets/
    └── logo.png
```

**Theme Header Only** — `style.css` contains only the theme declaration. All CSS is generated dynamically from `shell-config.json` design tokens and injected as inline `<style>` in `header.php`.

**Config Storage** — `default-config.json` seeds `wp_options` on activation. After install, all reads and writes go through `agentshell_get_config()` / REST API. `default-config.json` is never read at runtime.

---

## WordPress Integration Points

### Config Read

`agentshell_get_config()` — returns the full JSON object from `wp_options`. Used in all templates. **Never reads default-config.json at runtime.**

### Config Write

`agentshell_update_config(array $config)` — calls `update_option()`. Agents write via the REST API. Atomic single-write operation.

### REST API Endpoint

Theme registers a custom REST route:

```
GET /wp/v2/agentshell/config
PUT /wp/v2/agentshell/config
```

Agents authenticate via WordPress cookie/nonce or application password. The PUT endpoint requires `edit_theme_options` capability.

### Dynamic Widget Registration

`widgets_init` iterates over `content_mapping`. Any zone with `"source": "wp_widget_area"` automatically gets `register_sidebar()` called for it. Agents can add new widget zones by adding a new entry to `content_mapping` — no hardcoded sidebar calls required.

### No Block Hijacking

The theme does NOT use WordPress block templates or the FSE (Full Site Editing) engine. `shell-config.json` is NOT `theme.json`. WordPress core will not trigger FSE on this file.

---

## Agent Workflow

1. Read: `GET /wp-json/wp/v2/settings` or filesystem read of `shell-config.json`
2. Parse: Agent holds full config in memory as a JSON object
3. Diff: Agent computes changes, writes updated config atomically
4. Render: PHP re-reads config, regenerates CSS variables and Shell HTML on next page load

Agents never touch PHP templates directly. All Shell changes happen via `shell-config.json`.

---

## Scope Boundaries

### In Scope

- Full Shell control via `shell-config.json`
- Live preview configurator (push sidebar)
- Named grid-area CSS layout per breakpoint
- Navigation as JSON arrays (no WP menu taxonomy)
- Content zone pointers to WP Loop, Widget Areas, or JSON blocks
- Auto-adaptive form rendering based on value type
- Single atomic config write for all Shell changes

### Out of Scope (This Theme)

- Custom post types or taxonomies (handled by plugins)
- E-commerce / WooCommerce (plugin territory)
- Multi-language / i18n (plugin territory)
- Block-based header/footer editing (Shell is JSON-only, not Gutenberg)
- WordPress Customizer integration (replaced by custom push sidebar)

---

## Success Criteria

1. Agent can update the full site Shell by reading and writing exactly one JSON file
2. Human can update any Shell element via the live-preview push sidebar with zero page reload
3. Standard WP widgets render correctly in named Shell zones
4. Gutenberg blocks function normally inside the `main` content zone
5. Layout reflows instantly as breakpoint-aware grid areas change
6. Theme installs and functions with zero required plugins (except for widgets if desired)
