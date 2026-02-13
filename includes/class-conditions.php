<?php
/**
 * Conditions
 *
 * Conditional redirect engine that evaluates per-redirect conditions
 * before allowing a redirect to execute.
 *
 * @package SmartRedirectManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Conditions
 *
 * Handles CRUD operations for redirect conditions and runtime evaluation.
 *
 * Supported condition types:
 *   user_agent, referrer, login_status, user_role, device_type, language,
 *   cookie, query_param, ip_range, server_name, request_method, time_range,
 *   day_of_week
 */
class SRM_Conditions {

	/**
	 * Retrieve all conditions for a redirect.
	 *
	 * @param int $redirect_id Redirect ID.
	 * @return array Array of condition objects.
	 */
	public static function get_conditions( $redirect_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_conditions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE redirect_id = %d ORDER BY id ASC",
				$redirect_id
			)
		);
	}

	/**
	 * Save conditions for a redirect.
	 *
	 * Removes all existing conditions for the redirect and inserts the
	 * provided set so the stored state always matches the submitted data.
	 *
	 * @param int   $redirect_id Redirect ID.
	 * @param array $conditions  Array of condition arrays, each containing
	 *                           condition_type, condition_operator, condition_value.
	 * @return bool True on success, false on failure.
	 */
	public static function save_conditions( $redirect_id, $conditions ) {
		global $wpdb;

		$redirect_id = absint( $redirect_id );
		$table       = $wpdb->prefix . 'srm_conditions';

		// Remove existing conditions for this redirect.
		self::delete_conditions( $redirect_id );

		if ( empty( $conditions ) || ! is_array( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $condition ) {
			$type     = isset( $condition['condition_type'] ) ? sanitize_text_field( $condition['condition_type'] ) : '';
			$operator = isset( $condition['condition_operator'] ) ? sanitize_text_field( $condition['condition_operator'] ) : '';
			$value    = isset( $condition['condition_value'] ) ? sanitize_text_field( $condition['condition_value'] ) : '';

			if ( '' === $type ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert(
				$table,
				array(
					'redirect_id'        => $redirect_id,
					'condition_type'     => $type,
					'condition_operator' => $operator,
					'condition_value'    => $value,
				),
				array( '%d', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete all conditions for a redirect.
	 *
	 * @param int $redirect_id Redirect ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_conditions( $redirect_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'srm_conditions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE redirect_id = %d",
				$redirect_id
			)
		);

		return false !== $result;
	}

	/**
	 * Check whether all conditions for a redirect are satisfied.
	 *
	 * Uses AND logic: every condition must pass for the redirect to fire.
	 * If there are no conditions the redirect is unconditional and passes.
	 *
	 * @param int $redirect_id Redirect ID.
	 * @return bool True when all conditions match (or none exist).
	 */
	public static function check_conditions( $redirect_id ) {

		$conditions = self::get_conditions( $redirect_id );

		if ( empty( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $condition ) {
			if ( ! self::check_single_condition( $condition ) ) {
				return false;
			}
		}

		return true;
	}

	// -----------------------------------------------------------------
	// Single-condition evaluation
	// -----------------------------------------------------------------

	/**
	 * Evaluate a single condition against the current request.
	 *
	 * @param object $condition Condition row from the database.
	 * @return bool True when the condition matches.
	 */
	private static function check_single_condition( $condition ) {

		$type     = $condition->condition_type;
		$operator = $condition->condition_operator;
		$value    = $condition->condition_value;

		switch ( $type ) {

			case 'user_agent':
				return self::check_user_agent( $operator, $value );

			case 'referrer':
				return self::check_referrer( $operator, $value );

			case 'login_status':
				return self::check_login_status( $value );

			case 'user_role':
				return self::check_user_role( $operator, $value );

			case 'device_type':
				return self::check_device_type( $value );

			case 'language':
				return self::check_language( $operator, $value );

			case 'cookie':
				return self::check_cookie( $operator, $value );

			case 'query_param':
				return self::check_query_param( $operator, $value );

			case 'ip_range':
				return self::check_ip_range( $operator, $value );

			case 'server_name':
				return self::check_server_name( $operator, $value );

			case 'request_method':
				return self::check_request_method( $operator, $value );

			case 'time_range':
				return self::check_time_range( $value );

			case 'day_of_week':
				return self::check_day_of_week( $operator, $value );

			default:
				return false;
		}
	}

	// -----------------------------------------------------------------
	// Individual condition checkers
	// -----------------------------------------------------------------

	/**
	 * Check the User-Agent header.
	 *
	 * @param string $operator contains|not_contains|equals|regex.
	 * @param string $value    Value to test against.
	 * @return bool
	 */
	private static function check_user_agent( $operator, $value ) {

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

		return self::compare_string( $ua, $operator, $value );
	}

	/**
	 * Check the Referer header.
	 *
	 * @param string $operator contains|not_contains|equals|regex.
	 * @param string $value    Value to test against.
	 * @return bool
	 */
	private static function check_referrer( $operator, $value ) {

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';

		return self::compare_string( $referrer, $operator, $value );
	}

	/**
	 * Check login status.
	 *
	 * @param string $value 'logged_in' or 'logged_out'.
	 * @return bool
	 */
	private static function check_login_status( $value ) {

		if ( 'logged_in' === $value ) {
			return is_user_logged_in();
		}

		if ( 'logged_out' === $value ) {
			return ! is_user_logged_in();
		}

		return false;
	}

	/**
	 * Check whether the current user has the specified role.
	 *
	 * @param string $operator equals|not_equals.
	 * @param string $value    Role slug (e.g. 'administrator').
	 * @return bool
	 */
	private static function check_user_role( $operator, $value ) {

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			$has_role = false;
		} else {
			$has_role = in_array( $value, (array) $user->roles, true );
		}

		if ( 'not_equals' === $operator ) {
			return ! $has_role;
		}

		return $has_role;
	}

	/**
	 * Detect device type from the User-Agent header.
	 *
	 * @param string $value 'mobile', 'tablet', or 'desktop'.
	 * @return bool
	 */
	private static function check_device_type( $value ) {

		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$detected = 'desktop';

		// Tablet keywords (checked first because tablets may also match mobile keywords).
		$tablet_keywords = array( 'ipad', 'tablet', 'kindle', 'silk', 'playbook' );

		foreach ( $tablet_keywords as $keyword ) {
			if ( false !== strpos( $ua, $keyword ) ) {
				$detected = 'tablet';
				break;
			}
		}

		// Mobile keywords (only if not already detected as tablet).
		if ( 'desktop' === $detected ) {
			$mobile_keywords = array(
				'mobile',
				'android',
				'iphone',
				'ipod',
				'phone',
				'blackberry',
				'opera mini',
				'opera mobi',
			);

			foreach ( $mobile_keywords as $keyword ) {
				if ( false !== strpos( $ua, $keyword ) ) {
					$detected = 'mobile';
					break;
				}
			}
		}

		return strtolower( $value ) === $detected;
	}

	/**
	 * Check the Accept-Language header.
	 *
	 * @param string $operator contains|equals|starts_with.
	 * @param string $value    Language value to test against.
	 * @return bool
	 */
	private static function check_language( $operator, $value ) {

		$accept_language = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

		switch ( $operator ) {

			case 'contains':
				return false !== stripos( $accept_language, $value );

			case 'equals':
				return 0 === strcasecmp( $accept_language, $value );

			case 'starts_with':
				return 0 === stripos( $accept_language, $value );

			default:
				return false;
		}
	}

	/**
	 * Check a cookie value.
	 *
	 * The condition value is stored as "name=value" (or just "name" for
	 * exists / not_exists operators).
	 *
	 * @param string $operator exists|not_exists|equals.
	 * @param string $value    Cookie descriptor.
	 * @return bool
	 */
	private static function check_cookie( $operator, $value ) {

		$parts       = explode( '=', $value, 2 );
		$cookie_name = $parts[0];
		$cookie_val  = isset( $parts[1] ) ? $parts[1] : '';

		switch ( $operator ) {

			case 'exists':
				return isset( $_COOKIE[ $cookie_name ] );

			case 'not_exists':
				return ! isset( $_COOKIE[ $cookie_name ] );

			case 'equals':
				return isset( $_COOKIE[ $cookie_name ] ) && $_COOKIE[ $cookie_name ] === $cookie_val;

			default:
				return false;
		}
	}

	/**
	 * Check a query parameter.
	 *
	 * The condition value is stored as "name=value" (or just "name" for
	 * exists / not_exists operators).
	 *
	 * @param string $operator exists|not_exists|equals|contains.
	 * @param string $value    Query-param descriptor.
	 * @return bool
	 */
	private static function check_query_param( $operator, $value ) {

		$parts      = explode( '=', $value, 2 );
		$param_name = $parts[0];
		$param_val  = isset( $parts[1] ) ? $parts[1] : '';

		switch ( $operator ) {

			case 'exists':
				return isset( $_GET[ $param_name ] );

			case 'not_exists':
				return ! isset( $_GET[ $param_name ] );

			case 'equals':
				return isset( $_GET[ $param_name ] ) && $_GET[ $param_name ] === $param_val;

			case 'contains':
				return isset( $_GET[ $param_name ] ) && false !== strpos( $_GET[ $param_name ], $param_val );

			default:
				return false;
		}
	}

	/**
	 * Check the client IP against a value or CIDR range.
	 *
	 * @param string $operator equals|in_range.
	 * @param string $value    IP address or CIDR notation (e.g. 192.168.1.0/24).
	 * @return bool
	 */
	private static function check_ip_range( $operator, $value ) {

		$client_ip = self::get_client_ip();

		if ( '' === $client_ip ) {
			return false;
		}

		switch ( $operator ) {

			case 'equals':
				return $client_ip === $value;

			case 'in_range':
				return self::ip_in_cidr( $client_ip, $value );

			default:
				return false;
		}
	}

	/**
	 * Check the server name (hostname).
	 *
	 * @param string $operator equals|not_equals|contains.
	 * @param string $value    Server name to test against.
	 * @return bool
	 */
	private static function check_server_name( $operator, $value ) {

		$server_name = isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : '';

		switch ( $operator ) {

			case 'equals':
				return 0 === strcasecmp( $server_name, $value );

			case 'not_equals':
				return 0 !== strcasecmp( $server_name, $value );

			case 'contains':
				return false !== stripos( $server_name, $value );

			default:
				return false;
		}
	}

	/**
	 * Check the HTTP request method.
	 *
	 * @param string $operator equals.
	 * @param string $value    Method name (GET, POST, etc.).
	 * @return bool
	 */
	private static function check_request_method( $operator, $value ) {

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '';

		if ( 'equals' === $operator ) {
			return $method === strtoupper( $value );
		}

		return false;
	}

	/**
	 * Check whether the current server time falls within a time range.
	 *
	 * Value format: "HH:MM-HH:MM" (24-hour notation).
	 *
	 * @param string $value Time range descriptor.
	 * @return bool
	 */
	private static function check_time_range( $value ) {

		$parts = explode( '-', $value, 2 );

		if ( count( $parts ) < 2 ) {
			return false;
		}

		$start   = trim( $parts[0] );
		$end     = trim( $parts[1] );
		$current = date( 'H:i' );

		// Normal range (e.g. 09:00-17:00).
		if ( $start <= $end ) {
			return $current >= $start && $current <= $end;
		}

		// Overnight range (e.g. 22:00-06:00).
		return $current >= $start || $current <= $end;
	}

	/**
	 * Check the current day of the week.
	 *
	 * @param string $operator equals|not_equals.
	 * @param string $value    Day number (0 = Sunday, 1 = Monday, ... 6 = Saturday).
	 * @return bool
	 */
	private static function check_day_of_week( $operator, $value ) {

		$current_day = date( 'w' );

		if ( 'not_equals' === $operator ) {
			return $current_day !== (string) $value;
		}

		// Default: equals.
		return $current_day === (string) $value;
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Generic string comparison helper.
	 *
	 * @param string $haystack String to inspect.
	 * @param string $operator contains|not_contains|equals|regex.
	 * @param string $needle   Value to test against.
	 * @return bool
	 */
	private static function compare_string( $haystack, $operator, $needle ) {

		switch ( $operator ) {

			case 'contains':
				return false !== stripos( $haystack, $needle );

			case 'not_contains':
				return false === stripos( $haystack, $needle );

			case 'equals':
				return 0 === strcasecmp( $haystack, $needle );

			case 'regex':
				return (bool) @preg_match( $needle, $haystack );

			default:
				return false;
		}
	}

	/**
	 * Determine the client IP address.
	 *
	 * Checks common proxy headers before falling back to REMOTE_ADDR.
	 *
	 * @return string IP address or empty string.
	 */
	private static function get_client_ip() {

		$headers = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For may contain a comma-separated list.
				$ips = explode( ',', $_SERVER[ $header ] );
				$ip  = trim( $ips[0] );

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Check if an IP address falls within a CIDR range.
	 *
	 * @param string $ip   IP address to test.
	 * @param string $cidr CIDR notation (e.g. 192.168.1.0/24).
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {

		$parts = explode( '/', $cidr, 2 );

		if ( count( $parts ) < 2 ) {
			// No subnet mask provided; fall back to exact match.
			return $ip === $cidr;
		}

		$subnet = $parts[0];
		$bits   = intval( $parts[1] );

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
