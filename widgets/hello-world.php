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
