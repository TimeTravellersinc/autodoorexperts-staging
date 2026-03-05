<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. Register Footer Template CPT
 */
add_action('init', function () {
    register_post_type('tahefobu_footer', [
        'labels' => [
            'name' => __('Footer Templates', 'header-footer-builder-for-elementor'),
            'singular_name' => __('Footer Template', 'header-footer-builder-for-elementor'),
            'menu_name' => __('Footer Template', 'header-footer-builder-for-elementor'),
            'add_new' => __('Add Footer Template', 'header-footer-builder-for-elementor'),
            'add_new_item' => __('Add New Footer Template', 'header-footer-builder-for-elementor'),
            'edit_item' => __('Edit Footer Template', 'header-footer-builder-for-elementor'),
            'new_item' => __('New Footer Template', 'header-footer-builder-for-elementor'),
            'view_item' => __('View Footer Template', 'header-footer-builder-for-elementor'),
            'all_items' => __('Footer Templates', 'header-footer-builder-for-elementor'),
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => 'tahefobu_templates',
        'supports' => ['title', 'editor', 'elementor'],
        'exclude_from_search' => true,
        'show_in_rest' => true,
        'rewrite' => false,
        'capability_type' => 'post',
    ]);
});

/**
 * 2. Enable Elementor support
 */
add_action('elementor/init', function () {
    add_post_type_support('tahefobu_footer', 'elementor');
});

/**
 * 3. Inject Footer Template Popup Modal
 */
add_action('admin_footer-edit.php', 'tahefobu_render_footer_template_popup');
function tahefobu_render_footer_template_popup() {
    $screen = get_current_screen();
    if ($screen->post_type !== 'tahefobu_footer') return;

    $pages = get_pages();
    ?>
    <div id="tahefobu-footer-template-popup" class="tahefobu-header-popup-overlay" style="display:none;">
        <div class="tahefobu-header-popup-modal">
            <div class="modal-header-style">
                <h2 class="tahefobu-create-header-popup-headline"><?php esc_html_e('Create New Footer', 'header-footer-builder-for-elementor'); ?></h2>
                 <img src="<?php echo esc_url( TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/images/turbo-logo.png' ); ?>" alt="<?php esc_attr_e('Turbo Addons Logo', 'header-footer-builder-for-elementor'); ?>">
            </div>

            <!-- Footer Title Input -->
           <p class="header-title-modal"><?php esc_html_e( 'Footer Name', 'header-footer-builder-for-elementor' ); ?></p>
            <input type="text" id="tahefobu-footer-template-title" placeholder="<?php esc_attr_e( 'Type footer name', 'header-footer-builder-for-elementor' ); ?>" />

            <!-- Include Pages Selector -->
            <div class="modal-include-exclude-style">
                <label><?php esc_html_e( 'Include Pages:', 'header-footer-builder-for-elementor' ); ?></label><br>
                <label><input type="checkbox" id="select_all_include_footer"> <?php esc_html_e( 'Select All', 'header-footer-builder-for-elementor' ); ?></label><br>
            </div>

            <select class="tahefobu-footer-template-title" id="tahefobu_footer_include_pages" multiple>
                <?php foreach ($pages as $page): ?>
                    <option value="<?php echo esc_attr($page->ID); ?>"><?php echo esc_html($page->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            
            <!-- New: Display On -->
            <div class="modal-include-exclude-style">
                <label><?php esc_html_e('Set Display Condition:', 'header-footer-builder-for-elementor'); ?></label>
            </div>

            <select id="tahefobu_footer_display_targets" multiple>
                <option value="entire_site"><?php esc_html_e('Entire Site', 'header-footer-builder-for-elementor'); ?></option>
                <option value="all_posts"><?php esc_html_e('All Blog Posts', 'header-footer-builder-for-elementor'); ?></option>
                <option value="all_archives"><?php esc_html_e('All Archive Pages', 'header-footer-builder-for-elementor'); ?></option>

                <?php if (class_exists('WooCommerce')) : ?>
                    <option value="all_products"><?php esc_html_e('All WooCommerce Products', 'header-footer-builder-for-elementor'); ?></option>
                    <option value="all_woo"><?php esc_html_e('All WooCommerce Pages', 'header-footer-builder-for-elementor'); ?></option>
                <?php endif; ?>
            </select>

            <!-- Exclude Pages Selector -->
            <div class="modal-include-exclude-style">
                <label><?php esc_html_e( 'Exclude Pages:', 'header-footer-builder-for-elementor' ); ?></label><br>
                <label><input type="checkbox" id="select_all_exclude_footer"> <?php esc_html_e( 'Select All', 'header-footer-builder-for-elementor' ); ?></label><br>
            </div>
           
            <select id="tahefobu_footer_exclude_pages" multiple style="width:100%; min-height:100px;">
                <?php foreach ($pages as $page): ?>
                    <option value="<?php echo esc_attr($page->ID); ?>"><?php echo esc_html($page->post_title); ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Buttons --submit and cancel -->
            <div class="tahefobu-header-popup-actions" style="margin-top: 15px;">
                <button class="button tahefobu-header-creat-edit-button" id="tahefobu-create-footer-template"><?php esc_html_e('Create', 'header-footer-builder-for-elementor'); ?></button>
                <button class="button tahefobu-header-cancel-button" id="tahefobu-cancel-footer-template"><?php esc_html_e('Cancel', 'header-footer-builder-for-elementor'); ?></button>
            </div>
        </div>
    </div>

    <!-- Edit Conditions Modal -->
    <div id="tahefobu-footer-conditions-modal" class="tahefobu-header-popup-overlay" style="display:none;">
        <div class="tahefobu-header-popup-modal">
            <div class="modal-header-style">
                <h2 class="tahefobu-create-header-popup-headline"><?php esc_html_e('Edit Footer Conditions', 'header-footer-builder-for-elementor'); ?></h2>
                 <img src="<?php echo esc_url( TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/images/turbo-logo.png' ); ?>" alt="<?php esc_attr_e('Turbo Addons Logo', 'header-footer-builder-for-elementor'); ?>">
            </div>

            <div class="modal-display-conditions-field-style">
                <input type="hidden" id="tahefobu_footer_conditions_post_id" value="">

                <label><strong>Include Pages:</strong></label>
                <select id="tahefobu_footer_edit_include_pages" multiple style="width:100%; min-height:100px;">
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo esc_attr($page->ID); ?>"><?php echo esc_html($page->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                 <!-- New: Display On -->
                <div class="modal-include-exclude-style">
                    <label><?php esc_html_e('Set Display Condition:', 'header-footer-builder-for-elementor'); ?></label>
                </div>

                <select id="tahefobu_footer_edit_display_targets" multiple>
                    <option value="entire_site"><?php esc_html_e('Entire Site', 'header-footer-builder-for-elementor'); ?></option>
                    <option value="all_posts"><?php esc_html_e('All Blog Posts', 'header-footer-builder-for-elementor'); ?></option>
                    <option value="all_archives"><?php esc_html_e('All Archive Pages', 'header-footer-builder-for-elementor'); ?></option>

                    <?php if (class_exists('WooCommerce')) : ?>
                        <option value="all_products"><?php esc_html_e('All WooCommerce Products', 'header-footer-builder-for-elementor'); ?></option>
                        <option value="all_woo"><?php esc_html_e('All WooCommerce Pages', 'header-footer-builder-for-elementor'); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="modal-display-conditions-field-style">
            <label><strong>Exclude Pages:</strong></label>
                <select id="tahefobu_footer_edit_exclude_pages" multiple style="width:100%; min-height:100px;">
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo esc_attr($page->ID); ?>"><?php echo esc_html($page->post_title); ?></option>
                    <?php endforeach; ?>
                </select>   
            </div>

            <div class="tahefobu-header-popup-actions">
                <div>
                    <button class="button tahefobu-header-creat-edit-button" id="tahefobu-save-footer-condition-edit"><?php esc_html_e('Update', 'header-footer-builder-for-elementor'); ?></button>
                </div>
                <button class="button tahefobu-header-cancel-button" id="tahefobu-cancel-footer-condition-edit"><?php esc_html_e('Cancel', 'header-footer-builder-for-elementor'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

    /**
     * 5. AJAX Create Footer Template
     */
    add_action( 'wp_ajax_tahefobu_create_footer_template', function () {
        check_ajax_referer( 'tahefobu_save_conditions_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'header-footer-builder-for-elementor' ) ] );
        }

        $title = '';
        if ( isset( $_POST['title'] ) ) {
            $title = sanitize_text_field( wp_unslash( $_POST['title'] ) );
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'tahefobu_footer',
            'post_title'  => $title,
            'post_status' => 'publish',
        ] );

        if ( $post_id && ! is_wp_error( $post_id ) ) {
            // ✅ Sanitize arrays with wp_unslash
            $include_pages   = isset( $_POST['include_pages'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['include_pages'] ) ) : [];
            $exclude_pages   = isset( $_POST['exclude_pages'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['exclude_pages'] ) ) : [];
            $display_targets = [];
            if ( isset( $_POST['display_targets'] ) && is_array( $_POST['display_targets'] ) ) {
                $display_targets = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['display_targets'] ) );
            }

            update_post_meta( $post_id, '_tahefobu_include_pages', $include_pages );
            update_post_meta( $post_id, '_tahefobu_exclude_pages', $exclude_pages );
            update_post_meta( $post_id, '_tahefobu_display_targets', $display_targets );
            update_post_meta( $post_id, '_tahefobu_is_enabled', '1' );

            wp_send_json_success( [
                'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to create footer template.', 'header-footer-builder-for-elementor' ) ] );
        }
    } );

    /**
     * 6. AJAX Save Footer Conditions
     */
    add_action( 'wp_ajax_tahefobu_save_footer_conditions', function () {
        check_ajax_referer( 'tahefobu_save_conditions_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'header-footer-builder-for-elementor' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;

        $include_pages   = isset( $_POST['include_pages'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['include_pages'] ) ) : [];
        $exclude_pages   = isset( $_POST['exclude_pages'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['exclude_pages'] ) ) : [];
        $display_targets = [];
        if ( isset( $_POST['display_targets'] ) && is_array( $_POST['display_targets'] ) ) {
            $display_targets = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['display_targets'] ) );
        }

        update_post_meta( $post_id, '_tahefobu_display_targets', $display_targets );
        update_post_meta( $post_id, '_tahefobu_include_pages', $include_pages );
        update_post_meta( $post_id, '_tahefobu_exclude_pages', $exclude_pages );

        wp_send_json_success( [ 'message' => __( 'Conditions saved', 'header-footer-builder-for-elementor' ) ] );
    } );

    /**
     * 7. AJAX Load Footer Conditions Modal
     */
    add_action( 'wp_ajax_tahefobu_get_footer_conditions_popup', function () {
        check_ajax_referer( 'tahefobu_save_conditions_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'header-footer-builder-for-elementor' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;

        $include_pages   = get_post_meta( $post_id, '_tahefobu_include_pages', true ) ?: [];
        $exclude_pages   = get_post_meta( $post_id, '_tahefobu_exclude_pages', true ) ?: [];
        $display_targets = get_post_meta( $post_id, '_tahefobu_display_targets', true ) ?: [];

        wp_send_json_success( [
            'include' => array_map( 'strval', $include_pages ),
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Intentional: small dataset; excluding specific pages is acceptable here.
            'exclude' => array_map( 'strval', $exclude_pages ),
            'targets' => array_map( 'strval', $display_targets ),
        ] );
    } );


/**
 * 8. Add column in admin table for Edit Conditions
 */
//--------------------------Add "Edit Conditions" column with button
add_filter('manage_tahefobu_footer_posts_columns', function ($columns) {
    $columns['tahefobu_footer_display_conditions'] = __('Display Conditions', 'header-footer-builder-for-elementor');
    return $columns;
});

add_action('manage_tahefobu_footer_posts_custom_column', function ($column, $post_id) {
    if ($column === 'tahefobu_footer_display_conditions') {
        echo '<button type="button" class="button tahefobu-footer-edit-conditions-button" data-post-id="' . esc_attr($post_id) . '">Edit Conditions</button>';
    }
}, 10, 2);

/**
 * 9. Footer Template Matching Function
 */
function tahefobu_get_matching_footer_template_id() {
    if (is_admin() || wp_doing_ajax()) return null;

    $current_page_id = get_queried_object_id();
    // If the page is using Elementor Canvas layout, skip matching footers (Canvas intentionally excludes theme header/footer).
    if ( $current_page_id ) {
        $page_settings = get_post_meta( $current_page_id, '_elementor_page_settings', true );
        if ( is_array( $page_settings ) && isset( $page_settings['page_layout'] ) && 'elementor_canvas' === $page_settings['page_layout'] ) {
            return null;
        }
        $wp_template = get_post_meta( $current_page_id, '_wp_page_template', true );
        if ( ! empty( $wp_template ) && false !== strpos( $wp_template, 'elementor_canvas' ) ) {
            return null;
        }
    }
    $candidates = get_posts([
        'post_type'      => 'tahefobu_footer',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        // 'suppress_filters' => true,
    ]);

    $woo_pages = function_exists('wc_get_page_id') ? [
        'shop'      => wc_get_page_id('shop'),
        'cart'      => wc_get_page_id('cart'),
        'checkout'  => wc_get_page_id('checkout'),
        'myaccount' => wc_get_page_id('myaccount'),
    ] : [];

    $global_fallback = null;

    foreach ($candidates as $footer) {
        $include         = get_post_meta($footer->ID, '_tahefobu_include_pages', true) ?: [];
        $exclude         = get_post_meta($footer->ID, '_tahefobu_exclude_pages', true) ?: [];
        $display_targets = get_post_meta($footer->ID, '_tahefobu_display_targets', true) ?: [];

        // Normalize page IDs as int
        $include = array_map('intval', $include);
        $exclude = array_map('intval', $exclude);

        // Skip if excluded
        if (in_array($current_page_id, $exclude)) continue;

        // Match by Display Target
        if (!empty($display_targets)) {
            if (in_array('entire_site', $display_targets)) return $footer->ID;
            if (in_array('all_posts', $display_targets) && is_singular('post')) return $footer->ID;
            if (in_array('all_products', $display_targets) && is_singular('product')) return $footer->ID;
            if (in_array('all_archives', $display_targets) && is_archive()) return $footer->ID;
            if (in_array('all_woo', $display_targets)) {
                if (
                    function_exists('is_woocommerce') && is_woocommerce() ||
                    is_cart() || is_checkout() || is_account_page() ||
                    is_shop() || is_product() || is_product_category() || is_product_tag()
                ) {
                    return $footer->ID;
                }
            }
        }

        // Match by include_pages (including Woo special pages)
        if ($current_page_id > 0 && !empty($include)) {
            // Match product single
            if (is_singular('product') && in_array(get_the_ID(), $include)) {
                return $footer->ID;
            }

            // Match Shop archive safely if WooCommerce is active
            if ( function_exists('is_shop') && is_shop() && in_array($woo_pages['shop'], $include) ) {
                return $footer->ID;
            }

            // Match Woo special pages
            foreach ($woo_pages as $woo_id) {
                if ($woo_id && in_array($woo_id, $include)) {
                    if (is_page($woo_id) || is_shop() && $woo_id === wc_get_page_id('shop')) {
                        return $footer->ID;
                    }
                }
            }

            if (in_array($current_page_id, $include)) {
                return $footer->ID;
            }
        }

        // Save fallback if nothing matched
        if (empty($include) && empty($display_targets)) {
            $global_fallback = $footer->ID;
        }
    }

    return $global_fallback;
}

/**
 * 10. Add body class when footer template is active
 */
add_filter('body_class', function ($classes) {
    if (!is_admin() && !wp_doing_ajax() && function_exists('tahefobu_get_matching_footer_template_id')) {
        $footer_template_id = tahefobu_get_matching_footer_template_id();
        if ($footer_template_id) {
            $classes[] = 'ta-custom-footer-enabled';
        }
    }
    return $classes;
});


/**
 * 11. Enqueue Scripts and Styles for Footer Template
 */
add_action('admin_enqueue_scripts', function ($hook) {
    // Get current admin screen instead of reading $_GET
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ( ! $screen ) {
        return;
    }

    // We only want the list table for tahefobu_footer posts (edit screen)
    if ( $screen->base === 'edit' && $screen->post_type === 'tahefobu_footer' ) {

        // Enqueue Select2
        wp_enqueue_style(
            'select2',
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/vendor/select2/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/vendor/select2/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        // Enqueue custom CSS and JS
        wp_enqueue_style(
            'tahefobu-popup-css',
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/css/turbo-header-template-popup.css',
            [],
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tahefobu-footer-popup',
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/js/turbo-footer-template-popup.js',
            ['jquery', 'select2'],
            TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'tahefobu-footer-popup',
            'tahefobu_footer_condition_nonce',
            [ 'nonce' => wp_create_nonce( 'tahefobu_save_conditions_nonce' ) ]
        );
    }
});
