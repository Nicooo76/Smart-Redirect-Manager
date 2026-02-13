<?php
/**
 * Smart Redirect Manager - URL Monitor
 *
 * Auto-detects URL changes on posts and terms and creates
 * redirects from the old URL to the new URL automatically.
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
	 * Default settings for URL monitoring (used when option keys are missing).
	 *
	 * @var array
	 */
	private static $defaults = array(
		'auto_redirect'      => true,
		'monitor_post_types' => array( 'post', 'page' ),
		'default_status_code' => 301,
	);

	/**
	 * Initialize hooks for URL monitoring.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'store_old_permalink_before_save' ), 10, 2 );
		add_action( 'pre_post_update', array( __CLASS__, 'store_old_permalink' ), 10, 2 );
		add_action( 'post_updated', array( __CLASS__, 'check_permalink_change' ), 10, 3 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'check_permalink_change_after_insert' ), 10, 3 );
		add_action( 'pre_edit_term', array( __CLASS__, 'store_old_term_link' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'check_term_link_change' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'show_redirect_notice' ) );
	}

	/**
	 * Get settings merged with defaults so URL monitoring works even when option keys are missing.
	 *
	 * @return array
	 */
	private static function get_settings() {
		return wp_parse_args( get_option( 'srm_settings', array() ), self::$defaults );
	}

	/**
	 * Store the old permalink when post data is about to be saved (runs on wp_insert_post_data).
	 * Fallback for Block Editor / REST API where pre_post_update may not run in time.
	 *
	 * @param array $data    New post data.
	 * @param array $postarr Raw post data including ID on update.
	 * @return array Unchanged $data.
	 */
	public static function store_old_permalink_before_save( $data, $postarr ) {
		if ( empty( $postarr['ID'] ) ) {
			return $data;
		}
		self::store_old_permalink( (int) $postarr['ID'], $data );
		return $data;
	}

	/**
	 * Store the old permalink before a post is updated.
	 *
	 * @param int   $post_id Post ID about to be updated.
	 * @param array $data    Array of unslashed post data (optional, not used for storage).
	 * @return void
	 */
	public static function store_old_permalink( $post_id, $data ) {
		$settings = self::get_settings();

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

		$monitor_post_types = isset( $settings['monitor_post_types'] ) ? (array) $settings['monitor_post_types'] : array();
		if ( ! in_array( $post->post_type, $monitor_post_types, true ) ) {
			return;
		}

		// Cache leeren, damit get_permalink die alte URL aus der DB liest (nicht bereits
		// aktualisierte Daten aus dem Objekt-Cache, z. B. bei REST/Block-Editor).
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post ) {
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
		self::maybe_create_redirect_for_post( $post_id );
	}

	/**
	 * Fallback: run redirect creation after insert (for Block Editor / REST API).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object after save.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function check_permalink_change_after_insert( $post_id, $post, $update ) {
		if ( ! $update ) {
			return;
		}
		self::maybe_create_redirect_for_post( $post_id );
	}

	/**
	 * If we stored an old permalink for this post and the URL changed, create redirect and show notice.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function maybe_create_redirect_for_post( $post_id ) {
		if ( ! isset( self::$old_permalinks[ $post_id ] ) ) {
			return;
		}

		$old_permalink = self::$old_permalinks[ $post_id ];
		$new_permalink = get_permalink( $post_id );

		$old_normalized = SRM_Database::normalize_url( $old_permalink );
		$new_normalized = SRM_Database::normalize_url( $new_permalink );

		unset( self::$old_permalinks[ $post_id ] );

		if ( $old_normalized === $new_normalized ) {
			return;
		}

		$settings    = self::get_settings();
		$status_code = isset( $settings['default_status_code'] ) ? (int) $settings['default_status_code'] : 301;

		$redirect_id = SRM_Database::save_redirect( array(
			'source_url'  => $old_normalized,
			'target_url'  => $new_normalized,
			'status_code' => $status_code,
			'source_type' => 'auto_post',
			'post_id'     => $post_id,
			'is_active'   => 1,
		) );

		if ( ! $redirect_id ) {
			return;
		}

		self::update_redirect_chains( $old_normalized, $new_normalized );

		// Hinweis-Transient: aktueller User oder Post-Autor (REST/Block-Editor liefert evtl. keinen User).
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$post = get_post( $post_id );
			$user_id = $post ? (int) $post->post_author : 0;
		}
		if ( $user_id ) {
			set_transient( 'srm_redirect_created_' . $user_id, array(
				'source_url'  => $old_normalized,
				'target_url'  => $new_normalized,
				'status_code' => $status_code,
				'type'        => 'post',
				'post_id'     => $post_id,
			), 120 );
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
		$settings = self::get_settings();

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

		$settings    = self::get_settings();
		$status_code = isset( $settings['default_status_code'] ) ? (int) $settings['default_status_code'] : 301;

		$redirect_id = SRM_Database::save_redirect( array(
			'source_url'  => $old_normalized,
			'target_url'  => $new_normalized,
			'status_code' => $status_code,
			'source_type' => 'auto_term',
			'post_id'     => $term_id,
			'is_active'   => 1,
		) );

		if ( ! $redirect_id ) {
			return;
		}

		self::update_redirect_chains( $old_normalized, $new_normalized );

		$user_id = get_current_user_id();
		if ( $user_id ) {
			set_transient( 'srm_redirect_created_' . $user_id, array(
				'source_url'  => $old_normalized,
				'target_url'  => $new_normalized,
				'status_code' => $status_code,
				'type'        => 'term',
				'term_id'     => $term_id,
			), 120 );
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

		$table = SRM_Database::get_table_name( 'redirects' );

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

		if ( empty( $transient ) || empty( $transient['source_url'] ) || empty( $transient['target_url'] ) ) {
			return;
		}

		delete_transient( 'srm_redirect_created_' . $user_id );

		$source = esc_html( $transient['source_url'] );
		$target = esc_html( $transient['target_url'] );
		$code   = isset( $transient['status_code'] ) ? (int) $transient['status_code'] : 301;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: 1: HTTP status code, 2: old URL, 3: new URL */
				esc_html__( 'Smart Redirect Manager: Es wurde automatisch eine %1$d-Weiterleitung von %2$s nach %3$s eingerichtet.', 'smart-redirect-manager' ),
				$code,
				'<code>' . $source . '</code>',
				'<code>' . $target . '</code>'
			)
		);
	}
}
