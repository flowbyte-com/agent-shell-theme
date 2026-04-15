# AgentShell

A JSON-driven WordPress theme for seamless human-agent collaboration. The entire site Shell — header, footer, menus, layout grid, and design tokens — is controlled by a single config object, editable by both humans and AI agents.

## What It Does

**Shell vs Content separation:**

- **Shell** (header, footer, navigation, sidebar, layout grid) — fully JSON-driven, agent-editable
- **Content** (posts, pages, Gutenberg blocks) — standard WordPress, no shell interference

Agents update the entire site appearance by reading and writing one JSON object. No PHP templates touched.

## Setup

1. Upload this theme to your WordPress installation's `wp-content/themes/agentshell/`
2. Activate in **Appearance → Themes**
3. On first activation, `wp_options` is seeded from `default-config.json`
4. After installation, all shell changes go through the REST API or the built-in configurator

## Configuring

### Live Configurator

A floating button (bottom-right) opens a push-sidebar with live preview. Change any design token, layout, or navigation item and save — page reloads with the new shell.

### REST API

```bash
# Read config
curl https://example.com/wp-json/wp/v2/agentshell/config

# Update config (requires authentication)
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d @default-config.json
```

### Config Fields

| Field | Description |
|-------|-------------|
| `design.colors` | CSS custom properties for primary, secondary, accent, background, text |
| `design.typography` | Font family, base size, type scale |
| `design.logo` | Logo URL, width, height |
| `design.breakpoints` | mobile/tablet/desktop thresholds |
| `layout` | CSS grid-template-areas per breakpoint |
| `navigation.primary` | Header nav items with optional nested children |
| `navigation.footer_links` | Footer nav items |
| `content_mapping` | Zone sources: `wp_loop`, `wp_widget_area`, or `json_block` |

## Content Zone Sources

- **wp_loop** — Standard WordPress query loop for post/page content
- **wp_widget_area** — Dynamic sidebar zones for plugin widgets
- **json_block** — Raw HTML (sanitized with `wp_kses_post()`)

## Requirements

- WordPress 5.8+
- No paid plugins or dependencies
- Pure PHP + vanilla JS (no frameworks)

## File Structure

```
agentshell/
├── style.css              # Theme declaration only
├── functions.php          # REST endpoint, config helpers, asset enqueue
├── default-config.json    # Seed file (IDE reference, not read at runtime)
├── header.php             # Renders CSS vars + layout + header zone
├── footer.php             # Closes shell grid + configurator trigger
├── template-parts/
│   ├── shell-render.php   # Zone renderer, nav renderer, CSS vars
│   └── grid-areas.php     # Layout array → CSS grid parser
├── configurator/
│   ├── configurator.js    # Push sidebar, auto-adaptive forms, REST sync
│   └── configurator.css   # Panel docking styles
└── assets/
    └── logo.png           # Default logo
```