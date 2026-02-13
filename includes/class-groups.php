<?php
/**
 * Groups
 *
 * Manages redirect groups/tags for organizing redirects.
 *
 * @package SmartRedirectManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Groups
 *
 * Handles CRUD operations for redirect groups and redirect-count maintenance.
 */
class SRM_Groups {

	/**
	 * Retrieve all groups ordered by name.
	 *
	 * @return array Array of group objects.
	 */
	public static function get_groups() {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY name ASC"
		);
	}

	/**
	 * Retrieve a single group by its ID.
	 *
	 * @param int $id Group ID.
	 * @return object|null Group row or null when not found.
	 */
	public static function get_group( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Insert or update a group.
	 *
	 * When $data contains an `id` key the existing row is updated;
	 * otherwise a new row is inserted.
	 *
	 * @param array $data {
	 *     Group fields.
	 *
	 *     @type int    $id          Optional. Group ID for updates.
	 *     @type string $name        Group name.
	 *     @type string $description Optional. Group description.
	 *     @type string $color       Optional. Hex colour value (e.g. #ff0000).
	 * }
	 * @return int|false Group ID on success, false on failure.
	 */
	public static function save_group( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_groups';

		$name        = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug        = sanitize_title( $name );
		$description = isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '';
		$color       = isset( $data['color'] ) ? sanitize_hex_color( $data['color'] ) : '';

		if ( '' === $name ) {
			return false;
		}

		// Update existing group.
		if ( ! empty( $data['id'] ) ) {
			$id = absint( $data['id'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update(
				$table,
				array(
					'name'        => $name,
					'slug'        => $slug,
					'description' => $description,
					'color'       => $color,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return false !== $result ? $id : false;
		}

		// Insert new group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'color'       => $color,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Delete a group and disassociate any redirects that belong to it.
	 *
	 * Sets `group_id = NULL` on every redirect that referenced the
	 * deleted group so that those redirects are not orphaned.
	 *
	 * @param int $id Group ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_group( $id ) {
		global $wpdb;

		$id              = absint( $id );
		$groups_table    = $wpdb->prefix . 'srm_groups';
		$redirects_table = $wpdb->prefix . 'srm_redirects';

		// Disassociate redirects that reference this group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$redirects_table} SET group_id = NULL WHERE group_id = %d",
				$id
			)
		);

		// Delete the group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$groups_table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Recount the number of redirects for a single group.
	 *
	 * @param int $group_id Group ID.
	 * @return void
	 */
	public static function recount( $group_id ) {
		global $wpdb;

		$group_id        = absint( $group_id );
		$groups_table    = $wpdb->prefix . 'srm_groups';
		$redirects_table = $wpdb->prefix . 'srm_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$groups_table}
				SET redirect_count = (
					SELECT COUNT(*) FROM {$redirects_table} WHERE group_id = %d
				)
				WHERE id = %d",
				$group_id,
				$group_id
			)
		);
	}

	/**
	 * Recount redirect totals for every group.
	 *
	 * @return void
	 */
	public static function recount_all() {
		$groups = self::get_groups();

		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			self::recount( $group->id );
		}
	}
}
