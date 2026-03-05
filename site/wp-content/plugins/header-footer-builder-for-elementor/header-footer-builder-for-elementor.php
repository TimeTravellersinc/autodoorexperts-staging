<?php
/**
 * Plugin Name: Header Footer Builder for Elementor
 * Plugin URI: https://wp-turbo.com/header-footer-builder-for-elementor/
 * Description: Header Footer Builder for Elementor & WooCommerce. Easy, customizable plugin for headers/footers with display rules, sticky header & include/exclude.
 * Version: 1.1.1
 * Requires Plugins: elementor
 * Author: turbo addons 
 * Author URI: https://wp-turbo.com/
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 * Text Domain: header-footer-builder-for-elementor
 * Elementor tested up to: 3.35.0
 * Elementor Pro tested up to: 3.35.0   
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// wp-pulse integration
if ( ! class_exists( 'WPPulse_SDK' ) ) {
    require_once __DIR__ . '/wppulse/wppulse-plugin-analytics-engine-sdk.php';
}

    // Fetch plugin data automatically
    $tahefobu_plugin_data = get_file_data( __FILE__, [
        'Name'       => 'Plugin Name',
        'Version'    => 'Version',
        'TextDomain' => 'Text Domain',
    ] );

    $tahefobu_plugin_slug = dirname( plugin_basename( __FILE__ ) );

    // Initialize SDK
    if ( class_exists( 'WPPulse_SDK' ) ) {
        WPPulse_SDK::init( __FILE__, [
            'name'     => $tahefobu_plugin_data['Name'],
            'slug'     => $tahefobu_plugin_slug,
            'version'  => $tahefobu_plugin_data['Version'],
            'endpoint' => 'https://wp-turbo.com/wp-json/wppulse/v1/collect',
        ] );
    }


/**
 * Main Plugin Class
 * @since 1.0.0
 */
final class TAHEFOBU_Header_Footer_Builder_For_Elementor {
    const TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_ELEMENTOR_VERSION = '3.0.0';
    const TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_PHP_VERSION = '7.4';
    
    private static $_instance = null;

