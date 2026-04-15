# AgentShell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress theme where the site Shell (header, footer, menus, layout grid) is entirely JSON-driven via `shell-config.json`, with a live-preview push-sidebar configurator.

**Architecture:** Shell is decoupled from WordPress template hierarchy. PHP templates read from `wp_options` at runtime. CSS is generated dynamically from layout arrays and design tokens. Gutenberg is restricted to `the_content()` only.

**Tech Stack:** Vanilla PHP, vanilla JS (no frameworks), CSS Grid, WordPress REST API, `wp_kses_post()` for sanitization.

---

## File Map

```
agentshell/
├── style.css                        # Theme header only
├── functions.php                    # Theme setup, enqueue, REST endpoint, helpers
├── shell-config.json                # Default config (mirrors wp_options)
├── index.php                        # Fallback template
├── front-page.php                   # Static front page
├── singular.php                     # Single post/page
├── header.php                       # Injects CSS vars, calls shell-render
├── footer.php                       # Closes shell zones
├── template-parts/
│   ├── grid-areas.php               # layout[] arrays → grid-template-areas CSS
│   └── shell-render.php             # Config reader, zone renderer, nav renderer
├── configurator/
│   ├── configurator.js             # Push sidebar, live preview, auto-adapt forms
│   └── configurator.css            # Panel docking styles
└── assets/
    └── logo.png                     # Default logo placeholder
```

---

## Task 1: Theme Scaffold

**Files:**
- Create: `style.css`
- Create: `shell-config.json`
- Create: `index.php`
- Create: `front-page.php`
- Create: `singular.php`

- [ ] **Step 1: Write style.css**

```css
/*
Theme Name: AgentShell
Theme URI: https://example.com/agentshell
Author: Your Name
Author URI: https://example.com
Description: JSON-driven WordPress theme for human-agent collaboration
Version: 1.0.0
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: agentshell
*/
```

- [ ] **Step 2: Write shell-config.json** (the default config)

```json
{
  "design": {
    "breakpoints": {
      "mobile": "0px",
      "tablet": "768px",
      "desktop": "1024px"
    },
    "colors": {
      "primary": "#1a1a2e",
      "secondary": "#16213e",
      "accent": "#e94560",
      "background": "#ffffff",
      "text": "#333333"
    },
    "typography": {
      "fontFamily": "system-ui, sans-serif",
      "baseSize": "16px",
      "scale": "1.25"
    },
    "logo": {
      "url": "/wp-content/themes/agentshell/assets/logo.png",
      "width": "140px",
      "height": "40px"
    },
    "favicon": ""
  },
  "layout": {
    "mobile": ["header", "main", "footer"],
    "tablet": ["header header", "main main", "footer footer"],
    "desktop": ["header header", "main sidebar", "footer footer"]
  },
  "navigation": {
    "primary": [
      { "label": "Home", "url": "/" }
    ],
    "footer_links": [
      { "label": "Privacy Policy", "url": "/privacy" }
    ]
  },
  "content_mapping": {
    "header": { "source": "json_block", "html": "<span>© 2026</span>" },
    "sidebar": { "source": "wp_widget_area", "id": "primary-sidebar" },
    "main": { "source": "wp_loop" },
    "footer": { "source": "json_block", "html": "<p>Built with AgentShell</p>" }
  }
}
```

- [ ] **Step 3: Write index.php** (fallback template)

```php
<?php
/**
 * Fallback template - renders main zone with WP Loop
 */
get_header();
?>
<main class="shell-zone shell-zone--main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <?php the_content(); ?>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer();
```

- [ ] **Step 4: Write front-page.php** (static front page, same structure)

```php
<?php
/**
 * Front page template
 */
get_header();
?>
<main class="shell-zone shell-zone--main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <?php the_content(); ?>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer();
```

- [ ] **Step 5: Write singular.php** (single post/page)

```php
<?php
/**
 * Singular template - single post or page
 */
get_header();
?>
<main class="shell-zone shell-zone--main">
    <?php while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header>
                <?php if ( is_single() ) : ?>
                    <h1><?php the_title(); ?></h1>
                <?php else : ?>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <?php endif; ?>
            </header>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>
<?php get_footer();
```

- [ ] **Step 6: Commit**

