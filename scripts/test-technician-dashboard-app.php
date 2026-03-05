<?php
if (!defined('ABSPATH')) {
    exit;
}

$users = get_users([
    'number' => 1,
    'role__in' => ['technician', 'administrator'],
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => ['ID'],
]);

if (empty($users)) {
    fwrite(STDERR, "Missing technician/admin user for portal test.\n");
    exit(1);
}

$user_id = (int) $users[0]->ID;
wp_set_current_user($user_id);

echo 'user:' . $user_id . PHP_EOL;
echo 'shortcode:' . (shortcode_exists('ado_technician_portal_app') ? 'yes' : 'no') . PHP_EOL;

$views = ['dashboard', 'schedule', 'notes', 'files', 'photos', 'profile', 'timesheets'];
foreach ($views as $view) {
    $_GET['view'] = $view;
    $html = (string) do_shortcode('[ado_technician_portal_app]');
    $has_shell = strpos($html, 'ado-tech') !== false;
    $has_title = strpos($html, '<h1>') !== false;
    echo $view . ':len=' . strlen($html) . ',shell=' . ($has_shell ? 'yes' : 'no') . ',title=' . ($has_title ? 'yes' : 'no') . PHP_EOL;
}
