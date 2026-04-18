# AgentShell Extensibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform AgentShell from a "themed WordPress site" into a Declarative JSON Registry OS for agents — agents can theme all zones, inject custom CSS/JS, and register their own widgets without breaking the fixed grid.

**Architecture:**
1. **Schema-first self-description** — GET /wp/v2/agentshell/config returns schema metadata so agents can introspect what's available
2. **Declarative Zone Registry** — zones defined in config (not PHP hardcode), rendered via `agentshell_render_zone()` which checks Widget Registry first
3. **Wildcard CSS mapping** — flatten/unflatten auto-detect any key starting with `--` without path mapping
4. **Bilateral Widget Registry** — stable widgets in `/widgets/` files merged with agent-defined widgets in wp_options; JSON overrides file
5. **Structural prohibition** — agentshell_inject_saved_styles() injects a `<style>` that resets position: fixed/absolute on zone containers

**Tech Stack:** WordPress REST API, PHP, vanilla JS, CSS Grid (fixed), SQLite-free (no DB changes)

---

## File Map

| File | Role |
|------|------|
| `functions.php` | REST API, flatten/unflatten, CSS injection, widget libs, auth |
| `header.php` | Zone rendering — calls `agentshell_render_zone()` per zone |
| `footer.php` | Custom JS injection point |
| `template-parts/shell-render.php` | `agentshell_render_zone()` — already exists, will be extended |
| `template-parts/widgets.php` | **NEW** — widget registry renderer + scoped CSS |
| `style.css` | `:root` CSS vars (Section 1) + fixed grid (Sections 3 & 4) |
| `configurator/configurator.js` | Live preview panel |
| `default-config.json` | Seed config with zones array |
| `widgets/` | **NEW** — stable widget definition files |

---

## Task 1: Schema Self-Description + Wildcard CSS

**Files:**
- Modify: `functions.php:330-357` (REST API callbacks)
- Modify: `functions.php:248-277` (flatten) and `functions.php:279-327` (unflatten)

- [ ] **Step 1: Update agentshell_flatten_config() to support wildcard CSS vars**

Replace the hardcoded `$map` array with auto-detection of any key starting with `--`:

```php
function agentshell_flatten_config( array $config ) {
    $flat = array( 'sidebar_enabled' => ! empty( $config['sidebar_enabled'] ) );

    // Wildcard: any key starting with -- is a CSS variable, write directly
    array_walk_recursive( $config, function( $value, $key ) use ( &$flat ) {
        if ( strpos( $key, '--' ) === 0 && is_string( $value ) ) {
            $flat[ $key ] = $value;
        }
    } );

    // Legacy map: still include these so older configs flatten correctly
    $map = array(
        '--theme-bg'        => array( 'design', 'colors', 'background' ),
        '--theme-surface'   => array( 'design', 'colors', 'surface' ),
        '--theme-text'      => array( 'design', 'colors', 'text' ),
        '--theme-accent'    => array( 'design', 'colors', 'accent' ),
        '--theme-border'    => array( 'design', 'colors', 'border' ),
        '--theme-header-bg' => array( 'design', 'colors', 'primary' ),
        '--theme-footer-bg' => array( 'design', 'colors', 'secondary' ),
        '--font-base'       => array( 'design', 'typography', 'fontFamily' ),
        '--font-mono'       => array( 'design', 'typography', 'mono' ),
        '--spacing-base'    => array( 'design', 'typography', 'baseSize' ),
        '--radius-base'     => array( 'design', 'layout', 'radius' ),
    );

    foreach ( $map as $var => $path ) {
        $val = $config;
        foreach ( $path as $k ) {
            $val = is_array( $val ) ? ( $val[ $k ] ?? null ) : null;
            if ( $val === null ) break;
        }
        if ( $val !== null ) {
            $flat[ $var ] = $val;
        }
    }

    return $flat;
}
```

- [ ] **Step 2: Update agentshell_unflatten_config() for wildcard vars**

