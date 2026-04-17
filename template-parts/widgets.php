<?php
/**
 * Widget Registry Renderer
 *
 * Outputs scoped CSS for all registered widgets into <head>.
 * Widget init JS is rendered in footer.php via agentshell_get_widget_assets().
 */

if ( ! function_exists( 'agentshell_get_widget_assets' ) ) {
    return;
}

$registry = agentshell_get_widget_registry();
$assets   = agentshell_get_widget_assets();

// Scoped CSS — all widget styles scoped to prevent bleed
if ( $assets['css'] ) {
    echo "<style id='agentshell-widget-css'>\n";
    echo $assets['css'] . "\n";
    echo "</style>\n";
}
