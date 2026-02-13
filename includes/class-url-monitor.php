<?php
/**
 * Smart Redirect Manager - URL Monitor
 *
 * Auto-detects URL changes on posts and terms and creates
 * redirects from the old URL to the new URL automatically.
 *
 * @package SmartRedirectManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_URL_Monitor
 *
 * Monitors permalink and term-link changes and creates automatic redirects
 * when a published post or term slug is updated.
 */
class SRM_URL_Monitor {

	/**
	 * In-memory storage for old post permalinks before update.
	 *
	 * @var array<int, string>
	 */
	private static $old_permalinks = array();

	/**
	 * In-memory storage for old term links before update.
	 *
	 * @var array<int, string>
	 */
	private static $old_term_links = array();

	/**
	 * Initialize hooks for URL monitoring.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'pre_post_update', array( __CLASS__, 'store_old_permalink' ), 10, 2 );
		add_action( 'post_updated', array( __CLASS__, 'check_permalink_change' ), 10, 3 );
		add_action( 'pre_edit_term', array( __CLASS__, 'store_old_term_link' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'check_term_link_change' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'show_redirect_notice' ) );
	}

	/**
	 * Store the old permalink before a post is updated.
	 *
	 * @param int   $post_id Post ID about to be updated.
	 * @param array $data    Array of unslashed post data.
	 * @return void
	 */
	public static function store_old_permalink( $post_id, $data ) {
		$settings = get_option( 'srm_settings', array() );

		// Skip if auto-redirect is disabled.
		if ( empty( $settings['auto_redirect'] ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Check if the post type is monitored.
		$monitor_post_types = isset( $settings['monitor_post_types'] ) ? (array) $settings['monitor_post_types'] : array();

		if ( ! in_array( $post->post_type, $monitor_post_types, true ) ) {
			return;
		}

		self::$old_permalinks[ $post_id ] = get_permalink( $post_id );
	}

	/**
	 * Check if the permalink changed after a post update and create a redirect.
	 *
	 * @param int     $post_id    Post ID.
	 * @param WP_Post $post_after Post object after the update.
	 * @param WP_Post $post_before Post object before the update.
	 * @return void
	 */
	public static function check_permalink_change( $post_id, $post_after, $post_before ) {
		if ( ! isset( self::$old_permalinks[ $post_id ] ) ) {
			return;
		}

		$old_permalink = self::$old_permalinks[ $post_id ];
		$new_permalink = get_permalink( $post_id );

		$old_normalized = SRM_Database::normalize_url( $old_permalink );
		$new_normalized = SRM_Database::normalize_url( $new_permalink );

		// Clean up stored value.
		unset( self::$old_permalinks[ $post_id ] );

		if ( $old_normalized === $new_normalized ) {
			return;
		}

		$settings   = get_option( 'srm_settings', array() );
		$status_code = isset( $settings['default_status_code'] ) ? (int) $settings['default_status_code'] : 301;

		// Create the redirect from old URL to new URL.
		SRM_Database::save_redirect( array(
			'source_url'  => $old_normalized,
			'target_url'  => $new_normalized,
			'status_code' => $status_code,
			'source_type' => 'auto_post',
			'post_id'     => $post_id,
			'is_active'   => 1,
		) );

		// Chain resolution: update existing redirects that point to the old URL.
		self::update_redirect_chains( $old_normalized, $new_normalized );

		// Set transient so we can show an admin notice.
		$user_id = get_current_user_id();

		if ( $user_id ) {
			set_transient( 'srm_redirect_created_' . $user_id, array(
				'source_url'  => $old_normalized,
				'target_url'  => $new_normalized,
				'status_code' => $status_code,
				'type'        => 'post',
				'post_id'     => $post_id,
			), 60 );
		}
	}

	/**
	 * Store the old term link before a term is updated.
	 *
	 * @param int    $term_id  Term ID about to be updated.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function store_old_term_link( $term_id, $taxonomy ) {
		$settings = get_option( 'srm_settings', array() );

		// Skip if auto-redirect is disabled.
		if ( empty( $settings['auto_redirect'] ) ) {
			return;
		}

		$term_link = get_term_link( (int) $term_id, $taxonomy );

		// get_term_link may return WP_Error.
		if ( is_wp_error( $term_link ) ) {
			return;
		}

		self::$old_term_links[ $term_id ] = $term_link;
	}

	/**
	 * Check if the term link changed after an update and create a redirect.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function check_term_link_change( $term_id, $tt_id, $taxonomy ) {
		if ( ! isset( self::$old_term_links[ $term_id ] ) ) {
			return;
		}

		$old_term_link = self::$old_term_links[ $term_id ];
		$new_term_link = get_term_link( (int) $term_id, $taxonomy );

		// Clean up stored value.
		unset( self::$old_term_links[ $term_id ] );

		if ( is_wp_error( $new_term_link ) ) {
			return;
		}

		$old_normalized = SRM_Database::normalize_url( $old_term_link );
		$new_normalized = SRM_Database::normalize_url( $new_term_link );

		if ( $old_normalized === $new_normalized ) {
			return;
		}

		$settings    = get_option( 'srm_settings', array() );
		$status_code = isset( $settings['default_status_code'] ) ? (int) $settings['default_status_code'] : 301;

		// Create the redirect from old URL to new URL.
		SRM_Database::save_redirect( array(
			'source_url'  => $old_normalized,
			'target_url'  => $new_normalized,
			'status_code' => $status_code,
			'source_type' => 'auto_term',
			'post_id'     => $term_id,
			'is_active'   => 1,
		) );

		// Chain resolution: update existing redirects that point to the old URL.
		self::update_redirect_chains( $old_normalized, $new_normalized );

		// Set transient so we can show an admin notice.
		$user_id = get_current_user_id();

		if ( $user_id ) {
			set_transient( 'srm_redirect_created_' . $user_id, array(
				'source_url'  => $old_normalized,
				'target_url'  => $new_normalized,
				'status_code' => $status_code,
				'type'        => 'term',
				'term_id'     => $term_id,
			), 60 );
		}
	}

	/**
	 * Update existing redirects that point to the old URL so they point
	 * to the new URL instead (redirect chain resolution).
	 *
	 * @param string $old_url The old normalized URL.
	 * @param string $new_url The new normalized URL.
	 * @return void
	 */
	private static function update_redirect_chains( $old_url, $new_url ) {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_redirects';

		$wpdb->update(
			$table,
			array( 'target_url' => $new_url ),
			array( 'target_url' => $old_url ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Show an admin notice when a redirect was automatically created.
	 *
	 * @return void
	 */
	public static function show_redirect_notice() {
		$user_id   = get_current_user_id();
		$transient = get_transient( 'srm_redirect_created_' . $user_id );

		if ( empty( $transient ) ) {
			return;
		}

		// Delete the transient so the notice is only shown once.
		delete_transient( 'srm_redirect_created_' . $user_id );

		$source = esc_html( $transient['source_url'] );
		$target = esc_html( $transient['target_url'] );
		$code   = (int) $transient['status_code'];

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: HTTP status code, 2: old URL, 3: new URL */
				esc_html__( 'Smart Redirect Manager: A %1$d redirect was automatically created from %2$s to %3$s.', 'smart-redirect-manager' ),
				$code,
				'<code>' . $source . '</code>',
				'<code>' . $target . '</code>'
			)
		);
	}
}