```bash
git add style.css shell-config.json index.php front-page.php singular.php
git commit -m "feat: scaffold AgentShell theme files"
```

---

## Task 2: functions.php — Theme Setup + Config API

**Files:**
- Create: `functions.php`

- [ ] **Step 1: Write functions.php**

```php
<?php
/**
 * AgentShell theme functions
 */

/**
 * Register agentshell_config option on theme activation
 */
function agentshell_theme_activation() {
    $default_config = json_decode( file_get_contents( get_template_directory() . '/shell-config.json' ), true );
    if ( get_option( 'agentshell_config' ) === false ) {
        add_option( 'agentshell_config', $default_config );
    }
}
add_action( 'after_switch_theme', 'agentshell_theme_activation' );

/**
 * Enqueue theme assets
 */
function agentshell_enqueue_assets() {
    wp_enqueue_style( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.css', array(), '1.0.0' );
    wp_enqueue_script( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.js', array(), '1.0.0', true );
    wp_localize_script( 'agentshell-configurator', 'AgentShellConfig', array(
        'adminUrl'   => admin_url( 'admin-ajax.php' ),
        'restUrl'    => rest_url( 'wp/v2/settings' ),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_assets' );

/**
 * Register primary sidebar widget area
 */
function agentshell_widgets_init() {
    register_sidebar( array(
        'name'          => 'Primary Sidebar',
        'id'            => 'primary-sidebar',
        'description'   => 'Widgets for the primary sidebar zone',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ) );
}
add_action( 'widgets_init', 'agentshell_widgets_init' );

/**
 * Get the full shell config
 * @return array
 */
function agentshell_get_config() {
    $config = get_option( 'agentshell_config' );
    if ( ! $config || is_wp_error( $config ) ) {
        $config = json_decode( file_get_contents( get_template_directory() . '/shell-config.json' ), true );
    }
    return $config;
}

/**
 * Update the shell config atomically
 * @param array $config
 * @return bool
 */
function agentshell_update_config( array $config ) {
    return update_option( 'agentshell_config', $config );
}

/**
 * REST API: PUT /wp/v1/agentshell/config
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => function() {
            return agentshell_get_config();
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'PUT',
        'callback' => function( WP_REST_Request $request ) {
            $config = $request->get_json_params();
            if ( ! is_array( $config ) ) {
                return new WP_Error( 'invalid_config', 'Config must be a valid JSON object', array( 'status' => 400 ) );
            }
            $updated = agentshell_update_config( $config );
            return $updated ? agentshell_get_config() : new WP_Error( 'update_failed', 'Failed to update config', array( 'status' => 500 ) );
        },
        'permission_callback' => function() {
            return current_user_can( 'edit_theme_options' );
        },
    ) );
} );
```

- [ ] **Step 2: Commit**

```bash
git add functions.php
git commit -m "feat: add theme setup, config API, REST endpoint"
```

---

## Task 3: CSS Grid Layout Engine

**Files:**
- Create: `template-parts/grid-areas.php`

- [ ] **Step 1: Write template-parts/grid-areas.php**

