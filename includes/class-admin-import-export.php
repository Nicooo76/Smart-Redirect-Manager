<?php
/**
 * Admin-Seite fuer Import und Export von Redirects.
 *
 * Stellt die Benutzeroberflaeche fuer CSV-Export (Redirects und 404-Log)
 * sowie CSV-Import von Redirects bereit.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SRM_Admin_Import_Export
 *
 * Rendert die Import/Export-Verwaltungsseite im WordPress-Admin.
 */
class SRM_Admin_Import_Export {

    /**
     * Seite rendern: Import/Export-Oberflaeche.
     *
     * Verarbeitet eingehende Import-Formulare (POST mit Datei-Upload),
     * Export-Anfragen (GET-Aktionen) und gibt die drei Karten
     * (Export, Import, CSV-Vorlage) aus.
     *
     * @return void
     */
    public static function render() {

        // -----------------------------------------------------------------
        // 1. Export-Aktionen verarbeiten (GET).
        // -----------------------------------------------------------------
        if ( isset( $_GET['action'] ) && 'srm_export_redirects' === $_GET['action'] ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'srm_export_redirects' ) ) {
                wp_die( __( 'Sicherheitspruefung fehlgeschlagen.', 'smart-redirect-manager' ) );
            }

            SRM_Import_Export::export_redirects();
            // export_redirects() ruft exit auf – wird nicht weiter ausgefuehrt.
        }

        if ( isset( $_GET['action'] ) && 'srm_export_404' === $_GET['action'] ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'srm_export_404' ) ) {
                wp_die( __( 'Sicherheitspruefung fehlgeschlagen.', 'smart-redirect-manager' ) );
            }

            SRM_Import_Export::export_404_log();
            // export_404_log() ruft exit auf – wird nicht weiter ausgefuehrt.
        }

        // -----------------------------------------------------------------
        // 2. Import-Formular verarbeiten (POST).
        // -----------------------------------------------------------------
        $import_result = null;

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_FILES['csv_file'] ) ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'srm_import_redirects' ) ) {
                wp_die( __( 'Sicherheitspruefung fehlgeschlagen.', 'smart-redirect-manager' ) );
            }

            $import_result = SRM_Import_Export::import_redirects( $_FILES['csv_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }

        // -----------------------------------------------------------------
        // 3. Seite ausgeben.
        // -----------------------------------------------------------------
        ?>
        <div class="wrap">

            <h1><?php esc_html_e( 'Import / Export', 'smart-redirect-manager' ); ?></h1>

            <?php // Import-Ergebnis anzeigen. ?>
            <?php if ( null !== $import_result ) : ?>
                <?php
                $has_errors = ! empty( $import_result['errors'] );
                $notice_class = $has_errors ? 'notice-warning' : 'notice-success';
                ?>
                <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible" style="margin: 16px 0;">
                    <p>
                        <strong><?php esc_html_e( 'Import abgeschlossen.', 'smart-redirect-manager' ); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: imported count, 2: updated count, 3: skipped count */
                            esc_html__( '%1$d importiert, %2$d aktualisiert, %3$d uebersprungen.', 'smart-redirect-manager' ),
                            absint( $import_result['imported'] ),
                            absint( $import_result['updated'] ),
                            absint( $import_result['skipped'] )
                        );
                        ?>
                    </p>
                    <?php if ( $has_errors ) : ?>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach ( $import_result['errors'] as $error ) : ?>
                                <li><?php echo esc_html( $error ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // Karten-Layout. ?>
            <div class="srm-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-top: 20px;">

                <?php // ---- Karte 1: Export ---- ?>
                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px;">

                    <h2 style="margin-top: 0;">
                        <?php esc_html_e( 'Redirects & 404-Log exportieren', 'smart-redirect-manager' ); ?>
                    </h2>

                    <p>
                        <?php esc_html_e( 'Exportiere alle gespeicherten Redirects oder das 404-Fehlerprotokoll als CSV-Datei. Die Dateien verwenden Semikolon als Trennzeichen und UTF-8-Kodierung.', 'smart-redirect-manager' ); ?>
                    </p>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px;">
                        <a
                            href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=srm-import-export&action=srm_export_redirects' ), 'srm_export_redirects' ) ); ?>"
                            class="button button-primary"
                        >
                            <?php esc_html_e( 'Redirects exportieren (CSV)', 'smart-redirect-manager' ); ?>
                        </a>

                        <a
                            href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=srm-import-export&action=srm_export_404' ), 'srm_export_404' ) ); ?>"
                            class="button button-primary"
                        >
                            <?php esc_html_e( '404-Log exportieren (CSV)', 'smart-redirect-manager' ); ?>
                        </a>
                    </div>

                </div>

                <?php // ---- Karte 2: Import ---- ?>
                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px;">

                    <h2 style="margin-top: 0;">
                        <?php esc_html_e( 'Redirects importieren', 'smart-redirect-manager' ); ?>
                    </h2>

                    <p>
                        <?php esc_html_e( 'Lade eine CSV-Datei hoch, um Redirects in die Datenbank zu importieren. Die Datei muss mindestens die Spalten source_url und target_url enthalten. Semikolon und Komma werden als Trennzeichen automatisch erkannt.', 'smart-redirect-manager' ); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data" style="margin-top: 16px;">
                        <?php wp_nonce_field( 'srm_import_redirects' ); ?>

                        <p>
                            <label for="srm-csv-file">
                                <strong><?php esc_html_e( 'CSV-Datei auswaehlen:', 'smart-redirect-manager' ); ?></strong>
                            </label><br />
                            <input
                                type="file"
                                name="csv_file"
                                id="srm-csv-file"
                                accept=".csv"
                                required
                                style="margin-top: 6px;"
                            />
                        </p>

                        <?php submit_button( __( 'Importieren', 'smart-redirect-manager' ), 'primary', 'srm_import_submit', true ); ?>
                    </form>

                    <p style="font-size: 13px; color: #666; margin-top: 12px;">
                        <strong><?php esc_html_e( 'Hinweis:', 'smart-redirect-manager' ); ?></strong>
                        <?php esc_html_e( 'Bereits vorhandene Redirects (gleiche Quell-URL) werden automatisch aktualisiert und nicht doppelt angelegt.', 'smart-redirect-manager' ); ?>
                    </p>

                </div>

                <?php // ---- Karte 3: CSV-Vorlage ---- ?>
                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px;">

                    <h2 style="margin-top: 0;">
                        <?php esc_html_e( 'CSV-Format', 'smart-redirect-manager' ); ?>
                    </h2>

                    <p>
                        <?php esc_html_e( 'Die CSV-Datei muss eine Kopfzeile enthalten. Pflichtfelder sind source_url und target_url. Alle weiteren Spalten sind optional und werden mit Standardwerten belegt, wenn sie fehlen.', 'smart-redirect-manager' ); ?>
                    </p>

                    <pre style="background: #f0f0f1; padding: 14px; border-radius: 4px; overflow-x: auto; font-size: 13px; line-height: 1.6;"><code>source_url;target_url;status_code;is_regex;is_active;notes
/alter-pfad;/neuer-pfad;301;0;1;Beispiel Redirect
/kategorie/(.*);/blog/$1;301;1;1;Regex Beispiel</code></pre>

                    <table class="widefat" style="margin-top: 14px; font-size: 13px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Spalte', 'smart-redirect-manager' ); ?></th>
                                <th><?php esc_html_e( 'Pflicht', 'smart-redirect-manager' ); ?></th>
                                <th><?php esc_html_e( 'Beschreibung', 'smart-redirect-manager' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>source_url</code></td>
                                <td><?php esc_html_e( 'Ja', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( 'Die Quell-URL, die umgeleitet werden soll', 'smart-redirect-manager' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>target_url</code></td>
                                <td><?php esc_html_e( 'Ja', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( 'Die Ziel-URL, auf die umgeleitet wird', 'smart-redirect-manager' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>status_code</code></td>
                                <td><?php esc_html_e( 'Nein', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( 'HTTP-Statuscode (Standard: 301)', 'smart-redirect-manager' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>is_regex</code></td>
                                <td><?php esc_html_e( 'Nein', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( '1 = Regulaerer Ausdruck, 0 = Exakt (Standard: 0)', 'smart-redirect-manager' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>is_active</code></td>
                                <td><?php esc_html_e( 'Nein', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( '1 = Aktiv, 0 = Inaktiv (Standard: 1)', 'smart-redirect-manager' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>notes</code></td>
                                <td><?php esc_html_e( 'Nein', 'smart-redirect-manager' ); ?></td>
                                <td><?php esc_html_e( 'Optionale Notiz zum Redirect', 'smart-redirect-manager' ); ?></td>
                            </tr>
                        </tbody>
                    </table>

                </div>

            </div><!-- .srm-cards -->

        </div><!-- .wrap -->
        <?php
    }
}
