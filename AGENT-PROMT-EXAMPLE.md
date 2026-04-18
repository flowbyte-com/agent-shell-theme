
# AgentShell — Safe Edit Prompts

Use these when you want a targeted, small change. These rules prevent accidental shell deconstruction while allowing for full design flexibility.

---

## Rule Zero: The Shell Hierarchy

| File / Zone | Status | Role |
| :--- | :--- | :--- |
| `header.php`, `footer.php` | **Forbidden** | Static shell HTML — structural only. |
| `template-parts/grid-areas.php` | **Grid OS Only** | Only edit to fix track logic or selector scope. |
| `style.css` (Sec 3 & 4) | **Forbidden** | Static Grid foundation. |
| `style.css` (Other) | **Editable** | Add CSS targeting `#zone-header`, `#zone-nav`, etc. |
| `widgets/` | **Editable** | Add stable widgets as `*.php` files + register in `.index.json`. |
| **REST Config** | **Primary** | Toggle `sidebar_enabled`, update Design Tokens, inject `custom_css`/`custom_js`. |

**Rule:** Content payloads (`#zone-main`) **cannot** inject `<style>` blocks that target other shell zones. Use `style.css` for shell styling.

---

## The Unbreakable Grid Protocol

To prevent the "Content Collapse" issue (where content shrinks or pushes left when the sidebar is off), always follow these track rules:

1. **The Rule of 1fr:** The base `#agentshell-root` must always have `grid-template-columns: 1fr;`. This ensures the content expands to fill the 1200px (or 1280px) max-width by default.
2. **Descendant Scoping:** Never use `#agentshell-root.sidebar-enabled`. Always use `.sidebar-enabled #agentshell-root`. The class is on the `<body>`, and the grid is the child.
3. **No String Smashing:** When generating `grid-template-areas` in PHP, every row MUST be individually quoted and separated by spaces.
    * **Bad:** `"header main footer"`  ← cells merge into one column
    * **Good:** `"header" "main" "footer"`  ← properly quoted rows

---

## What You Can Style

### Custom CSS and JS via REST API

Inject arbitrary CSS and JS through the config endpoint. Both are **trusted author context** — output raw, no sanitization.

```bash
# Custom CSS — injected as <style id='agentshell-custom-css'> in <head>
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -d '{ "custom_css": "#zone-main { border: 2px solid red; }" }'

# Custom JS — injected as <script> before </body>
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -d '{ "custom_js": "window.MyWidget = { init(el) { el.innerHTML = \"Hi!\"; } };" }'
```

### Design Tokens (CSS Variables)
Edit `:root` variables in `style.css` directly. These drive the global aesthetic.

```css
--theme-bg, --theme-surface, --theme-text, --theme-accent, --theme-border
--theme-header-bg, --theme-footer-bg
--font-base, --font-mono, --spacing-base, --radius-base
```

### Shell Zones
You CAN target shell zones in `style.css` as long as you do not touch the Grid Sections (3 & 4).

```css
#zone-header { background: var(--theme-header-bg); }
#zone-nav a { color: var(--theme-accent); }
#zone-footer { border-top: 1px solid var(--theme-border); }
```

---

## Safe Edit Reference

### Toggle Sidebar
```bash
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -d '{ "sidebar_enabled": true }'
```

## Widgets

AgentShell has a **bilateral widget registry** — stable widgets from `/widgets/*.php` files merged with agent-defined widgets from `wp_options`.

### Stable Widgets (file-based)

Add a widget by creating two files:

**1. `widgets/hello-world.php`** — returns a widget definition array:

```php
<?php
return array(
    'id'       => 'hello-world',
    'name'     => 'Hello World',
    'init_js'  => "window.AgentshellWidgets = window.AgentshellWidgets || {};
window.AgentshellWidgets['hello-world'] = {
    init: function(el) {
        el.innerHTML = '<p>Hello from the widget registry!</p>';
    }
};",
    'css'      => ".hello-world p { font-weight: bold; color: var(--theme-accent); }",
    'template' => "<div data-widget-id=\"hello-world\"></div>",
);
```

**2. Register in `widgets/.index.json`:**

```json
{
  "stable": [
    { "id": "hello-world", "name": "Hello World", "source": "file", "file": "hello-world.php", "version": "1.0.0" }
  ]
}
```

### Agent-Defined Widgets (via REST API)

Agent widgets in `wp_options` override stable widgets with the same ID:

```bash
curl -X PUT https://example.com/wp-json/wp/v2/agentshell/config \
  -d '{
    "widgets": [{
      "id": "my-widget",
      "name": "My Widget",
      "init_js": "window.AgentshellWidgets[\"my-widget\"] = { init: function(el) { el.innerHTML = \"<p>Running!</p>\"; } };",
      "css": ".my-widget p { color: green; }"
    }]
  }'
```

Widget `init_js` populates `window.AgentshellWidgets[id]` and a `MutationObserver` in `footer.php` auto-initializes all `[data-widget-id]` elements on the page — including dynamically injected ones.

---

### Fix Layout Alignment (The "Pushed Left" Fix)
If the content is not filling the width when the sidebar is off, the agent must check `template-parts/grid-areas.php` for:
* **Base track size:** Ensure `grid-template-columns` is `1fr`.
* **Media Query Scope:** Ensure 2-column rules are wrapped in `.sidebar-enabled #agentshell-root`.


## Common Mistakes

* **Wrong Selector Scope:** Using `#agentshell-root.sidebar-enabled` (fails because class is on body).
* **Missing 1fr:** Leaving columns as `auto`, which causes the main zone to shrink to its smallest element.
* **PHP in Post Content:** Attempting to use PHP tags in a `wp_loop` payload (stripped by WP). Use the Web Component Protocol for logic.
* **Direct Shell HTML Edits:** Modifying `header.php` to add a div. Add the element via the REST API to `#zone-main` or use the `json_block` source instead.
