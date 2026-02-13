<?php
/**
 * 404 Logger
 *
 * Logs 404 errors for analysis, with optional grouping,
 * IP anonymization, and automatic cleanup.
 *
 * @package SmartRedirectManager
 * @author  Sven Gauditz
 * @link    https://gauditz.com
 * @license MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_404_Logger
 *
 * Handles logging, retrieval, and maintenance of 404 error records.
 */
class SRM_404_Logger {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 999 );
		add_action( 'srm_cleanup_logs', array( __CLASS__, 'cleanup_old_logs' ) );
	}

	/**
	 * Log a 404 error when the current request results in a 404 response.
	 *
	 * Respects the log_404 setting, excluded paths, and grouping configuration.
	 * IP addresses are anonymized before storage.
	 *
	 * @return void
	 */
	public static function log_404() {

		// Only log front-end 404 responses.
		if ( ! is_404() || is_admin() ) {
			return;
		}

		// Check if 404 logging is enabled.
		$settings = get_option( 'srm_settings', array() );
		$log_404  = isset( $settings['log_404'] ) ? (bool) $settings['log_404'] : false;

		if ( ! $log_404 ) {
			return;
		}

		// ------------------------------------------------------------------
		// 1. Normalize the request URL.
		// ------------------------------------------------------------------
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$request_url = SRM_Database::normalize_url( $request_uri );

		// ------------------------------------------------------------------
		// 2. Check excluded paths.
		// ------------------------------------------------------------------
		$excluded_paths = isset( $settings['excluded_paths'] ) ? (array) $settings['excluded_paths'] : array();

		foreach ( $excluded_paths as $excluded_path ) {
			$excluded_path = trim( $excluded_path );
			if ( '' === $excluded_path ) {
				continue;
			}
			if ( 0 === strpos( $request_url, $excluded_path ) ) {
				return;
			}
		}

		// ------------------------------------------------------------------
		// 3. Collect and sanitize request metadata.
		// ------------------------------------------------------------------
		$raw_ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_address = self::anonymize_ip( $raw_ip );
		$referer    = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// ------------------------------------------------------------------
		// 4. Insert or update the 404 log entry.
		// ------------------------------------------------------------------
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';
		$group_404  = isset( $settings['group_404'] ) ? (bool) $settings['group_404'] : false;

		if ( $group_404 ) {

			// Check if an entry for this URL already exists.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE request_url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$request_url
				)
			);

			if ( $existing_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table_name} SET count = count + 1, last_occurred = NOW(), ip_address = %s, referer = %s, user_agent = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$ip_address,
						$referer,
						$user_agent,
						$existing_id
					)
				);
				return;
			}
		}

		// Insert a new log entry.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (request_url, ip_address, referer, user_agent, count, created_at, last_occurred) VALUES (%s, %s, %s, %s, 1, NOW(), NOW())", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$request_url,
				$ip_address,
				$referer,
				$user_agent
			)
		);
	}

	/**
	 * Delete log entries older than the configured retention period.
	 *
	 * Hooked to the srm_cleanup_logs cron event.
	 *
	 * @return void
	 */
	public static function cleanup_old_logs() {
		$settings           = get_option( 'srm_settings', array() );
		$log_retention_days = isset( $settings['log_retention_days'] ) ? absint( $settings['log_retention_days'] ) : 30;

		if ( 0 === $log_retention_days ) {
			return;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$log_retention_days
			)
		);
	}

	/**
	 * Retrieve paginated 404 log entries with optional filtering.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $page     Current page number. Default 1.
	 *     @type int    $per_page Number of items per page. Default 25.
	 *     @type string $search   Search term to match against request_url and referer.
	 *     @type string $status   Filter by resolution status: 'all', 'resolved', or 'unresolved'. Default 'all'.
	 *     @type string $orderby  Column to order by. Default 'last_occurred'.
	 *     @type string $order    Sort direction: 'ASC' or 'DESC'. Default 'DESC'.
	 * }
	 * @return array {
	 *     @type array $items Array of log entry objects.
	 *     @type int   $total Total number of matching entries.
	 * }
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		$defaults = array(
			'page'     => 1,
			'per_page' => 25,
			'search'   => '',
			'status'   => 'all',
			'orderby'  => 'last_occurred',
			'order'    => 'DESC',
		);

		$args     = wp_parse_args( $args, $defaults );
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Whitelist allowed columns for ORDER BY to prevent SQL injection.
		$allowed_orderby = array(
			'id',
			'request_url',
			'ip_address',
			'referer',
			'count',
			'created_at',
			'last_occurred',
			'is_resolved',
		);

		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'last_occurred';

		// ------------------------------------------------------------------
		// Build WHERE clauses.
		// ------------------------------------------------------------------
		$where  = 'WHERE 1=1';
		$values = array();

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$where  .= ' AND (request_url LIKE %s OR referer LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		// Status filter.
		if ( 'resolved' === $args['status'] ) {
			$where .= ' AND is_resolved = 1';
		} elseif ( 'unresolved' === $args['status'] ) {
			$where .= ' AND is_resolved = 0';
		}

		// ------------------------------------------------------------------
		// Count total matching records.
		// ------------------------------------------------------------------
		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$values
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table_name} {$where}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		// ------------------------------------------------------------------
		// Fetch paginated results.
		// ------------------------------------------------------------------
		$values[] = $per_page;
		$values[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$values
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Mark a 404 log entry as resolved, optionally linking it to a redirect.
	 *
	 * @param int      $id          The 404 log entry ID.
	 * @param int|null $redirect_id Optional redirect ID that resolves this 404.
	 * @return bool|int False on failure, or the number of rows updated.
	 */
	public static function resolve_404( $id, $redirect_id = null ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET is_resolved = 1, redirect_id = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$redirect_id,
				$id
			)
		);
	}

	/**
	 * Delete a 404 log entry.
	 *
	 * @param int $id The 404 log entry ID.
	 * @return bool|int False on failure, or the number of rows deleted.
	 */
	public static function delete_404( $id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
	}

	/**
	 * Get the number of 404 errors logged in the last hour.
	 *
	 * @return int Number of 404 entries in the last 60 minutes.
	 */
	public static function get_404_count_last_hour() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name} WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Anonymize an IP address for privacy compliance.
	 *
	 * For IPv4 addresses the last octet is replaced with 0
	 * (e.g. 192.168.1.123 becomes 192.168.1.0).
	 * For IPv6 addresses the last 80 bits are zeroed out.
	 *
	 * @param string $ip The raw IP address.
	 * @return string The anonymized IP address, or an empty string on failure.
	 */
	public static function anonymize_ip( $ip ) {

		if ( empty( $ip ) ) {
			return '';
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $packed ) {
			return '';
		}

		$length = strlen( $packed );

		if ( 4 === $length ) {
			// IPv4: set the last octet (byte 4) to 0.
			$packed[3] = "\x00";
		} elseif ( 16 === $length ) {
			// IPv6: zero the last 80 bits (bytes 6-15, i.e. 10 bytes).
			for ( $i = 6; $i < 16; $i++ ) {
				$packed[ $i ] = "\x00";
			}
		} else {
			return '';
		}

		$anonymized = inet_ntop( $packed );

		return false !== $anonymized ? $anonymized : '';
	}
}
