<?php
/**
 * Header template — Config-Driven Zone Registry
 *
 * Zones are read from agentshell_get_config()['zones'] and rendered
 * via agentshell_render_zone(). Agents can reorder/reconfigure zones
 * via REST API without editing PHP.
 *
 * The grid layout is handled entirely by CSS (style.css) — no PHP grid generation.
 */
$sidebar_enabled = ! empty( agentshell_get_config()['sidebar_enabled'] );
$zones = agentshell_get_zones();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
    <?php
    // Widget registry scoped CSS (outputs directly)
    get_template_part( 'template-parts/widgets' );
    ?>
</head>
<body <?php body_class( $sidebar_enabled ? 'sidebar-enabled' : '' ); ?>>
<?php wp_body_open(); ?>

<div id="agentshell-root">
<?php foreach ( $zones as $zone ) : ?>
    <?php
    // Skip sidebar zone when sidebar is disabled
    if ( $zone['id'] === 'sidebar' && ! $sidebar_enabled ) {
        continue;
    }
    // Use semantic HTML tags where applicable
    $tag = 'header' === $zone['id'] ? 'header' : ( 'footer' === $zone['id'] ? 'footer' : ( 'main' === $zone['id'] ? 'main' : ( 'aside' === $zone['id'] ? 'aside' : 'div' ) ) );
    ?>
    <<?php echo $tag; ?> id="zone-<?php echo esc_attr( $zone['id'] ); ?>" class="shell-zone" data-zone="<?php echo esc_attr( $zone['id'] ); ?>">
        <?php echo agentshell_render_zone( $zone ); ?>
    </<?php echo $tag; ?>>
<?php endforeach; ?>
</div>