```php
<?php
/**
 * Parses layout arrays into CSS grid-template-areas strings
 *
 * @param array  $areas  e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $prefix Optional class prefix
 * @return string CSS grid-template-areas value
 */
function agentshell_parse_grid_areas( array $areas, $prefix = 'zone--' ) {
    $css = '';
    foreach ( $areas as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            $css .= '"' . esc_attr( $cell ) . '" ';
        }
        $css .= "\n";
    }
    return trim( $css );
}

/**
 * Generates a full CSS block for a breakpoint
 *
 * @param array  $layout  e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $query   Media query string, e.g. "(min-width: 1024px)"
 * @param string $zone_prefix  CSS class prefix for zones
 * @return string Complete CSS block
 */
function agentshell_generate_grid_css( array $layout, $query, $zone_prefix = 'zone--' ) {
    $areas_css  = agentshell_parse_grid_areas( $layout );
    $row_count  = count( $layout );

    $css  = $query ? "@media $query {\n" : "";
    $css .= "  .shell-grid {\n";
    $css .= "    grid-template-areas:\n";
    foreach ( $layout as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            $css .= '      "' . esc_attr( $cell ) . '" ';
        }
        $css .= "\n";
    }
    $css .= "    grid-template-rows: auto;\n";
    $css .= "  }\n";

    // Generate zone class rules
    $seen_zones = array();
    foreach ( $layout as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            if ( ! isset( $seen_zones[ $cell ] ) ) {
                $seen_zones[ $cell ] = true;
                $css .= "  .{$zone_prefix}{$cell} { grid-area: {$cell}; }\n";
            }
        }
    }

    if ( $query ) {
        $css .= "}\n";
    }
    return $css;
}

/**
 * Generate all breakpoint CSS from layout config
 *
 * @param array $layout   e.g. { mobile: [...], tablet: [...], desktop: [...] }
 * @param array $breakpoints e.g. { mobile: "0px", tablet: "768px", desktop: "1024px" }
 * @return string Complete CSS string
 */
function agentshell_get_layout_css( array $layout, array $breakpoints ) {
    $css = "<style id='agentshell-layout-css'>\n";

    foreach ( $breakpoints as $name => $threshold ) {
        if ( isset( $layout[ $name ] ) && isset( $threshold ) ) {
            $query = $threshold === '0px' ? '' : "(min-width: {$threshold})";
            $css .= agentshell_generate_grid_css( $layout[ $name ], $query );
        }
    }

    // Base grid container
    $css .= "  .shell-grid {\n";
    $css .= "    display: grid;\n";
    $css .= "    gap: 1rem;\n";
    $css .= "  }\n";

    $css .= "</style>\n";
    return $css;
}
```

- [ ] **Step 2: Commit**

```bash
git add template-parts/grid-areas.php
git commit -m "feat: add CSS grid layout engine"
```

---

## Task 4: Shell Renderer

**Files:**
- Create: `template-parts/shell-render.php`

- [ ] **Step 1: Write template-parts/shell-render.php**

