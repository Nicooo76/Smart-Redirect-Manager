<?php
/**
 * Migration from the Redirection plugin.
 *
 * Detects an existing Redirection installation, previews migratable
 * data, and imports redirects, 404 logs, and hit statistics into
 * Smart Redirect Manager tables.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Migration
 *
 * Handles detection, preview, and migration of data from the
 * Redirection plugin (redirection/redirection.php).
 */
class SRM_Migration {

	// ---------------------------------------------------------------------
	// Detection Helpers
	// ---------------------------------------------------------------------

	/**
	 * Check whether the Redirection plugin database tables exist.
	 *
	 * Looks for the `redirection_items` table which is the primary
	 * table created by the Redirection plugin.
	 *
	 * @return bool True if the table exists, false otherwise.
	 */
	public static function is_redirection_installed() {
		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return ( null !== $result );
	}

	/**
	 * Check whether the Redirection plugin is currently active.
	 *
	 * @return bool True if the plugin is active, false otherwise.
	 */
	public static function is_redirection_active() {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'redirection/redirection.php' );
	}

	// ---------------------------------------------------------------------
	// Preview
	// ---------------------------------------------------------------------

	/**
	 * Gather summary counts and a small sample of data available for
	 * migration from the Redirection plugin.
	 *
	 * @return array {
	 *     @type int   $redirects   Total number of redirect items.
	 *     @type int   $groups      Total number of redirect groups.
	 *     @type int   $404_logs    Total number of 404 log entries.
	 *     @type int   $hit_logs    Total number of hit-tracking log entries.
	 *     @type array $preview     Last 5 redirect items for preview.
	 * }
	 */
	public static function get_preview() {
		global $wpdb;

		$items_table  = $wpdb->prefix . 'redirection_items';
		$groups_table = $wpdb->prefix . 'redirection_groups';
		$logs_table   = $wpdb->prefix . 'redirection_logs';
		$four04_table = $wpdb->prefix . 'redirection_404';

		// Count redirects.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$redirect_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$items_table}"
		);

		// Count groups.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$group_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$groups_table}"
		);

		// Count 404 logs (module_id = 1 in the Redirection plugin).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$four04_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$four04_table}"
		);

		// Count hit / redirect logs (module_id != 1).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hit_log_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$logs_table}"
		);

		// Last 5 redirect items for preview.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$preview = $wpdb->get_results(
			"SELECT url, action_data, action_code, regex FROM {$items_table} ORDER BY id DESC LIMIT 5"
		);

		return array(
			'redirects' => $redirect_count,
			'groups'    => $group_count,
			'404_logs'  => $four04_count,
			'hit_logs'  => $hit_log_count,
			'preview'   => $preview ? $preview : array(),
		);
	}

	// ---------------------------------------------------------------------
	// Migration
	// ---------------------------------------------------------------------

	/**
	 * Migrate data from the Redirection plugin into Smart Redirect Manager.
	 *
	 * @param array $options {
	 *     Optional. Controls which data sets are migrated.
	 *
	 *     @type bool $redirects         Import redirect rules. Default true.
	 *     @type bool $404_logs          Import grouped 404 log entries. Default true.
	 *     @type bool $hit_stats         Import per-day hit statistics. Default true.
	 *     @type bool $deactivate_plugin Deactivate the Redirection plugin after
	 *                                   migration. Default false.
	 * }
	 * @return array {
	 *     Migration result summary.
	 *
	 *     @type int   $imported Total number of successfully imported items.
	 *     @type int   $skipped  Total number of skipped items.
	 *     @type array $errors   List of error messages encountered.
	 *     @type array $details  Detailed per-item information (skipped reasons, etc.).
	 * }
	 */
	public static function migrate( $options = array() ) {
		global $wpdb;

		$defaults = array(
			'redirects'         => true,
			'404_logs'          => true,
			'hit_stats'         => true,
			'deactivate_plugin' => false,
		);

		$options = wp_parse_args( $options, $defaults );

		// Counters.
		$imported = 0;
		$skipped  = 0;
		$errors   = array();
		$details  = array();

		// Keep a map of old redirect IDs to new redirect IDs for hit-stat
		// migration.
		$id_map = array();

		// -----------------------------------------------------------------
		// 1. Redirects
		// -----------------------------------------------------------------
		if ( $options['redirects'] ) {

			$items_table  = $wpdb->prefix . 'redirection_items';
			$groups_table = $wpdb->prefix . 'redirection_groups';
			$srm_table    = SRM_Database::get_table_name( 'redirects' );

			// Pre-load group names keyed by group ID.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$raw_groups = $wpdb->get_results(
				"SELECT id, name FROM {$groups_table}",
				OBJECT_K
			);

			$group_names = array();
			if ( $raw_groups ) {
				foreach ( $raw_groups as $gid => $group ) {
					$group_names[ $gid ] = $group->name;
				}
			}

			// Fetch all redirect items.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results( "SELECT * FROM {$items_table}" );

			if ( $items ) {
				foreach ( $items as $item ) {

					// Skip unsupported action types.
					if ( 'url' !== $item->action_type ) {
						$skipped++;
						$details[] = array(
							'source_url' => $item->url,
							'status'     => 'skipped',
							'reason'     => sprintf(
								/* translators: %s: the action_type value */
								__( 'Unsupported action_type: %s', 'smart-redirect-manager' ),
								$item->action_type
							),
						);
						continue;
					}

					// Skip unsupported match types.
					if ( 'url' !== $item->match_type ) {
						$skipped++;
						$details[] = array(
							'source_url' => $item->url,
							'status'     => 'skipped',
							'reason'     => sprintf(
								/* translators: %s: the match_type value */
								__( 'Unsupported match_type: %s', 'smart-redirect-manager' ),
								$item->match_type
							),
						);
						continue;
					}

					// Duplicate check: look for an existing redirect with
					// the same source_url.
					$normalized_source = SRM_Database::normalize_url( $item->url );

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$existing = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$srm_table} WHERE source_url = %s LIMIT 1",
							$normalized_source
						)
					);

					if ( $existing ) {
						$skipped++;
						$id_map[ $item->id ] = (int) $existing;
						$details[] = array(
							'source_url' => $item->url,
							'status'     => 'skipped',
							'reason'     => __( 'Duplicate: source_url already exists', 'smart-redirect-manager' ),
						);
						continue;
					}

					// Build notes from group name.
					$notes = '';
					if ( ! empty( $item->group_id ) && isset( $group_names[ $item->group_id ] ) ) {
						$notes = sprintf(
							/* translators: %s: group name from Redirection plugin */
							__( 'Migrated from Redirection group: %s', 'smart-redirect-manager' ),
							$group_names[ $item->group_id ]
						);
					}

					// Prepare redirect data.
					$redirect_data = array(
						'source_url'  => $item->url,
						'target_url'  => $item->action_data,
						'status_code' => absint( $item->action_code ),
						'is_regex'    => ! empty( $item->regex ) ? 1 : 0,
						'is_active'   => ! empty( $item->enabled ) ? 1 : 0,
						'source_type' => 'migration',
						'notes'       => $notes,
					);

					$new_id = SRM_Database::save_redirect( $redirect_data );

					if ( $new_id ) {
						$imported++;
						$id_map[ $item->id ] = (int) $new_id;

						// Migrate the lifetime hit count from Redirection's
						// last_count field.
						if ( ! empty( $item->last_count ) ) {
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$wpdb->query(
								$wpdb->prepare(
									"UPDATE {$srm_table} SET hit_count = %d WHERE id = %d",
									absint( $item->last_count ),
									$new_id
								)
							);
						}

						$details[] = array(
							'source_url' => $item->url,
							'target_url' => $item->action_data,
							'status'     => 'imported',
							'new_id'     => $new_id,
						);
					} else {
						$errors[] = sprintf(
							/* translators: %s: source URL that failed to import */
							__( 'Failed to save redirect for: %s', 'smart-redirect-manager' ),
							$item->url
						);
						$details[] = array(
							'source_url' => $item->url,
							'status'     => 'error',
							'reason'     => __( 'Database insert failed', 'smart-redirect-manager' ),
						);
					}
				}
			}
		}

		// -----------------------------------------------------------------
		// 2. 404 Logs
		// -----------------------------------------------------------------
		if ( $options['404_logs'] ) {

			$four04_table = $wpdb->prefix . 'redirection_404';
			$srm_log      = SRM_Database::get_table_name( '404_log' );

			// Group by URL to consolidate entries.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$four04_rows = $wpdb->get_results(
				"SELECT url, COUNT(*) AS cnt, MAX(created) AS last, referrer
				FROM {$four04_table}
				GROUP BY url"
			);

			if ( $four04_rows ) {
				foreach ( $four04_rows as $row ) {
					$wpdb->insert(
						$srm_log,
						array(
							'request_url'   => SRM_Database::normalize_url( $row->url ),
							'referer'       => ! empty( $row->referrer ) ? esc_url_raw( $row->referrer ) : '',
							'user_agent'    => '',
							'ip_address'    => '',
							'count'         => absint( $row->cnt ),
							'last_occurred' => $row->last,
							'created_at'    => $row->last,
							'is_resolved'   => 0,
						),
						array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
					);

					if ( $wpdb->insert_id ) {
						$imported++;
					} else {
						$errors[] = sprintf(
							/* translators: %s: the 404 URL that failed to import */
							__( 'Failed to import 404 log entry for: %s', 'smart-redirect-manager' ),
							$row->url
						);
					}
				}
			}
		}

		// -----------------------------------------------------------------
		// 3. Hit Statistics
		// -----------------------------------------------------------------
		if ( $options['hit_stats'] ) {

			$logs_table = $wpdb->prefix . 'redirection_logs';
			$hits_table = SRM_Database::get_table_name( 'redirect_hits' );

			// Aggregate hits per redirect per day.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$hit_rows = $wpdb->get_results(
				"SELECT redirect_id, DATE(created) AS hit_date, COUNT(*) AS hits
				FROM {$logs_table}
				GROUP BY redirect_id, DATE(created)"
			);

			if ( $hit_rows ) {
				foreach ( $hit_rows as $row ) {

					// Map the old redirect ID to the new SRM redirect ID.
					if ( ! isset( $id_map[ $row->redirect_id ] ) ) {
						// No matching redirect was imported; skip this
						// hit-stat entry.
						continue;
					}

					$new_redirect_id = $id_map[ $row->redirect_id ];

					// Insert or update (ON DUPLICATE KEY) to handle
					// potential overlap with existing data.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$hits_table} ( redirect_id, hit_date, hit_count )
							VALUES ( %d, %s, %d )
							ON DUPLICATE KEY UPDATE hit_count = hit_count + VALUES(hit_count)",
							$new_redirect_id,
							$row->hit_date,
							absint( $row->hits )
						)
					);

					$imported++;
				}
			}
		}

		// -----------------------------------------------------------------
		// 4. Deactivate Redirection plugin (optional)
		// -----------------------------------------------------------------
		if ( $options['deactivate_plugin'] ) {

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins( 'redirection/redirection.php' );
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'details'  => $details,
		);
	}
}
