# AgentShell — Safe Edit Prompts

Use these when you want a targeted, small change. They are more constrained than the UI Generation prompt to prevent the agent from accidentally modifying shell structure.

---

## Rule Zero

**The shell is untouchable.** The following files and zones are immutable — do not edit them, do not target them with injected styles, do not add HTML to them:

| Forbidden | Why |
|-----------|-----|
| `header.php`, `footer.php` | Static shell HTML — structural only |
| `style.css` Sections 3 & 4 | CSS Grid layout — do not edit |
| `#zone-header`, `#zone-nav`, `#zone-sidebar`, `#zone-footer` | Shell zone elements |
| `<style>:root{...}</style>` in content | Cannot set CSS variables from content |

---

## Safe Edit Reference

### 1. Edit CSS Variables (design tokens)

**Do this to change colors, fonts, spacing:**

Edit `style.css :root` directly with the variables you want to change. No build step — WordPress serves it as-is.

```bash
# Or via REST API (changes apply on next page load):
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "--theme-accent": "#ff6b6b", "--theme-bg": "#0d0d0d" }'
```

Available variables:
```
--theme-bg, --theme-surface, --theme-text, --theme-accent, --theme-border
--theme-header-bg, --theme-header-text, --theme-footer-bg, --theme-footer-text
--font-base, --font-mono, --spacing-base, --radius-base
```

### 2. Toggle Sidebar

```bash
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Authorization: Basic ..." \
  -d '{ "sidebar_enabled": true }'
```

### 3. Edit Page Content

To change text or layout **inside `#zone-main`**:

```bash
curl -X PUT https://example.com/wp-json/wp/v2/pages/<id> \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{ "content": { "raw": "<p>New paragraph here</p>" } }'
```

Only `#zone-main` accepts content edits. Do not target other zones.

### 4. Add Interactive Content (widgets)

For charts, calculators, terminals — wrap in a Web Component:

```html
<mpm-my-chart></mpm-my-chart>
<script>
if (!customElements.get('mpm-my-chart')) {
    class MyChart extends HTMLElement {
        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
        }
        connectedCallback() {
            this.shadowRoot.innerHTML = `<style>:host { display: block; }</style>
                <div class="chart"></div>`;
        }
    }
    customElements.define('mpm-my-chart', MyChart);
}
</script>
```

Rules: `mpm-` prefix, Shadow DOM, guard `customElements.define` with `if (!customElements.get(...))`, all CSS inside shadow root, pre-loaded `window.d3` and `window.math` available.

---

## Example Prompts

**"Style the header"** → Agent should edit `style.css :root` to change `--theme-header-bg`, `--theme-header-text`. Do not touch `header.php` or target `#zone-header` with injected styles.

**"Make the site dark mode"** → Agent changes `--theme-bg: #0d0d0d`, `--theme-surface: #1a1a1a`, `--theme-text: #e0e0e0`, `--theme-accent: #00d4ff`, `--theme-border: #2a2a2a` in `style.css :root`.

**"Add a login form to the sidebar"** → Impossible — agents cannot add content to `#zone-sidebar`. Suggest adding a widget to the Primary Sidebar widget area in WP Admin instead.

**"Change the site logo"** → Upload a logo in WP Admin → Appearance → Customize → Site Identity. Agents cannot inject a logo via content or CSS alone.

---

## What Looks Like a Shell Edit But Isn't

These common requests are actually content edits, not shell edits:

| Request | Actual target |
|---------|---------------|
| "Add a hero section" | Edit page content in `#zone-main` |
| "Show the user's name in the nav" | Cannot be done — nav is static shell |
| "Make the footer show current year" | Edit `footer.php` — **forbidden**. Add via WP widget or use JavaScript in `#zone-main` |
| "Hide the header on scroll" | Cannot be done — shell is static HTML/CSS |
| "Add a back button" | Add to page content in `#zone-main` |