```php
<?php
/**
 * Shell renderer - reads config, renders CSS variables, navigation, and content zones
 */

/**
 * Render CSS custom properties (design tokens) as an inline style block
 *
 * @param array $design  e.g. [colors => [...], typography => [...]]
 * @return string <style> tag with :root variables
 */
function agentshell_render_css_vars( array $design ) {
    $colors     = $design['colors']     ?? array();
    $typography = $design['typography'] ?? array();

    $vars = ":root {\n";
    foreach ( $colors as $name => $value ) {
        $vars .= "  --color-{$name}: " . esc_attr( $value ) . ";\n";
    }
    if ( ! empty( $typography['baseSize'] ) ) {
        $vars .= "  --type-base: " . esc_attr( $typography['baseSize'] ) . ";\n";
    }
    if ( ! empty( $typography['scale'] ) ) {
        $vars .= "  --type-scale: " . esc_attr( $typography['scale'] ) . ";\n";
    }
    if ( ! empty( $typography['fontFamily'] ) ) {
        $vars .= "  --font-family: " . esc_attr( $typography['fontFamily'] ) . ";\n";
    }
    $vars .= "}\n";

    return "<style id='agentshell-design-css'>\n{$vars}</style>";
}

/**
 * Render a single navigation menu from the navigation config
 *
 * @param array  $items  Array of nav items [{label, url, children[]}]
 * @return string HTML <nav> with nested <ul>
 */
function agentshell_render_nav( array $items ) {
    $html = '<nav class="shell-nav"><ul>';
    foreach ( $items as $item ) {
        $label    = esc_html( $item['label'] );
        $url      = esc_url( $item['url'] );
        $has_kids = ! empty( $item['children'] ) && is_array( $item['children'] );

        $html .= '<li>';
        $html .= '<a href="' . $url . '">' . $label . '</a>';
        if ( $has_kids ) {
            $html .= '<ul class="sub-menu">';
            foreach ( $item['children'] as $child ) {
                $html .= '<li><a href="' . esc_url( $child['url'] ) . '">' . esc_html( $child['label'] ) . '</a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Render a content zone based on its mapping config
 *
 * @param string $zone_name  e.g. "header", "sidebar", "main"
 * @param array  $mapping    e.g. [source => "wp_loop"], [source => "wp_widget_area", id => "..."]
 * @return string HTML content
 */
function agentshell_render_zone( $zone_name, array $mapping ) {
    $source = $mapping['source'] ?? '';

    switch ( $source ) {
        case 'wp_loop':
            ob_start();
            if ( have_posts() ) {
                while ( have_posts() ) {
                    the_post();
                    the_content();
                }
            } else {
                echo '<p>' . esc_html__( 'No content found.', 'agentshell' ) . '</p>';
            }
            return ob_get_clean();

        case 'wp_widget_area':
            $id = $mapping['id'] ?? '';
            if ( ! $id ) return '';
            ob_start();
            dynamic_sidebar( $id );
            return ob_get_clean();

        case 'json_block':
            $html = $mapping['html'] ?? '';
            return wp_kses_post( $html );

        default:
            return '';
    }
}

/**
 * Render the complete Shell (CSS vars + layout CSS + zone grid)
 *
 * @param array $config Full shell config array
 * @return string Complete HTML for the shell
 */
function agentshell_render_shell( array $config ) {
    $design   = $config['design']   ?? array();
    $layout   = $config['layout']   ?? array();
    $nav      = $config['navigation'] ?? array();
    $mapping  = $config['content_mapping'] ?? array();

    $output = agentshell_render_css_vars( $design );
    $output .= agentshell_get_layout_css( $layout, $design['breakpoints'] ?? array() );

    // Open shell grid container
    $output .= '<div class="shell-grid">' . "\n";

    // Collect all zone names from all breakpoints
    $all_zones = array();
    foreach ( $layout as $breakpoint_layout ) {
        foreach ( $breakpoint_layout as $row ) {
            $cells = preg_split( '/\s+/', trim( $row ) );
            foreach ( $cells as $cell ) {
                $all_zones[ $cell ] = true;
            }
        }
    }

    // Render each zone in the order they first appear (mobile-first)
    $rendered = array();
    foreach ( $layout as $breakpoint_layout ) {
        foreach ( $breakpoint_layout as $row ) {
            $cells = preg_split( '/\s+/', trim( $row ) );
            foreach ( $cells as $cell ) {
                if ( isset( $rendered[ $cell ] ) ) continue;
                $rendered[ $cell ] = true;

                $zone_class = 'shell-zone zone--' . esc_attr( $cell );
                $zone_html  = agentshell_render_zone( $cell, $mapping[ $cell ] ?? array() );

                // Inject nav for header zone
                if ( $cell === 'header' && ! empty( $nav['primary'] ) ) {
                    $zone_html = agentshell_render_nav( $nav['primary'] ) . $zone_html;
                }
                // Inject nav for footer zone
                if ( $cell === 'footer' && ! empty( $nav['footer_links'] ) ) {
                    $zone_html .= agentshell_render_nav( $nav['footer_links'] );
                }

                $output .= '<div class="' . $zone_class . '">' . $zone_html . '</div>' . "\n";
            }
        }
    }

    $output .= '</div>' . "\n";
    return $output;
}
```

- [ ] **Step 2: Commit**

```bash
git add template-parts/shell-render.php
git commit -m "feat: add shell renderer (CSS vars, grid, zones, nav)"
```

---

## Task 5: Header and Footer Templates

**Files:**
- Modify: `header.php` (create new)
- Modify: `footer.php` (create new)

- [ ] **Step 1: Write header.php**

```php
<?php
/**
 * Header template
 */
$config = agentshell_get_config();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
    <?php echo agentshell_render_css_vars( $config['design'] ?? array() ); ?>
    <?php echo agentshell_get_layout_css( $config['layout'] ?? array(), $config['design']['breakpoints'] ?? array() ); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="shell-wrapper">
    <?php
    // Render navigation zones
    $nav   = $config['navigation'] ?? array();
    $mapping = $config['content_mapping'] ?? array();

    // Header zone
    if ( ! empty( $config['layout']['mobile'] ) ) {
        // Determine zone order from mobile layout (first occurrence wins)
        $seen = array();
        foreach ( $config['layout']['mobile'] as $row ) {
            $cells = preg_split( '/\s+/', trim( $row ) );
            foreach ( $cells as $cell ) {
                if ( isset( $seen[ $cell ] ) ) continue;
                $seen[ $cell ] = true;
                if ( $cell === 'header' ) {
                    echo '<header class="shell-zone zone--header">';
                    if ( ! empty( $nav['primary'] ) ) {
                        echo agentshell_render_nav( $nav['primary'] );
                    }
                    echo agentshell_render_zone( 'header', $mapping['header'] ?? array() );
                    echo '</header>';
                    break 2;
                }
            }
        }
    }
    ?>
    <div class="shell-grid">
```

