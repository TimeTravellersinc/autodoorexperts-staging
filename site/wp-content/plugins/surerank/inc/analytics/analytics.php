<?php
/**
 * Analytics class helps to connect BSFAnalytics.
 *
 * @package surerank.
 */

namespace SureRank\Inc\Analytics;

use SureRank\Inc\Functions\Defaults;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\GoogleSearchConsole\Controller;
use SureRank\Inc\Modules\EmailReports\Utils as EmailReportsUtil;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Analytics class.
 *
 * @since 1.4.0
 */
class Analytics {
	use Get_Instance;

	/**
	 * Class constructor.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function __construct() {

		if ( ! class_exists( 'Astra_Notices' ) ) {
			require_once SURERANK_DIR . 'inc/lib/astra-notices/class-astra-notices.php';
		}

		/*
		* BSF Analytics.
		*/
		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			require_once SURERANK_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-loader.php';
		}

		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			return;
		}

		$surerank_bsf_analytics = \BSF_Analytics_Loader::get_instance();

		$surerank_bsf_analytics->set_entity(
			[
				'surerank' => [
					'product_name'        => 'SureRank',
					'path'                => SURERANK_DIR . 'inc/lib/bsf-analytics',
					'author'              => 'SureRank',
					'time_to_display'     => '+24 hours',
					'hide_optin_checkbox' => true,
				],
			]
		);

		add_filter( 'bsf_core_stats', [ $this, 'add_surerank_analytics_data' ] );
	}

	/**
	 * Callback function to add SureRank specific analytics data.
	 *
	 * @param array<string, mixed> $stats_data existing stats_data.
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	public function add_surerank_analytics_data( $stats_data ) {
		$settings    = Settings::get();
		$pro_enabled = defined( 'SURERANK_PRO_VERSION' );

		$other_stats               = [
			'site_language'                            => get_locale(),
			'gsc_connected'                            => $this->get_gsc_connected(),
			'plugin_version'                           => SURERANK_VERSION,
			'php_version'                              => phpversion(),
			'wordpress_version'                        => get_bloginfo( 'version' ),
			'is_active'                                => $this->is_active(),
			'enable_xml_sitemap'                       => $settings['enable_xml_sitemap'] ?? true,
			'enable_xml_image_sitemap'                 => $settings['enable_xml_image_sitemap'] ?? true,
			'enable_xml_news_sitemap'                  => $pro_enabled ? $settings['enable_xml_news_sitemap'] ?? false : false,
			'robots_data'                              => Helper::get_robots_data(),
			'author_archive'                           => $settings['author_archive'] ?? true,
			'date_archive'                             => $settings['date_archive'] ?? true,
			'cron_available'                           => Helper::are_crons_available(),
			'redirect_attachment_pages_to_post_parent' => $settings['redirect_attachment_pages_to_post_parent'] ?? true,
			'auto_set_image_alt'                       => $settings['auto_set_image_alt'] ?? true,
			'email_reports'                            => EmailReportsUtil::get_instance()->get_settings(),
			'site_type'                                => $this->get_site_type(),
			'kpi_records'                              => $this->get_kpi_tracking_data(),
		];
		$stats                     = array_merge(
			$other_stats,
			$this->get_failed_site_seo_checks(),
			$this->get_enabled_features()
		);
		$stats_data['plugin_data'] = [
			'surerank' => $stats,
		];
		return $stats_data;
	}

	/**
	 * Compare top-level and one-level nested settings with defaults.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $defaults Default settings.
	 * @return array<string, mixed> Changed settings (top-level + one-level deep).
	 */
	public static function shallow_two_level_diff( array $settings, array $defaults ) {
		$difference = [];

		if ( isset( $defaults['surerank_usage_optin'] ) ) {
			unset( $defaults['surerank_usage_optin'] );
		}

		foreach ( $settings as $key => $value ) {

			// Key missing in defaults = changed.
			if ( ! array_key_exists( $key, $defaults ) ) {
				$difference[ $key ] = $value;
				continue;
			}

			// If value is an array, only check one level deep.
			if ( is_array( $value ) && is_array( $defaults[ $key ] ) ) {
				$nested_diff = [];
				foreach ( $value as $sub_key => $sub_value ) {
					if ( ! array_key_exists( $sub_key, $defaults[ $key ] ) || $sub_value !== $defaults[ $key ][ $sub_key ] ) {
						$nested_diff[ $sub_key ] = $sub_value;
					}
				}
				if ( ! empty( $nested_diff ) ) {
					$difference[ $key ] = $nested_diff;
				}
			} elseif ( $value !== $defaults[ $key ] ) {
				// Compare scalar values directly.
				$difference[ $key ] = $value;
			}
		}

		return $difference;
	}

	/**
	 * Get failed site SEO checks.
	 *
	 * @return array<string,int>
	 */
	private function get_failed_site_seo_checks() {
		$failed_checks      = Get::option( 'surerank_site_seo_checks', [] );
		$failed_checks_list = [];
		foreach ( $failed_checks as $check ) {
			foreach ( $check as $key => $value ) {
				if ( isset( $value['status'] ) && $value['status'] === 'error' ) {
					$failed_checks_list[ $key ] = 0;
				}
			}
		}
		return $failed_checks_list;
	}

	/**
	 * Get enabled features.
	 *
	 * @return array<string, mixed>
	 */
	private function get_enabled_features() {
		return [
			'enable_page_level_seo' => Settings::get( 'enable_page_level_seo' ),
			'enable_google_console' => Settings::get( 'enable_google_console' ),
			'enable_schemas'        => Settings::get( 'enable_schemas' ),
		];
	}

	/**
	 * Get Google Search Console connected status.
	 *
	 * @return bool
	 */
	private function get_gsc_connected() {
		return Controller::get_instance()->get_auth_status();
	}

	/**
	 * Check if SureRank is active (has settings different from defaults).
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function is_active() {

		$surerank_defaults = Defaults::get_instance()->get_global_defaults();

		$surerank_settings = get_option( SURERANK_SETTINGS, [] );

		if ( is_array( $surerank_settings ) && is_array( $surerank_defaults ) ) {
				$changed_settings = self::shallow_two_level_diff( $surerank_settings, $surerank_defaults );
			if ( count( $changed_settings ) >= 1 ) {
				return true;
			}
		}

		global $wpdb;
			$posts_like = $wpdb->esc_like( 'surerank_settings_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"
						SELECT DISTINCT pm.post_id
						FROM {$wpdb->postmeta} pm
						INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
						WHERE pm.meta_key LIKE %s
						AND p.post_status = 'publish'
						LIMIT 1
					",
					$posts_like
				)
			);

			// Check if any terms have been optimized.
			$terms_like = $wpdb->esc_like( 'surerank_seo_checks' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$terms = $wpdb->get_col(
				$wpdb->prepare(
					"
						SELECT DISTINCT tm.term_id
						FROM {$wpdb->termmeta} tm
						INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
						INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
						WHERE tm.meta_key LIKE %s
						LIMIT 1
					",
					$terms_like
				)
			);

		if ( ( ! empty( $posts ) && is_array( $posts ) ) || ( ! empty( $terms ) && is_array( $terms ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get site type - Inactive|Active|Super|Dormant|Super Dormant.
	 *
	 * This site type is used to determine the status of the site and further use of business logic.
	 *
	 * INACTIVE SITE
	 * A site is considered "Inactive" if both of the following are true:
	 * No page or post has ever been manually optimized
	 * The plugin is active on the site
	 *
	 * ACTIVE SITE
	 * A site is considered "Active" if all of the following are true:
	 * At least 1 page or post has been manually optimized
	 * The optimization happened in the last 180 days
	 * The plugin is active on the site
	 *
	 * SUPER SITE
	 * A site is considered a "Super Site" if both conditions are met:
	 * At least 20 pages or posts have been manually optimized in the last 180 days
	 * At least 50% of total pages or posts have been manually optimized in the last 180 days
	 * The plugin is active on the site
	 *
	 * DORMANT SITE
	 * A site is considered "Dormant" if all of the following are true:
	 * At least 1 page or post was manually optimized in the past
	 * No manual optimization has occurred in the last 180 days
	 * The plugin is active on the site
	 *
	 * SUPER DORMANT SITE
	 * A site is considered a "Dormant Super Site" if all of the following are true:
	 * At least 20 pages or posts were manually optimized in the past
	 * At least 50% of total pages or posts were manually optimized in the past
	 * No manual optimization has occurred in the last 180 days
	 * The plugin is active on the site
	 *
	 * @return string Site type: 'inactive', 'active', 'super', 'dormant', or 'super_dormant'.
	 * @since 1.6.3
	 */
	private function get_site_type() {
		// Get count of posts optimized in last 180 days.
		$recent_optimized_count = $this->get_optimized_posts_count_last_180_days();

		// Get total optimized posts (ever).
		$total_optimized_count = $this->get_optimized_posts_count();

		// No posts ever optimized = inactive.
		if ( 0 === $total_optimized_count ) {
			return 'inactive';
		}

		// No recent optimizations = dormant or super_dormant.
		if ( 0 === $recent_optimized_count ) {
			// Check if site qualifies as "super" based on total optimizations.
			$is_super_past = $this->is_super_site_past( $total_optimized_count );
			return $is_super_past ? 'super_dormant' : 'dormant';
		}

		// Has recent optimizations = active or super.
		$is_super_recent = $this->is_super_site_recent( $recent_optimized_count );
		return $is_super_recent ? 'super' : 'active';
	}

	/**
	 * Get count of unique posts and terms that have been optimized with SureRank.
	 *
	 * @return int Number of optimized posts and terms.
	 * @since 1.6.3
	 */
	private function get_optimized_posts_count() {
		global $wpdb;

		$posts_like        = $wpdb->esc_like( 'surerank_settings_' ) . '%';
		$public_post_types = $this->get_public_post_types_for_query();

		if ( empty( $public_post_types ) ) {
			$post_count = 0;
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $public_post_types ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key LIKE %s
					AND p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( [ $posts_like ], $public_post_types )
				)
			);
		}

		// Count terms with sureRank settings.
		$terms_like = $wpdb->esc_like( 'surerank_seo_checks' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tm.term_id)
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tm.meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$terms_like
			)
		);

		return absint( $post_count ) + absint( $term_count );
	}

	/**
	 * Get count of posts that have been optimized with SureRank within the last 180 days.
	 * Uses per-post 'surerank_post_optimized_at' timestamps for accurate counting.
	 * Uses per-term 'surerank_term_optimized_at' timestamps for accurate counting.
	 *
	 * @return int Number of optimized posts and terms within the last 180 days.
	 * @since 1.6.3
	 */
	private function get_optimized_posts_count_last_180_days() {
		global $wpdb;

		$days_180_ago      = strtotime( '-180 days' );
		$public_post_types = $this->get_public_post_types_for_query();

		if ( empty( $public_post_types ) ) {
			$post_count = 0;
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $public_post_types ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = 'surerank_post_optimized_at'
					AND CAST(pm.meta_value AS UNSIGNED) > %d
					AND p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safely handled.
					array_merge( [ $days_180_ago ], $public_post_types )
				)
			);
		}

		// Count terms optimized in the last 180 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tm.term_id)
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tm.meta_key = 'surerank_term_optimized_at'
				AND CAST(tm.meta_value AS UNSIGNED) > %d",
				$days_180_ago
			)
		);

		return absint( $post_count ) + absint( $term_count );
	}

	/**
	 * Check if site qualifies as a "Super" site based on recent optimizations.
	 *
	 * Logic:
	 * - If total posts >= 40: require 20+ optimized posts (20-post threshold).
	 * - If total posts < 40: require 50%+ of total posts optimized (percentage threshold).
	 *
	 * This ensures small sites (e.g., 10 pages) can still qualify as "super"
	 * if they have optimized a significant portion of their content.
	 *
	 * @param int $recent_optimized_count Number of recently optimized posts.
	 * @return bool True if site qualifies as super.
	 * @since 1.6.3
	 */
	private function is_super_site_recent( int $recent_optimized_count ) {
		return $this->is_super_criteria_met( $recent_optimized_count );
	}

	/**
	 * Check if site qualifies as a "Super" site based on past optimizations.
	 *
	 * Used for dormant/super_dormant classification.
	 *
	 * @param int $past_optimized_count Number of optimized posts (historical).
	 * @return bool True if site qualifies as super.
	 * @since 1.6.3
	 */
	private function is_super_site_past( int $past_optimized_count ) {
		return $this->is_super_criteria_met( $past_optimized_count );
	}

	/**
	 * Shared logic for super site criteria.
	 *
	 * @param int $optimized_count Number of optimized posts.
	 * @return bool True if super criteria is met.
	 * @since 1.6.3
	 */
	private function is_super_criteria_met( int $optimized_count ) {
		$total_posts = $this->get_total_published_posts_count();

		if ( 0 === $total_posts ) {
			return false;
		}

		// Threshold where 50% equals 20 posts (20 / 0.5 = 40).
		$threshold = 40;

		if ( $total_posts >= $threshold ) {
			// For larger sites, require minimum 20 optimized posts.
			return $optimized_count >= 20;
		}

		// For smaller sites, require 50% of total posts optimized.
		$percentage = $optimized_count / $total_posts * 100;

		return $percentage >= 50;
	}

	/**
	 * Get total count of published posts across all public post types.
	 *
	 * @return int Total published posts count.
	 * @since 1.6.3
	 */
	private function get_total_published_posts_count() {
		global $wpdb;

		$public_post_types = $this->get_public_post_types_for_query();

		if ( empty( $public_post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $public_post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safely handled.
				$public_post_types
			)
		);

		return absint( $count );
	}

	/**
	 * Get public post types for database queries, excluding attachments and revisions.
	 *
	 * @return array<string> Array of post type names.
	 * @since 1.6.3
	 */
	private function get_public_post_types_for_query() {
		$post_types = get_post_types( [ 'public' => true ], 'names' );

		// Exclude attachment and revision post types.
		$excluded = [ 'attachment', 'revision' ];

		return array_values( array_diff( $post_types, $excluded ) );
	}

	/**
	 * Get optimized posts count for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @since 1.6.3
	 * @return int Optimized posts count
	 */
	private function get_optimized_posts_count_within_date( $date ) {
		global $wpdb;

		$start_timestamp = strtotime( $date . ' 00:00:00' );
		$end_timestamp   = strtotime( $date . ' 23:59:59' );

		$public_post_types = $this->get_public_post_types_for_query();

		if ( empty( $public_post_types ) ) {
			$post_count = 0;
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $public_post_types ), '%s' ) );

			// Count posts optimized on this specific date.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_count = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic placeholders for post types handled via array_merge.
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id)
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = 'surerank_post_optimized_at'
					AND CAST(pm.meta_value AS UNSIGNED) >= %d
					AND CAST(pm.meta_value AS UNSIGNED) <= %d
					AND p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safely handled.
					array_merge( [ $start_timestamp, $end_timestamp ], $public_post_types )
				)
			);
		}

		// Count terms optimized on this specific date.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tm.term_id)
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tm.meta_key = 'surerank_term_optimized_at'
				AND CAST(tm.meta_value AS UNSIGNED) >= %d
				AND CAST(tm.meta_value AS UNSIGNED) <= %d",
				[ $start_timestamp, $end_timestamp ]
			)
		);

		return absint( $post_count ) + absint( $term_count );
	}

	/**
	 * Get KPI tracking data for the last 2 days.
	 *
	 * @since 1.6.3
	 * @return array<string, array<string, array<string, int>>> KPI data organized by date
	 */
	private function get_kpi_tracking_data() {
		$kpi_data = [];
		$today    = current_time( 'Y-m-d' );

		// Get data for yesterday and day before yesterday.
		for ( $i = 1; $i <= 2; $i++ ) {
			$date            = gmdate( 'Y-m-d', absint( strtotime( $today . ' -' . $i . ' days' ) ) );
			$optimized_count = $this->get_optimized_posts_count_within_date( $date );

			// Always include data, even if optimized_count is 0.
			$kpi_data[ $date ] = [
				'numeric_values' => [
					'optimized_posts' => $optimized_count,
				],
			];
		}

		return $kpi_data;
	}
}
