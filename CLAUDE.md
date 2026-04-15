# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**AgentShell** is a WordPress theme for human-agent collaboration. The entire Shell (header, footer, menus, sidebars, layout grid) is JSON-driven and agent-editable via REST API. Gutenberg is restricted to post/page content only — it cannot touch Shell elements.

## Quick Commands

```bash
# No build step — pure PHP/JS, activate in WordPress admin
# Update config via REST API (authentication required):
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d @default-config.json

# Or use the live configurator at /wp-admin/ (floating button, bottom-right)
```

## Architecture

**Core Principle:** `wp_options` is the SOLE source of truth at runtime. The physical `default-config.json` in the theme root is a **seed only** — it populates `wp_options` on first theme activation. After installation, agents always read/write via `agentshell_get_config()` / REST API or the live configurator.

```
┌─────────────────────────────────────────────────────────┐
│  Shell (controlled by agentshell_config in wp_options) │
│  - Header / Footer / Sidebar / Navigation               │
│  - Layout grid (CSS grid-template-areas)                │
│  - Design tokens (colors, typography, logo)             │
└─────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────┐
│  Content: Standard WP Loop + Gutenberg                  │
│  - the_content() for all post/page content             │
│  - Plugins work via wp_widget_area                      │
└─────────────────────────────────────────────────────────┘
```

### Key Files

| File | Role |
|------|------|
| `functions.php` | Theme setup, REST endpoint (GET/PUT /wp/v2/agentshell/config), config helpers |
| `template-parts/shell-render.php` | Renders zones, nav, CSS custom properties from config |
| `template-parts/grid-areas.php` | Parses layout arrays → CSS grid-template-areas |
| `configurator/configurator.js` | Live preview push sidebar (auto-adaptive forms) |
| `default-config.json` | Seed file for fresh installs only — never read at runtime |

### Config Schema (agentshell_config in wp_options)

```json
{
  "zones": ["header", "main", "sidebar", "footer"],
  "design": {
    "breakpoints": { "mobile": "0px", "tablet": "768px", "desktop": "1024px" },
    "colors": { "primary", "secondary", "accent", "background", "text" },
    "typography": { "fontFamily", "baseSize", "scale" },
    "logo": { "url", "width", "height" }
  },
  "layout": {
    "mobile":  ["header", "main", "footer"],
    "tablet":  ["header header", "main main", "footer footer"],
    "desktop": ["header header", "main sidebar", "footer footer"]
  },
  "navigation": {
    "primary":     [{ "label", "url", "post_id", "children[]" }],
    "footer_links": [{ "label", "url", "post_id" }]
  },
  "content_mapping": {
    "<zone>": { "source": "wp_loop | wp_widget_area | json_block", ... }
  }
}
```

### Content Zone Sources

| Source | Behavior |
|--------|----------|
| `wp_loop` | Standard WordPress query loop |
| `wp_widget_area` | Renders `dynamic_sidebar($id)` — plugin widget compatible |
| `json_block` | Raw HTML sanitized with `wp_kses_post()` |

**json_block sanitization strips:** `<script>`, inline event handlers (`onclick`, etc.), `javascript:` URLs, `<style>` tags, `data:` URLs. Agents must emit CSS-class-based JS, not inline handlers.

### REST API

```
GET  /wp-json/wp/v2/agentshell/config  → full config (public)
PUT  /wp-json/wp/v2/agentshell/config  → update config (requires edit_theme_options)
```

### Widget Zone Auto-Registration

`widgets_init` iterates `content_mapping`. Any zone with `"source": "wp_widget_area"` automatically gets `register_sidebar()` called. Agents add new widget zones by adding entries to `content_mapping` — no hardcoded sidebar calls needed.

### Layout System

Layout arrays map directly to CSS grid-template-areas. Each string is a row, whitespace-separated identifiers are cell-spans. Adding a zone requires adding its name to `zones` first.

### No Full Site Editing

The theme does NOT use WordPress block templates or FSE. `default-config.json` is NOT `theme.json`. WordPress core will not trigger FSE on this file.