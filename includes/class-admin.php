<?php
/**
 * Admin controller for Smart Redirect Manager.
 *
 * Registers the admin menu, enqueues assets on plugin pages, and provides
 * all AJAX handlers consumed by the admin JavaScript.
 *
 * @package SmartRedirectManager
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
            'is_active'   => isset( $_POST['is_active'] )    ? (bool) $_POST['is_active']                                 : true,
            'source_type' => isset( $_POST['source_type'] )  ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : 'manual',
            'group_id'    => isset( $_POST['group_id'] )     ? absint( $_POST['group_id'] )                               : 0,
            'notes'       => isset( $_POST['notes'] )        ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) )   : '',
            'expires_at'  => isset( $_POST['expires_at'] )   ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) )  : '',
        );

        // Include ID for updates.
        if ( ! empty( $_POST['id'] ) ) {
            $data['id'] = absint( $_POST['id'] );
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