    /**
     * Singleton Instance Method
     * @since 1.0.0
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     * @since 1.0.0
     */
    public function __construct() {
        if ( ! function_exists( 'hfbfe_fs' ) ) {
            // Create a helper function for easy SDK access.
            function hfbfe_fs() {
                global $hfbfe_fs;

                if ( ! isset( $hfbfe_fs ) ) {
                    // Include Freemius SDK.
                    require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

                    $hfbfe_fs = fs_dynamic_init( array(
                        'id'                  => '22909',
                        'slug'                => 'header-footer-builder-for-elementor',
                        'type'                => 'plugin',
                        'public_key'          => 'pk_092670a4b0e91a5ad9dc497efbf71',
                        'is_premium'          => false,
                        'has_addons'          => false,
                        'has_paid_plans'      => false, // Must be false for WordPress.org
                        'menu'                => array(
                            'slug'           => 'edit.php?post_type=tahefobu_header',
                            // For WordPress.org, only these menu items are allowed:
                            'account'        => false, // Must be false on .org
                            'contact'        => false, // Must be false on .org
                            'support'        => false, // Must be false on .org
                            'pricing'        => false, // Must be false on .org
                            'addons'         => false, // Must be false on .org
                            'affiliation'    => false, // Must be false on .org
                        ),
                        // WordPress.org specific settings:
                        'is_live'             => true,
                        'is_org_compliant'    => true, // Important: Mark as .org compliant
                    ) );
                }

                return $hfbfe_fs;
            }

            // Init Freemius - but with WordPress.org restrictions
            hfbfe_fs();
            
            // Optional: Add WordPress.org compliant opt-in message
            // hfbfe_fs()->add_filter('connect_message', 'hfbfe_custom_connect_message', 10, 6);
            // hfbfe_fs()->add_filter('connect_message_on_update', 'hfbfe_custom_connect_message_on_update', 10, 6);
            
            // function hfbfe_custom_connect_message($message, $user_first_name, $product_title, $user_login, $site_link, $freemius_link) {
            //     return sprintf(
            //         __( 'Hey %1$s', 'header-footer-builder-for-elementor' ) . ',<br>' .
            //         __( 'Never miss an important update! Opt-in to receive security & feature updates, educational content, and occasional deals.', 'header-footer-builder-for-elementor' ) . '<br>' .
            //         __( 'If you skip this, that\'s okay! %2$s will still work just fine.', 'header-footer-builder-for-elementor' ),
            //         $user_first_name,
            //         '<b>' . $product_title . '</b>'
            //     );
            // }
            
            // function hfbfe_custom_connect_message_on_update($message, $user_first_name, $product_title, $user_login, $site_link, $freemius_link) {
            //     return sprintf(
            //         __( 'Hey %1$s', 'header-footer-builder-for-elementor' ) . ',<br>' .
            //         __( 'Please help us improve %2$s by allowing tracking of usage data.', 'header-footer-builder-for-elementor' ) . '<br>' .
            //         __( 'This will help us make better decisions about future features.', 'header-footer-builder-for-elementor' ),
            //         $user_first_name,
            //         '<b>' . $product_title . '</b>'
            //     );
            // }
            
            // Signal that SDK was initiated.
            do_action( 'hfbfe_fs_loaded' );
        }
        include_once plugin_dir_path(__FILE__) . 'helper/helper.php';
        $this->define_constants();
        add_action( 'wp_enqueue_scripts', [ $this, 'tahefobu_header_footer_builder_for_elementor_enqueue_scripts_styles' ] );
        add_action( 'init', [ $this, 'tahefobu_header_footer_builder_for_elementor_load_textdomain' ] );
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'tahefobu_header_footer_builder_for_elementor_editor_icon_enqueue_scripts' ] );
       
       // Widget category
        add_action( 'elementor/elements/categories_registered', [ $this, 'register_widgets_category' ] );
       
        // widgets = style + script//
        add_action( 'elementor/widgets/register', [ $this, 'register_new_hf_widgets' ] );
        add_action( 'wp_enqueue_scripts', 'tahefobu_register_assets' );
        add_action( 'elementor/frontend/before_enqueue_scripts', 'tahefobu_register_assets' );
    }
    
    /**
     * Define Plugin Constants
     * @since 1.0.0
     */
    private function define_constants() {
        define( 'TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL', trailingslashit( plugins_url( '/', __FILE__ ) ) );
        define( 'TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
        define( 'TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_VERSION', '1.1.1' );
    }

    /**
     * Enqueue Scripts & Styles
     * @since 1.0.0
     */
    public function tahefobu_header_footer_builder_for_elementor_enqueue_scripts_styles() {   
        // turbo header footer css //
        wp_enqueue_style( 'tahefobu-header-style', TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/css/turbo-header-style.css', [], filemtime( TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'assets/css/turbo-header-style.css' ), 'all' );
        
        // turbo header footer js //
        wp_enqueue_script( 'tahefobu-header-behavior', TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/js/turbo-header-behavior.js', ['jquery'], filemtime( TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'assets/js/turbo-header-behavior.js' ), true );
    }

    /**
     * Enqueue Styles For Widget Icon
     * @since 1.0.0
    */
    public function tahefobu_header_footer_builder_for_elementor_editor_icon_enqueue_scripts() {
    wp_enqueue_style(
        'tahefobu-editor-icon',
        TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_URL . 'assets/css/editor-warning.css',
        [],
        filemtime( TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'assets/css/editor-warning.css' ),
        'all'
    );
}

    /**
     * Load Text Domain for Translations
     * @since 1.0.0
     */
    public function tahefobu_header_footer_builder_for_elementor_load_textdomain() {
        load_plugin_textdomain( 'header-footer-builder-for-elementor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Initialize the plugin
     * @since 1.0.0
     */
    public function init() {
        if ( ! did_action( 'elementor/loaded' ) ) {
            add_action( 'admin_notices', [ $this, 'tahefobu_header_footer_builder_for_elementor_admin_notice_missing_main_plugin' ] );
            return;
        }

        if ( ! version_compare( ELEMENTOR_VERSION, self::TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_ELEMENTOR_VERSION, '>=' ) ) {
            add_action( 'admin_notices', [ $this, 'tahefobu_header_footer_builder_for_elementor_admin_notice_minimum_elementor_version' ] );
            return;
        }

        if ( ! version_compare( PHP_VERSION, self::TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_PHP_VERSION, '>=' ) ) {
            add_action( 'admin_notices', [ $this, 'tahefobu_header_footer_builder_for_elementor_admin_notice_minimum_php_version' ] );
            return;
        }
        // Auto-append the preview nonce for your CPTs (prevents broken preview)
        add_filter( 'elementor/document/urls/preview', function( $url, $document ) {
            $post_id = 0;
            if ( method_exists( $document, 'get_main_id' ) ) {
                $post_id = (int) $document->get_main_id();
            }
            if ( ! $post_id && method_exists( $document, 'get_id' ) ) {
                $post_id = (int) $document->get_id();
            }
            if ( ! $post_id ) {
                return $url;
            }

            $pt = get_post_type( $post_id );
            if ( in_array( $pt, [ 'tahefobu_header', 'tahefobu_footer' ], true ) ) {
                $url = add_query_arg(
                    'tahefobu_nonce',
                    wp_create_nonce( 'tahefobu_preview_' . $post_id ),
                    $url
                );
            }
            return $url;
        }, 10, 2 );

        // Load header and footer template functionality
        $this->load_header_footer_templates();

    }

    /**
     * Load Header and Footer Template Files
     * @since 1.0.0
     */
    private function load_header_footer_templates() {
        // Load header template functionality
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'header-footer-template/header-builder/turbo-header-template.php';
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'header-footer-template/header-builder/turbo-header-render.php';
        
        // Load footer template functionality
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'header-footer-template/footer-builder/turbo-footer-template.php';
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'header-footer-template/footer-builder/turbo-footer-render.php';
        
        // Load admin menu functionality
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'header-footer-template/header-footer-menu/header-footer-menu.php';

        //helper allow wp_kses-post
        require_once TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'helper/helper.php';


        // Ensure Elementor CSS for the matched Header is enqueued in <head> to avoid FOUC
       add_action( 'wp_enqueue_scripts', function () {
            // Register a base stylesheet (can be empty if you don’t have a file)
            wp_register_style(
                'tahefobu-frontend',
                false, // no file, just for inline use
                [],
                '1.1.1'
            );
            wp_enqueue_style( 'tahefobu-frontend' );

            // Add your dynamic CSS inline
            // Start header visually hidden (opacity 0) but present in layout; apply a very short fade when ready.
            $dynamic_css = '#tahefobu-header { opacity: 0; transform: none; pointer-events: none; } #tahefobu-header.tahefobu-ready { opacity: 1; pointer-events: auto; transition: opacity .25s linear; }';
            wp_add_inline_style( 'tahefobu-frontend', $dynamic_css );
        }, 1 );


        // Ensure Elementor preview has the_content() for our CPTs on any theme
        add_filter( 'template_include', function ( $template ) {

            // Elementor preview handling — must be nonce + caps gated
            if ( isset( $_GET['elementor-preview'] ) ) {
                $raw_id = filter_input( INPUT_GET, 'elementor-preview', FILTER_SANITIZE_NUMBER_INT );
                $pid    = absint( $raw_id );
                $nonce  = filter_input( INPUT_GET, 'tahefobu_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

                // Fail early if nonce missing/invalid
                if ( ! $pid || ! $nonce || ! wp_verify_nonce( $nonce, 'tahefobu_preview_' . $pid ) ) {
                    return $template;
                }

                // Capability check (nonces aren’t auth)
                if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $pid ) ) {
                    return $template;
                }

                $pt = get_post_type( $pid );
                if ( in_array( $pt, [ 'tahefobu_header', 'tahefobu_footer' ], true ) ) {
                    return ( 'tahefobu_header' === $pt )
                        ? TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'templates/single-tahefobu_header_template.php'
                        : TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'templates/single-tahefobu_footer_template.php';
                }
            }

            // Normal singular views (safe)
            if ( is_singular( 'tahefobu_header' ) ) {
                return TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'templates/single-tahefobu_header_template.php';
            }
            if ( is_singular( 'tahefobu_footer' ) ) {
                return TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'templates/single-tahefobu_footer_template.php';
            }

            return $template;
        }, 99 );
    }

    /**
     * Admin Notice for Minimum Elementor Version
     * @since 1.0.0
     */
    public function tahefobu_header_footer_builder_for_elementor_admin_notice_minimum_elementor_version() {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }

            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                wp_kses_post( sprintf(
                    /* translators: 1: Plugin name (Header Footer Builder), 2: Dependency name (Elementor), 3: Minimum required Elementor version */
                    esc_html__( '"%1$s" requires "%2$s" version %3$s or greater.', 'header-footer-builder-for-elementor' ),
                    '<strong>' . esc_html__( 'Turbo Header Footer Builder For Elementor', 'header-footer-builder-for-elementor' ) . '</strong>',
                    '<strong>' . esc_html__( 'Elementor', 'header-footer-builder-for-elementor' ) . '</strong>',
                    esc_html( self::TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_ELEMENTOR_VERSION )
                ) )
            );
        }

   /**
     * Admin Notice for Minimum PHP Version
     * @since 1.0.0
     */
    public function tahefobu_header_footer_builder_for_elementor_admin_notice_minimum_php_version() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            wp_kses_post( sprintf(
                /* translators: 1: Plugin name (Header Footer Builder), 2: Software name (PHP), 3: Minimum required PHP version */
                esc_html__( '"%1$s" requires "%2$s" version %3$s or greater.', 'header-footer-builder-for-elementor' ),
                '<strong>' . esc_html__( 'Turbo Header Footer Builder For Elementor', 'header-footer-builder-for-elementor' ) . '</strong>',
                '<strong>' . esc_html__( 'PHP', 'header-footer-builder-for-elementor' ) . '</strong>',
                esc_html( self::TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_MIN_PHP_VERSION )
            ) )
        );
    }

    // category register//
    public function register_widgets_category( $elements_manager ) {

        $elements_manager->add_category(
            'tahefobu-hf-widgets',
            [
                'title' => __( 'Turbo H&F Builder', 'header-footer-builder-for-elementor' ),
                'icon'  => 'fa fa-plug',
            ]
        );
    }

    public function register_new_hf_widgets( $widgets_manager ) {

        $new_widgets = [
            'navigation-menu-hf.php',
            'icon-button-hf.php',
            'top-bar-hf.php',
            'copy-right-hf.php',
            'site-logo-hf.php',
        ];

        foreach ( $new_widgets as $file ) {
            $path = TAHEFOBU_HEADER_FOOTER_BUILDER_FOR_ELEMENTOR_PLUGIN_PATH . 'widgets/' . $file;

            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }

        // Register one by one
        if ( class_exists('TAHEFOBU_Navigation_Menu') )
            $widgets_manager->register( new \TAHEFOBU_Navigation_Menu() );

        if ( class_exists('TAHEFOBU_Icon_Button') )
            $widgets_manager->register( new \TAHEFOBU_Icon_Button() );

        if ( class_exists('TAHEFOBU_Top_Bar') )
            $widgets_manager->register( new \TAHEFOBU_Top_Bar() );

        if ( class_exists('TAHEFOBU_Copy_Right') )
            $widgets_manager->register( new \TAHEFOBU_Copy_Right() );

        if ( class_exists('TAHEFOBU_Site_Logo') )
            $widgets_manager->register( new \TAHEFOBU_Site_Logo() );
    }

}

/**
 * Recommend Turbo Addons if Elementor Pro is not active
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-hfb-recommend-turbo-addons.php';


/**
 * Initializes the Plugin
 * @since 1.0.0
 */
/**
 * Initializes the Plugin only if Turbo Addons Pro is NOT active
 */
function tahefobu_header_footer_builder_for_elementor() {

    return TAHEFOBU_Header_Footer_Builder_For_Elementor::instance();
}

tahefobu_header_footer_builder_for_elementor();

