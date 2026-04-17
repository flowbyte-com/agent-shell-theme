<?php
/**
 * Footer template
 */

$config = agentshell_get_config();

// custom_js — trusted author context, injected before body close
// Do NOT use wp_strip_all_tags — it removes JS code entirely.
if ( ! empty( $config['custom_js'] ) ) {
    echo "<script id='agentshell-custom-js'>\n" . $config['custom_js'] . "\n</script>\n";
}

// Widget init — waits for DOM + MutationObserver for dynamically injected widgets
if ( function_exists( 'agentshell_get_widget_assets' ) ) :
    $assets = agentshell_get_widget_assets();
    if ( ! empty( $assets['init_js'] ) ) : ?>
<script id='agentshell-widget-init'>
(function() {
    // WIDGETS is a local alias for window.AgentshellWidgets for faster lookups
    var WIDGETS = window.AgentshellWidgets = (window.AgentshellWidgets || {});
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

    // Immediate scan for server-rendered widgets
    scanWidgets();

    // MutationObserver for dynamically injected widgets (e.g., via json_block)
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if (node.matches && node.matches('[data-widget]')) {
                        initWidget(node);
                    }
                    if (node.querySelectorAll) {
                        node.querySelectorAll('[data-widget]').forEach(initWidget);
                    }
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
</script>
<?php endif;
endif;
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
