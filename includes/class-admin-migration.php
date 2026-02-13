<?php
/**
 * Admin-Seite fuer die Migration vom Redirection-Plugin.
 *
 * Zeigt den Installationsstatus, eine Datenvorschau und ein
 * Migrationsformular fuer den Umstieg vom Redirection-Plugin
 * auf Smart Redirect Manager.
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
 * Class SRM_Admin_Migration
 *
 * Rendert die Migrations-Verwaltungsseite im WordPress-Admin.
 */
class SRM_Admin_Migration {

    /**
     * Seite rendern: Migration vom Redirection-Plugin.
     *
     * Verarbeitet eingehende Migrationsformulare (POST),
     * prueft den Installationsstatus, zeigt eine Vorschau
     * der migrierbaren Daten und das Migrationsformular.
     *
     * @return void
     */
    public static function render() {

        // -----------------------------------------------------------------
        // 1. Migrationsformular verarbeiten (POST).
        // -----------------------------------------------------------------
        $migration_result = null;

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['srm_start_migration'] ) ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'srm_migration' ) ) {
                wp_die( __( 'Sicherheitspruefung fehlgeschlagen.', 'smart-redirect-manager' ) );
            }

            $options = array(
                'redirects'         => ! empty( $_POST['migrate_redirects'] ),
                '404_logs'          => ! empty( $_POST['migrate_404_logs'] ),
                'hit_stats'         => ! empty( $_POST['migrate_hit_stats'] ),
                'deactivate_plugin' => ! empty( $_POST['deactivate_redirection'] ),
            );

            $migration_result = SRM_Migration::migrate( $options );
        }

        // -----------------------------------------------------------------
        // 2. Status ermitteln.
        // -----------------------------------------------------------------
        $is_installed = SRM_Migration::is_redirection_installed();
        $is_active    = SRM_Migration::is_redirection_active();

        // -----------------------------------------------------------------
        // 3. Seite ausgeben.
        // -----------------------------------------------------------------
        ?>
        <div class="wrap">

            <h1><?php esc_html_e( 'Migration vom Redirection-Plugin', 'smart-redirect-manager' ); ?></h1>

            <?php // ---- Migrationsergebnis anzeigen ---- ?>
            <?php if ( null !== $migration_result ) : ?>
                <?php
                $has_errors   = ! empty( $migration_result['errors'] );
                $notice_class = $has_errors ? 'notice-warning' : 'notice-success';
                ?>
                <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible" style="margin: 16px 0;">
                    <p>
                        <strong><?php esc_html_e( 'Migration abgeschlossen.', 'smart-redirect-manager' ); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: imported count, 2: skipped count, 3: error count */
                            esc_html__( '%1$d importiert, %2$d uebersprungen, %3$d Fehler.', 'smart-redirect-manager' ),
                            absint( $migration_result['imported'] ),
                            absint( $migration_result['skipped'] ),
                            count( $migration_result['errors'] )
                        );
                        ?>
                    </p>

                    <?php if ( $has_errors ) : ?>
                        <details style="margin-top: 8px; margin-bottom: 8px;">
                            <summary style="cursor: pointer; font-weight: 600;">
                                <?php esc_html_e( 'Fehlerdetails anzeigen', 'smart-redirect-manager' ); ?>
                            </summary>
                            <ul style="list-style: disc; margin-left: 20px; margin-top: 8px;">
                                <?php foreach ( $migration_result['errors'] as $error ) : ?>
                                    <li><?php echo esc_html( $error ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // ---- Status-Karte ---- ?>
            <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px; margin-top: 20px; max-width: 800px;">

                <h2 style="margin-top: 0;">
                    <?php esc_html_e( 'Status des Redirection-Plugins', 'smart-redirect-manager' ); ?>
                </h2>

                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Datenbanktabellen', 'smart-redirect-manager' ); ?></th>
                        <td>
                            <?php if ( $is_installed ) : ?>
                                <span style="color: #46b450; font-weight: 600;">&#10003; <?php esc_html_e( 'Redirection-Tabellen gefunden', 'smart-redirect-manager' ); ?></span>
                            <?php else : ?>
                                <span style="color: #dc3232; font-weight: 600;">&#10007; <?php esc_html_e( 'Keine Redirection-Tabellen gefunden', 'smart-redirect-manager' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Plugin-Status', 'smart-redirect-manager' ); ?></th>
                        <td>
                            <?php if ( $is_active ) : ?>
                                <span style="color: #46b450; font-weight: 600;">&#10003; <?php esc_html_e( 'Aktiv', 'smart-redirect-manager' ); ?></span>
                            <?php else : ?>
                                <span style="color: #826e00; font-weight: 600;">&#9679; <?php esc_html_e( 'Inaktiv', 'smart-redirect-manager' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

            </div>

            <?php
            // Wenn keine Redirection-Tabellen vorhanden, hier abbrechen.
            if ( ! $is_installed ) :
            ?>
                <div class="notice notice-info" style="margin-top: 20px; max-width: 800px;">
                    <p>
                        <?php esc_html_e( 'Keine Redirection-Tabellen gefunden. Bitte stelle sicher, dass das Redirection-Plugin mindestens einmal installiert und aktiviert war, damit die Datenbanktabellen vorhanden sind.', 'smart-redirect-manager' ); ?>
                    </p>
                </div>
                <?php
                return;
            endif;
            ?>

            <?php
            // -----------------------------------------------------------------
            // 4. Vorschau der migrierbaren Daten.
            // -----------------------------------------------------------------
            $preview = SRM_Migration::get_preview();
            ?>

            <?php // ---- Statistik-Karten ---- ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-top: 24px; max-width: 800px;">

                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
                    <div style="font-size: 28px; font-weight: 700; color: #2271b1;">
                        <?php echo absint( $preview['redirects'] ); ?>
                    </div>
                    <div style="font-size: 13px; color: #666; margin-top: 4px;">
                        <?php esc_html_e( 'Redirects', 'smart-redirect-manager' ); ?>
                    </div>
                </div>

                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
                    <div style="font-size: 28px; font-weight: 700; color: #2271b1;">
                        <?php echo absint( $preview['groups'] ); ?>
                    </div>
                    <div style="font-size: 13px; color: #666; margin-top: 4px;">
                        <?php esc_html_e( 'Gruppen', 'smart-redirect-manager' ); ?>
                    </div>
                </div>

                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
                    <div style="font-size: 28px; font-weight: 700; color: #2271b1;">
                        <?php echo absint( $preview['404_logs'] ); ?>
                    </div>
                    <div style="font-size: 13px; color: #666; margin-top: 4px;">
                        <?php esc_html_e( '404-Logs', 'smart-redirect-manager' ); ?>
                    </div>
                </div>

                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; text-align: center;">
                    <div style="font-size: 28px; font-weight: 700; color: #2271b1;">
                        <?php echo absint( $preview['hit_logs'] ); ?>
                    </div>
                    <div style="font-size: 13px; color: #666; margin-top: 4px;">
                        <?php esc_html_e( 'Hit-Logs', 'smart-redirect-manager' ); ?>
                    </div>
                </div>

            </div>

            <?php // ---- Vorschau-Tabelle: Letzte 5 Redirects ---- ?>
            <?php if ( ! empty( $preview['preview'] ) ) : ?>
                <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px; margin-top: 20px; max-width: 800px;">

                    <h2 style="margin-top: 0;">
                        <?php esc_html_e( 'Vorschau: Letzte 5 Redirects aus Redirection', 'smart-redirect-manager' ); ?>
                    </h2>

                    <table class="wp-list-table widefat fixed striped" style="margin-top: 12px;">
                        <thead>
                            <tr>
                                <th style="width: 35%;"><?php esc_html_e( 'URL', 'smart-redirect-manager' ); ?></th>
                                <th style="width: 35%;"><?php esc_html_e( 'Ziel', 'smart-redirect-manager' ); ?></th>
                                <th style="width: 15%;"><?php esc_html_e( 'Code', 'smart-redirect-manager' ); ?></th>
                                <th style="width: 15%;"><?php esc_html_e( 'Regex', 'smart-redirect-manager' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $preview['preview'] as $item ) : ?>
                                <tr>
                                    <td>
                                        <code title="<?php echo esc_attr( $item->url ); ?>">
                                            <?php echo esc_html( mb_strimwidth( $item->url, 0, 50, '...' ) ); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <code title="<?php echo esc_attr( $item->action_data ); ?>">
                                            <?php echo esc_html( mb_strimwidth( $item->action_data, 0, 50, '...' ) ); ?>
                                        </code>
                                    </td>
                                    <td><?php echo absint( $item->action_code ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $item->regex ) ) : ?>
                                            <span style="color: #46b450; font-weight: 600;"><?php esc_html_e( 'Ja', 'smart-redirect-manager' ); ?></span>
                                        <?php else : ?>
                                            <span style="color: #999;"><?php esc_html_e( 'Nein', 'smart-redirect-manager' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                </div>
            <?php endif; ?>

            <?php // ---- Migrationsformular ---- ?>
            <div class="srm-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px; margin-top: 20px; max-width: 800px;">

                <h2 style="margin-top: 0;">
                    <?php esc_html_e( 'Migration starten', 'smart-redirect-manager' ); ?>
                </h2>

                <?php // Warnung: Datenbank-Backup ?>
                <div style="border: 2px solid #dba617; background: #fcf9e8; border-radius: 4px; padding: 14px 18px; margin-bottom: 20px;">
                    <strong style="color: #826e00;">
                        <?php esc_html_e( 'Wichtig:', 'smart-redirect-manager' ); ?>
                    </strong>
                    <?php esc_html_e( 'Bitte erstelle vorher ein Datenbank-Backup! Die Migration kann nicht rueckgaengig gemacht werden.', 'smart-redirect-manager' ); ?>
                </div>

                <form method="post">
                    <?php wp_nonce_field( 'srm_migration' ); ?>

                    <fieldset style="margin-bottom: 16px;">
                        <legend style="font-weight: 600; font-size: 14px; margin-bottom: 8px;">
                            <?php esc_html_e( 'Zu migrierende Daten:', 'smart-redirect-manager' ); ?>
                        </legend>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="migrate_redirects" value="1" checked />
                            <?php esc_html_e( 'Redirects migrieren', 'smart-redirect-manager' ); ?>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="migrate_404_logs" value="1" checked />
                            <?php esc_html_e( '404-Logs migrieren', 'smart-redirect-manager' ); ?>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="migrate_hit_stats" value="1" checked />
                            <?php esc_html_e( 'Hit-Statistiken migrieren', 'smart-redirect-manager' ); ?>
                        </label>

                        <hr style="margin: 12px 0;" />

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="deactivate_redirection" value="1" />
                            <?php esc_html_e( 'Redirection-Plugin nach Migration deaktivieren', 'smart-redirect-manager' ); ?>
                        </label>
                    </fieldset>

                    <div style="background: #f0f0f1; border-radius: 4px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #666;">
                        <strong><?php esc_html_e( 'Hinweis:', 'smart-redirect-manager' ); ?></strong>
                        <?php esc_html_e( 'Folgende Redirection-Eintraege werden nicht migriert: Redirects mit nicht-URL-Aktionstypen (z.B. "Error", "Random", "Pass") sowie Redirects mit erweiterten Bedingungen (z.B. "Login Status", "HTTP Header", "Cookie"). Nur einfache URL-zu-URL-Redirects werden unterstuetzt.', 'smart-redirect-manager' ); ?>
                    </div>

                    <?php
                    submit_button(
                        __( 'Migration starten', 'smart-redirect-manager' ),
                        'primary',
                        'srm_start_migration',
                        true,
                        array(
                            'onclick' => "return confirm('" . esc_js( __( 'Migration wirklich starten? Bitte stelle sicher, dass ein Datenbank-Backup vorhanden ist.', 'smart-redirect-manager' ) ) . "');",
                        )
                    );
                    ?>
                </form>

            </div>

        </div><!-- .wrap -->
        <?php
    }
}
