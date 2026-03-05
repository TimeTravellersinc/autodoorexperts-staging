<?php
/**
 * Translation Provider Interface
 *
 * Defines the contract that all translation provider implementations must follow.
 *
 * @package surerank
 * @since 1.6.3
 */

namespace SureRank\Inc\ThirdPartyIntegrations\Multilingual;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation Provider Interface
 *
 * @since 1.6.3
 */
interface Provider {

	/**
	 * Get translation URLs for a single post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_translations( int $post_id, string $post_type ): array;

	/**
	 * Batch fetch translations for multiple posts
	 *
	 * @since 1.6.3
	 * @param array<int> $post_ids Array of post IDs.
	 * @param string     $post_type Post type.
	 * @return array<int, array<string, array{url: string, locale: string}>>
	 */
	public function get_translations_batch( array $post_ids, string $post_type ): array;

	/**
	 * Get default site language
	 *
	 * @since 1.6.3
	 * @return string Language code.
	 */
	public function get_default_language(): string;

	/**
	 * Check if translation is available for post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return bool
	 */
	public function is_translation_available( int $post_id, string $language ): bool;

	/**
	 * Get translated post ID
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return int|null
	 */
	public function get_translated_post_id( int $post_id, string $language ): ?int;

	/**
	 * Get the language of a post
	 *
	 * @since 1.6.3
	 * @param int $post_id Post ID.
	 * @return string Language code, or empty string if not found.
	 */
	public function get_post_language( int $post_id ): string;

	/**
	 * Get translation URLs for a single term
	 *
	 * @since x.x.x
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array;

	/**
	 * Get the language of a term
	 *
	 * @since x.x.x
	 * @param int $term_id Term ID.
	 * @return string Language code, or empty string if not found.
	 */
	public function get_term_language( int $term_id ): string;
}
