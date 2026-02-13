<?php
/**
 * Database handler for Smart Redirect Manager.
 *
 * Manages schema creation, CRUD operations for redirects,
 * settings management, and object cache integration.
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
 * Class SRM_Database
 *
 * Handles all database operations for the Smart Redirect Manager plugin.
 */
class SRM_Database {

    /**
     * Current database schema version.
     *
     * @var string
     */
    const DB_VERSION = '1.0.0';

    /**
     * Option key for the stored database version.
     *
     * @var string
     */
    const DB_VERSION_OPTION = 'srm_db_version';

    /**
     * Option key for plugin settings.
     *
     * @var string
     */
    const SETTINGS_OPTION = 'srm_settings';

    /**
     * Cache group used for object caching.
     *
     * @var string
     */
    const CACHE_GROUP = 'srm';

    /**
     * Cache key for active redirects.
     *
     * @var string
     */
    const ACTIVE_REDIRECTS_CACHE_KEY = 'active_redirects';

    /**
     * Default plugin settings.
     *
     * @return array
     */
    private static function get_default_settings() {
        return array(
            'auto_redirect'              => true,
            'monitor_post_types'         => array( 'post', 'page' ),
            'default_status_code'        => 301,
            'log_404'                    => true,
            'log_retention_days'         => 30,
            'group_404'                  => true,
            'track_hits'                 => true,
            'redirect_cache_ttl'         => 3600,
            'excluded_paths'             => "/wp-admin\n/wp-login.php\n/wp-cron.php",
            'auto_410'                   => false,
            'auto_cleanup_enabled'       => true,
            'auto_cleanup_days'          => 365,
            'auto_cleanup_min_days_idle' => 90,
            'auto_cleanup_action'        => 'deactivate',
            'auto_cleanup_exclude_types' => array( 'manual' ),
            'notifications_enabled'      => false,
            'notification_email'         => '',
            'notification_frequency'     => 'daily',
            '404_spike_threshold'        => 50,
        );
    }

    // -------------------------------------------------------------------------
    // Activation / Deactivation
    // -------------------------------------------------------------------------

    /**
     * Run on plugin activation.
     *
     * Creates all database tables, stores default settings, and schedules cron
     * events.
     *
     * @return void
     */
    public static function activate() {
        self::create_tables();

        // Store default settings if none exist yet.
        if ( false === get_option( self::SETTINGS_OPTION ) ) {
            update_option( self::SETTINGS_OPTION, self::get_default_settings() );
        }

        // Persist the current schema version.
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        // Schedule cron events.
        if ( ! wp_next_scheduled( 'srm_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'srm_daily_cleanup' );
        }

        if ( ! wp_next_scheduled( 'srm_log_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'srm_log_cleanup' );
        }
    }

    /**
     * Run on plugin deactivation.
     *
     * Clears all scheduled cron hooks owned by this plugin.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'srm_daily_cleanup' );
        wp_clear_scheduled_hook( 'srm_log_cleanup' );
    }

    // -------------------------------------------------------------------------
    // Table Creation
    // -------------------------------------------------------------------------

    /**
     * Create or update all plugin tables using dbDelta().
     *
     * @return void
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $redirects_table   = self::get_table_name( 'redirects' );
        $log_table         = self::get_table_name( '404_log' );
        $hits_table        = self::get_table_name( 'redirect_hits' );
        $groups_table      = self::get_table_name( 'groups' );
        $conditions_table  = self::get_table_name( 'conditions' );

        // -- wp_srm_redirects -------------------------------------------------
        $sql_redirects = "CREATE TABLE {$redirects_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_url varchar(2048) NOT NULL,
            target_url varchar(2048) NOT NULL,
            status_code smallint(3) NOT NULL DEFAULT 301,
            is_regex tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit datetime DEFAULT NULL,
            source_type varchar(50) DEFAULT 'manual',
            post_id bigint(20) unsigned DEFAULT NULL,
            group_id bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_url_idx (source_url(191)),
            KEY is_active_idx (is_active),
            KEY post_id_idx (post_id),
            KEY group_id_idx (group_id),
            KEY expires_at_idx (expires_at)
        ) {$charset_collate};";

        // -- wp_srm_404_log ---------------------------------------------------
        $sql_log = "CREATE TABLE {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_url varchar(2048) NOT NULL,
            referer varchar(2048) DEFAULT NULL,
            user_agent varchar(512) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            count bigint(20) unsigned NOT NULL DEFAULT 1,
            last_occurred datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_resolved tinyint(1) NOT NULL DEFAULT 0,
            redirect_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY request_url_idx (request_url(191)),
            KEY is_resolved_idx (is_resolved),
            KEY last_occurred_idx (last_occurred)
        ) {$charset_collate};";

        // -- wp_srm_redirect_hits ---------------------------------------------
        $sql_hits = "CREATE TABLE {$hits_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            redirect_id bigint(20) unsigned NOT NULL,
            hit_date date NOT NULL,
            hit_count int(11) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY redirect_date_idx (redirect_id, hit_date),
            KEY hit_date_idx (hit_date)
        ) {$charset_collate};";

        // -- wp_srm_groups ----------------------------------------------------
        $sql_groups = "CREATE TABLE {$groups_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text DEFAULT NULL,
            color varchar(7) DEFAULT '#2271b1',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            redirect_count int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug_idx (slug(191))
        ) {$charset_collate};";

        // -- wp_srm_conditions ------------------------------------------------
        $sql_conditions = "CREATE TABLE {$conditions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            redirect_id bigint(20) unsigned NOT NULL,
            condition_type varchar(50) NOT NULL,
            condition_operator varchar(20) NOT NULL DEFAULT 'equals',
            condition_value varchar(500) NOT NULL,
            PRIMARY KEY  (id),
            KEY redirect_id_idx (redirect_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_redirects );
        dbDelta( $sql_log );
        dbDelta( $sql_hits );
        dbDelta( $sql_groups );
        dbDelta( $sql_conditions );
    }

    // -------------------------------------------------------------------------
    // Settings Management
    // -------------------------------------------------------------------------

    /**
     * Retrieve all plugin settings, merged with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        $saved    = get_option( self::SETTINGS_OPTION, array() );
        $defaults = self::get_default_settings();

        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Persist the given settings array.
     *
     * @param array $settings Settings key-value pairs to save.
     * @return bool True on success, false on failure.
     */
    public static function save_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return false;
        }

        $sanitized = array();
        $defaults  = self::get_default_settings();

        foreach ( $defaults as $key => $default_value ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $sanitized[ $key ] = $default_value;
                continue;
            }

            $value = $settings[ $key ];

            if ( is_bool( $default_value ) ) {
                $sanitized[ $key ] = (bool) $value;
            } elseif ( is_int( $default_value ) ) {
                $sanitized[ $key ] = (int) $value;
            } elseif ( is_array( $default_value ) ) {
                $sanitized[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : $default_value;
            } else {
                $sanitized[ $key ] = sanitize_textarea_field( $value );
            }
        }

        return update_option( self::SETTINGS_OPTION, $sanitized );
    }

