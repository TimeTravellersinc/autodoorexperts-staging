<?php
if (!defined('ABSPATH')) {
    exit;
}

define('ADO_MODERN_THEME_VERSION', '1.0.0');

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('ado-google-fonts', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap', [], null);
    wp_enqueue_style('twentytwentyfour-style', get_template_directory_uri() . '/style.css', [], wp_get_theme('twentytwentyfour')->get('Version'));
    wp_enqueue_style('ado-modern-style', get_stylesheet_uri(), ['twentytwentyfour-style', 'ado-google-fonts'], ADO_MODERN_THEME_VERSION);
}, 20);

require_once get_stylesheet_directory() . '/inc/ado-portal/ado-core-access.php';
require_once get_stylesheet_directory() . '/inc/ado-portal/ado-quote-carts.php';
require_once get_stylesheet_directory() . '/inc/ado-portal/ado-project-dashboards.php';
require_once get_stylesheet_directory() . '/inc/ado-portal/ado-client-dashboard-app.php';
require_once get_stylesheet_directory() . '/inc/ado-portal/ado-technician-portal-app.php';

add_action('after_setup_theme', static function (): void {
    add_theme_support('woocommerce');
});
