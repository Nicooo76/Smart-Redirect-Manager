<?php
/**
 * Statistics
 *
 * Hit tracking and dashboard data for redirects.
 *
 * @package SmartRedirectManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Statistics
 *
 * Tracks redirect hits and provides aggregated statistics
 * for the dashboard and per-redirect views.
 */
class SRM_Statistics {

	/**
	 * Initialize.
	 *
	 * Nothing needed here; methods are called directly by other classes.
	 *
	 * @return void
	 */
	public static function init() {
		// Intentionally empty. Methods are invoked by other classes.
	}

	/**
	 * Track a single hit for a redirect.
	 *
	 * Increments the lifetime hit counter on the redirects table and
	 * inserts or updates the per-day counter in the redirect_hits table.
	 *
	 * @param int $redirect_id The redirect ID to track.
	 * @return void
	 */
	public static function track_hit( $redirect_id ) {

		$settings   = get_option( 'srm_settings', array() );
		$track_hits = isset( $settings['track_hits'] ) ? (bool) $settings['track_hits'] : false;

		if ( ! $track_hits ) {
			return;
		}

		global $wpdb;

		$redirect_id    = absint( $redirect_id );
		$redirects_table = SRM_Database::get_table_name( 'redirects' );
		$hits_table      = SRM_Database::get_table_name( 'redirect_hits' );

		// Update lifetime hit count and last-hit timestamp on the redirect.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$redirects_table} SET hit_count = hit_count + 1, last_hit = NOW() WHERE id = %d",
				$redirect_id
			)
		);

		// Insert or update the daily hit record.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$hits_table} ( redirect_id, hit_date, hit_count )
				VALUES ( %d, CURDATE(), 1 )
				ON DUPLICATE KEY UPDATE hit_count = hit_count + 1",
				$redirect_id
			)
		);
	}

	/**
	 * Get an overview of redirect statistics for the dashboard.
	 *
	 * @return array {
	 *     @type int   $total_redirects  Total number of redirects.
	 *     @type int   $active_redirects Number of active redirects.
	 *     @type int   $hits_today       Total hits recorded today.
	 *     @type int   $hits_7_days      Total hits in the last 7 days.
	 *     @type int   $open_404         Number of unresolved 404 entries.
	 *     @type array $top_redirects    Top 5 redirects by hit_count.
	 *     @type array $top_404          Top 5 unresolved 404 URLs by count.
	 * }
	 */
	public static function get_overview() {

		global $wpdb;

		$redirects_table = SRM_Database::get_table_name( 'redirects' );
		$hits_table      = SRM_Database::get_table_name( 'redirect_hits' );
		$log_table       = SRM_Database::get_table_name( '404_log' );

		// Total redirects.
		$total_redirects = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$redirects_table}"
		);

		// Active redirects.
		$active_redirects = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$redirects_table} WHERE is_active = 1"
		);

		// Hits today.
		$hits_today = (int) $wpdb->get_var(
			"SELECT COALESCE( SUM( hit_count ), 0 ) FROM {$hits_table} WHERE hit_date = CURDATE()"
		);

		// Hits in the last 7 days.
		$hits_7_days = (int) $wpdb->get_var(
			"SELECT COALESCE( SUM( hit_count ), 0 ) FROM {$hits_table} WHERE hit_date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )"
		);

		// Open (unresolved) 404 entries.
		$open_404 = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$log_table} WHERE is_resolved = 0"
		);

		// Top 5 redirects by hit count.
		$top_redirects = $wpdb->get_results(
			"SELECT id, source_url, target_url, hit_count
			FROM {$redirects_table}
			ORDER BY hit_count DESC
			LIMIT 5"
		);

		// Top 5 unresolved 404 URLs by count.
		$top_404 = $wpdb->get_results(
			"SELECT id, url, count
			FROM {$log_table}
			WHERE is_resolved = 0
			ORDER BY count DESC
			LIMIT 5"
		);

		return array(
			'total_redirects'  => $total_redirects,
			'active_redirects' => $active_redirects,
			'hits_today'       => $hits_today,
			'hits_7_days'      => $hits_7_days,
			'open_404'         => $open_404,
			'top_redirects'    => $top_redirects,
			'top_404'          => $top_404,
		);
	}

	/**
	 * Get daily hit totals for a given number of days.
	 *
	 * @param int $days Number of days to look back. Default 30.
	 * @return array List of objects with hit_date and hits properties.
	 */
	public static function get_daily_hits( $days = 30 ) {

		global $wpdb;

		$hits_table = SRM_Database::get_table_name( 'redirect_hits' );
		$days       = absint( $days );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hit_date, SUM( hit_count ) AS hits
				FROM {$hits_table}
				WHERE hit_date >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
				GROUP BY hit_date
				ORDER BY hit_date ASC",
				$days
			)
		);
	}

	/**
	 * Get daily hits for a specific redirect.
	 *
	 * @param int $redirect_id The redirect ID.
	 * @param int $days        Number of days to look back. Default 30.
	 * @return array List of objects with hit_date and hit_count properties.
	 */
	public static function get_redirect_hits( $redirect_id, $days = 30 ) {

		global $wpdb;

		$hits_table  = SRM_Database::get_table_name( 'redirect_hits' );
		$redirect_id = absint( $redirect_id );
		$days        = absint( $days );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hit_date, hit_count
				FROM {$hits_table}
				WHERE redirect_id = %d
				AND hit_date >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
				ORDER BY hit_date ASC",
				$redirect_id,
				$days
			)
		);
	}
}
