<?php
/**
 * REST API endpoints for Smart Redirect Manager.
 *
 * Provides a full CRUD interface for redirects, statistics,
 * and 404 log entries under the srm/v1 namespace.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_REST_API
 *
 * Registers and handles all REST API routes for the plugin.
 */
class SRM_REST_API {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'srm/v1';

	/**
	 * Initialize the REST API integration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// -------------------------------------------------------------------------
	// Route Registration
	// -------------------------------------------------------------------------

	/**
	 * Register all REST API routes.
	 *
	 * @return void
	 */
	public static function register_routes() {

		// -----------------------------------------------------------------
		// GET /redirects — list redirects (paginated, filterable).
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_redirects' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => self::get_collection_params(),
			)
		);

		// -----------------------------------------------------------------
		// POST /redirects — create a new redirect.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_redirect' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => self::get_redirect_create_params(),
			)
		);

		// -----------------------------------------------------------------
		// GET /redirects/<id> — single redirect.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_redirect' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Unique identifier for the redirect.', 'smart-redirect-manager' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// -----------------------------------------------------------------
		// PUT|PATCH /redirects/<id> — update an existing redirect.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( __CLASS__, 'update_redirect' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => self::get_redirect_update_params(),
			)
		);

		// -----------------------------------------------------------------
		// DELETE /redirects/<id> — delete a redirect.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_redirect' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Unique identifier for the redirect.', 'smart-redirect-manager' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// -----------------------------------------------------------------
		// GET /stats — overview statistics.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_stats' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		// -----------------------------------------------------------------
		// GET /stats/daily — daily hit totals.
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/stats/daily',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_stats_daily' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'days' => array(
						'description'       => __( 'Number of days to look back.', 'smart-redirect-manager' ),
						'type'              => 'integer',
						'default'           => 30,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0 && (int) $value <= 365;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// -----------------------------------------------------------------
		// GET /404-log — 404 log entries (paginated, filterable).
		// -----------------------------------------------------------------
		register_rest_route(
			self::NAMESPACE,
			'/404-log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_404_log' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => self::get_404_log_params(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission Callback
	// -------------------------------------------------------------------------

	/**
	 * Verify the current user has permission to access the REST API.
	 *
	 * @return bool|WP_Error True if the user has 'manage_options', WP_Error otherwise.
	 */
	public static function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'smart-redirect-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Redirect Endpoints
	// -------------------------------------------------------------------------

	/**
	 * Handle GET /redirects — list redirects with pagination and search.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public static function get_redirects( $request ) {
		$args = array(
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'search'   => $request->get_param( 'search' ),
			'status'   => $request->get_param( 'status' ),
			'type'     => $request->get_param( 'type' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		);

		// Remove null values so that SRM_Database uses its defaults.
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value;
			}
		);

		$result      = SRM_Database::get_redirects( $args );
		$total       = $result['total'];
		$per_page    = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	/**
	 * Handle POST /redirects — create a new redirect.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_redirect( $request ) {
		$data = array(
			'source_url'  => $request->get_param( 'source_url' ),
			'target_url'  => $request->get_param( 'target_url' ),
			'status_code' => $request->get_param( 'status_code' ),
			'is_regex'    => $request->get_param( 'is_regex' ),
			'is_active'   => $request->get_param( 'is_active' ),
			'notes'       => $request->get_param( 'notes' ),
			'expires_at'  => $request->get_param( 'expires_at' ),
			'group_id'    => $request->get_param( 'group_id' ),
		);

		// Remove null values so that SRM_Database applies its defaults.
		$data = array_filter(
			$data,
			function ( $value ) {
				return null !== $value;
			}
		);

		$id = SRM_Database::save_redirect( $data );

		if ( false === $id ) {
			return new WP_Error(
				'srm_create_failed',
				__( 'Failed to create the redirect.', 'smart-redirect-manager' ),
				array( 'status' => 400 )
			);
		}

		$redirect = SRM_Database::get_redirect( $id );

		return new WP_REST_Response( $redirect, 201 );
	}

	/**
	 * Handle GET /redirects/<id> — retrieve a single redirect.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_redirect( $request ) {
		$id       = $request->get_param( 'id' );
		$redirect = SRM_Database::get_redirect( $id );

		if ( null === $redirect ) {
			return new WP_Error(
				'srm_not_found',
				__( 'Redirect not found.', 'smart-redirect-manager' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $redirect, 200 );
	}

	/**
	 * Handle PUT|PATCH /redirects/<id> — update an existing redirect.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_redirect( $request ) {
		$id       = $request->get_param( 'id' );
		$existing = SRM_Database::get_redirect( $id );

		if ( null === $existing ) {
			return new WP_Error(
				'srm_not_found',
				__( 'Redirect not found.', 'smart-redirect-manager' ),
				array( 'status' => 404 )
			);
		}

		$data = array(
			'id'          => $id,
			'source_url'  => $request->get_param( 'source_url' ),
			'target_url'  => $request->get_param( 'target_url' ),
			'status_code' => $request->get_param( 'status_code' ),
			'is_regex'    => $request->get_param( 'is_regex' ),
			'is_active'   => $request->get_param( 'is_active' ),
			'notes'       => $request->get_param( 'notes' ),
			'expires_at'  => $request->get_param( 'expires_at' ),
			'group_id'    => $request->get_param( 'group_id' ),
		);

		// Remove null values so only supplied fields are updated.
		$data = array_filter(
			$data,
			function ( $value ) {
				return null !== $value;
			}
		);

		// Always keep the id.
		$data['id'] = $id;

		$result_id = SRM_Database::save_redirect( $data );

		if ( false === $result_id ) {
			return new WP_Error(
				'srm_update_failed',
				__( 'Failed to update the redirect.', 'smart-redirect-manager' ),
				array( 'status' => 400 )
			);
		}

		$redirect = SRM_Database::get_redirect( $id );

		return new WP_REST_Response( $redirect, 200 );
	}

	/**
	 * Handle DELETE /redirects/<id> — delete a redirect.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_redirect( $request ) {
		$id       = $request->get_param( 'id' );
		$existing = SRM_Database::get_redirect( $id );

		if ( null === $existing ) {
			return new WP_Error(
				'srm_not_found',
				__( 'Redirect not found.', 'smart-redirect-manager' ),
				array( 'status' => 404 )
			);
		}

		$deleted = SRM_Database::delete_redirect( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'srm_delete_failed',
				__( 'Failed to delete the redirect.', 'smart-redirect-manager' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Statistics Endpoints
	// -------------------------------------------------------------------------

	/**
	 * Handle GET /stats — overview statistics.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public static function get_stats( $request ) {
		$overview = SRM_Statistics::get_overview();

		return new WP_REST_Response( $overview, 200 );
	}

	/**
	 * Handle GET /stats/daily — daily hit totals.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public static function get_stats_daily( $request ) {
		$days = $request->get_param( 'days' );
		$hits = SRM_Statistics::get_daily_hits( $days );

		return new WP_REST_Response( $hits, 200 );
	}

	// -------------------------------------------------------------------------
	// 404 Log Endpoint
	// -------------------------------------------------------------------------

	/**
	 * Handle GET /404-log — paginated 404 log entries.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public static function get_404_log( $request ) {
		$args = array(
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'search'   => $request->get_param( 'search' ),
			'status'   => $request->get_param( 'status' ),
		);

		// Remove null values so that SRM_404_Logger uses its defaults.
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value;
			}
		);

		$result      = SRM_404_Logger::get_logs( $args );
		$total       = $result['total'];
		$per_page    = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 25;
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		$response = new WP_REST_Response( $result['items'], 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Argument Definitions
	// -------------------------------------------------------------------------

	/**
	 * Get the collection query parameters for the /redirects list endpoint.
	 *
	 * @return array Associative array of argument definitions.
	 */
	private static function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1;
				},
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned per page.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100;
				},
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'description'       => __( 'Search term to filter by source or target URL.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'description'       => __( 'Filter by active status.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'active', 'inactive' ),
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( '', 'active', 'inactive' ), true );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'     => array(
				'description'       => __( 'Filter by source type (e.g. manual, auto, import).', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'description'       => __( 'Column to sort the results by.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => 'created_at',
				'enum'              => array(
					'id',
					'source_url',
					'target_url',
					'status_code',
					'is_active',
					'hit_count',
					'last_hit',
					'source_type',
					'created_at',
					'updated_at',
				),
				'validate_callback' => function ( $value ) {
					$allowed = array(
						'id',
						'source_url',
						'target_url',
						'status_code',
						'is_active',
						'hit_count',
						'last_hit',
						'source_type',
						'created_at',
						'updated_at',
					);
					return in_array( $value, $allowed, true );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'    => array(
				'description'       => __( 'Sort direction.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'validate_callback' => function ( $value ) {
					return in_array( strtoupper( $value ), array( 'ASC', 'DESC' ), true );
				},
				'sanitize_callback' => function ( $value ) {
					return strtoupper( sanitize_text_field( $value ) );
				},
			),
		);
	}

	/**
	 * Get the argument definitions for creating a redirect (POST).
	 *
	 * @return array Associative array of argument definitions.
	 */
	private static function get_redirect_create_params() {
		return array(
			'source_url'  => array(
				'description'       => __( 'The source URL path to redirect from.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => function ( $value ) {
					return is_string( $value ) && '' !== trim( $value );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'target_url'  => array(
				'description'       => __( 'The target URL to redirect to.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => function ( $value ) {
					return is_string( $value ) && '' !== trim( $value );
				},
				'sanitize_callback' => 'esc_url_raw',
			),
			'status_code' => array(
				'description'       => __( 'HTTP status code for the redirect.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => 301,
				'enum'              => array( 301, 302, 303, 307, 308, 410 ),
				'validate_callback' => function ( $value ) {
					return in_array( (int) $value, array( 301, 302, 303, 307, 308, 410 ), true );
				},
				'sanitize_callback' => 'absint',
			),
			'is_regex'    => array(
				'description'       => __( 'Whether the source URL is a regular expression.', 'smart-redirect-manager' ),
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_is_boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'is_active'   => array(
				'description'       => __( 'Whether the redirect is active.', 'smart-redirect-manager' ),
				'type'              => 'boolean',
				'default'           => true,
				'validate_callback' => 'rest_is_boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'notes'       => array(
				'description'       => __( 'Optional notes for the redirect.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'expires_at'  => array(
				'description'       => __( 'Expiration date and time in MySQL datetime format (Y-m-d H:i:s).', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => null,
				'validate_callback' => function ( $value ) {
					if ( empty( $value ) ) {
						return true;
					}
					// Validate MySQL datetime format.
					return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'group_id'    => array(
				'description'       => __( 'Group ID to assign the redirect to.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => null,
				'validate_callback' => function ( $value ) {
					if ( null === $value || '' === $value ) {
						return true;
					}
					return is_numeric( $value ) && (int) $value >= 0;
				},
				'sanitize_callback' => function ( $value ) {
					if ( null === $value || '' === $value ) {
						return null;
					}
					return absint( $value );
				},
			),
		);
	}

	/**
	 * Get the argument definitions for updating a redirect (PUT/PATCH).
	 *
	 * All fields are optional since PATCH only sends changed fields.
	 *
	 * @return array Associative array of argument definitions.
	 */
	private static function get_redirect_update_params() {
		$params = self::get_redirect_create_params();

		// Add the id parameter.
		$params['id'] = array(
			'description'       => __( 'Unique identifier for the redirect.', 'smart-redirect-manager' ),
			'type'              => 'integer',
			'required'          => true,
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
			'sanitize_callback' => 'absint',
		);

		// Make source_url and target_url optional for updates.
		$params['source_url']['required'] = false;
		$params['target_url']['required'] = false;

		return $params;
	}

	/**
	 * Get the query parameters for the /404-log endpoint.
	 *
	 * @return array Associative array of argument definitions.
	 */
	private static function get_404_log_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1;
				},
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned per page.', 'smart-redirect-manager' ),
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100;
				},
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'description'       => __( 'Search term to filter by request URL or referer.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'description'       => __( 'Filter by resolution status.', 'smart-redirect-manager' ),
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'all', 'resolved', 'unresolved' ),
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( 'all', 'resolved', 'unresolved' ), true );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
