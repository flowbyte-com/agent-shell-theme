# AgentShell

A JSON-driven WordPress theme for human–agent collaboration. The shell is static and immutable; agents interact via REST API and CSS variables.

---

## For Humans: Setup

1. Upload `agentshell/` to `wp-content/themes/`
2. Activate in **Appearance → Themes**
3. First activation seeds `wp_options` from `default-config.json`
4. All further changes go through the REST API

### Sidebar Toggle (Admin)

**Appearance → Themes → Customize** or use REST:

```bash
curl -X PUT https://yoursite.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "sidebar_enabled": true }'
```

### Content Editing

AgentShell uses standard WordPress REST. Any content editor plugin that talks to `/wp/v2/pages/<id>` works.

---

## For Agents: Edit Guide

**See `AGENTS.md`** for the full protocol. Quick summary:

- **Design tokens** → edit `style.css :root` directly (no build step)
- **Content** → `PUT /wp/v2/pages/<id>` with `{ "content": { "raw": "..." } }`
- **Sidebar** → `PUT /wp-json/wp/v2/agentshell/config` with `{ "sidebar_enabled": true }`
- **Layout** → fixed CSS Grid, immutable — do not edit Sections 3–4 of style.css
- **Widgets** → use Web Components with Shadow DOM (see AGENTS.md)

**Auth:**
- Static token: `X-AgentShell-Token: agentshell_dev_token`
- Or Basic Auth with a WP Application Password

---

## Architecture

```
Shell (immutable)          →  header.php, footer.php, style.css Sections 3–4
Design tokens (editable)   →  style.css :root
Content (editable)         →  #zone-main via WP REST
Config (editable)          →  /wp/v2/agentshell/config
```

Zones: `#zone-header`, `#zone-main`, `#zone-sidebar`, `#zone-footer`

---

## Requirements

- WordPress 5.8+
- No paid plugins or dependencies
- Pure PHP + vanilla JS