    /**
     * Retrieve a single setting value by key.
     *
     * @param string $key The setting key.
     * @return mixed The setting value, or null if the key does not exist.
     */
    public static function get_setting( $key ) {
        $settings = self::get_settings();

        return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
    }

    // -------------------------------------------------------------------------
    // URL Normalization
    // -------------------------------------------------------------------------

    /**
     * Normalize a URL for consistent storage and matching.
     *
     * Strips the domain, ensures a leading slash, removes a trailing slash, and
     * preserves the query string.
     *
     * @param string $url The URL to normalize.
     * @return string The normalized URL path (with optional query string).
     */
    public static function normalize_url( $url ) {
        if ( empty( $url ) ) {
            return '/';
        }

        $url = trim( $url );

        // Parse the URL to extract the path and query components.
        $parsed = wp_parse_url( $url );

        $path  = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        $query = isset( $parsed['query'] ) ? $parsed['query'] : '';

        // Ensure a leading slash.
        if ( 0 !== strpos( $path, '/' ) ) {
            $path = '/' . $path;
        }

        // Remove trailing slash (but keep root "/").
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
        }

        // Lowercase the path for consistent matching.
        $path = strtolower( $path );

        // Re-attach query string if present.
        if ( '' !== $query ) {
            $path .= '?' . $query;
        }

