    <?php
    // Render footer zone (inside grid)
    $config   = agentshell_get_config();
    $nav      = $config['navigation']    ?? array();
    $mapping  = $config['content_mapping'] ?? array();

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

    <!-- Configurator panel -->
    <div id="agentshell-config-panel"></div>

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
