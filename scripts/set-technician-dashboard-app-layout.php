<?php
if (!defined('ABSPATH')) {
    exit;
}

$page = get_page_by_path('technician-portal', OBJECT, 'page');
if (!$page instanceof WP_Post) {
    fwrite(STDERR, "Missing technician-portal page.\n");
    exit(1);
}

$layout = [
    [
        'id' => substr(md5('ado-technician-dashboard-root'), 0, 7),
        'elType' => 'container',
        'isInner' => false,
        'settings' => [
            'content_width' => 'full',
            'flex_direction' => 'column',
            'padding' => [
                'unit' => 'px',
                'top' => '0',
                'right' => '0',
                'bottom' => '0',
                'left' => '0',
                'isLinked' => true,
            ],
        ],
        'elements' => [
            [
                'id' => substr(md5('ado-technician-dashboard-shortcode'), 0, 7),
                'elType' => 'widget',
                'widgetType' => 'shortcode',
                'settings' => [
                    'shortcode' => '[ado_technician_portal_app]',
                ],
                'elements' => [],
            ],
        ],
    ],
];

$json = wp_json_encode($layout);
if (!is_string($json) || $json === '') {
    fwrite(STDERR, "Unable to encode Elementor layout.\n");
    exit(1);
}

update_post_meta($page->ID, '_elementor_data', wp_slash($json));
update_post_meta($page->ID, '_elementor_edit_mode', 'builder');
update_post_meta($page->ID, '_elementor_template_type', 'wp-page');
update_post_meta($page->ID, '_elementor_page_settings', []);
update_post_meta($page->ID, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.35.5');
update_post_meta($page->ID, '_wp_page_template', 'elementor_canvas');

wp_update_post([
    'ID' => $page->ID,
    'post_content' => '',
]);

echo 'updated:' . $page->ID . PHP_EOL;
