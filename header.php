<?php
/**
 * Header template
 */
require_once get_template_directory() . '/template-parts/shell-render.php';
require_once get_template_directory() . '/template-parts/grid-areas.php';

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
    // Render header zone
    $nav     = $config['navigation']    ?? array();
    $mapping = $config['content_mapping'] ?? array();

    if ( ! empty( $config['layout']['mobile'] ) ) {
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
                    echo agentshell_render_zone( $mapping['header'] ?? array() );
                    echo '</header>';
                    break 2;
                }
            }
        }
    }
    ?>
    <div class="shell-grid">
