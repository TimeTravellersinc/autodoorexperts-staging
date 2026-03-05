<?php
/**
 * HFE Analytics.
 *
 * @package HFE
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * HFE Analytics.
 *
 * HFE Analytics. handler class is responsible for rolling back HFE to
 * previous version.
 *
 * @since 2.3.0
 */
if ( ! class_exists( 'HFE_Analytics' ) ) {

	class HFE_Analytics {

		/**
		 * HFE Analytics constructor.
		 *
		 * Initializing HFE Analytics.
		 *
		 * @since 2.3.0
		 * @access public
		 *
		 * @param array $args Optional. HFE Analytics arguments. Default is an empty array.
		 */
		public function __construct() {
			add_action( 'admin_init', [ $this, 'maybe_migrate_analytics_tracking' ] );
			// BSF Analytics Tracker.
			if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
				require_once HFE_DIR . 'admin/bsf-analytics/class-bsf-analytics-loader.php';
			}

			$bsf_analytics = BSF_Analytics_Loader::get_instance();

			$bsf_analytics->set_entity(
				[
					'uae' => [
						'product_name'        => 'Ultimate Addons for Elementor',
						'path'                => HFE_DIR . 'admin/bsf-analytics',
						'author'              => 'Ultimate Addons for Elementor',
						'time_to_display'     => '+24 hours',
						'deactivation_survey' => [
							[
								'id'                => 'deactivation-survey-header-footer-elementor', // 'deactivation-survey-<your-plugin-slug>'
								'popup_logo'        => HFE_URL . 'assets/images/settings/logo.svg',
								'plugin_slug'       => 'header-footer-elementor', // <your-plugin-slug>
								'plugin_version'    => HFE_VER,
								'popup_title'       => 'Quick Feedback',
								'support_url'       => 'https://ultimateelementor.com/contact/',
								'popup_description' => 'If you have a moment, please share why you are deactivating Ultimate Addons for Elementor:',
								'show_on_screens'   => [ 'plugins' ],
							],
						],
						'hide_optin_checkbox' => true,
					],
				]
			);
			
			add_filter( 'bsf_core_stats', [ $this, 'add_uae_analytics_data' ] );
		}

		/**
		 * Migrates analytics tracking option from 'bsf_usage_optin' to 'uae_usage_optin'.
		 *
		 * Checks if the old analytics tracking option ('bsf_usage_optin') is set to 'yes'
		 * and if the new option ('uae_usage_optin') is not already set.
		 * If so, updates the new tracking option to 'yes' to maintain user consent during migration.
		 *
		 * @since 2.3.2
		 * @access public
		 *
		 * @return void
		 */
		public function maybe_migrate_analytics_tracking() {
			$old_tracking = get_option( 'bsf_usage_optin', false );
			$new_tracking = get_option( 'uae_usage_optin', false );
			if ( 'yes' === $old_tracking && false === $new_tracking ) {
				update_option( 'uae_usage_optin', 'yes' );
				$time = get_option( 'bsf_usage_installed_time' );
				update_option( 'bsf_usage_installed_time', $time );
			}
		}
        
        /**
         * Callback function to add specific analytics data.
         *
         * @param array $stats_data existing stats_data.
         * @since 2.3.0
         * @return array
         */
        public function add_uae_analytics_data( $stats_data ) {
			 // Check if $stats_data is empty or not an array.
			 if ( empty( $stats_data ) || ! is_array( $stats_data ) ) {
				$stats_data = []; // Initialize as an empty array.
			}
		
            $stats_data['plugin_data']['uae']		= [
                'free_version'  => HFE_VER,
                'pro_version' => ( defined( 'UAEL_VERSION' ) ? UAEL_VERSION : '' ),
                'site_language' => get_locale(),
                'elementor_version' => ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ),
                'elementor_pro_version' => ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '' ),
                'onboarding_triggered' => ( 'yes' === get_option( 'hfe_onboarding_triggered' ) ) ? 'yes' : 'no',
				'uaelite_subscription' => ( 'done' === get_option( 'uaelite_subscription' ) ) ? 'yes' : 'no'
            ];

            $hfe_posts = get_posts( 
				[
					'post_type'   => 'elementor-hf',
					'post_status' => 'publish',
					'numberposts' => -1,
        	    ] 
			);

            $stats_data['plugin_data']['uae']['numeric_values'] = [
                'total_hfe_templates' => count( $hfe_posts ),
            ];

			$fetch_elementor_data = $this->hfe_get_widgets_usage();
			foreach ( $fetch_elementor_data as $key => $value ) {
				$stats_data['plugin_data']['uae']['numeric_values'][$key] = $value;
			}

			$learn_progress = $this->get_learn_progress_analytics_data();
			if ( ! empty( $learn_progress ) ) {
				$stats_data['plugin_data']['uae']['learn_chapters_completed'] = $learn_progress;
			}

			// Add KPI tracking data.
			$kpi_data = $this->get_kpi_tracking_data();
			if ( ! empty( $kpi_data ) ) {
				$stats_data['plugin_data']['uae']['kpi_records'] = $kpi_data;
			}

            return $stats_data;
        }

		/**
		 * Fetch Elementor data.
		 */
		private function hfe_get_widgets_usage() {
				$get_Widgets = get_option( 'uae_widgets_usage_data_option', [] );
				return $get_Widgets;
		}

		/**
		 * Get UAE learn progress analytics data.
		 *
		 * @return array
		 */
		private function get_learn_progress_analytics_data() {
			global $wpdb;

			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
					'hfe_learn_progress'
				),
				ARRAY_A
			);

			if ( empty( $results ) ) {
				return [];
			}

			if ( ! class_exists( '\HFE\API\HFE_Learn_API' ) ) {
				return [];
			}

			$chapters = \HFE\API\HFE_Learn_API::get_chapters_structure();

			$completed_chapters = [];

			foreach ( $results as $row ) {
				$progress_data = maybe_unserialize( $row['meta_value'] );

				if ( ! is_array( $progress_data ) ) {
					continue;
				}

				foreach ( $chapters as $chapter ) {

					$chapter_id = $chapter['id'];

					// Skip already counted.
					if ( in_array( $chapter_id, $completed_chapters, true ) ) {
						continue;
					}

					// Skip invalid chapters.
					if ( empty( $chapter['steps'] ) || ! is_array( $chapter['steps'] ) ) {
						continue;
					}

					// Skip if not present in user data.
					if ( empty( $progress_data[ $chapter_id ] ) || ! is_array( $progress_data[ $chapter_id ] ) ) {
						continue;
					}

					$all_steps_completed = true;

					foreach ( $chapter['steps'] as $step ) {
						$step_id = $step['id'];

						if (
							! isset( $progress_data[ $chapter_id ][ $step_id ] ) ||
							! $progress_data[ $chapter_id ][ $step_id ]
						) {
							$all_steps_completed = false;
							break;
						}
					}

					if ( $all_steps_completed ) {
						$completed_chapters[] = $chapter_id;
					}
				}
			}

			return array_values( array_unique( $completed_chapters ) );
		}

		/**
		 * Get KPI tracking data for the last 2 days (excluding today).
		 *
		 * Uses stored snapshots for state-based metrics (total_templates,
		 * widgets_count, total_widget_instances) and computes modified_templates
		 * fresh for each completed past day to ensure accurate counts.
		 *
		 * @since 2.8.4
		 * @return array KPI data organized by date.
		 */
		private function get_kpi_tracking_data() {
			$snapshots = get_option( 'hfe_kpi_daily_snapshots', [] );

			if ( empty( $snapshots ) || ! is_array( $snapshots ) ) {
				return [];
			}

			$kpi_data = [];
			$today    = current_time( 'Y-m-d' );

			// Only send data for dates that have actual per-day snapshots.
			for ( $i = 1; $i <= 2; $i++ ) {
				$date = gmdate( 'Y-m-d', strtotime( $today . ' -' . $i . ' days' ) );

				if ( ! isset( $snapshots[ $date ]['numeric_values'] ) ) {
					continue;
				}

				$kpi_data[ $date ] = [
					'numeric_values' => array_merge(
						$snapshots[ $date ]['numeric_values'],
						[ 'modified_templates' => $this->get_modified_template_count( $date ) ]
					),
				];
			}

			return $kpi_data;
		}

		/**
		 * Get count of HFE templates modified on a given date.
		 *
		 * @since 2.8.4
		 * @param string $date Date in Y-m-d format.
		 * @return int Modified template count for the date.
		 */
		private function get_modified_template_count( $date ) {
			$posts = get_posts(
				[
					'post_type'   => 'elementor-hf',
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
					'date_query'  => [
						[
							'column'    => 'post_modified',
							'after'     => $date . ' 00:00:00',
							'before'    => $date . ' 23:59:59',
							'inclusive' => true,
						],
					],
				]
			);

			return count( $posts );
		}
	}
}
new HFE_Analytics();
