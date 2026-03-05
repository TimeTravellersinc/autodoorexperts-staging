<?php
/**
 * Multilingual Integration Loader
 *
 * Initializes multilingual sitemap support for translation plugins.
 *
 * @package surerank
 * @since 1.6.3
 */

namespace SureRank\Inc\ThirdPartyIntegrations\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureRank\Inc\Functions\Cron;
use SureRank\Inc\Sitemap\Checksum;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Multilingual Init Class
 *
 * @since 1.6.3
 */
class Init {

	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.6.3
	 */
	public function __construct() {
		add_action( 'activated_plugin', [ $this, 'on_plugin_activation' ], 10, 1 );
		add_action( 'deactivated_plugin', [ $this, 'on_plugin_deactivation' ], 10, 1 );

		if ( ! $this->has_translation_plugin() ) {
			return;
		}

		$this->init_classes();
	}

	/**
	 * Handle plugin activation
	 *
	 * @since 1.6.3
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	public function on_plugin_activation( $plugin ) {
		if ( $this->is_translation_plugin( $plugin ) ) {
			$this->trigger_sitemap_regeneration();
		}
	}

	/**
	 * Handle plugin deactivation
	 *
	 * @since 1.6.3
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	public function on_plugin_deactivation( $plugin ) {
		if ( $this->is_translation_plugin( $plugin ) ) {
			$this->trigger_sitemap_regeneration();
		}
	}

	/**
	 * Check if translation plugin is active
	 *
	 * @since 1.6.3
	 * @return bool
	 */
	private function has_translation_plugin(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) ||
			function_exists( 'pll_default_language' ) ||
			class_exists( 'TRP_Translate_Press' );
	}

	/**
	 * Initialize classes
	 *
	 * @since 1.6.3
	 * @return void
	 */
	private function init_classes() {
		Translation_Manager::get_instance();
		Hreflang_Generator::get_instance();
	}

	/**
	 * Check if plugin is a translation plugin
	 *
	 * @since 1.6.3
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private function is_translation_plugin( $plugin ) {
		$translation_plugins = [
			'sitepress-multilingual-cms/sitepress.php',
			'polylang/polylang.php',
			'polylang-pro/polylang.php',
			'translatepress-multilingual/index.php',
		];

		return in_array( $plugin, $translation_plugins, true );
	}

	/**
	 * Trigger sitemap regeneration
	 *
	 * @since 1.6.3
	 * @return void
	 */
	private function trigger_sitemap_regeneration() {

		if ( class_exists( 'SureRank\\Inc\\Sitemap\\Checksum' ) ) {
			Checksum::get_instance()->update_checksum();
		}

		if ( class_exists( 'SureRank\\Inc\\Functions\\Cron' ) ) {
			wp_schedule_single_event( time() + 10, Cron::SITEMAP_CRON_EVENT, [ 'yes' ] );
		}
	}
}
