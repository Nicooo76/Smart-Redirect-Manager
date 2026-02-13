<?php
/**
 * Admin Settings page for Smart Redirect Manager.
 *
 * Renders the plugin settings UI and handles form submission.
 * All user-facing text is in German.
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
 * Class SRM_Admin_Settings
 *
 * Provides the settings page UI with all configurable options for
 * automatic redirects, 404 logging, cleanup, notifications, statistics,
 * performance tuning, and database information.
 */
class SRM_Admin_Settings {

	/**
	 * Render the settings page.
	 *
	 * Handles form submission (save), displays a success notice, and
	 * outputs the full settings form with all sections.
	 *
	 * @return void
	 */
	public static function render() {

		// ----------------------------------------------------------------
		// Handle form submission.
		// ----------------------------------------------------------------
		$saved = false;

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['_wpnonce'] ) ) {

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'srm_settings_nonce' ) ) {
				wp_die( 'Sicherheitspruefung fehlgeschlagen.' );
			}

			$settings = array(
				// Section 1: Automatische Redirects.
				'auto_redirect'              => ! empty( $_POST['auto_redirect'] ),
				'monitor_post_types'         => isset( $_POST['monitor_post_types'] ) && is_array( $_POST['monitor_post_types'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['monitor_post_types'] ) )
					: array(),
				'default_status_code'        => isset( $_POST['default_status_code'] )
					? absint( $_POST['default_status_code'] )
					: 301,

				// Section 2: 404-Fehler Logging.
				'log_404'                    => ! empty( $_POST['log_404'] ),
				'group_404'                  => ! empty( $_POST['group_404'] ),
				'log_retention_days'         => isset( $_POST['log_retention_days'] )
					? absint( $_POST['log_retention_days'] )
					: 30,

				// Section 3: Geloeschte Inhalte.
				'auto_410'                   => ! empty( $_POST['auto_410'] ),

				// Section 4: Automatische Redirect-Bereinigung.
				'auto_cleanup_enabled'       => ! empty( $_POST['auto_cleanup_enabled'] ),
				'auto_cleanup_days'          => isset( $_POST['auto_cleanup_days'] )
					? absint( $_POST['auto_cleanup_days'] )
					: 365,
				'auto_cleanup_min_days_idle' => isset( $_POST['auto_cleanup_min_days_idle'] )
					? absint( $_POST['auto_cleanup_min_days_idle'] )
					: 90,
				'auto_cleanup_action'        => isset( $_POST['auto_cleanup_action'] )
					? sanitize_text_field( wp_unslash( $_POST['auto_cleanup_action'] ) )
					: 'deactivate',
				'auto_cleanup_exclude_types' => isset( $_POST['auto_cleanup_exclude_types'] ) && is_array( $_POST['auto_cleanup_exclude_types'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['auto_cleanup_exclude_types'] ) )
					: array(),

				// Section 5: E-Mail Benachrichtigungen.
				'notifications_enabled'      => ! empty( $_POST['notifications_enabled'] ),
				'notification_email'         => isset( $_POST['notification_email'] )
					? sanitize_email( wp_unslash( $_POST['notification_email'] ) )
					: '',
				'notification_frequency'     => isset( $_POST['notification_frequency'] )
					? sanitize_text_field( wp_unslash( $_POST['notification_frequency'] ) )
					: 'daily',
				'404_spike_threshold'        => isset( $_POST['404_spike_threshold'] )
					? absint( $_POST['404_spike_threshold'] )
					: 50,

				// Section 6: Statistiken.
				'track_hits'                 => ! empty( $_POST['track_hits'] ),

				// Section 7: Performance.
				'redirect_cache_ttl'         => isset( $_POST['redirect_cache_ttl'] )
					? absint( $_POST['redirect_cache_ttl'] )
					: 3600,
				'excluded_paths'             => isset( $_POST['excluded_paths'] )
					? sanitize_textarea_field( wp_unslash( $_POST['excluded_paths'] ) )
					: '',
			);

			SRM_Database::save_settings( $settings );
			$saved = true;
		}

		// ----------------------------------------------------------------
		// Load current settings.
		// ----------------------------------------------------------------
		$settings = SRM_Database::get_settings();

		?>
		<div class="wrap">
			<h1>Einstellungen</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Einstellungen erfolgreich gespeichert.</p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'srm_settings_nonce' ); ?>

				<!-- ============================================================ -->
				<!-- Section 1: Automatische Redirects                            -->
				<!-- ============================================================ -->
				<h2>Automatische Redirects</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Automatische Weiterleitungen bei URL-&Auml;nderungen erstellen</th>
						<td>
							<label>
								<input type="checkbox" name="auto_redirect" value="1" <?php checked( ! empty( $settings['auto_redirect'] ) ); ?> />
								Automatische Weiterleitungen bei URL-&Auml;nderungen erstellen
							</label>
							<p class="description">
								Wenn aktiviert, erstellt das Plugin automatisch eine Weiterleitung (Redirect), sobald sich
								die URL (Permalink) eines Beitrags, einer Seite oder eines anderen &uuml;berwachten
								Inhaltstyps &auml;ndert. Die alte URL wird automatisch auf die neue URL weitergeleitet,
								sodass bestehende Links und Suchmaschinen-Rankings erhalten bleiben.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">&Uuml;berwachte Inhaltstypen</th>
						<td>
							<fieldset>
								<?php
								$post_types          = get_post_types( array( 'public' => true ), 'objects' );
								$monitored           = isset( $settings['monitor_post_types'] ) ? (array) $settings['monitor_post_types'] : array();

								foreach ( $post_types as $post_type ) :
									$checked = in_array( $post_type->name, $monitored, true );
									?>
									<label style="display:block; margin-bottom:4px;">
										<input type="checkbox" name="monitor_post_types[]"
											value="<?php echo esc_attr( $post_type->name ); ?>"
											<?php checked( $checked ); ?> />
										<?php echo esc_html( $post_type->labels->name ); ?>
										<code>(<?php echo esc_html( $post_type->name ); ?>)</code>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								W&auml;hlen Sie die Inhaltstypen aus, deren URL-&Auml;nderungen &uuml;berwacht werden sollen.
								F&uuml;r jeden ausgew&auml;hlten Typ wird bei einer Permalink-&Auml;nderung automatisch ein
								Redirect erstellt.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="default_status_code">Standard HTTP-Status-Code</label></th>
						<td>
							<?php
							$current_code = isset( $settings['default_status_code'] ) ? (int) $settings['default_status_code'] : 301;
							$status_codes = array(
								301 => '301 &ndash; Moved Permanently (dauerhaft verschoben)',
								302 => '302 &ndash; Found (tempor&auml;r verschoben)',
								307 => '307 &ndash; Temporary Redirect (tempor&auml;re Weiterleitung)',
								308 => '308 &ndash; Permanent Redirect (dauerhafte Weiterleitung, Methode beibehalten)',
							);
							?>
							<select id="default_status_code" name="default_status_code">
								<?php foreach ( $status_codes as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_code, $code ); ?>>
										<?php echo $label; // Already escaped with entities. ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<strong>301 (Moved Permanently):</strong> Teilt Suchmaschinen mit, dass die Seite dauerhaft
								umgezogen ist. Der gesamte SEO-Wert (Link Juice) wird auf die neue URL &uuml;bertragen.
								Empfohlen f&uuml;r die meisten F&auml;lle.<br>
								<strong>302 (Found):</strong> Signalisiert eine tempor&auml;re Weiterleitung. Suchmaschinen
								behalten die alte URL im Index. Geeignet f&uuml;r zeitlich begrenzte Umleitungen.<br>
								<strong>307 (Temporary Redirect):</strong> Wie 302, stellt jedoch sicher, dass die HTTP-Methode
								(z.&thinsp;B. POST) bei der Weiterleitung beibehalten wird.<br>
								<strong>308 (Permanent Redirect):</strong> Wie 301, stellt jedoch sicher, dass die HTTP-Methode
								beibehalten wird. Neuerer Standard f&uuml;r API-Endpunkte und Formulare.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 2: 404-Fehler Logging                               -->
				<!-- ============================================================ -->
				<h2>404-Fehler Logging</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">404-Fehler protokollieren</th>
						<td>
							<label>
								<input type="checkbox" name="log_404" value="1" <?php checked( ! empty( $settings['log_404'] ) ); ?> />
								404-Fehler protokollieren
							</label>
							<p class="description">
								Zeichnet alle Anfragen auf, die zu einer 404-Fehlerseite f&uuml;hren. IP-Adressen werden
								DSGVO-konform anonymisiert gespeichert (letztes Oktett wird auf 0 gesetzt). Anhand der
								protokollierten 404-Fehler k&ouml;nnen Sie gezielt Weiterleitungen erstellen und so
								verlorene Besucher zur&uuml;ckgewinnen.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Gleiche 404-URLs zusammenfassen</th>
						<td>
							<label>
								<input type="checkbox" name="group_404" value="1" <?php checked( ! empty( $settings['group_404'] ) ); ?> />
								Gleiche 404-URLs zusammenfassen
							</label>
							<p class="description">
								Wenn aktiviert, wird bei mehrfachen Aufrufen derselben nicht existierenden URL nur ein
								Eintrag im Log gespeichert und ein Z&auml;hler hochgez&auml;hlt. Dies h&auml;lt das Log
								&uuml;bersichtlich und reduziert die Datenbankgr&ouml;&szlig;e erheblich.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="log_retention_days">Log-Aufbewahrungsdauer (Tage)</label></th>
						<td>
							<input type="number" id="log_retention_days" name="log_retention_days"
								value="<?php echo esc_attr( isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30 ); ?>"
								min="1" max="365" class="small-text" />
							<p class="description">
								404-Log-Eintr&auml;ge, die &auml;lter als die angegebene Anzahl Tage sind, werden
								automatisch gel&ouml;scht. Dies h&auml;lt die Datenbank schlank und entspricht den
								Empfehlungen zur Datensparsamkeit gem&auml;&szlig; DSGVO.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 3: Geloeschte Inhalte                               -->
				<!-- ============================================================ -->
				<h2>Gel&ouml;schte Inhalte</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Automatisch 410 (Gone) f&uuml;r gel&ouml;schte Inhalte senden</th>
						<td>
							<label>
								<input type="checkbox" name="auto_410" value="1" <?php checked( ! empty( $settings['auto_410'] ) ); ?> />
								Automatisch 410 (Gone) f&uuml;r gel&ouml;schte Inhalte senden
							</label>
							<p class="description">
								Wenn ein Beitrag oder eine Seite endg&uuml;ltig gel&ouml;scht wird (nicht nur in den
								Papierkorb verschoben), sendet das Plugin automatisch einen HTTP-Statuscode 410 (Gone)
								f&uuml;r die ehemalige URL. Dies teilt Suchmaschinen mit, dass der Inhalt absichtlich
								entfernt wurde und nicht mehr zur&uuml;ckkehren wird. Google entfernt 410-URLs schneller
								aus dem Index als 404-Seiten.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 4: Automatische Redirect-Bereinigung                -->
				<!-- ============================================================ -->
				<h2>Automatische Redirect-Bereinigung</h2>

				<div style="border-left: 4px solid #2271b1; background: #f0f6fc; padding: 12px 16px; margin-bottom: 20px;">
					<p style="margin-top:0;">
						<strong>Warum sollten alte Redirects bereinigt werden?</strong>
					</p>
					<p>
						Googles John Mueller empfiehlt, Redirects nicht f&uuml;r immer beizubehalten. Nach einer gewissen
						Zeit haben Suchmaschinen die neue URL vollst&auml;ndig &uuml;bernommen und die alte URL ist aus
						dem Index verschwunden. Ab diesem Zeitpunkt verursachen die Redirects nur noch unn&ouml;tigen
						Server-Overhead.
					</p>
					<p>
						<strong>Die 3 Phasen eines Redirects:</strong>
					</p>
					<ol style="margin-bottom:0;">
						<li>
							<strong>Aktive Phase (0&ndash;6 Monate):</strong> Suchmaschinen und Nutzer folgen dem Redirect.
							Der SEO-Wert wird &uuml;bertragen. Der Redirect wird dringend ben&ouml;tigt.
						</li>
						<li>
							<strong>&Uuml;bergangsphase (6&ndash;12 Monate):</strong> Die meisten Suchmaschinen haben die
							neue URL &uuml;bernommen. Externe Backlinks verweisen m&ouml;glicherweise noch auf die alte URL.
							Der Redirect ist noch sinnvoll.
						</li>
						<li>
							<strong>Veraltete Phase (12+ Monate):</strong> Die alte URL ist aus allen Suchindizes
							verschwunden. Es gibt kaum noch Traffic &uuml;ber die alte URL. Der Redirect kann
							gefahrlos entfernt werden.
						</li>
					</ol>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Alte Redirects automatisch bereinigen</th>
						<td>
							<label>
								<input type="checkbox" name="auto_cleanup_enabled" value="1" <?php checked( ! empty( $settings['auto_cleanup_enabled'] ) ); ?> />
								Alte Redirects automatisch bereinigen
							</label>
							<p class="description">
								Aktiviert die automatische Bereinigung von Redirects, die das konfigurierte Mindestalter
								&uuml;berschritten haben und in der festgelegten Leerlaufzeit nicht mehr aufgerufen wurden.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="auto_cleanup_days">Mindestalter f&uuml;r Bereinigung (Tage)</label></th>
						<td>
							<input type="number" id="auto_cleanup_days" name="auto_cleanup_days"
								value="<?php echo esc_attr( isset( $settings['auto_cleanup_days'] ) ? (int) $settings['auto_cleanup_days'] : 365 ); ?>"
								min="90" max="1825" class="small-text" />
							<p class="description">
								Ein Redirect muss mindestens so viele Tage alt sein, bevor er f&uuml;r die automatische
								Bereinigung in Frage kommt.<br>
								<strong>Empfehlungen:</strong> 365 Tage (1 Jahr) f&uuml;r die meisten Websites &bull;
								180 Tage (6 Monate) f&uuml;r Websites mit h&auml;ufigen URL-&Auml;nderungen &bull;
								730 Tage (2 Jahre) f&uuml;r besonders konservative Konfigurationen &bull;
								Maximum: 1825 Tage (5 Jahre).
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="auto_cleanup_min_days_idle">Minimale Leerlaufzeit (Tage ohne Aufruf)</label></th>
						<td>
							<input type="number" id="auto_cleanup_min_days_idle" name="auto_cleanup_min_days_idle"
								value="<?php echo esc_attr( isset( $settings['auto_cleanup_min_days_idle'] ) ? (int) $settings['auto_cleanup_min_days_idle'] : 90 ); ?>"
								min="30" max="365" class="small-text" />
							<p class="description">
								Zus&auml;tzlich zum Mindestalter muss ein Redirect mindestens so viele Tage lang keinen
								einzigen Aufruf mehr erhalten haben, bevor er bereinigt wird. Dies stellt sicher, dass
								noch aktiv genutzte Redirects nicht versehentlich entfernt werden.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="auto_cleanup_action">Bereinigungsaktion</label></th>
						<td>
							<?php
							$current_action  = isset( $settings['auto_cleanup_action'] ) ? $settings['auto_cleanup_action'] : 'deactivate';
							$cleanup_actions = array(
								'deactivate'  => 'Deaktivieren',
								'delete'      => 'Endgueltig loeschen',
								'convert_410' => 'In 410 (Gone) umwandeln',
							);
							?>
							<select id="auto_cleanup_action" name="auto_cleanup_action">
								<?php foreach ( $cleanup_actions as $action_key => $action_label ) : ?>
									<option value="<?php echo esc_attr( $action_key ); ?>" <?php selected( $current_action, $action_key ); ?>>
										<?php echo esc_html( $action_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<strong>Deaktivieren:</strong> Der Redirect wird deaktiviert, bleibt aber in der Datenbank
								erhalten. Er kann jederzeit manuell wieder aktiviert werden. Dies ist die sicherste
								Option.<br>
								<strong>Endg&uuml;ltig l&ouml;schen:</strong> Der Redirect wird unwiderruflich aus der
								Datenbank entfernt. Spart Speicherplatz, ist aber nicht r&uuml;ckg&auml;ngig zu machen.<br>
								<strong>In 410 (Gone) umwandeln:</strong> Der Redirect wird in eine 410-Antwort umgewandelt.
								Suchmaschinen erhalten das Signal, dass die Quell-URL absichtlich entfernt wurde.
								Sinnvoll, wenn die Ziel-URL ebenfalls nicht mehr existiert.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Von der Bereinigung ausschlie&szlig;en</th>
						<td>
							<fieldset>
								<?php
								$source_types = array(
									'manual'       => 'Manuell erstellt',
									'auto'         => 'Automatisch erstellt (URL-Aenderung)',
									'auto_410'     => 'Automatisch erstellt (410 Gone)',
									'import'       => 'Importiert',
									'bulk'         => 'Massenoperation',
								);

								$excluded_types = isset( $settings['auto_cleanup_exclude_types'] )
									? (array) $settings['auto_cleanup_exclude_types']
									: array();

								foreach ( $source_types as $type_key => $type_label ) :
									$checked = in_array( $type_key, $excluded_types, true );
									?>
									<label style="display:block; margin-bottom:4px;">
										<input type="checkbox" name="auto_cleanup_exclude_types[]"
											value="<?php echo esc_attr( $type_key ); ?>"
											<?php checked( $checked ); ?> />
										<?php echo esc_html( $type_label ); ?>
										<code>(<?php echo esc_html( $type_key ); ?>)</code>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								Redirects mit den ausgew&auml;hlten Quelltypen werden von der automatischen Bereinigung
								ausgenommen &ndash; unabh&auml;ngig von Alter und Leerlaufzeit.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 5: E-Mail Benachrichtigungen                        -->
				<!-- ============================================================ -->
				<h2>E-Mail Benachrichtigungen</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Benachrichtigungen aktivieren</th>
						<td>
							<label>
								<input type="checkbox" name="notifications_enabled" value="1" <?php checked( ! empty( $settings['notifications_enabled'] ) ); ?> />
								E-Mail-Benachrichtigungen aktivieren
							</label>
							<p class="description">
								Aktiviert den Versand von Zusammenfassungs-E-Mails und Warnungen bei 404-Fehler-Spitzen.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="notification_email">Empf&auml;nger-E-Mail-Adresse</label></th>
						<td>
							<input type="email" id="notification_email" name="notification_email"
								value="<?php echo esc_attr( isset( $settings['notification_email'] ) ? $settings['notification_email'] : '' ); ?>"
								class="regular-text" />
							<p class="description">
								E-Mail-Adresse f&uuml;r Benachrichtigungen. Wenn leer, wird die
								Administrator-E-Mail-Adresse verwendet:
								<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="notification_frequency">Benachrichtigungsh&auml;ufigkeit</label></th>
						<td>
							<?php
							$current_frequency = isset( $settings['notification_frequency'] ) ? $settings['notification_frequency'] : 'daily';
							?>
							<select id="notification_frequency" name="notification_frequency">
								<option value="daily" <?php selected( $current_frequency, 'daily' ); ?>>
									T&auml;glich
								</option>
								<option value="weekly" <?php selected( $current_frequency, 'weekly' ); ?>>
									W&ouml;chentlich
								</option>
							</select>
							<p class="description">
								Wie oft soll die Zusammenfassungs-E-Mail mit Redirect-Statistiken und 404-&Uuml;bersicht
								versendet werden?
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="404_spike_threshold">404-Spike Schwellenwert</label></th>
						<td>
							<input type="number" id="404_spike_threshold" name="404_spike_threshold"
								value="<?php echo esc_attr( isset( $settings['404_spike_threshold'] ) ? (int) $settings['404_spike_threshold'] : 50 ); ?>"
								min="10" max="1000" class="small-text" />
							<p class="description">
								Wenn innerhalb einer Stunde mehr als die angegebene Anzahl an 404-Fehlern auftritt, wird
								sofort eine Warn-E-Mail versendet. Dies kann auf einen fehlerhaften Deployment, eine
								gel&ouml;schte Sitemap oder einen Bot-Angriff hinweisen.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 6: Statistiken                                      -->
				<!-- ============================================================ -->
				<h2>Statistiken</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Redirect-Aufrufe z&auml;hlen</th>
						<td>
							<label>
								<input type="checkbox" name="track_hits" value="1" <?php checked( ! empty( $settings['track_hits'] ) ); ?> />
								Redirect-Aufrufe z&auml;hlen
							</label>
							<p class="description">
								Wenn aktiviert, wird jeder Aufruf eines Redirects gez&auml;hlt und t&auml;glich aggregiert
								gespeichert. Die Statistiken sind hilfreich, um die Nutzung von Redirects zu analysieren,
								ungenutzte Redirects zu identifizieren und die Effektivit&auml;t der automatischen
								Bereinigung zu &uuml;berpr&uuml;fen. Bei sehr hohem Traffic kann das Deaktivieren die
								Datenbank-Last reduzieren.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 7: Performance                                      -->
				<!-- ============================================================ -->
				<h2>Performance</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="redirect_cache_ttl">Redirect-Cache Lebensdauer (Sekunden)</label></th>
						<td>
							<input type="number" id="redirect_cache_ttl" name="redirect_cache_ttl"
								value="<?php echo esc_attr( isset( $settings['redirect_cache_ttl'] ) ? (int) $settings['redirect_cache_ttl'] : 3600 ); ?>"
								min="0" max="86400" class="small-text" />
							<p class="description">
								Die aktiven Redirects werden im WordPress Object Cache zwischengespeichert, um
								Datenbank-Abfragen zu reduzieren. Der Wert gibt an, wie lange (in Sekunden) der Cache
								g&uuml;ltig ist, bevor er erneuert wird. Standard: 3600 (1 Stunde). Setzen Sie den Wert
								auf 0, um den Cache zu deaktivieren (nicht empfohlen f&uuml;r Produktionsumgebungen).
								Bei Verwendung eines persistenten Object Caches (Redis, Memcached) profitieren Sie
								besonders von h&ouml;heren Werten. Maximum: 86400 (24 Stunden).
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="excluded_paths">Ausgeschlossene Pfade</label></th>
						<td>
							<textarea id="excluded_paths" name="excluded_paths" rows="6" cols="50" class="large-text code"><?php
								echo esc_textarea( isset( $settings['excluded_paths'] ) ? $settings['excluded_paths'] : '' );
							?></textarea>
							<p class="description">
								URL-Pfade, die vom Redirect-Matching und 404-Logging ausgeschlossen werden sollen &ndash;
								ein Pfad pro Zeile. Anfragen, die mit einem dieser Pfade beginnen, werden ignoriert.
								Typische Eintr&auml;ge sind z.&thinsp;B.
								<code>/wp-admin</code>, <code>/wp-login.php</code>, <code>/wp-cron.php</code>.
							</p>
						</td>
					</tr>
				</table>

				<!-- ============================================================ -->
				<!-- Section 8: Datenbank-Info                                   -->
				<!-- ============================================================ -->
				<h2>Datenbank-Info</h2>
				<?php self::render_database_info(); ?>

				<?php submit_button( 'Einstellungen speichern' ); ?>

			</form>
		</div>
		<?php
	}

	/**
	 * Render the read-only database information section.
	 *
	 * Shows counts for redirects, 404 log entries, hit statistics,
	 * groups, conditions, and estimated total database size used by
	 * the plugin tables.
	 *
	 * @return void
	 */
	private static function render_database_info() {

		global $wpdb;

		$redirects_table  = SRM_Database::get_table_name( 'redirects' );
		$log_table        = SRM_Database::get_table_name( '404_log' );
		$hits_table       = SRM_Database::get_table_name( 'redirect_hits' );
		$groups_table     = SRM_Database::get_table_name( 'groups' );
		$conditions_table = SRM_Database::get_table_name( 'conditions' );

		// Redirect counts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_redirects  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$redirects_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_redirects = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$redirects_table} WHERE is_active = 1" );
		$inactive_redirects = $total_redirects - $active_redirects;

		// 404 log count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$log_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" );

		// Hit stats count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hits_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hits_table}" );

		// Groups count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$groups_table}" );

		// Conditions count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conditions_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conditions_table}" );

		// Estimated database size.
		$table_names = array(
			$redirects_table,
			$log_table,
			$hits_table,
			$groups_table,
			$conditions_table,
		);

		$total_size_bytes = 0;

		foreach ( $table_names as $table_name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT data_length + index_length AS size_bytes
					 FROM information_schema.TABLES
					 WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$table_name
				)
			);

			if ( $row && isset( $row->size_bytes ) ) {
				$total_size_bytes += (int) $row->size_bytes;
			}
		}

		$total_size_display = size_format( $total_size_bytes, 2 );

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Redirects</th>
				<td>
					<?php echo esc_html( number_format_i18n( $total_redirects ) ); ?> gesamt
					&nbsp;/&nbsp;
					<span style="color:#00a32a;"><?php echo esc_html( number_format_i18n( $active_redirects ) ); ?> aktiv</span>
					&nbsp;/&nbsp;
					<span style="color:#996800;"><?php echo esc_html( number_format_i18n( $inactive_redirects ) ); ?> inaktiv</span>
				</td>
			</tr>
			<tr>
				<th scope="row">404-Log Eintr&auml;ge</th>
				<td><?php echo esc_html( number_format_i18n( $log_count ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">Hit-Statistik Eintr&auml;ge</th>
				<td><?php echo esc_html( number_format_i18n( $hits_count ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">Gruppen</th>
				<td><?php echo esc_html( number_format_i18n( $groups_count ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">Bedingungen (Conditions)</th>
				<td><?php echo esc_html( number_format_i18n( $conditions_count ) ); ?></td>
			</tr>
			<tr>
				<th scope="row">Gesch&auml;tzte Datenbankgr&ouml;&szlig;e</th>
				<td>
					<?php echo esc_html( $total_size_display ); ?>
					<p class="description">
						Gesamtgr&ouml;&szlig;e aller Plugin-Tabellen (Daten + Indizes).
					</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
