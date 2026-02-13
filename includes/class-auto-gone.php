<?php
/**
 * Auto Gone handler for Smart Redirect Manager.
 *
 * Automatically creates 410 Gone responses when published
 * posts or terms are permanently deleted.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Auto_Gone
 *
 * Monitors post and term deletions and creates automatic 410 Gone
 * redirects so that search engines and visitors receive the correct
 * HTTP status code for removed content.
 */
class SRM_Auto_Gone {

	/**
	 * Initialize hooks for automatic 410 Gone handling.
	 *
	 * Only registers hooks when the auto_410 setting is enabled.
	 *
	 * @return void
	 */
	public static function init() {
		$settings = SRM_Database::get_settings();

		if ( empty( $settings['auto_410'] ) ) {
			return;
		}

		add_action( 'before_delete_post', array( __CLASS__, 'handle_post_delete' ), 10, 1 );
		add_action( 'pre_delete_term', array( __CLASS__, 'handle_term_delete' ), 10, 2 );
	}

	/**
	 * Handle a post deletion by creating a 410 Gone redirect.
	 *
	 * Only creates the redirect if the post was published and its post
	 * type is included in the monitored post types setting.
	 *
	 * @param int $post_id The ID of the post being deleted.
	 * @return void
	 */
	public static function handle_post_delete( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Check if the post type is monitored.
		$settings           = SRM_Database::get_settings();
		$monitor_post_types = isset( $settings['monitor_post_types'] )
			? (array) $settings['monitor_post_types']
			: array();

		if ( ! in_array( $post->post_type, $monitor_post_types, true ) ) {
			return;
		}

		// Get the permalink before the post is deleted.
		$permalink  = get_permalink( $post_id );
		$source_url = SRM_Database::normalize_url( $permalink );

		// Skip if a redirect already exists for this source URL.
		if ( self::redirect_exists( $source_url ) ) {
			return;
		}

		// Create the 410 Gone redirect.
		SRM_Database::save_redirect( array(
			'source_url'  => $source_url,
			'target_url'  => '',
			'status_code' => 410,
			'source_type' => 'auto_410',
			'post_id'     => $post_id,
			'is_active'   => 1,
		) );
	}

	/**
	 * Handle a term deletion by creating a 410 Gone redirect.
	 *
	 * Creates the redirect so the old term archive URL returns a 410
	 * status code instead of a 404.
	 *
	 * @param int    $term_id  The ID of the term being deleted.
	 * @param string $taxonomy The taxonomy the term belongs to.
	 * @return void
	 */
	public static function handle_term_delete( $term_id, $taxonomy ) {
		// Get the term link before the term is deleted.
		$term_link = get_term_link( (int) $term_id, $taxonomy );

		// get_term_link may return WP_Error.
		if ( is_wp_error( $term_link ) ) {
			return;
		}

		$source_url = SRM_Database::normalize_url( $term_link );

		// Skip if a redirect already exists for this source URL.
		if ( self::redirect_exists( $source_url ) ) {
			return;
		}

		// Create the 410 Gone redirect.
		SRM_Database::save_redirect( array(
			'source_url'  => $source_url,
			'target_url'  => '',
			'status_code' => 410,
			'source_type' => 'auto_410',
			'is_active'   => 1,
		) );
	}

	/**
	 * Check if a redirect already exists for the given source URL.
	 *
	 * @param string $source_url The normalized source URL to check.
	 * @return bool True if a redirect already exists, false otherwise.
	 */
	private static function redirect_exists( $source_url ) {
		global $wpdb;

		$table = SRM_Database::get_table_name( 'redirects' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_url = %s LIMIT 1",
				$source_url
			)
		);

		return ! empty( $existing );
	}
}
