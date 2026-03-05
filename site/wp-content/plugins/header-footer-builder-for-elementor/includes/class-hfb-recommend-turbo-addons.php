<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recommend Turbo Addons plugin if not active.
 */
class HFB_Recommend_Turbo_Addons {

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'show_recommendation_notice' ] );
    }

    /**
     * Check if Turbo Addons FREE is active
     */
    private function hfbfe_is_turbo_addons_free_version_active() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option( 'active_plugins', [] );
        $all_plugins    = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if (
                in_array( $plugin_file, $active_plugins, true ) &&
                isset( $plugin_data['Name'] ) &&
                $plugin_data['Name'] === 'Turbo Addons Elementor'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show admin notice suggesting Turbo Addons installation
     */
    public function show_recommendation_notice() {

        // 🔴 Turbo Addons active থাকলে → পুরো section hide
        if ( $this->hfbfe_is_turbo_addons_free_version_active() ) {
            return;
        }

        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Install & Activate URLs
        $install_url = wp_nonce_url(
            self_admin_url( 'update.php?action=install-plugin&plugin=turbo-addons-elementor' ),
            'install-plugin_turbo-addons-elementor'
        );

        $activate_url = wp_nonce_url(
            self_admin_url( 'plugins.php?action=activate&plugin=turbo-addons-elementor%2Fturbo-addons-elementor.php' ),
            'activate-plugin_turbo-addons-elementor/turbo-addons-elementor.php'
        );

        // Installed but inactive?
        $is_installed = file_exists(
            WP_PLUGIN_DIR . '/turbo-addons-elementor/turbo-addons-elementor.php'
        );
        ?>

        <!-- ✅ NOTICE ONLY SHOWS WHEN TURBO ADDONS IS NOT ACTIVE -->
        <div class="notice notice-info is-dismissible" 
            style="padding:20px; border-left:4px solid #ff8800;">

            <!-- Flex Layout -->
            <div style="
                display:flex;
                align-items:stretch; 
                justify-content:space-between;
                gap:20px;
            ">
                <!-- Left: Text + Button -->
                <div style="width:70%; flex:1; display:flex; flex-direction:column; justify-content:center;">
                         <!-- Heading -->
                    <p style="margin:0 0 12px 0;">
                        <strong style="color:#ff9a00; font-size:20px; line-height:1.4;">
                            <?php esc_html_e( 'Get Advanced Features for Modern Design — Free!', 'header-footer-builder-for-elementor' ); ?>
                        </strong>
                    </p>

                    <p style="margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#444;">
                        <?php esc_html_e(
                            'Design without limits! Turbo Addons gives you 80+ advanced widgets, WooCommerce support, and 100+ stunning templates — Free and Pro forever. Visit our website to explore demos and see how easily you can create modern designs.',
                            'header-footer-builder-for-elementor'
                        ); ?>
                    </p>

                   <div>
                     <?php if ( $is_installed ) : ?>
                        <a href="<?php echo esc_url( $activate_url ); ?>" 
                        class="button button-primary"
                        style="width:175px;background:#ff9a00; border-color:#ff9a00; padding:6px 18px; font-size:14px;">
                            <?php esc_html_e( 'Activate Turbo Addons', 'header-footer-builder-for-elementor' ); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $install_url ); ?>" 
                        class="button button-primary"
                        style="font-weight:600; background:#ff9a00; border-color:#ff9a00; padding:6px 18px; font-size:14px;">
                            <?php esc_html_e( 'Install Turbo Addons', 'header-footer-builder-for-elementor' ); ?>
                        </a>
                    <?php endif; ?>

                   <a href="<?php echo esc_url( 'https://turbo-addons.com/' ); ?>" 
                        target="_blank"
                        class="button"
                        style="font-weight:600; margin-left:12px; background:#ffffff; border:1px solid #ccd0d4; color:#0073aa; padding:6px 16px; font-size:14px; cursor:pointer;">
                        <?php esc_html_e( 'Explore All Features', 'header-footer-builder-for-elementor' ); ?>
                        </a>
                   </div>

                </div>

                <!-- Right: Image -->
                <div style="width:28%; flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                    <img 
                        src="<?php echo esc_url( plugins_url( 'assets/images/promotion-banner.webp', dirname( __FILE__ ) ) ); ?>"
                        alt="<?php esc_attr_e( 'Turbo Addons for Elementor', 'header-footer-builder-for-elementor' ); ?>"
                        style="margin:-20px; width:100%; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.15);" 
                    />
                </div>

            </div>
        </div>
        <?php
    }
}

new HFB_Recommend_Turbo_Addons();