        return $path;
    }

    // -------------------------------------------------------------------------
    // Redirect CRUD
    // -------------------------------------------------------------------------

    /**
     * Retrieve a paginated, filterable list of redirects.
     *
     * @param array $args {
     *     Optional. Arguments to control the query.
     *
     *     @type int    $per_page  Number of items per page. Default 20.
     *     @type int    $page      Current page number. Default 1.
     *     @type string $search    Search term matched against source_url and target_url.
     *     @type string $status    Filter by active state: 'active', 'inactive', or empty for all.
     *     @type string $type      Filter by source_type.
     *     @type int    $group_id  Filter by group_id.
     *     @type string $orderby   Column to order by. Default 'created_at'.
     *     @type string $order     ASC or DESC. Default 'DESC'.
     * }
     * @return array {
     *     @type array $items Array of redirect objects.
     *     @type int   $total Total number of matching redirects.
     * }
     */
    public static function get_redirects( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => 20,
            'page'     => 1,
            'search'   => '',
            'status'   => '',
            'type'     => '',
            'group_id' => 0,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $table = self::get_table_name( 'redirects' );

        // -- Build WHERE clauses -----------------------------------------------
        $where   = array();
        $values  = array();

        if ( '' !== $args['search'] ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(source_url LIKE %s OR target_url LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        if ( 'active' === $args['status'] ) {
            $where[] = 'is_active = 1';
        } elseif ( 'inactive' === $args['status'] ) {
            $where[] = 'is_active = 0';
        }

        if ( '' !== $args['type'] ) {
            $where[]  = 'source_type = %s';
            $values[] = sanitize_text_field( $args['type'] );
        }

        if ( ! empty( $args['group_id'] ) ) {
            $where[]  = 'group_id = %d';
            $values[] = absint( $args['group_id'] );
        }

        $where_sql = '';
        if ( ! empty( $where ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where );
        }

        // -- Sanitize ORDER BY -------------------------------------------------
        $allowed_columns = array(
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

        $orderby = in_array( $args['orderby'], $allowed_columns, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // -- Count total -------------------------------------------------------
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // -- Fetch items -------------------------------------------------------
        $per_page = absint( $args['per_page'] );
        $page     = absint( $args['page'] );

        if ( $per_page < 1 ) {
            $per_page = 20;
        }
        if ( $page < 1 ) {
            $page = 1;
        }

        $offset = ( $page - 1 ) * $per_page;

        $query = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_values   = array_merge( $values, array( $per_page, $offset ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = $wpdb->get_results( $wpdb->prepare( $query, $query_values ) );

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }

    /**
     * Retrieve a single redirect by its ID, including its conditions.
     *
     * @param int $id Redirect ID.
     * @return object|null Redirect row with an added `conditions` property, or null.
     */
    public static function get_redirect( $id ) {
        global $wpdb;

        $id    = absint( $id );
        $table = self::get_table_name( 'redirects' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $redirect = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        if ( ! $redirect ) {
            return null;
        }

        $conditions_table = self::get_table_name( 'conditions' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $conditions = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$conditions_table} WHERE redirect_id = %d", $id )
        );

        $redirect->conditions = $conditions ? $conditions : array();

        return $redirect;
    }

    /**
     * Insert or update a redirect.
     *
     * If the `$data` array contains an `id` key the row is updated; otherwise a
     * new row is inserted. The object cache is invalidated automatically.
     *
     * @param array $data Redirect data. Accepted keys mirror the column names of
     *                    the redirects table plus an optional `conditions` array.
     * @return int|false The redirect ID on success, false on failure.
     */
    public static function save_redirect( $data ) {
        global $wpdb;

        $table = self::get_table_name( 'redirects' );

        // Sanitize incoming data. target_url: Pfade (/) mit normalize_url, absolute URLs mit esc_url_raw.
        $target_raw = isset( $data['target_url'] ) ? trim( (string) $data['target_url'] ) : '';
        $target_url = '';
        if ( '' !== $target_raw ) {
            $target_url = ( 0 === strpos( $target_raw, '/' ) )
                ? self::normalize_url( $target_raw )
                : esc_url_raw( $target_raw );
        }

        $row = array(
            'source_url'  => isset( $data['source_url'] )  ? self::normalize_url( $data['source_url'] ) : '',
            'target_url'  => $target_url,
            'status_code' => isset( $data['status_code'] )  ? absint( $data['status_code'] ) : 301,
            'is_regex'    => isset( $data['is_regex'] )     ? ( $data['is_regex'] ? 1 : 0 ) : 0,
            'is_active'   => isset( $data['is_active'] )    ? ( $data['is_active'] ? 1 : 0 ) : 1,
            'source_type' => isset( $data['source_type'] )  ? sanitize_text_field( $data['source_type'] ) : 'manual',
            'post_id'     => isset( $data['post_id'] )      ? absint( $data['post_id'] ) : null,
            'group_id'    => isset( $data['group_id'] )     ? absint( $data['group_id'] ) : null,
            'notes'       => isset( $data['notes'] )        ? sanitize_textarea_field( $data['notes'] ) : null,
            'expires_at'  => isset( $data['expires_at'] )   ? sanitize_text_field( $data['expires_at'] ) : null,
        );

        // Validate required fields.
        if ( empty( $row['source_url'] ) || empty( $row['target_url'] ) ) {
            return false;
        }

        $format = array(
            '%s', // source_url
            '%s', // target_url
            '%d', // status_code
            '%d', // is_regex
            '%d', // is_active
            '%s', // source_type
            '%d', // post_id
            '%d', // group_id
            '%s', // notes
            '%s', // expires_at
        );

        // Remove null values so that $wpdb doesn't insert the literal string "null".
        foreach ( $row as $key => $value ) {
            if ( is_null( $value ) ) {
                unset( $row[ $key ] );
                // Re-index format to keep it in sync — rebuild after the loop.
            }
        }

        // Rebuild format to match remaining keys.
        $format = array();
        foreach ( $row as $key => $value ) {
            if ( in_array( $key, array( 'status_code', 'is_regex', 'is_active', 'post_id', 'group_id' ), true ) ) {
                $format[] = '%d';
            } else {
                $format[] = '%s';
            }
        }

        // Determine insert vs. update.
        if ( ! empty( $data['id'] ) ) {
            // Update existing redirect.
            $id     = absint( $data['id'] );
            $result = $wpdb->update( $table, $row, array( 'id' => $id ), $format, array( '%d' ) );

            if ( false === $result ) {
                return false;
            }
        } else {
            // Insert new redirect.
            $result = $wpdb->insert( $table, $row, $format );

            if ( false === $result ) {
                return false;
            }

            $id = (int) $wpdb->insert_id;
        }

        // Save conditions if provided.
        if ( isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
            self::save_redirect_conditions( $id, $data['conditions'] );
        }

        self::invalidate_cache();

        return $id;
    }

    /**
     * Replace all conditions for a given redirect.
     *
     * @param int   $redirect_id The redirect ID.
     * @param array $conditions  Array of condition arrays with keys:
     *                           condition_type, condition_operator, condition_value.
     * @return void
     */
    private static function save_redirect_conditions( $redirect_id, $conditions ) {
        global $wpdb;

        $table       = self::get_table_name( 'conditions' );
        $redirect_id = absint( $redirect_id );

        // Remove existing conditions for this redirect.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->delete( $table, array( 'redirect_id' => $redirect_id ), array( '%d' ) );

        foreach ( $conditions as $condition ) {
            if ( empty( $condition['condition_type'] ) || empty( $condition['condition_value'] ) ) {
                continue;
            }

            $wpdb->insert(
                $table,
                array(
                    'redirect_id'        => $redirect_id,
                    'condition_type'     => sanitize_text_field( $condition['condition_type'] ),
                    'condition_operator' => isset( $condition['condition_operator'] )
                        ? sanitize_text_field( $condition['condition_operator'] )
                        : 'equals',
                    'condition_value'    => sanitize_text_field( $condition['condition_value'] ),
                ),
                array( '%d', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * Delete a redirect and its associated conditions.
     *
     * @param int $id Redirect ID.
     * @return bool True on success, false on failure.
     */
    public static function delete_redirect( $id ) {
        global $wpdb;

        $id = absint( $id );

        if ( ! $id ) {
            return false;
        }

        $redirects_table  = self::get_table_name( 'redirects' );
        $conditions_table = self::get_table_name( 'conditions' );

        // Delete conditions first.
        $wpdb->delete( $conditions_table, array( 'redirect_id' => $id ), array( '%d' ) );

        // Delete the redirect itself.
        $result = $wpdb->delete( $redirects_table, array( 'id' => $id ), array( '%d' ) );

        self::invalidate_cache();

        return false !== $result;
    }

    // -------------------------------------------------------------------------
    // Active Redirects (cached)
    // -------------------------------------------------------------------------

    /**
     * Retrieve all active, non-expired redirects.
     *
     * Results are stored in the WordPress object cache to avoid repeated
     * database queries during a single page load or across persistent cache
     * backends.
     *
     * @return array Array of redirect row objects.
     */
    public static function get_active_redirects() {
        $cached = wp_cache_get( self::ACTIVE_REDIRECTS_CACHE_KEY, self::CACHE_GROUP );

        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $table = self::get_table_name( 'redirects' );
        $now   = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_active = 1 AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY is_regex ASC, id ASC",
                $now
            )
        );

        $redirects = $results ? $results : array();

        $ttl = (int) self::get_setting( 'redirect_cache_ttl' );
        if ( $ttl < 1 ) {
            $ttl = 3600;
        }

        wp_cache_set( self::ACTIVE_REDIRECTS_CACHE_KEY, $redirects, self::CACHE_GROUP, $ttl );

        return $redirects;
    }

    // -------------------------------------------------------------------------
    // Cache Helpers
    // -------------------------------------------------------------------------

    /**
     * Invalidate the active-redirects object cache entry.
     *
     * Should be called whenever redirect data is created, updated, or deleted.
     *
     * @return void
     */
    public static function invalidate_cache() {
        wp_cache_delete( self::ACTIVE_REDIRECTS_CACHE_KEY, self::CACHE_GROUP );
    }

    // -------------------------------------------------------------------------
    // Table Name Helper
    // -------------------------------------------------------------------------

    /**
     * Build a fully-prefixed table name.
     *
     * @param string $table Short table identifier (e.g. 'redirects', '404_log').
     * @return string The full table name including the WordPress table prefix.
     */
    public static function get_table_name( $table ) {
        global $wpdb;

        return $wpdb->prefix . 'srm_' . $table;
    }
}