Note: `header.php` opens the grid container. `footer.php` closes it and renders the footer zone.

- [ ] **Step 2: Write footer.php**

```php
    <?php
    // Render footer zone (inside grid)
    $config   = agentshell_get_config();
    $nav      = $config['navigation'] ?? array();
    $mapping  = $config['content_mapping'] ?? array();

    // Find footer zone from mobile layout
    $seen = array();
    foreach ( $config['layout']['mobile'] as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            if ( isset( $seen[ $cell ] ) ) continue;
            $seen[ $cell ] = true;
            if ( $cell === 'footer' ) {
                echo '<footer class="shell-zone zone--footer">';
                echo agentshell_render_zone( 'footer', $mapping['footer'] ?? array() );
                if ( ! empty( $nav['footer_links'] ) ) {
                    echo agentshell_render_nav( $nav['footer_links'] );
                }
                echo '</footer>';
                break 2;
            }
        }
    }
    ?>
    </div><!-- .shell-grid -->

    <!-- Configurator trigger button -->
    <button id="agentshell-config-trigger" aria-label="Open theme configurator">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
            <path d="M10 6v8M6 10h8" stroke="currentColor" stroke-width="2"/>
        </svg>
    </button>

    <?php wp_footer(); ?>
</div><!-- .shell-wrapper -->
</body>
</html>
```

- [ ] **Step 3: Commit**

```bash
git add header.php footer.php
git commit -m "feat: add header/footer templates with shell grid"
```

---

## Task 6: Live Preview Configurator (JS + CSS)

**Files:**
- Create: `configurator/configurator.css`
- Create: `configurator/configurator.js`

- [ ] **Step 1: Write configurator.css**

```css
/* Push sidebar panel — docked right, pushes main content */
#agentshell-config-panel {
    position: fixed;
    top: 0;
    right: 0;
    width: 350px;
    height: 100vh;
    background: #1a1a2e;
    color: #e0e0e0;
    z-index: 99999;
    overflow-y: auto;
    transform: translateX(100%);
    transition: transform 0.25s ease;
    font-family: system-ui, -apple-system, sans-serif;
    font-size: 14px;
    box-sizing: border-box;
}

#agentshell-config-panel.is-open {
    transform: translateX(0);
}

#agentshell-config-panel .panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    border-bottom: 1px solid #333;
    position: sticky;
    top: 0;
    background: #1a1a2e;
    z-index: 1;
}

#agentshell-config-panel .panel-header h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
}

#agentshell-config-panel .panel-close {
    background: none;
    border: none;
    color: #e0e0e0;
    cursor: pointer;
    padding: 0.25rem;
    line-height: 1;
}

#agentshell-config-panel .panel-section {
    padding: 1rem;
    border-bottom: 1px solid #2a2a4e;
}

#agentshell-config-panel .panel-section h3 {
    margin: 0 0 0.75rem;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #888;
}

#agentshell-config-panel label {
    display: block;
    font-size: 0.8rem;
    margin-bottom: 0.25rem;
    color: #aaa;
}

#agentshell-config-panel input[type="text"],
#agentshell-config-panel input[type="url"] {
    width: 100%;
    padding: 0.4rem 0.5rem;
    background: #2a2a4e;
    border: 1px solid #3a3a5e;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 0.85rem;
    box-sizing: border-box;
    margin-bottom: 0.5rem;
}

#agentshell-config-panel input[type="color"] {
    width: 100%;
    height: 32px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    background: transparent;
    margin-bottom: 0.5rem;
}

#agentshell-config-panel textarea {
    width: 100%;
    min-height: 80px;
    padding: 0.4rem 0.5rem;
    background: #2a2a4e;
    border: 1px solid #3a3a5e;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 0.8rem;
    font-family: monospace;
    box-sizing: border-box;
    resize: vertical;
    margin-bottom: 0.5rem;
}

/* Trigger button */
#agentshell-config-trigger {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #e94560;
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99998;
    transition: background 0.2s;
}

#agentshell-config-trigger:hover {
    background: #d63850;
}

/* Main content push when panel is open */
.shell-wrapper {
    transition: padding-right 0.25s ease;
}

body.config-panel-open .shell-wrapper {
    padding-right: 350px;
}

body.config-panel-open .shell-grid {
    max-width: calc(100% - 350px);
}
```

