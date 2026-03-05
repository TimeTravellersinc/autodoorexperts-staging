<?php
if (!defined('ABSPATH')) {
    exit;
}

wp_set_current_user(3);

echo shortcode_exists('ado_client_dashboard_app') ? "shortcode:yes\n" : "shortcode:no\n";

$views = ['dashboard', 'new-quote', 'quotes', 'projects', 'schedule', 'invoices'];
foreach ($views as $view) {
    $_GET['view'] = $view;
    $html = (string) do_shortcode('[ado_client_dashboard_app]');
    $hasShell = strpos($html, 'ado-app') !== false;
    $hasTop = strpos($html, '<h1>' . ucfirst($view)) !== false || strpos($html, 'Dashboard') !== false;
    echo $view . ':len=' . strlen($html) . ',shell=' . ($hasShell ? 'yes' : 'no') . ',title=' . ($hasTop ? 'yes' : 'no') . "\n";
}
