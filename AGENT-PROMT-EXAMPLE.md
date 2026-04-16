# AgentShell — Safe Edit Prompts

Use these when you want a targeted, small change. They are more constrained than the UI Generation prompt to prevent the agent from accidentally modifying shell structure.

---

## Rule Zero

**The shell PHP is untouchable.** Do not edit these files directly:

| Forbidden | Why |
|-----------|-----|
| `header.php`, `footer.php` | Static shell HTML — structural only |
| `style.css` Sections 3 & 4 | CSS Grid layout — do not edit |

**Everything else in `style.css` IS editable** — you CAN add CSS rules targeting shell zone elements (`#zone-header`, `#zone-nav`, etc.) as long as you do not touch Sections 3 & 4.

**Content payloads CANNOT inject styles** — do not include `<style>` blocks in REST API content payloads that target shell zones.

---

## What You Can Style

### CSS Variables — Global Design Tokens

Edit `style.css :root` directly. No build step — WordPress serves it as-is.

```bash
# Or via REST API:
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'user:app_password' | base64)" \
  -d '{ "--theme-accent": "#ff6b6b", "--theme-bg": "#0d0d0d" }'
```

```
--theme-bg, --theme-surface, --theme-text, --theme-accent, --theme-border
--theme-header-bg, --theme-header-text, --theme-footer-bg, --theme-footer-text
--font-base, --font-mono, --spacing-base, --radius-base
```

### Shell Zone Elements — Via style.css

You CAN add CSS to `style.css` (outside Sections 3 & 4) to target shell zone elements:

```css
/* header styling */
#zone-header {
    background: var(--theme-header-bg);
    color: var(--theme-header-text);
}

/* nav menu styling */
#zone-nav a {
    color: var(--theme-accent);
    transition: opacity 0.2s;
}
#zone-nav a:hover {
    opacity: 0.8;
}

/* sidebar styling */
#zone-sidebar {
    background: var(--theme-surface);
}

/* footer styling */
#zone-footer {
    background: var(--theme-footer-bg);
    color: var(--theme-footer-text);
}
```

**Rule:** You can style `#zone-header`, `#zone-nav`, `#zone-sidebar`, `#zone-footer` and their children — just do not edit Sections 3 & 4 (the grid layout) or the PHP files.

---

## Safe Edit Reference

### Toggle Sidebar

```bash
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -H "Authorization: Basic ..." \
  -d '{ "sidebar_enabled": true }'
```

### Edit Page Content

To change text or layout **inside `#zone-main`**:

```bash
curl -X PUT https://example.com/wp-json/wp/v2/pages/<id> \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic ..." \
  -d '{ "content": { "raw": "<p>New paragraph here</p>" } }'
```

Only `#zone-main` accepts content edits.

### Add Interactive Content (widgets)

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

**"Style the header and menu"** → Add CSS to `style.css` targeting `#zone-header`, `#zone-nav`, and `#zone-nav a`. Use `var(--theme-header-bg)`, `var(--theme-accent)` etc. Do not edit Sections 3 & 4.

**"Make the site dark mode"** → Change `--theme-bg`, `--theme-surface`, `--theme-text`, `--theme-accent`, `--theme-border` in `style.css :root`.

**"Make the footer show the current year"** → Add to page content in `#zone-main` using a Web Component with JS, or use a shortcode from a WP plugin.

**"Add a hero section"** → Edit page content in `#zone-main` via REST API.

**"Add a login form to the sidebar"** → Not possible via REST — add a widget to the Primary Sidebar in WP Admin instead.

---

## What Looks Like a Shell Edit But Isn't

| Request | Actual target |
|---------|---------------|
| "Change header background color" | Edit `--theme-header-bg` in `style.css :root` |
| "Style the nav links" | Add CSS to `style.css` targeting `#zone-nav a` |
| "Add a hero section" | Edit page content in `#zone-main` |
| "Change the site logo" | WP Admin → Appearance → Customize → Site Identity |
| "Show the user's name in the nav" | Cannot be done — nav is a WP menu, managed in WP Admin |
| "Make the footer show current year" | Add via Web Component in `#zone-main` |
| "Hide the header on scroll" | Add JS in `#zone-main` (Web Component) — not a shell edit |
| "Add a back button" | Add to page content in `#zone-main` |