- [ ] **Step 2: Write configurator.js**

```javascript
(function() {
    'use strict';

    const panel = document.getElementById('agentshell-config-panel');
    const trigger = document.getElementById('agentshell-config-trigger');
    const closeBtn = document.querySelector('.panel-close');

    let config = null;

    // Load current config from REST API
    async function loadConfig() {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + '/wp/v2/agentshell/config', {
                headers: {
                    'X-WP-Nonce': AgentShellConfig.nonce
                }
            });
            config = await resp.json();
            renderPanel();
        } catch (e) {
            console.error('AgentShell: Failed to load config', e);
        }
    }

    // Save config to REST API
    async function saveConfig(newConfig) {
        try {
            const resp = await fetch(AgentShellConfig.restUrl + '/wp/v2/agentshell/config', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AgentShellConfig.nonce
                },
                body: JSON.stringify(newConfig)
            });
            if (!resp.ok) throw new Error('Save failed');
            config = await resp.json();
            // Reload page to see changes (or implement hot-reload)
            location.reload();
        } catch (e) {
            console.error('AgentShell: Failed to save config', e);
            alert('Failed to save config. Please try again.');
        }
    }

    // Infer form field type from value
    function inferFieldType(key, value) {
        if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
            return 'color';
        }
        if (typeof value === 'string' && /^\d+(\.\d+)?(px|em|rem|%)$/.test(value)) {
            return 'text';
        }
        if (typeof value === 'string' || typeof value === 'number') {
            return 'text';
        }
        return 'text';
    }

    // Render the configurator panel form
    function renderPanel() {
        if (!config) return;

        const sections = [
            {
                title: 'Logo',
                fields: [
                    { path: ['design', 'logo', 'url'], label: 'Logo URL' },
                    { path: ['design', 'logo', 'width'], label: 'Width' },
                    { path: ['design', 'logo', 'height'], label: 'Height' },
                ]
            },
            {
                title: 'Colors',
                fields: Object.entries(config.design?.colors || {}).map(([name, value]) => ({
                    path: ['design', 'colors', name],
                    label: name.charAt(0).toUpperCase() + name.slice(1),
                    type: 'color'
                }))
            },
            {
                title: 'Typography',
                fields: [
                    { path: ['design', 'typography', 'fontFamily'], label: 'Font Family' },
                    { path: ['design', 'typography', 'baseSize'], label: 'Base Size' },
                ]
            },
            {
                title: 'Layout (Desktop)',
                fields: [
                    {
                        path: ['layout', 'desktop'],
                        label: 'grid-template-areas rows',
                        type: 'textarea',
                        getValue: () => (config.layout?.desktop || []).join('\n'),
                        setValue: (v) => { config.layout.desktop = v.split('\n').map(s => s.trim()).filter(Boolean); }
                    }
                ]
            }
        ];

        let html = `
            <div class="panel-header">
                <h2>Shell Config</h2>
                <button class="panel-close" aria-label="Close">✕</button>
            </div>
        `;

        sections.forEach(sec => {
            html += `<div class="panel-section"><h3>${sec.title}</h3>`;
            sec.fields.forEach(field => {
                const value = field.getValue
                    ? field.getValue()
                    : field.path.reduce((o, k) => (o || {})[k], config);
                const type = field.type || inferFieldType(field.path.join('.'), value);

                if (type === 'color') {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="color" data-path="${field.path.join('.')}" value="${value || '#000000'}">`;
                } else if (type === 'textarea') {
                    html += `<label>${field.label}</label>`;
                    html += `<textarea data-path="${field.path.join('.')}">${value || ''}</textarea>`;
                } else {
                    html += `<label>${field.label}</label>`;
                    html += `<input type="text" data-path="${field.path.join('.')}" value="${value || ''}">`;
                }
            });
            html += '</div>';
        });

        // Add save button
        html += `<div class="panel-section"><button id="agentshell-save" style="width:100%;padding:0.6rem;background:#e94560;color:#fff;border:none;border-radius:4px;cursor:pointer;">Save & Reload</button></div>`;

        panel.innerHTML = html;

        // Bind events
        trigger.addEventListener('click', openPanel);
        closeBtn.addEventListener('click', closePanel);

        document.getElementById('agentshell-save').addEventListener('click', () => {
            // Gather all field values back into config
            panel.querySelectorAll('input[type="text"], input[type="url"], textarea').forEach(el => {
                const path = el.dataset.path.split('.');
                if (el.type === 'number') {
                    setInConfig(path, parseFloat(el.value));
                } else {
                    setInConfig(path, el.value);
                }
            });
            saveConfig(config);
        });
    }

    // Helper: get nested config value by path array
    function getFromConfig(path) {
        return path.reduce((o, k) => (o || {})[k], config);
    }

    // Helper: set nested config value by path array
    function setInConfig(path, value) {
        let o = config;
        for (let i = 0; i < path.length - 1; i++) {
            if (!o[path[i]]) o[path[i]] = {};
            o = o[path[i]];
        }
        o[path[path.length - 1]] = value;
    }

    function openPanel() {
        panel.classList.add('is-open');
        document.body.classList.add('config-panel-open');
    }

    function closePanel() {
        panel.classList.remove('is-open');
        document.body.classList.remove('config-panel-open');
    }

    // Make helper functions available
    window.getFromConfig = getFromConfig;
    window.setInConfig = setInConfig;

    // Initialize panel HTML (hidden)
    if (!panel) {
        const div = document.createElement('div');
        div.id = 'agentshell-config-panel';
        document.body.appendChild(div);
    }

    loadConfig();
})();
```

- [ ] **Step 3: Commit**

```bash
git add configurator/configurator.css configurator/configurator.js
git commit -m "feat: add live preview configurator (push sidebar)"
```

---

## Task 7: Config Panel HTML Injection

**Files:**
- Modify: `footer.php` (update the trigger button markup to include the panel div)
- Modify: `functions.php` (enqueue configurator assets)

- [ ] **Step 1: Add configurator panel div to footer.php**

In `footer.php`, replace the trigger button with:

```php
    <!-- Configurator panel -->
    <div id="agentshell-config-panel"></div>

    <button id="agentshell-config-trigger" aria-label="Open theme configurator">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
            <path d="M10 6v8M6 10h8" stroke="currentColor" stroke-width="2"/>
        </svg>
    </button>
