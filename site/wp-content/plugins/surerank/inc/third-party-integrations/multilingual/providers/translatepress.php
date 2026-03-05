<?php
/**
 * TranslatePress Translation Provider
 *
 * Handles translation data retrieval for TranslatePress plugin.
 *
 * @package surerank
 * @since 1.6.3
 */

namespace SureRank\Inc\ThirdPartyIntegrations\Multilingual\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureRank\Inc\ThirdPartyIntegrations\Multilingual\Provider;

/**
 * TranslatePress Provider Class
 *
 * @since 1.6.3
 */
class Translatepress implements Provider {

	/**
	 * URL converter component
	 *
	 * @since 1.6.3
	 * @var object|null
	 */
	private $url_converter = null;

	/**
	 * Settings
	 *
	 * @since 1.6.3
	 * @var array<string, mixed>
	 */
	private $settings = [];

	/**
	 * Constructor
	 *
	 * @since 1.6.3
	 */
	public function __construct() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return;
		}

		$trp = \TRP_Translate_Press::get_trp_instance();

		if ( $trp ) {
			$this->url_converter = $trp->get_component( 'url_converter' );
			$settings_component  = $trp->get_component( 'settings' );

			if ( $settings_component ) {
				$this->settings = $settings_component->get_settings();
			}
		}
	}

	/**
	 * Get translation URLs for a single post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_translations( int $post_id, string $post_type ): array {
		$url       = get_permalink( $post_id );
		$languages = $this->get_published_languages();

		if ( ! $url || empty( $languages ) || ! $this->url_converter ) {
			return [];
		}

		$translations = [];

		foreach ( $languages as $lang_code ) {
			if ( ! method_exists( $this->url_converter, 'get_url_for_language' ) ) {
				continue;
			}

			$translated_url = $this->url_converter->get_url_for_language( $lang_code, $url, '' );

			if ( ! $translated_url ) {
				continue;
			}

			$translations[ $lang_code ] = [
				'url'    => $translated_url,
				'locale' => $this->format_locale( $lang_code ),
			];
		}

		return $translations;
	}

	/**
	 * Batch fetch translations for multiple posts
	 *
	 * @since 1.6.3
	 * @param array<int> $post_ids Array of post IDs.
	 * @param string     $post_type Post type.
	 * @return array<int, array<string, array{url: string, locale: string}>>
	 */
	public function get_translations_batch( array $post_ids, string $post_type ): array {
		$results = [];

		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->get_translations( $post_id, $post_type );
		}

		return $results;
	}

	/**
	 * Get default site language
	 *
	 * @since 1.6.3
	 * @return string
	 */
	public function get_default_language(): string {
		return $this->settings['default-language'] ?? '';
	}

	/**
	 * Check if translation is available for post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return bool
	 */
	public function is_translation_available( int $post_id, string $language ): bool {
		$languages = $this->get_published_languages();
		return in_array( $language, $languages, true );
	}

	/**
	 * Get translated post ID
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return int|null
	 */
	public function get_translated_post_id( int $post_id, string $language ): ?int {
		return $post_id;
	}

	/**
	 * Get the language of a post
	 *
	 * @since 1.6.3
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_post_language( int $post_id ): string {
		// TranslatePress doesn't have language per post - it uses URL-based language switching.
		// All posts exist in the default language, translations are URL-based.
		return $this->get_default_language();
	}

	/**
	 * Get translation URLs for a single term
	 *
	 * @since x.x.x
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array {
		$url       = get_term_link( $term_id, $taxonomy );
		$languages = $this->get_published_languages();

		if ( is_wp_error( $url ) || ! $url || empty( $languages ) || ! $this->url_converter ) {
			return [];
		}

		$translations = [];

		foreach ( $languages as $lang_code ) {
			if ( ! method_exists( $this->url_converter, 'get_url_for_language' ) ) {
				continue;
			}

			$translated_url = $this->url_converter->get_url_for_language( $lang_code, $url, '' );

			if ( ! $translated_url ) {
				continue;
			}

			$translations[ $lang_code ] = [
				'url'    => $translated_url,
				'locale' => $this->format_locale( $lang_code ),
			];
		}

		return $translations;
	}

	/**
	 * Get the language of a term
	 *
	 * @since x.x.x
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public function get_term_language( int $term_id ): string {
		// TranslatePress uses URL-based language switching.
		// All terms exist in the default language.
		return $this->get_default_language();
	}

	/**
	 * Get published languages
	 *
	 * @since 1.6.3
	 * @return array<string>
	 */
	private function get_published_languages(): array {
		return isset( $this->settings['publish-languages'] ) && is_array( $this->settings['publish-languages'] )
			? $this->settings['publish-languages']
			: [];
	}

	/**
	 * Format locale for hreflang
	 *
	 * @since 1.6.3
	 * @param string $locale Locale string.
	 * @return string
	 */
	private function format_locale( string $locale ): string {
		return str_replace( '_', '-', $locale );
	}
}
