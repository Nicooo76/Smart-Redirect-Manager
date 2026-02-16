<?php
/**
 * Dashboard Widget
 *
 * WordPress-Dashboard-Widget fuer Smart Redirect Manager.
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
 * Class SRM_Dashboard_Widget
 *
 * Zeigt eine Uebersicht der Redirect-Statistiken und
 * 404-Fehler direkt im WordPress-Dashboard an.
 */
class SRM_Dashboard_Widget {

	/**
	 * Initialisierung.
	 *
	 * Registriert den Hook fuer das Dashboard-Setup.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Widget registrieren.
	 *
	 * Fuegt das Dashboard-Widget hinzu.
	 *
	 * @return void
	 */
	public static function register_widget() {
		wp_add_dashboard_widget(
			'srm_dashboard_widget',
			'Smart Redirect Manager',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Widget rendern.
	 *
	 * Gibt die Statistik-Uebersicht im Dashboard aus.
	 *
	 * @return void
	 */
	public static function render() {

		$data = SRM_Statistics::get_overview();

		$active_redirects = isset( $data['active_redirects'] ) ? (int) $data['active_redirects'] : 0;
		$hits_today       = isset( $data['hits_today'] ) ? (int) $data['hits_today'] : 0;
		$hits_7_days      = isset( $data['hits_7_days'] ) ? (int) $data['hits_7_days'] : 0;
		$open_404         = isset( $data['open_404'] ) ? (int) $data['open_404'] : 0;
		$top_404          = isset( $data['top_404'] ) ? $data['top_404'] : array();

		$open_404_color = $open_404 > 0 ? 'srm-color-red' : 'srm-color-green';

		?>
		<style>
			.srm-widget-grid {
				display: grid;
				grid-template-columns: repeat(4, 1fr);
				gap: 12px;
			}
			.srm-widget-card {
				text-align: center;
				padding: 12px;
				background: #f0f0f1;
				border-radius: 4px;
			}
			.srm-widget-card .srm-widget-number {
				font-size: 28px;
				font-weight: 700;
				line-height: 1.2;
			}
			.srm-widget-card .srm-widget-label {
				font-size: 12px;
				color: #50575e;
				margin-top: 4px;
			}
			.srm-color-blue {
				color: #2271b1;
			}
			.srm-color-red {
				color: #d63638;
			}
			.srm-color-green {
				color: #00a32a;
			}
			.srm-widget-footer {
				border-top: 1px solid #f0f0f1;
				margin-top: 12px;
				padding-top: 12px;
			}
		</style>

		<div class="srm-widget-grid">
			<div class="srm-widget-card">
				<div class="srm-widget-number srm-color-blue"><?php echo esc_html( number_format_i18n( $active_redirects ) ); ?></div>
				<div class="srm-widget-label">Aktive Redirects</div>
			</div>
			<div class="srm-widget-card">
				<div class="srm-widget-number srm-color-blue"><?php echo esc_html( number_format_i18n( $hits_today ) ); ?></div>
				<div class="srm-widget-label">Hits heute</div>
			</div>
			<div class="srm-widget-card">
				<div class="srm-widget-number srm-color-blue"><?php echo esc_html( number_format_i18n( $hits_7_days ) ); ?></div>
				<div class="srm-widget-label">Hits 7 Tage</div>
			</div>
			<div class="srm-widget-card">
				<div class="srm-widget-number <?php echo esc_attr( $open_404_color ); ?>"><?php echo esc_html( number_format_i18n( $open_404 ) ); ?></div>
				<div class="srm-widget-label">Offene 404</div>
			</div>
		</div>

		<?php if ( ! empty( $top_404 ) ) : ?>
			<h4>Häufigste 404-Fehler</h4>
			<ol>
				<?php foreach ( array_slice( $top_404, 0, 3 ) as $entry ) : ?>
					<li>
						<code><?php echo esc_html( isset( $entry->request_url ) ? $entry->request_url : '' ); ?></code>
						(<?php echo esc_html( number_format_i18n( (int) $entry->count ) ); ?>)
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<div class="srm-widget-footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects' ) ); ?>">Redirects verwalten</a>
			 |
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-404-log' ) ); ?>">404-Log ansehen</a>
		</div>
		<?php
	}
}
