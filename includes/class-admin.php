<?php
/**
 * Admin controller for Smart Redirect Manager.
 *
 * Registers the admin menu, enqueues assets on plugin pages, and provides
 * all AJAX handlers consumed by the admin JavaScript.
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
 * Class SRM_Admin
 *
 * Central admin bootstrap: menu registration, asset loading, and AJAX routing.
 */
class SRM_Admin {

    /**
     * Wire up all admin hooks.
     *
     * @return void
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_powered_by' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_srm_save_redirect', array( __CLASS__, 'ajax_save_redirect' ) );
        add_action( 'wp_ajax_srm_delete_redirect', array( __CLASS__, 'ajax_delete_redirect' ) );
        add_action( 'wp_ajax_srm_toggle_redirect', array( __CLASS__, 'ajax_toggle_redirect' ) );
        add_action( 'wp_ajax_srm_create_redirect_from_404', array( __CLASS__, 'ajax_create_redirect_from_404' ) );
        add_action( 'wp_ajax_srm_resolve_404', array( __CLASS__, 'ajax_resolve_404' ) );
        add_action( 'wp_ajax_srm_delete_404', array( __CLASS__, 'ajax_delete_404' ) );
        add_action( 'wp_ajax_srm_bulk_action', array( __CLASS__, 'ajax_bulk_action' ) );
        add_action( 'wp_ajax_srm_test_regex', array( __CLASS__, 'ajax_test_regex' ) );
    }

    // -------------------------------------------------------------------------
    // Menu Registration
    // -------------------------------------------------------------------------

    /**
     * Register the top-level menu and all submenu pages.
     *
     * @return void
     */
    public static function register_menu() {

        // Main menu page.
        add_menu_page(
            __( 'Redirects', 'smart-redirect-manager' ),
            __( 'Redirects', 'smart-redirect-manager' ),
            'manage_options',
            'srm-redirects',
            array( 'SRM_Admin_Redirects', 'render' ),
            'dashicons-randomize',
            80
        );

        // Submenu: Alle Redirects (mirrors the main page).
        add_submenu_page(
            'srm-redirects',
            __( 'Alle Redirects', 'smart-redirect-manager' ),
            __( 'Alle Redirects', 'smart-redirect-manager' ),
            'manage_options',
            'srm-redirects',
            array( 'SRM_Admin_Redirects', 'render' )
        );

        // Submenu: 404-Fehler.
        add_submenu_page(
            'srm-redirects',
            __( '404-Fehler', 'smart-redirect-manager' ),
            __( '404-Fehler', 'smart-redirect-manager' ),
            'manage_options',
            'srm-404-log',
            array( 'SRM_Admin_404_Log', 'render' )
        );

        // Submenu: Import / Export.
        add_submenu_page(
            'srm-redirects',
            __( 'Import / Export', 'smart-redirect-manager' ),
            __( 'Import / Export', 'smart-redirect-manager' ),
            'manage_options',
            'srm-import-export',
            array( 'SRM_Admin_Import_Export', 'render' )
        );

        // Submenu: Tools.
        add_submenu_page(
            'srm-redirects',
            __( 'Tools', 'smart-redirect-manager' ),
            __( 'Tools', 'smart-redirect-manager' ),
            'manage_options',
            'srm-tools',
            array( 'SRM_Admin_Tools', 'render' )
        );

        // Submenu: Einstellungen.
        add_submenu_page(
            'srm-redirects',
            __( 'Einstellungen', 'smart-redirect-manager' ),
            __( 'Einstellungen', 'smart-redirect-manager' ),
            'manage_options',
            'srm-settings',
            array( 'SRM_Admin_Settings', 'render' )
        );

        // Conditional submenu: Migration (only when Redirection plugin is present).
        if ( class_exists( 'SRM_Migration' ) && SRM_Migration::is_redirection_installed() ) {
            add_submenu_page(
                'srm-redirects',
                __( 'Migration', 'smart-redirect-manager' ),
                __( 'Migration', 'smart-redirect-manager' ),
                'manage_options',
                'srm-migration',
                array( 'SRM_Admin_Migration', 'render' )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Asset Enqueuing
    // -------------------------------------------------------------------------

    /**
     * Enqueue admin CSS and JavaScript on plugin pages.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueue_assets( $hook ) {

        // Load assets only on our own pages or on the dashboard (for the widget).
        $is_plugin_page = ( false !== strpos( $hook, 'srm-' ) );
        $is_dashboard   = ( 'index.php' === $hook );

        if ( ! $is_plugin_page && ! $is_dashboard ) {
            return;
        }

        wp_enqueue_style(
            'srm-admin',
            SRM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SRM_VERSION
        );

        wp_enqueue_script(
            'srm-admin',
            SRM_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            SRM_VERSION,
            true
        );

        wp_localize_script( 'srm-admin', 'srm_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'srm_nonce' ),
            'strings'  => array(
                'confirm_delete'     => __( 'Soll dieser Redirect wirklich gelöscht werden?', 'smart-redirect-manager' ),
                'confirm_bulk'       => __( 'Soll die Aktion für die ausgewählten Einträge ausgeführt werden?', 'smart-redirect-manager' ),
                'saved'              => __( 'Gespeichert.', 'smart-redirect-manager' ),
                'deleted'            => __( 'Gelöscht.', 'smart-redirect-manager' ),
                'error'              => __( 'Ein Fehler ist aufgetreten.', 'smart-redirect-manager' ),
                'no_selection'       => __( 'Bitte mindestens einen Eintrag auswählen.', 'smart-redirect-manager' ),
                'regex_match'        => __( 'Treffer!', 'smart-redirect-manager' ),
                'regex_no_match'     => __( 'Kein Treffer.', 'smart-redirect-manager' ),
                'regex_invalid'      => __( 'Ungültiger regulärer Ausdruck.', 'smart-redirect-manager' ),
                'redirect_created'   => __( 'Redirect erstellt.', 'smart-redirect-manager' ),
                'resolved'           => __( 'Als gelöst markiert.', 'smart-redirect-manager' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Powered by (Footer Branding)
    // -------------------------------------------------------------------------

    /**
     * Output "Powered by" footer box on plugin pages only (bottom of page, not in menu).
     *
     * @return void
     */
    public static function render_powered_by() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( $screen->id, 'srm-' ) === false ) {
            return;
        }
        $home_url = 'https://gauditz.com';
        $github_url = 'https://github.com/Nicooo76';
        ?>
        <div class="srm-powered-by" id="srm-powered-by">
            <div class="srm-powered-by__box">
                <p class="srm-powered-by__line">Mit ❤️ erstellt von <strong>Sven Gauditz</strong></p>
                <p class="srm-powered-by__links">
                    <a href="<?php echo esc_url( $home_url ); ?>" target="_blank" rel="noopener noreferrer" class="srm-powered-by__link"><?php esc_html_e( 'Homepage', 'smart-redirect-manager' ); ?></a>
                    <span class="srm-powered-by__sep">/</span>
                    <a href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener noreferrer" class="srm-powered-by__link srm-powered-by__link--github" aria-label="GitHub">
                        <svg class="srm-powered-by__icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                        <span>GitHub</span>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: Save Redirect
    // -------------------------------------------------------------------------

    /**
     * Save (create or update) a redirect via AJAX.
     *
     * @return void
     */
    public static function ajax_save_redirect() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $data = array(
            'source_url'  => isset( $_POST['source_url'] )  ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) )  : '',
            'target_url'  => isset( $_POST['target_url'] )  ? esc_url_raw( wp_unslash( $_POST['target_url'] ) )          : '',
            'status_code' => isset( $_POST['status_code'] )  ? absint( $_POST['status_code'] )                            : 301,
            'is_regex'    => isset( $_POST['is_regex'] )     ? (bool) $_POST['is_regex']                                  : false,
            'is_active'   => isset( $_POST['is_active'] )    ? (bool) $_POST['is_active']                                 : false,
            'source_type' => isset( $_POST['source_type'] )  ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : 'manual',
            'group_id'    => isset( $_POST['group_id'] )     ? absint( $_POST['group_id'] )                               : 0,
            'notes'       => isset( $_POST['notes'] )        ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) )   : '',
            'expires_at'  => isset( $_POST['expires_at'] )   ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) )  : '',
        );

        // Include ID for updates (form sends redirect_id).
        if ( ! empty( $_POST['id'] ) ) {
            $data['id'] = absint( $_POST['id'] );
        } elseif ( ! empty( $_POST['redirect_id'] ) ) {
            $data['id'] = absint( $_POST['redirect_id'] );
        }

        // Include conditions if provided.
        if ( isset( $_POST['conditions'] ) && is_array( $_POST['conditions'] ) ) {
            $data['conditions'] = array_map( function ( $condition ) {
                return array(
                    'condition_type'     => isset( $condition['condition_type'] )     ? sanitize_text_field( $condition['condition_type'] )     : '',
                    'condition_operator' => isset( $condition['condition_operator'] ) ? sanitize_text_field( $condition['condition_operator'] ) : 'equals',
                    'condition_value'    => isset( $condition['condition_value'] )    ? sanitize_text_field( $condition['condition_value'] )    : '',
                );
            }, $_POST['conditions'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }

        $redirect_id = SRM_Database::save_redirect( $data );

        if ( false === $redirect_id ) {
            wp_send_json_error( array( 'message' => __( 'Redirect konnte nicht gespeichert werden.', 'smart-redirect-manager' ) ) );
        }

        $redirect = SRM_Database::get_redirect( $redirect_id );

        wp_send_json_success( array(
            'message'  => __( 'Redirect gespeichert.', 'smart-redirect-manager' ),
            'redirect' => $redirect,
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Delete Redirect
    // -------------------------------------------------------------------------

    /**
     * Delete a single redirect via AJAX.
     *
     * @return void
     */
    public static function ajax_delete_redirect() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Ungültige ID.', 'smart-redirect-manager' ) ) );
        }

        $result = SRM_Database::delete_redirect( $id );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Redirect konnte nicht gelöscht werden.', 'smart-redirect-manager' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Redirect gelöscht.', 'smart-redirect-manager' ) ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Toggle Redirect Active State
    // -------------------------------------------------------------------------

    /**
     * Toggle the active/inactive state of a redirect via AJAX.
     *
     * @return void
     */
    public static function ajax_toggle_redirect() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $id        = isset( $_POST['id'] )        ? absint( $_POST['id'] )        : 0;
        $is_active = isset( $_POST['is_active'] )  ? (bool) $_POST['is_active']   : false;

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Ungültige ID.', 'smart-redirect-manager' ) ) );
        }

        global $wpdb;

        $table  = SRM_Database::get_table_name( 'redirects' );
        $result = $wpdb->update(
            $table,
            array( 'is_active' => $is_active ? 1 : 0 ),
            array( 'id' => $id ),
            array( '%d' ),
            array( '%d' )
        );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( 'Status konnte nicht geändert werden.', 'smart-redirect-manager' ) ) );
        }

        SRM_Database::invalidate_cache();

        wp_send_json_success( array(
            'message'   => __( 'Status aktualisiert.', 'smart-redirect-manager' ),
            'is_active' => $is_active,
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Create Redirect from 404 Entry
    // -------------------------------------------------------------------------

    /**
     * Create a new redirect from an existing 404 log entry and mark it resolved.
     *
     * @return void
     */
    public static function ajax_create_redirect_from_404() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $entry_id   = isset( $_POST['404_id'] )     ? absint( $_POST['404_id'] )                                     : 0;
        $target_url = isset( $_POST['target_url'] )  ? esc_url_raw( wp_unslash( $_POST['target_url'] ) )              : '';

        if ( ! $entry_id || empty( $target_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Fehlende Parameter.', 'smart-redirect-manager' ) ) );
        }

        // Retrieve the 404 log entry to get the source URL.
        global $wpdb;

        $log_table = SRM_Database::get_table_name( '404_log' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$log_table} WHERE id = %d", $entry_id ) );

        if ( ! $entry ) {
            wp_send_json_error( array( 'message' => __( '404-Eintrag nicht gefunden.', 'smart-redirect-manager' ) ) );
        }

        // Create the redirect.
        $redirect_id = SRM_Database::save_redirect( array(
            'source_url'  => $entry->request_url,
            'target_url'  => $target_url,
            'status_code' => 301,
            'source_type' => 'from_404',
            'is_active'   => true,
        ) );

        if ( false === $redirect_id ) {
            wp_send_json_error( array( 'message' => __( 'Redirect konnte nicht erstellt werden.', 'smart-redirect-manager' ) ) );
        }

        // Mark the 404 entry as resolved.
        SRM_404_Logger::resolve_404( $entry_id, $redirect_id );

        wp_send_json_success( array(
            'message'     => __( 'Redirect erstellt und 404 als gelöst markiert.', 'smart-redirect-manager' ),
            'redirect_id' => $redirect_id,
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Resolve 404
    // -------------------------------------------------------------------------

    /**
     * Mark a 404 log entry as resolved via AJAX.
     *
     * @return void
     */
    public static function ajax_resolve_404() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Ungültige ID.', 'smart-redirect-manager' ) ) );
        }

        $result = SRM_404_Logger::resolve_404( $id );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( '404-Eintrag konnte nicht aufgelöst werden.', 'smart-redirect-manager' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Als gelöst markiert.', 'smart-redirect-manager' ) ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Delete 404
    // -------------------------------------------------------------------------

    /**
     * Delete a 404 log entry via AJAX.
     *
     * @return void
     */
    public static function ajax_delete_404() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Ungültige ID.', 'smart-redirect-manager' ) ) );
        }

        $result = SRM_404_Logger::delete_404( $id );

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( '404-Eintrag konnte nicht gelöscht werden.', 'smart-redirect-manager' ) ) );
        }

        wp_send_json_success( array( 'message' => __( '404-Eintrag gelöscht.', 'smart-redirect-manager' ) ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Bulk Action
    // -------------------------------------------------------------------------

    /**
     * Execute a bulk action on multiple redirects via AJAX.
     *
     * Supported actions: activate, deactivate, delete.
     *
     * @return void
     */
    public static function ajax_bulk_action() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();

        if ( empty( $action ) || empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Fehlende Parameter.', 'smart-redirect-manager' ) ) );
        }

        // Remove any zero values after absint conversion.
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine gültigen IDs.', 'smart-redirect-manager' ) ) );
        }

        global $wpdb;

        $table = SRM_Database::get_table_name( 'redirects' );
        $count = 0;

        switch ( $action ) {

            case 'activate':
                foreach ( $ids as $id ) {
                    $result = $wpdb->update( $table, array( 'is_active' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
                    if ( false !== $result ) {
                        $count++;
                    }
                }
                SRM_Database::invalidate_cache();
                break;

            case 'deactivate':
                foreach ( $ids as $id ) {
                    $result = $wpdb->update( $table, array( 'is_active' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
                    if ( false !== $result ) {
                        $count++;
                    }
                }
                SRM_Database::invalidate_cache();
                break;

            case 'delete':
                foreach ( $ids as $id ) {
                    $result = SRM_Database::delete_redirect( $id );
                    if ( $result ) {
                        $count++;
                    }
                }
                break;

            default:
                wp_send_json_error( array( 'message' => __( 'Unbekannte Aktion.', 'smart-redirect-manager' ) ) );
                break;
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of affected redirects */
                __( '%d Einträge aktualisiert.', 'smart-redirect-manager' ),
                $count
            ),
            'count' => $count,
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: Test Regex
    // -------------------------------------------------------------------------

    /**
     * Test a regular expression pattern against a URL via AJAX.
     *
     * Returns whether the pattern matches and any captured groups.
     *
     * @return void
     */
    public static function ajax_test_regex() {
        check_ajax_referer( 'srm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'smart-redirect-manager' ) ) );
        }

        $pattern  = isset( $_POST['pattern'] )  ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) )  : '';
        $test_url = isset( $_POST['test_url'] )  ? sanitize_text_field( wp_unslash( $_POST['test_url'] ) ) : '';

        if ( empty( $pattern ) || empty( $test_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Fehlende Parameter.', 'smart-redirect-manager' ) ) );
        }

        // Suppress errors from invalid patterns.
        $matches = array();
        $result  = @preg_match( $pattern, $test_url, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false === $result ) {
            wp_send_json_error( array(
                'message' => __( 'Ungültiger regulärer Ausdruck.', 'smart-redirect-manager' ),
                'match'   => false,
            ) );
        }

        wp_send_json_success( array(
            'match'   => ( $result === 1 ),
            'groups'  => $matches,
            'message' => ( $result === 1 )
                ? __( 'Treffer!', 'smart-redirect-manager' )
                : __( 'Kein Treffer.', 'smart-redirect-manager' ),
        ) );
    }
}
