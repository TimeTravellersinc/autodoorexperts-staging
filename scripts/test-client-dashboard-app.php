<?php
if (!defined('ABSPATH')) {
    exit;
}

wp_set_current_user(3);

echo shortcode_exists('ado_client_dashboard_app') ? "shortcode:yes\n" : "shortcode:no\n";

$views = ['dashboard', 'new-quote', 'quotes', 'projects', 'schedule', 'site-docs', 'invoices'];
$titles = [
    'dashboard' => 'Dashboard',
    'new-quote' => 'New Quote',
    'quotes' => 'My Quotes',
    'projects' => 'My Projects',
    'schedule' => 'Schedule',
    'site-docs' => 'Upload Site Docs',
    'invoices' => 'Invoices',
];
foreach ($views as $view) {
    $_GET['view'] = $view;
    $html = (string) do_shortcode('[ado_client_dashboard_app]');
    $hasShell = strpos($html, 'ado-app') !== false;
    $hasTop = strpos($html, '<h1>' . ($titles[$view] ?? $view) . '</h1>') !== false || strpos($html, ($titles[$view] ?? $view)) !== false;
    echo $view . ':len=' . strlen($html) . ',shell=' . ($hasShell ? 'yes' : 'no') . ',title=' . ($hasTop ? 'yes' : 'no') . "\n";
}
