<?php
/**
 * Footer template
 */
?>
    <?php wp_footer(); ?>

    <?php if ( is_user_logged_in() ) : ?>
    <div id="agentshell-config-panel"></div>

    <button id="agentshell-config-trigger" aria-label="Open theme configurator">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2"/>
            <path d="M10 6v8M6 10h8" stroke="currentColor" stroke-width="2"/>
        </svg>
    </button>
    <?php endif; ?>

</body>
</html>