```

- [ ] **Step 2: Ensure configurator assets are properly enqueued**

In `functions.php`, `agentshell_enqueue_assets()` already enqueues both files. No change needed.

- [ ] **Step 3: Commit**

```bash
git add footer.php
git commit -m "feat: inject configurator panel DOM"
```

---

## Task 8: Spec Self-Review

Before marking the plan complete, scan for gaps:

1. **Spec coverage:** All spec requirements mapped to tasks?
   - Shell config schema → Task 1
   - Layout CSS generation → Task 3
   - Navigation rendering → Task 4
   - Content zone rendering (wp_loop, wp_widget_area, json_block) → Task 4
   - Live preview push sidebar → Tasks 6-7
   - REST API for agents → Task 2
   - CSS vars from design tokens → Task 4
   - No Gutenberg in Shell → enforced by template structure

2. **Placeholder scan:** All steps have complete code. No TODOs.

3. **Type consistency:** `agentshell_get_config()` returns `array`, used consistently in all templates.

4. **Gap found:** `shell-config.json` file needs to be copied to `wp_options` on theme activation. Covered in `agentshell_theme_activation()` in Task 2.

---

## Execution Order

1. Task 1: Theme Scaffold
2. Task 2: functions.php + Config API
3. Task 3: CSS Grid Layout Engine
4. Task 4: Shell Renderer
5. Task 5: Header/Footer Templates
6. Task 6: Live Preview Configurator (JS + CSS)
7. Task 7: Config Panel Injection
8. Task 8: Self-Review
