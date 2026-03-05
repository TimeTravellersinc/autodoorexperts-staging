<?php
/**
 * Admin Menu and Elementor/WooCommerce Support
 *
 * @package Header_Footer_Builder_For_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * 1. Turbo H&F Builder Admin Menu
 */
add_action( 'admin_menu', function () {
    add_menu_page(
        esc_html__( 'Turbo H&F Builder', 'header-footer-builder-for-elementor' ), // Page title.
        esc_html__( 'Turbo H&F Builder', 'header-footer-builder-for-elementor' ), // Menu title.
        'manage_options',                                                        // Capability.
        'tahefobu_templates',                                                    // Menu slug (prefixed).
        'tahefobu_render_admin_menu_page',                                       // Callback function.
        TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/images/turboFile.svg',
        21
    );
} );

/**
 * Render the admin menu page.
 *
 * No user input is processed here, so nonce verification is not required.
 */
function tahefobu_render_admin_menu_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Turbo H&F Builder', 'header-footer-builder-for-elementor' ); ?></h1>
        <p><?php esc_html_e( 'Welcome to Turbo H&F Builder. Here you can manage your header and footer templates.', 'header-footer-builder-for-elementor' ); ?></p>
    </div>
    <?php
}

/**
 * 2. Elementor CPT Support
 *
 * Adds Elementor editor support to our custom template CPTs.
 */
add_action( 'elementor/init', function () {
    if ( post_type_exists( 'tahefobu_single_template' ) ) {
        add_post_type_support( 'tahefobu_single_template', 'elementor' );
    }
} );

/**
 * 3. WooCommerce Single Product Support
 *
 * Ensures Elementor assets load on WooCommerce product pages when editing with Elementor.
 */
if ( class_exists( 'WooCommerce' ) ) {
    add_action( 'wp_enqueue_scripts', function () {
        if ( is_product() && class_exists( '\Elementor\Plugin' ) ) {
            $frontend = \Elementor\Plugin::instance()->frontend;
            $frontend->enqueue_styles();
            $frontend->enqueue_scripts();
        }
    } );
}

/**
 * 4. Always allow Elementor to print inline CSS on frontend.
 */
add_filter( 'elementor/frontend/print_css', '__return_true' );
