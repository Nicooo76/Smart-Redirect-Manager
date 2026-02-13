<?php
/**
 * Auto Cleanup handler for Smart Redirect Manager.
 *
 * Automatically cleans up old, unused redirects based on
 * configurable age and idle-time thresholds.
 *
 * @package SmartRedirectManager
 * @author  Sven Gauditz
 * @link    https://gauditz.com
 * @license MIT
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Auto_Cleanup
 *
 * Handles scheduled cleanup of stale redirects by deactivating,
 * deleting, or converting them to 410 Gone responses.
 */
class SRM_Auto_Cleanup {

	/**
	 * Initialize hooks for automatic cleanup.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'srm_auto_cleanup_redirects', array( __CLASS__, 'run_cleanup' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_cleanup_notice' ) );
	}

	/**
	 * Run the automatic cleanup of old redirects.
	 *
	 * Evaluates all active redirects against the configured thresholds
	 * and applies the chosen cleanup action (deactivate, delete, or
	 * convert to 410).
	 *
	 * @return void
	 */
	public static function run_cleanup() {
		$settings = SRM_Database::get_settings();

		if ( empty( $settings['auto_cleanup_enabled'] ) ) {
			return;
		}

		$days          = absint( $settings['auto_cleanup_days'] );
		$idle_days     = absint( $settings['auto_cleanup_min_days_idle'] );
		$action        = sanitize_text_field( $settings['auto_cleanup_action'] );
		$exclude_types = isset( $settings['auto_cleanup_exclude_types'] )
			? (array) $settings['auto_cleanup_exclude_types']
			: array();

		if ( $days < 1 ) {
			$days = 365;
		}
		if ( $idle_days < 1 ) {
			$idle_days = 90;
		}

		global $wpdb;

		$table = SRM_Database::get_table_name( 'redirects' );

		// -- Build WHERE clause ------------------------------------------------
		$where_parts = array(
			'is_active = 1',
			$wpdb->prepare( 'created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $days ),
			$wpdb->prepare(
				'(last_hit IS NULL OR last_hit < DATE_SUB(NOW(), INTERVAL %d DAY))',
				$idle_days
			),
			'expires_at IS NULL',
		);

		// Exclude specific source types.
		if ( ! empty( $exclude_types ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where_parts[] = $wpdb->prepare(
				"source_type NOT IN ({$placeholders})",
				$exclude_types
			);
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );

		// -- Execute cleanup action --------------------------------------------
		$count    = 0;
		$date_now = current_time( 'Y-m-d H:i:s' );

		if ( 'deactivate' === $action ) {

			// Get matching IDs and URLs for the result transient.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$candidates = $wpdb->get_results(
				"SELECT id, source_url, notes FROM {$table} {$where_sql} LIMIT 100"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} {$where_sql}"
			);

			if ( $count > 0 ) {
				$note_text = "\nAuto-Cleanup: Deaktiviert am " . $date_now;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET is_active = 0, notes = CONCAT(COALESCE(notes, ''), %s) {$where_sql}",
						$note_text
					)
				);
			}

		} elseif ( 'delete' === $action ) {

			// Get matching IDs and URLs for logging before deletion.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$candidates = $wpdb->get_results(
				"SELECT id, source_url FROM {$table} {$where_sql} LIMIT 100"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} {$where_sql}"
			);

			if ( $count > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$table} {$where_sql}" );
			}

		} elseif ( 'convert_410' === $action ) {

			// Get matching IDs and URLs for the result transient.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$candidates = $wpdb->get_results(
				"SELECT id, source_url, notes FROM {$table} {$where_sql} LIMIT 100"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} {$where_sql}"
			);

			if ( $count > 0 ) {
				$note_text = "\nAuto-Cleanup: Konvertiert zu 410 am " . $date_now;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status_code = 410, target_url = '', source_type = 'auto_410', notes = CONCAT(COALESCE(notes, ''), %s) {$where_sql}",
						$note_text
					)
				);
			}

		} else {
			return;
		}

		// -- Store result transient --------------------------------------------
		$items = array();
		if ( ! empty( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				$items[] = array(
					'id'         => (int) $candidate->id,
					'source_url' => $candidate->source_url,
				);
			}
		}

		set_transient( 'srm_last_cleanup_result', array(
			'count'  => $count,
			'action' => $action,
			'date'   => $date_now,
			'items'  => $items,
		), DAY_IN_SECONDS );

		// Invalidate the redirect cache.
		SRM_Database::invalidate_cache();

		// Send notification if enabled and count exceeds threshold.
		if ( $count > 10 ) {
			$settings = SRM_Database::get_settings();

			if ( ! empty( $settings['notifications_enabled'] ) && class_exists( 'SRM_Notifications' ) ) {
				SRM_Notifications::send( array(
					'type'    => 'auto_cleanup',
					'count'   => $count,
					'action'  => $action,
					'date'    => $date_now,
				) );
			}
		}
	}

	/**
	 * Show an admin notice after a cleanup run.
	 *
	 * Displays a one-time info notice with the number of affected
	 * redirects and the action that was taken.
	 *
	 * @return void
	 */
	public static function show_cleanup_notice() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = get_transient( 'srm_last_cleanup_result' );

		if ( empty( $result ) ) {
			return;
		}

		// Delete the transient so the notice is only shown once.
		delete_transient( 'srm_last_cleanup_result' );

		$count  = isset( $result['count'] ) ? (int) $result['count'] : 0;
		$action = isset( $result['action'] ) ? sanitize_text_field( $result['action'] ) : '';
		$date   = isset( $result['date'] ) ? esc_html( $result['date'] ) : '';

		if ( $count < 1 ) {
			return;
		}

		$action_labels = array(
			'deactivate'  => __( 'deaktiviert', 'smart-redirect-manager' ),
			'delete'      => __( 'geloescht', 'smart-redirect-manager' ),
			'convert_410' => __( 'zu 410 Gone konvertiert', 'smart-redirect-manager' ),
		);

		$action_label = isset( $action_labels[ $action ] ) ? $action_labels[ $action ] : $action;

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: number of redirects, 2: action taken, 3: date */
				esc_html__( 'Smart Redirect Manager: Auto-Cleanup hat %1$d Weiterleitungen %2$s (%3$s).', 'smart-redirect-manager' ),
				$count,
				esc_html( $action_label ),
				$date
			)
		);
	}

	/**
	 * Get cleanup candidates for a dry-run or preview.
	 *
	 * Returns the redirects that would be affected by the next cleanup
	 * run, without actually modifying any data.
	 *
	 * @param int $limit Maximum number of candidates to return. Default 100.
	 * @return array Array of redirect objects matching the cleanup criteria.
	 */
	public static function get_cleanup_candidates( $limit = 100 ) {
		$settings = SRM_Database::get_settings();

		$days          = absint( $settings['auto_cleanup_days'] );
		$idle_days     = absint( $settings['auto_cleanup_min_days_idle'] );
		$exclude_types = isset( $settings['auto_cleanup_exclude_types'] )
			? (array) $settings['auto_cleanup_exclude_types']
			: array();

		if ( $days < 1 ) {
			$days = 365;
		}
		if ( $idle_days < 1 ) {
			$idle_days = 90;
		}

		global $wpdb;

		$table = SRM_Database::get_table_name( 'redirects' );

		// -- Build WHERE clause ------------------------------------------------
		$where_parts = array(
			'is_active = 1',
			$wpdb->prepare( 'created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $days ),
			$wpdb->prepare(
				'(last_hit IS NULL OR last_hit < DATE_SUB(NOW(), INTERVAL %d DAY))',
				$idle_days
			),
			'expires_at IS NULL',
		);

		if ( ! empty( $exclude_types ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where_parts[] = $wpdb->prepare(
				"source_type NOT IN ({$placeholders})",
				$exclude_types
			);
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
		$limit     = absint( $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY created_at ASC LIMIT %d",
				$limit
			)
		);

		return $results ? $results : array();
	}
}
