<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. Detect matching Turbo Footer early and remove theme footers
 */
add_action( 'template_redirect', function () {

    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }

    // Avoid output while editing or previewing our CPTs in Elementor
    if ( is_singular( 'tahefobu_header' ) || is_singular( 'tahefobu_footer' ) ) {
        return;
    }

    // Skip rendering/matching during Elementor preview only when previewing our CPTs.
    if ( defined( 'ELEMENTOR_VERSION' ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
        $pid = get_the_ID();

        // If the preview is for our header/footer CPTs, verify nonce + caps and skip matching/rendering.
        if ( $pid && in_array( get_post_type( $pid ), [ 'tahefobu_header', 'tahefobu_footer' ], true ) ) {
            $nonce = filter_input( INPUT_GET, 'tahefobu_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

            // Fail early if nonce missing/invalid
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'tahefobu_preview_' . $pid ) ) {
                return;
            }

            // Authorization (nonces aren’t auth)
            if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $pid ) ) {
                return;
            }

            // Valid preview of our CPT — skip our matching/rendering to avoid duplication.
            return;
        }

        // For previews of regular pages (editor), allow footer matching/rendering so editor shows the plugin footer when applicable.
    }

    $footers = get_posts( [
        'post_type'   => 'tahefobu_footer',
        'post_status' => 'publish',
        'numberposts' => -1,
    ] );

    if ( empty( $footers ) ) {
        return;
    }

    $current_page_id = get_queried_object_id();
    $matched_footer  = null;

    foreach ( $footers as $footer ) {
        // Ensure arrays; normalize to ints for strict comparisons
        $include = get_post_meta( $footer->ID, '_tahefobu_include_pages', true );
        $exclude = get_post_meta( $footer->ID, '_tahefobu_exclude_pages', true );
        $targets = get_post_meta( $footer->ID, '_tahefobu_display_targets', true );

        $include = is_array( $include ) ? array_map( 'absint', $include ) : [];
        $exclude = is_array( $exclude ) ? array_map( 'absint', $exclude ) : [];
        $targets = is_array( $targets ) ? array_map( 'sanitize_key', $targets ) : [];

        if ( in_array( $current_page_id, $exclude, true ) ) {
            continue;
        }

        if ( in_array( 'all_pages', $targets, true ) && is_page() ) {
            $matched_footer = $footer->ID;
            break;
        }

        if ( in_array( 'all_posts', $targets, true ) && is_singular( 'post' ) ) {
            $matched_footer = $footer->ID;
            break;
        }

        if ( in_array( 'entire_site', $targets, true ) ) {
            $matched_footer = $footer->ID;
            break;
        }

        if ( ! empty( $include ) && in_array( $current_page_id, $include, true ) ) {
            $matched_footer = $footer->ID;
            break;
        }
    }

    if ( $matched_footer ) {
        $GLOBALS['tahefobu_footer_template_id'] = $matched_footer;
        $GLOBALS['tahefobu_footer_rendered']    = true;

        // Remove theme footers
        remove_all_actions( 'astra_footer' );
        remove_action( 'generate_footer', 'generate_construct_footer' );
        remove_action( 'storefront_footer', 'storefront_credit', 20 );
        remove_all_actions( 'ocean_footer' );
        remove_all_actions( 'hello_elementor_footer' );
        remove_all_actions( 'neve_footer' );
    }
} );


/**
 * 2. Render the Turbo Addons footer
 */
add_action('wp_footer', function () {
    if ( ! empty( $GLOBALS['tahefobu_footer_template_id'] ) ) {
    $content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $GLOBALS['tahefobu_footer_template_id'] );
        if ( ! empty( $content ) ) {
            echo '<div class="turbo-footer-template">';
                // Elementor already escapes/sanitizes template content.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $content;
            echo '</div>';
        }
    }
});

/**
 * 3. CSS Fallback to hide default footer markup if needed
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( empty( $GLOBALS['tahefobu_footer_rendered'] ) ) {
        return;
    }

    // Ensure a handle exists to attach inline CSS to.
    $handle = 'tahefobu-footer-style';

    // Register a 'virtual' stylesheet (no file), then enqueue it.
    if ( ! wp_style_is( $handle, 'registered' ) ) {
        wp_register_style( $handle, false, [], TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_VERSION );
    }
    if ( ! wp_style_is( $handle, 'enqueued' ) ) {
        wp_enqueue_style( $handle );
    }

    $css = '
    body.tahefobu-hide-theme-footer
    footer, 
    body.tahefobu-hide-theme-footer .site-footer, 
    body.tahefobu-hide-theme-footer #colophon, 
    body.tahefobu-hide-theme-footer .footer, 
    body.tahefobu-hide-theme-footer .footer-bottom, 
    body.tahefobu-hide-theme-footer .footer-wrap,
    body.tahefobu-hide-theme-footer .elementor-location-footer, 
    body.tahefobu-hide-theme-footer .ast-footer-copyright,
    body.tahefobu-hide-theme-footer .ast-footer-overlay, 
    body.tahefobu-hide-theme-footer .generatepress-footer, 
    body.tahefobu-hide-theme-footer .storefront-footer,
    body.tahefobu-hide-theme-footer .footer-widgets, 
    body.tahefobu-hide-theme-footer .main-footer, 
    body.tahefobu-hide-theme-footer #footer, 
    body.tahefobu-hide-theme-footer .theme-footer {
        display: none !important;
    }';

    wp_add_inline_style( $handle, $css );
}, 20 );

/**
 * 4. Add a body class only when our footer is rendered (helps limit CSS impact).
 */
add_filter( 'body_class', function ( $classes ) {
    if ( ! empty( $GLOBALS['tahefobu_footer_rendered'] ) ) {
        $classes[] = 'tahefobu-hide-theme-footer';
    }
    return $classes;
} );