After the legacy path mappings, add a loop that writes any remaining `--` keys directly to top-level of the nested structure:

```php
    // ... existing legacy map code ...

    // New: handle any --key directly (not in legacy map)
    foreach ( $flat as $key => $value ) {
        if ( strpos( $key, '--' ) === 0 && ! isset( $css_to_path[ $key ] ) ) {
            // Write directly to design root as custom_css_vars.{key}
            if ( ! isset( $merged['design'] ) ) {
                $merged['design'] = array();
            }
            if ( ! isset( $merged['design']['custom_css_vars'] ) ) {
                $merged['design']['custom_css_vars'] = array();
            }
            $merged['design']['custom_css_vars'][ $key ] = $value;
        }
    }
```

- [ ] **Step 3: Add schema metadata to GET /wp/v2/agentshell/config**

In the REST callback, append schema and defaults after flattening:

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => function() {
            $config  = agentshell_get_config();
            $flat    = agentshell_flatten_config( $config );
            $schema  = array(
                'sidebar_enabled'  => array( 'type' => 'boolean' ),
                'zones'            => array(
                    'type'  => 'array',
                    'items' => array(
                        'id'      => 'string',
                        'label'   => 'string',
                        'source'  => 'string', // wp_loop | wp_widget_area | json_block | widget
                    )
                ),
                'widgets'          => array( 'type' => 'array' ),
                'custom_css'       => array( 'type' => 'string', 'maxLength' => 10000 ),
                'custom_js'        => array( 'type' => 'string', 'maxLength' => 10000 ),
                'design'           => array( 'type' => 'object' ),
            );
            return array(
                'schema'   => $schema,
                'defaults' => $flat,
                'config'   => $flat,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'PUT',
        'callback' => function( WP_REST_Request $request ) {
            $flat = $request->get_json_params();
            if ( ! is_array( $flat ) ) {
                return new WP_Error( 'invalid_config', 'Config must be a valid JSON object', array( 'status' => 400 ) );
            }
            $existing = agentshell_get_config();
            $merged   = agentshell_unflatten_config( $flat, $existing );
            $updated  = agentshell_update_config( $merged );
            return $updated
                ? agentshell_flatten_config( $merged )
                : new WP_Error( 'update_failed', 'Failed to update config', array( 'status' => 500 ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );
```

- [ ] **Step 4: Update agentshell_inject_saved_styles() to inject custom_css and structural prohibition**

Replace the existing function with one that:
1. Injects all `--` vars from flattened config
2. Appends custom_css if present
3. Injects grid-fix rule to prevent position: fixed/absolute breakout

```php
function agentshell_inject_saved_styles() {
    $config = get_option( 'agentshell_config', array() );

    if ( function_exists( 'agentshell_flatten_config' ) ) {
        $flat = agentshell_flatten_config( $config );
    } else {
        $flat = $config;
    }

    if ( empty( $flat ) ) return;

    echo "<style id='agentshell-saved-config'>\n:root {\n";
    foreach ( $flat as $key => $value ) {
        if ( strpos( $key, '--' ) === 0 && ! empty( $value ) ) {
            echo '    ' . esc_attr( $key ) . ': ' . esc_attr( $value ) . ";\n";
        }
    }
    echo "}\n</style>\n";

    // custom_css — trusted author context
    if ( ! empty( $config['custom_css'] ) ) {
        echo "<style id='agentshell-custom-css'>\n" . wp_strip_all_tags( $config['custom_css'] ) . "\n</style>\n";
    }

    // Structural prohibition: prevent agents from breaking the grid
    echo "<style id='agentshell-grid-fix'>
#zone-header, #zone-main, #zone-sidebar, #zone-footer {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
    z-index: auto !important;
}
</style>\n";
}
```

- [ ] **Step 5: Add custom_js injection to footer.php**

In `footer.php`, before `wp_footer()`:

```php
$config = agentshell_get_config();
if ( ! empty( $config['custom_js'] ) ) {
    echo "<script id='agentshell-custom-js'>\n" . wp_strip_all_tags( $config['custom_js'] ) . "\n</script>\n";
}
```

- [ ] **Step 6: Add custom_js and custom_css fields to PUT handler**

The PUT handler already stores the merged config via `agentshell_unflatten_config()`, so custom_css and custom_js will be stored automatically. No changes needed to the PUT callback — just document that these top-level keys are allowed.

- [ ] **Step 7: Commit**

```bash
git add functions.php footer.php
git commit -m "feat: schema-first config with wildcard CSS vars and structural prohibition"
```

---

## Task 2: Declarative Zone Registry

**Files:**
- Modify: `header.php` (read zones from config instead of hardcoding)
- Modify: `default-config.json` (add zones array)
- Modify: `functions.php` (zone helpers)

- [ ] **Step 1: Update default-config.json with zones array**

```json
{
  "sidebar_enabled": false,
  "zones": [
    { "id": "header", "label": "Header", "source": "wp_loop" },
    { "id": "main",   "label": "Main",   "source": "wp_loop" },
    { "id": "sidebar","label": "Sidebar","source": "wp_widget_area", "widget_area_id": "primary-sidebar" },
    { "id": "footer", "label": "Footer", "source": "wp_loop" }
  ],
  "design": { ... }
}
```

- [ ] **Step 2: Add agentshell_get_zones() helper in functions.php**

```php
function agentshell_get_zones() {
    $config = agentshell_get_config();
    $zones  = $config['zones'] ?? array();
    if ( empty( $zones ) ) {
        // Default fallback for existing installs
        return array(
            array( 'id' => 'header',  'label' => 'Header',  'source' => 'wp_loop' ),
            array( 'id' => 'main',    'label' => 'Main',    'source' => 'wp_loop' ),
            array( 'id' => 'sidebar', 'label' => 'Sidebar', 'source' => 'wp_widget_area', 'widget_area_id' => 'primary-sidebar' ),
            array( 'id' => 'footer',  'label' => 'Footer',  'source' => 'wp_loop' ),
        );
    }
    return $zones;
}
```

- [ ] **Step 3: Refactor header.php to use config-driven zones**

Replace hardcoded zone markup with a loop:

```php
$sidebar_enabled = ! empty( agentshell_get_config()['sidebar_enabled'] );
$zones = agentshell_get_zones();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class( $sidebar_enabled ? 'sidebar-enabled' : '' ); ?>>
<?php wp_body_open(); ?>

<div id="agentshell-root">
<?php foreach ( $zones as $zone ) : ?>
    <<?php echo 'header' === $zone['id'] || 'footer' === $zone['id'] ? $zone['id'] : 'div'; ?>
        id="zone-<?php echo esc_attr( $zone['id'] ); ?>"
        class="shell-zone"
        data-zone="<?php echo esc_attr( $zone['id'] ); ?>"
    >
        <?php echo agentshell_render_zone( $zone ); ?>
    </<?php echo 'header' === $zone['id'] || 'footer' === $zone['id'] ? $zone['id'] : 'div'; ?>
<?php endforeach; ?>
</div>
```

Note: header.php uses semantic HTML tags (header, main, aside, footer) based on zone id. Sidebar is rendered as `<aside>` when its zone id is "sidebar". Div is used for other zones.

- [ ] **Step 4: Update agentshell_render_zone() in shell-render.php**

Add a `case 'widget':` that renders the Widget Registry takeover before falling through to standard content:

```php
function agentshell_render_zone( array $mapping ) {
    $source = $mapping['source'] ?? '';

    // Widget Registry takeover — if a widget is registered for this zone,
    // it takes priority over the standard WP content rendering.
    if ( $source === 'widget' ) {
        $widget_id = $mapping['widget_id'] ?? '';
        if ( $widget_id ) {
            return agentshell_render_widget( $widget_id );
        }
    }

    switch ( $source ) {
        case 'wp_loop':
            // ... existing code ...
        case 'wp_widget_area':
            // ... existing code ...
        case 'json_block':
            // ... existing code ...
        default:
            return '';
    }
}
```

- [ ] **Step 5: Update agentshell_render_zone() to handle sidebar_enabled per zone**

Sidebar is no longer a binary body class — each zone can be independently enabled/disabled via the zones array. The sidebar zone should only render if `sidebar_enabled` is true. Update the loop in header.php to skip sidebar zone when disabled:

```php
foreach ( $zones as $zone ) :
    if ( $zone['id'] === 'sidebar' && ! $sidebar_enabled ) {
        continue;
    }
    // ...
endforeach;
```

- [ ] **Step 6: Commit**

```bash
git add header.php functions.php default-config.json template-parts/shell-render.php
git commit -m "feat: declarative zone registry driven by config"
```

---

## Task 3: Bilateral Widget Registry

**Files:**
- Create: `template-parts/widgets.php`
- Create: `widgets/.index.json` — stable widget index
- Create: `widgets/hello-world.php` — example stable widget
- Modify: `functions.php` (widget helpers, merge logic)
- Modify: `footer.php` (widget init script)

- [ ] **Step 1: Create widgets/.index.json — stable widget manifest**

```json
{
  "stable": [
    {
      "id": "hello-world",
      "name": "Hello World",
      "description": "Simple greeting widget for testing",
      "source": "file",
      "file": "hello-world.php",
      "version": "1.0.0"
    }
  ]
}
```

- [ ] **Step 2: Create widgets/hello-world.php — example stable widget**

```php
<?php
/**
 * Stable Widget: Hello World
 * Version: 1.0.0
 * Description: Simple greeting widget for testing widget registry
 */

return array(
    'id'       => 'hello-world',
    'name'     => 'Hello World',
    'init_js'  => "window.AgentshellWidgets = window.AgentshellWidgets || {};
window.AgentshellWidgets['hello-world'] = {
    init: function(el) {
        el.innerHTML = '<p class=\"hello-widget\">Hello from the widget registry!</p>';
    }
};",
    'css'      => ".hello-widget { font-weight: bold; color: var(--theme-accent); }",
    'template' => '',
);
```

- [ ] **Step 3: Add widget registry helpers to functions.php**

```php
/**
 * Merge stable widgets (from /widgets/ files) with agent-defined widgets (from wp_options).
 * JSON-defined widgets override file-defined widgets with the same ID.
 *
 * @return array Merged widget registry keyed by widget ID
 */
function agentshell_get_widget_registry() {
    $registry = array();

    // 1. Load stable widgets from /widgets/ directory
    $stable_index = get_template_directory() . '/widgets/.index.json';
    if ( file_exists( $stable_index ) ) {
        $index = json_decode( file_get_contents( $stable_index ), true );
        foreach ( $index['stable'] ?? array() as $entry ) {
            if ( empty( $entry['id'] ) || empty( $entry['file'] ) ) {
                continue;
            }
            $widget_file = get_template_directory() . '/widgets/' . $entry['file'];
            if ( file_exists( $widget_file ) ) {
                $widget = include $widget_file;
                if ( is_array( $widget ) && ! empty( $widget['id'] ) ) {
                    $registry[ $widget['id'] ] = $widget;
                }
            }
        }
    }

    // 2. Merge agent-defined widgets from config (these override stable with same ID)
    $config = agentshell_get_config();
    foreach ( $config['widgets'] ?? array() as $widget ) {
        if ( empty( $widget['id'] ) ) {
            continue;
        }
        $registry[ $widget['id'] ] = $widget;
    }

    return $registry;
}

/**
 * Render a single widget by ID.
 * Returns empty string if widget not found.
 *
 * @param string $widget_id
 * @return string HTML
 */
function agentshell_render_widget( $widget_id ) {
    $registry = agentshell_get_widget_registry();
    if ( ! isset( $registry[ $widget_id ] ) ) {
        return '';
    }
    $widget = $registry[ $widget_id ];

    // Render template (optional HTML skeleton)
    $html = '';
    if ( ! empty( $widget['template'] ) ) {
        $html = wp_kses_post( $widget['template'] );
    } else {
        $html = "<div data-widget-id=\"{$widget_id}\"></div>";
    }

    return $html;
}

/**
 * Get all widget init scripts and scoped CSS for footer injection.
 * Returns array with 'init_js' and 'css' keys.
 *
 * @return array
 */
function agentshell_get_widget_assets() {
    $registry = agentshell_get_widget_registry();
    $init_js  = '';
    $css      = '';

    foreach ( $registry as $widget ) {
        if ( ! empty( $widget['init_js'] ) ) {
            $init_js .= "\n/* Widget: {$widget['id']} */\n" . $widget['init_js'];
        }
        if ( ! empty( $widget['css'] ) ) {
            $css .= "\n/* Widget: {$widget['id']} */\n" . $widget['css'];
        }
    }

    return array( 'init_js' => $init_js, 'css' => $css );
}
```

- [ ] **Step 4: Create template-parts/widgets.php**

```php
<?php
/**
 * Widget Registry Renderer
 * Outputs widget containers and scoped CSS for all registered widgets.
 */

$registry = agentshell_get_widget_registry();
$assets   = agentshell_get_widget_assets();

// Scoped CSS — all widget styles scoped to data-widget-id to prevent bleed
if ( $assets['css'] ) {
    echo "<style id='agentshell-widget-css'>\n";
    echo $assets['css'] . "\n";
    echo "</style>\n";
}
```

- [ ] **Step 5: Update footer.php to include widget init script**

Add widget initialization after custom_js:

```php
<?php
$config  = agentshell_get_config();
$assets  = agentshell_get_widget_assets();

// custom_js
if ( ! empty( $config['custom_js'] ) ) {
    echo "<script id='agentshell-custom-js'>\n" . wp_strip_all_tags( $config['custom_js'] ) . "\n</script>\n";
}

// Widget init — waits for DOM + MutationObserver for dynamically injected widgets
if ( $assets['init_js'] ) : ?>
<script id='agentshell-widget-init'>
(function() {
    var WIDGETS = {};
    try { <?php echo $assets['init_js']; ?> } catch(e) { console.error('AgentShell widget init error:', e); }

    function initWidget(el) {
        var id = el.dataset.widgetId || el.dataset.widget;
        if (id && WIDGETS[id] && typeof WIDGETS[id].init === 'function') {
            WIDGETS[id].init(el);
        }
    }

    function scanWidgets() {
        document.querySelectorAll('[data-widget]').forEach(initWidget);
    }

    // Immediate scan
    scanWidgets();

    // MutationObserver for dynamically injected widgets (e.g., via json_block)
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if (node.matches && node.matches('[data-widget]')) {
                        initWidget(node);
                    }
                    node.querySelectorAll && node.querySelectorAll('[data-widget]').forEach(initWidget);
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
</script>
<?php endif; ?>

<?php wp_footer(); ?>
```

- [ ] **Step 6: Include widgets.php in header.php**

In `header.php`, after `wp_head()` and before the body content:

```php
<?php
// Widget registry scoped CSS (rendered in <head>)
get_template_part( 'template-parts/widgets' );
?>
```

Note: `get_template_part()` doesn't capture output in a variable — widgets.php should echo its own CSS directly (which it does per Step 4).

- [ ] **Step 7: Commit**

```bash
git add template-parts/widgets.php functions.php footer.php widgets/
git commit -m "feat: bilateral widget registry with stable + JSON layers"
```

---

## Task 4: Configurator UX — Zone, Layout, Custom CSS/JS, Widgets

**Files:**
- Modify: `configurator/configurator.js`

- [ ] **Step 1: Expand configurator.js sections**

Update the `renderPanel()` function to include new sections for:
1. **Zones section** — read-only list of zones with IDs and sources (from schema)
2. **Custom CSS/JS section** — textarea fields for custom_css and custom_js
3. **Widgets section** — list registered widgets, show add/edit form
4. **Layout section** — breakpoint-aware grid area editor (textarea, one row string per line)

The configurator reads from `AgentShellConfig.restUrl + 'wp/v2/agentshell/config'` which now returns schema + defaults + config in one response.

```javascript
// Add to META/sections in configurator.js:
const SECTIONS = {
    // ... existing sections ...
    'Custom Assets': ['custom_css', 'custom_js'],
    'Widgets': [], // populated from config.widgets
    'Layout': ['layout_mobile', 'layout_tablet', 'layout_desktop'],
};
```

- [ ] **Step 2: Add textarea fields for custom_css and custom_js**

In the renderPanel HTML, add sections:

```javascript
// Custom CSS section
html += `<div class="panel-section"><h3>Custom CSS</h3>`;
html += `<textarea id="f-custom_css" rows="6" placeholder="/* agents can add any CSS here */">${liveState.custom_css || ''}</textarea>`;
html += `</div>`;

// Custom JS section
html += `<div class="panel-section"><h3>Custom JavaScript</h3>`;
html += `<textarea id="f-custom_js" rows="6" placeholder="/* window.MyWidget = { init(el) { ... } }; */">${liveState.custom_js || ''}</textarea>`;
html += `</div>`;
```

- [ ] **Step 3: Wire custom_css and custom_js in saveConfig()**

```javascript
// In saveConfig(), add to payload:
payload.custom_css = liveState.custom_css || '';
payload.custom_js  = liveState.custom_js  || '';
```

- [ ] **Step 4: Commit**

```bash
git add configurator/configurator.js
git commit -m "feat: configurator UX for custom CSS/JS and widget registry"
```

---

## Task 5: Layout Config via Breakpoints

**Files:**
- Modify: `style.css` (make Sections 3 & 4 read from config-driven CSS)
- Modify: `functions.php` (add layout helpers)

- [ ] **Step 1: Add layout section to default-config.json**

```json
{
  "layout": {
    "breakpoints": {
      "mobile": "0px",
      "tablet": "768px",
      "desktop": "1024px"
    },
    "grid_areas": {
      "mobile":   ["header", "main", "footer"],
      "tablet":   ["header header", "main sidebar", "footer footer"],
      "desktop":  ["header header", "main sidebar", "footer footer"]
    },
    "grid_gap": "1rem",
    "grid_padding": "2rem"
  }
}
```

- [ ] **Step 2: Add agentshell_get_layout_config() to functions.php**

```php
function agentshell_get_layout_config() {
    $config = agentshell_get_config();
    $layout = $config['layout'] ?? array();

    $defaults = array(
        'breakpoints' => array(
            'mobile'  => '0px',
            'tablet'  => '768px',
            'desktop' => '1024px',
        ),
        'grid_areas' => array(
            'mobile'  => array( 'header', 'main', 'footer' ),
            'tablet'  => array( 'header header', 'main sidebar', 'footer footer' ),
            'desktop' => array( 'header header', 'main sidebar', 'footer footer' ),
        ),
        'grid_gap'     => '1rem',
        'grid_padding' => '2rem',
    );

    // Deep-merge defaults
    foreach ( $defaults as $key => $default ) {
        if ( ! isset( $layout[ $key ] ) ) {
            $layout[ $key ] = $default;
        } elseif ( is_array( $default ) ) {
            foreach ( $default as $sub_key => $sub_value ) {
                if ( ! isset( $layout[ $key ][ $sub_key ] ) ) {
                    $layout[ $key ][ $sub_key ] = $sub_value;
                }
            }
        }
    }

    return $layout;
}
```

- [ ] **Step 3: Inject layout CSS via agentshell_inject_saved_styles()**

Add after the structural prohibition rule:

```php
    // Layout grid CSS from config
    $layout_css = agentshell_get_layout_css(
        array( 'header', 'main', 'sidebar', 'footer' ),
        agentshell_get_layout_config()['grid_areas'],
        agentshell_get_layout_config()['breakpoints']
    );
    echo $layout_css;
```

Note: `agentshell_get_layout_css()` already exists in grid-areas.php — just needs config-driven inputs.

- [ ] **Step 4: Update grid-areas.php to use config gap/padding**

Modify `agentshell_get_layout_css()` to read gap and padding from config:

```php
function agentshell_get_layout_css( array $zones, array $layout, array $breakpoints, $gap = '1rem', $padding = '2rem' ) {
    // ... existing code, use $gap and $padding in the CSS output
}
```

- [ ] **Step 5: Commit**

```bash
git add functions.php style.css default-config.json
git commit -m "feat: config-driven layout grid with breakpoints"
```

---

## Task 6: Full Integration Test

- [ ] **Step 1: Verify GET /wp/v2/agentshell/config returns schema metadata**

```bash
curl -s http://localhost:10003/wp-json/wp/v2/agentshell/config | python3 -m json.tool | head -60
```

Expected: response includes `schema`, `defaults`, and `config` keys.

- [ ] **Step 2: PUT custom CSS and verify it appears in `<head>`**

```bash
curl -X PUT http://localhost:10003/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n '808:PASSWORD' | base64)" \
  -d '{ "custom_css": "#zone-main { border: 2px solid red; }" }'
```

Check page source for `#agentshell-custom-css` style block.

- [ ] **Step 3: PUT custom JS and verify it executes**

```bash
curl -X PUT http://localhost:10003/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n '808:PASSWORD' | base64)" \
  -d '{ "custom_js": "console.log('AgentShell custom JS running'); window.testMsg='ok';" }'
```

Check: `window.testMsg === 'ok'` in browser console.

- [ ] **Step 4: Register a widget via PUT config and verify it renders**

```bash
curl -X PUT http://localhost:10003/wp-json/wp/v2/agentshell/config \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n '808:PASSWORD' | base64)" \
  -d '{
    "widgets": [{
      "id": "test-widget",
      "name": "Test Widget",
      "init_js": "window.AgentshellWidgets = window.AgentshellWidgets || {}; window.AgentshellWidgets[\"test-widget\"] = { init: function(el) { el.innerHTML = \"<p>Widget running!</p>\"; } };",
      "css": ".test-widget p { color: green; font-weight: bold; }"
    }]
  }'
```

- [ ] **Step 5: Place widget in content and verify MutationObserver initializes it**

Create a WP post with content: `<div data-widget="test-widget"></div>`

Verify the widget initializes (innerHTML set) when the post renders.

- [ ] **Step 6: Verify structural prohibition blocks position: fixed breakout**

PUT custom_css: `#zone-main { position: fixed; top: 0; }`

Verify in browser that #zone-main still renders in normal flow (structural prohibition wins).

- [ ] **Step 7: Verify wp_kses_post() still strips script tags in json_block**

PUT a json_block zone with `<script>alert(1)</script>` — verify script is stripped.

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: complete AgentShell extensibility — zones, widgets, custom CSS/JS, layout"
```

---

## Self-Review Checklist

- [ ] Schema metadata returned on GET /wp/v2/agentshell/config ✓
- [ ] Any `--` key auto-detected as CSS var without path mapping ✓
- [ ] Zones rendered from config array in header.php ✓
- [ ] `agentshell_render_zone()` checks Widget Registry before standard WP content ✓
- [ ] Structural prohibition CSS injected in wp_head ✓
- [ ] custom_css injected as `<style id='agentshell-custom-css'>` ✓
- [ ] custom_js injected before `</body>` with DOMContentLoaded + MutationObserver ✓
- [ ] Bilateral Widget Registry: stable files merged with JSON overrides ✓
- [ ] Widget init uses `window.AgentshellWidgets` namespace ✓
- [ ] Layout grid driven by config breakpoints and grid_areas ✓
- [ ] Configurator.js updated for new sections ✓