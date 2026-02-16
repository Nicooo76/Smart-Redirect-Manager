<?php
/**
 * Notifications
 *
 * Email digest and 404-spike alerts for Smart Redirect Manager.
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
 * Class SRM_Notifications
 *
 * Sends scheduled email digests with redirect statistics and
 * real-time alerts when 404 error spikes are detected.
 */
class SRM_Notifications {

	/**
	 * Initialize notification hooks and cron schedules.
	 *
	 * @return void
	 */
	public static function init() {

		$settings              = get_option( 'srm_settings', array() );
		$notifications_enabled = isset( $settings['notifications_enabled'] ) ? (bool) $settings['notifications_enabled'] : false;

		if ( $notifications_enabled ) {
			self::schedule_events();
		}

		add_action( 'srm_send_digest', array( __CLASS__, 'send_digest' ) );
		add_action( 'srm_check_404_spike', array( __CLASS__, 'check_404_spike' ) );
	}

	/**
	 * Schedule cron events for digest emails and 404 spike checks.
	 *
	 * @return void
	 */
	public static function schedule_events() {

		$settings  = get_option( 'srm_settings', array() );
		$frequency = isset( $settings['notification_frequency'] ) ? $settings['notification_frequency'] : 'daily';

		// Schedule the digest email based on the configured frequency.
		if ( ! wp_next_scheduled( 'srm_send_digest' ) ) {
			$recurrence = ( 'weekly' === $frequency ) ? 'weekly' : 'daily';
			wp_schedule_event( time(), $recurrence, 'srm_send_digest' );
		}

		// Schedule the 404 spike check to run hourly.
		if ( ! wp_next_scheduled( 'srm_check_404_spike' ) ) {
			wp_schedule_event( time(), 'hourly', 'srm_check_404_spike' );
		}
	}

