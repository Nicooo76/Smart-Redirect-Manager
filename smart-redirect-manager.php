<?php
/**
 * Plugin Name: Smart Redirect Manager
 * Description: Automatische URL-Überwachung mit Redirects, 404-Logging, Conditional Redirects, Hit-Tracking, WP-CLI und vielem mehr.
 * Version: 1.0.0
 * Author: Smart Redirect Manager
 * Text Domain: smart-redirect-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SRM_VERSION', '1.0.0');
define('SRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SRM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SRM_PLUGIN_FILE', __FILE__);
define('SRM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Core includes
require_once SRM_PLUGIN_DIR . 'includes/class-database.php';
require_once SRM_PLUGIN_DIR . 'includes/class-redirect-handler.php';
require_once SRM_PLUGIN_DIR . 'includes/class-url-monitor.php';
require_once SRM_PLUGIN_DIR . 'includes/class-404-logger.php';
require_once SRM_PLUGIN_DIR . 'includes/class-statistics.php';
require_once SRM_PLUGIN_DIR . 'includes/class-groups.php';
require_once SRM_PLUGIN_DIR . 'includes/class-conditions.php';
require_once SRM_PLUGIN_DIR . 'includes/class-tools.php';
require_once SRM_PLUGIN_DIR . 'includes/class-notifications.php';
require_once SRM_PLUGIN_DIR . 'includes/class-auto-gone.php';
require_once SRM_PLUGIN_DIR . 'includes/class-auto-cleanup.php';
require_once SRM_PLUGIN_DIR . 'includes/class-import-export.php';
require_once SRM_PLUGIN_DIR . 'includes/class-migration.php';
require_once SRM_PLUGIN_DIR . 'includes/class-rest-api.php';

// Admin includes
if (is_admin()) {
    require_once SRM_PLUGIN_DIR . 'includes/class-admin.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-redirects.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-404-log.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-settings.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-tools.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-import-export.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-admin-migration.php';
    require_once SRM_PLUGIN_DIR . 'includes/class-dashboard-widget.php';
}

// WP-CLI
if (defined('WP_CLI') && WP_CLI) {
    require_once SRM_PLUGIN_DIR . 'includes/class-wp-cli.php';
}

// Activation / Deactivation
register_activation_hook(__FILE__, array('SRM_Database', 'activate'));
register_deactivation_hook(__FILE__, array('SRM_Database', 'deactivate'));

// Initialize
function srm_init() {
    SRM_Redirect_Handler::init();
    SRM_URL_Monitor::init();
    SRM_404_Logger::init();
    SRM_Statistics::init();
    SRM_Auto_Gone::init();
    SRM_Auto_Cleanup::init();
    SRM_Notifications::init();
    SRM_REST_API::init();

    if (is_admin()) {
        SRM_Admin::init();
        SRM_Dashboard_Widget::init();
    }
}
add_action('plugins_loaded', 'srm_init');
