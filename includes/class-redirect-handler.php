<?php
/**
 * Redirect Handler
 *
 * Intercepts incoming requests and executes matching redirects.
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
 * Class SRM_Redirect_Handler
 *
 * Handles request interception and redirect execution.
 */
class SRM_Redirect_Handler {

	/**
	 * Flag to prevent double execution from both hooks.
	 *
	 * @var bool
	 */
	private static $handled = false;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'handle_redirect' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirect' ), 1 );
	}

	/**
	 * Handle incoming request and execute a matching redirect.
	 *
	 * Called by both parse_request and template_redirect hooks.
	 * Uses a static flag to ensure it only runs once per request.
	 *
	 * @return void
	 */
	public static function handle_redirect() {

		// Prevent double execution from both hooks.
		if ( self::$handled ) {
			return;
		}

		// Do not run in admin or on AJAX requests.
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		// Mark as handled so the second hook is a no-op.
		self::$handled = true;

		// ------------------------------------------------------------------
		// 1. Normalize the request URL.
		// ------------------------------------------------------------------
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$request_url = SRM_Database::normalize_url( $request_uri );

		// ------------------------------------------------------------------
		// 2. Check excluded paths.
		// ------------------------------------------------------------------
		$settings       = get_option( 'srm_settings', array() );
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
		// 3. Retrieve active redirects.
		// ------------------------------------------------------------------
		$redirects = SRM_Database::get_active_redirects();

		if ( empty( $redirects ) ) {
			return;
		}

		$matched_redirect = null;
		$final_target_url = '';

		// ------------------------------------------------------------------
		// 4. First pass: exact URL matches (non-regex).
		// ------------------------------------------------------------------
		foreach ( $redirects as $redirect ) {
			if ( ! empty( $redirect->is_regex ) ) {
				continue;
			}

			$source_normalized = SRM_Database::normalize_url( $redirect->source_url );

			if ( $source_normalized === $request_url ) {
				$matched_redirect = $redirect;
				$final_target_url = $redirect->target_url;
				break;
			}
		}

		// ------------------------------------------------------------------
		// 5. Second pass: regex matches with backreference support.
		// ------------------------------------------------------------------
		if ( null === $matched_redirect ) {
			foreach ( $redirects as $redirect ) {
				if ( empty( $redirect->is_regex ) ) {
					continue;
				}

				$pattern = '@' . str_replace( '@', '\\@', $redirect->source_url ) . '@';

				if ( preg_match( $pattern, $request_url, $matches ) ) {
					$matched_redirect = $redirect;
					$final_target_url = preg_replace( $pattern, $redirect->target_url, $request_url );
					break;
				}
			}
		}

		// No match found.
		if ( null === $matched_redirect ) {
			return;
		}

		// ------------------------------------------------------------------
		// 6. Check conditions.
		// ------------------------------------------------------------------
		if ( ! SRM_Conditions::check_conditions( $matched_redirect->id ) ) {
			return;
		}

		// ------------------------------------------------------------------
		// 7. Track hit if tracking is enabled.
		// ------------------------------------------------------------------
		$track_hits = isset( $settings['track_hits'] ) ? (bool) $settings['track_hits'] : false;

		if ( $track_hits ) {
			SRM_Statistics::track_hit( $matched_redirect->id );
		}

		// ------------------------------------------------------------------
		// 8. Handle 410 Gone responses.
		// ------------------------------------------------------------------
		$status_code = absint( $matched_redirect->status_code );

		if ( 410 === $status_code ) {
			status_header( 410 );
			nocache_headers();

			$template_410 = get_query_template( '410' );

			if ( $template_410 ) {
				include $template_410;
			} else {
				wp_die(
					esc_html__( '410 Gone', 'smart-redirect-manager' ),
					esc_html__( '410 Gone', 'smart-redirect-manager' ),
					array( 'response' => 410 )
				);
			}
			exit;
		}

		// ------------------------------------------------------------------
		// 9. Loop detection: abort if target equals request.
		// ------------------------------------------------------------------
		if ( SRM_Database::normalize_url( $final_target_url ) === $request_url ) {
			return;
		}

		// ------------------------------------------------------------------
		// 10. Set cache headers.
		// ------------------------------------------------------------------
		if ( in_array( $status_code, array( 301, 308 ), true ) ) {
			header( 'Cache-Control: public, max-age=31536000' );
		} else {
			nocache_headers();
		}

		// ------------------------------------------------------------------
		// 11. Set identification header and redirect.
		// ------------------------------------------------------------------
		header( 'X-Redirect-By: Smart Redirect Manager' );

		wp_redirect( $final_target_url, $status_code );
		exit;
	}
}