	/**
	 * Send the digest email with redirect statistics.
	 *
	 * Collects an overview of recent activity and sends an HTML email
	 * to the configured notification recipient.
	 *
	 * @return void
	 */
	public static function send_digest() {

		$settings              = get_option( 'srm_settings', array() );
		$notifications_enabled = isset( $settings['notifications_enabled'] ) ? (bool) $settings['notifications_enabled'] : false;

		if ( ! $notifications_enabled ) {
			return;
		}

		// Gather statistics.
		$stats = SRM_Statistics::get_overview();

		$hits_today  = isset( $stats['hits_today'] ) ? (int) $stats['hits_today'] : 0;
		$new_404     = isset( $stats['open_404'] ) ? (int) $stats['open_404'] : 0;

		// Skip email when there is no activity to report.
		if ( 0 === $hits_today && 0 === $new_404 ) {
			return;
		}

		$active_redirects = isset( $stats['active_redirects'] ) ? (int) $stats['active_redirects'] : 0;
		$top_404          = isset( $stats['top_404'] ) ? $stats['top_404'] : array();

		// -----------------------------------------------------------------
		// Build HTML email body.
		// -----------------------------------------------------------------
		$site_name = get_bloginfo( 'name' );

		$html  = '<html><body>';
		$html .= '<h2>' . esc_html( $site_name ) . ' &ndash; Smart Redirect Manager</h2>';

		$html .= '<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;">';
		$html .= '<tr><td><strong>Aktive Redirects</strong></td><td>' . esc_html( $active_redirects ) . '</td></tr>';
		$html .= '<tr><td><strong>Hits im Zeitraum</strong></td><td>' . esc_html( $hits_today ) . '</td></tr>';
		$html .= '<tr><td><strong>Neue 404-Fehler</strong></td><td>' . esc_html( $new_404 ) . '</td></tr>';
		$html .= '</table>';

		// Top 5 404 URLs table.
		if ( ! empty( $top_404 ) ) {
			$html .= '<h3>Top 5 404-URLs</h3>';
			$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;">';
			$html .= '<tr><th style="text-align:left;">URL</th><th style="text-align:right;">Anzahl</th></tr>';

			foreach ( array_slice( $top_404, 0, 5 ) as $entry ) {
				$url   = isset( $entry->request_url ) ? $entry->request_url : ( isset( $entry->url ) ? $entry->url : '' );
				$count = isset( $entry->count ) ? (int) $entry->count : 0;
				$html .= '<tr>';
				$html .= '<td>' . esc_html( $url ) . '</td>';
				$html .= '<td style="text-align:right;">' . esc_html( $count ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</table>';
		}

		// Redirect loop warning.
		if ( class_exists( 'SRM_Tools' ) && method_exists( 'SRM_Tools', 'find_loops' ) ) {
			$loops = SRM_Tools::find_loops();

			if ( ! empty( $loops ) ) {
				$html .= '<h3 style="color:#d63638;">Warnung: Redirect-Loops erkannt</h3>';
				$html .= '<ul>';
				foreach ( $loops as $loop ) {
					$html .= '<li>' . esc_html( is_array( $loop ) ? implode( ' &rarr; ', $loop ) : $loop ) . '</li>';
				}
				$html .= '</ul>';
			}
		}

		// Auto-Cleanup result.
		$cleanup_result = get_transient( 'srm_last_cleanup_result' );

		if ( ! empty( $cleanup_result ) ) {
			$html .= '<h3>Auto-Cleanup Ergebnis</h3>';
			$html .= '<p>' . esc_html( $cleanup_result ) . '</p>';
		}

		$html .= '<p style="color:#888;font-size:12px;">Diese E-Mail wurde automatisch von Smart Redirect Manager versendet.</p>';
		$html .= '</body></html>';

		// -----------------------------------------------------------------
		// Send the email.
		// -----------------------------------------------------------------
		$to      = self::get_email();
		$date    = wp_date( 'd.m.Y' );
		$subject = '[Smart Redirect Manager] Zusammenfassung - ' . $date;

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
		wp_mail( $to, $subject, $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
	}

	/**
	 * Check for a 404 error spike and send an alert email if needed.
	 *
	 * Rate-limited to one alert every 4 hours via a transient.
	 *
	 * @return void
	 */
	public static function check_404_spike() {

		$settings              = get_option( 'srm_settings', array() );
		$notifications_enabled = isset( $settings['notifications_enabled'] ) ? (bool) $settings['notifications_enabled'] : false;

		if ( ! $notifications_enabled ) {
			return;
		}

		// Rate limiting: only send one alert every 4 hours.
		if ( get_transient( 'srm_404_spike_sent' ) ) {
			return;
		}

		$threshold = isset( $settings['404_spike_threshold'] ) ? (int) $settings['404_spike_threshold'] : 50;
		$count     = SRM_404_Logger::get_404_count_last_hour();

		if ( $count < $threshold ) {
			return;
		}

		// -----------------------------------------------------------------
		// Get top 10 recent 404 URLs from the last hour.
		// -----------------------------------------------------------------
		global $wpdb;

		$table_name = $wpdb->prefix . 'srm_404_log';

		$recent_404s = $wpdb->get_results(
			"SELECT request_url, count FROM {$table_name} WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY count DESC LIMIT 10" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// -----------------------------------------------------------------
		// Build alert email.
		// -----------------------------------------------------------------
		$site_name = get_bloginfo( 'name' );

		$html  = '<html><body>';
		$html .= '<h2 style="color:#d63638;">' . esc_html( $site_name ) . ' &ndash; 404-Spike erkannt!</h2>';
		$html .= '<p><strong>' . esc_html( $count ) . '</strong> 404-Fehler in der letzten Stunde (Schwellenwert: ' . esc_html( $threshold ) . ').</p>';

		if ( ! empty( $recent_404s ) ) {
			$html .= '<h3>Top 404-URLs (letzte Stunde)</h3>';
			$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;">';
			$html .= '<tr><th style="text-align:left;">URL</th><th style="text-align:right;">Anzahl</th></tr>';

			foreach ( $recent_404s as $entry ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( $entry->request_url ) . '</td>';
				$html .= '<td style="text-align:right;">' . esc_html( (int) $entry->count ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</table>';
		}

		$html .= '<p style="color:#888;font-size:12px;">Diese E-Mail wurde automatisch von Smart Redirect Manager versendet. Die n&auml;chste Warnung wird fr&uuml;hestens in 4 Stunden gesendet.</p>';
		$html .= '</body></html>';

		$to      = self::get_email();
		$subject = '[Smart Redirect Manager] WARNUNG: 404-Spike erkannt (' . $count . ' Fehler/Stunde)';

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
		wp_mail( $to, $subject, $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );

		// Prevent further alerts for 4 hours.
		set_transient( 'srm_404_spike_sent', 1, 14400 );
	}

	/**
	 * Get the notification email address.
	 *
	 * Returns the configured notification_email setting or falls back
	 * to the site admin email address.
	 *
	 * @return string Email address.
	 */
	public static function get_email() {

		$settings = get_option( 'srm_settings', array() );
		$email    = isset( $settings['notification_email'] ) ? trim( $settings['notification_email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			$email = get_option( 'admin_email' );
		}

		return $email;
	}

	/**
	 * Set the content type for wp_mail to HTML.
	 *
	 * Used as a filter callback for wp_mail_content_type.
	 *
	 * @return string The HTML content type.
	 */
	public static function set_html_content_type() {
		return 'text/html';
	}
}
