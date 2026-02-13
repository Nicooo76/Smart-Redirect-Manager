<?php
/**
 * Admin Tools page for Smart Redirect Manager.
 *
 * Renders the Tools admin page with tabbed interface for URL testing,
 * chain/loop detection, URL validation, duplicate detection,
 * server rule export, and group management.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SRM_Admin_Tools
 *
 * Tools page with tabbed navigation. All UI text is in German.
 */
class SRM_Admin_Tools {

    /**
     * Render the Tools admin page.
     *
     * @return void
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'url-tester';

        $tabs = array(
            'url-tester'   => 'URL-Tester',
            'chains'       => 'Ketten &amp; Loops',
            'validation'   => 'URL-Validierung',
            'duplicates'   => 'Duplikate',
            'server-rules' => 'Server-Regeln',
            'groups'       => 'Gruppen',
        );

        ?>
        <div class="wrap">
            <h1>Tools</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-tools&tab=' . $tab_slug ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo $tab_label; // Already escaped or entity-encoded. ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="margin-top: 20px;">
                <?php
                switch ( $active_tab ) {
                    case 'url-tester':
                        self::render_tab_url_tester();
                        break;
                    case 'chains':
                        self::render_tab_chains();
                        break;
                    case 'validation':
                        self::render_tab_validation();
                        break;
                    case 'duplicates':
                        self::render_tab_duplicates();
                        break;
                    case 'server-rules':
                        self::render_tab_server_rules();
                        break;
                    case 'groups':
                        self::render_tab_groups();
                        break;
                    default:
                        self::render_tab_url_tester();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: URL-Tester
    // -------------------------------------------------------------------------

    /**
     * Render the URL-Tester tab content.
     *
     * @return void
     */
    private static function render_tab_url_tester() {
        $result   = null;
        $test_url = '';

        // Handle form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['action'] ) && 'srm_test_url' === $_POST['action'] ) {
            check_admin_referer( 'srm_test_url_action', 'srm_test_url_nonce' );

            $test_url = isset( $_POST['test_url'] ) ? sanitize_text_field( wp_unslash( $_POST['test_url'] ) ) : '';

            if ( ! empty( $test_url ) ) {
                $result = SRM_Tools::test_url( $test_url );
            }
        }

        ?>
        <div class="srm-tools-url-tester">
            <form method="post">
                <?php wp_nonce_field( 'srm_test_url_action', 'srm_test_url_nonce' ); ?>
                <input type="hidden" name="action" value="srm_test_url" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="srm-test-url">URL</label></th>
                        <td>
                            <input type="text" id="srm-test-url" name="test_url"
                                   value="<?php echo esc_attr( $test_url ); ?>"
                                   class="regular-text" placeholder="/beispiel-seite/" />
                            <?php submit_button( 'Testen', 'primary', 'submit', false ); ?>
                        </td>
                    </tr>
                </table>
            </form>

            <?php if ( null !== $result ) : ?>
                <?php if ( $result['found'] ) : ?>
                    <?php
                    $redirect   = $result['redirect'];
                    $conditions = array();
                    if ( class_exists( 'SRM_Conditions' ) ) {
                        $conditions = SRM_Conditions::get_conditions( $redirect->id );
                    }
                    $edit_url = admin_url( 'admin.php?page=srm-redirects&action=edit&id=' . absint( $redirect->id ) );
                    ?>
                    <table class="widefat striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th>Eigenschaft</th>
                                <th>Wert</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Eingabe</strong></td>
                                <td><?php echo esc_html( $test_url ); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Normalisiert</strong></td>
                                <td><?php echo esc_html( SRM_Database::normalize_url( $test_url ) ); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Match-Typ</strong></td>
                                <td>
                                    <?php if ( 'exact' === $result['match_type'] ) : ?>
                                        <span class="srm-badge srm-badge-info">Exact</span>
                                    <?php else : ?>
                                        <span class="srm-badge srm-badge-warning">Regex</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Ziel-URL</strong></td>
                                <td><?php echo esc_html( $redirect->target_url ); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status-Code</strong></td>
                                <td><?php echo esc_html( $redirect->status_code ); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Redirect-ID</strong></td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>">
                                        #<?php echo absint( $redirect->id ); ?>
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Conditions</strong></td>
                                <td><?php echo count( $conditions ); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( ! empty( $result['chain'] ) && count( $result['chain'] ) > 1 ) : ?>
                        <div class="notice notice-warning" style="margin-top: 15px; padding: 10px 15px;">
                            <p><strong>Redirect-Kette erkannt!</strong></p>
                            <ol>
                                <?php foreach ( $result['chain'] as $index => $step ) : ?>
                                    <li>
                                        <?php echo esc_html( $step['source_url'] ); ?>
                                        &rarr;
                                        <?php echo esc_html( $step['target_url'] ); ?>
                                        <em>(<?php echo esc_html( $step['status_code'] ); ?>)</em>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>

                <?php else : ?>
                    <div class="notice notice-info" style="margin-top: 15px; padding: 10px 15px;">
                        <p>Kein Redirect f&uuml;r diese URL gefunden.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Ketten & Loops
    // -------------------------------------------------------------------------

    /**
     * Render the Ketten & Loops tab content.
     *
     * @return void
     */
    private static function render_tab_chains() {
        $fixed_count = null;

        // Handle fix chains form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['action'] ) && 'srm_fix_chains' === $_POST['action'] ) {
            check_admin_referer( 'srm_fix_chains_action', 'srm_fix_chains_nonce' );
            $fixed_count = SRM_Tools::fix_chains();
        }

        // Show success notice after fixing chains.
        if ( null !== $fixed_count ) {
            ?>
            <div class="notice notice-success" style="padding: 10px 15px;">
                <p>
                    <?php
                    printf(
                        /* translators: %d: number of fixed redirects */
                        '%d Redirect(s) wurden erfolgreich aktualisiert.',
                        $fixed_count
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Auto-analyze chains on page load.
        $chains = SRM_Tools::find_all_chains();

        if ( ! empty( $chains ) ) :
            ?>
            <table class="widefat striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th>Start-URL</th>
                        <th>L&auml;nge</th>
                        <th>Typ</th>
                        <th>Visualisierung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $chains as $chain ) : ?>
                        <tr>
                            <td><?php echo esc_html( $chain['start'] ); ?></td>
                            <td><?php echo absint( $chain['length'] ); ?></td>
                            <td>
                                <?php if ( $chain['is_loop'] ) : ?>
                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; background: #dc3232; color: #fff; font-size: 12px;">Loop</span>
                                <?php else : ?>
                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; background: #2271b1; color: #fff; font-size: 12px;">Kette</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $visualization_parts = array();
                                foreach ( $chain['steps'] as $step ) {
                                    $visualization_parts[] = esc_html( $step->source_url );
                                }
                                // Add the final target from the last step.
                                $last_step = end( $chain['steps'] );
                                if ( $last_step ) {
                                    $visualization_parts[] = esc_html( $last_step->target_url );
                                }
                                echo implode( ' &rarr; ', $visualization_parts );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" style="margin-top: 15px;"
                  onsubmit="return confirm('Soll die automatische Kettenaufl\u00f6sung wirklich durchgef\u00fchrt werden? Alle Zwischen-Redirects werden direkt auf das finale Ziel umgeleitet.');">
                <?php wp_nonce_field( 'srm_fix_chains_action', 'srm_fix_chains_nonce' ); ?>
                <input type="hidden" name="action" value="srm_fix_chains" />
                <?php submit_button( 'Ketten automatisch aufl&ouml;sen', 'primary', 'submit', false ); ?>
            </form>

        <?php else : ?>
            <div class="notice notice-success" style="margin-top: 15px; padding: 10px 15px;">
                <p>Keine Ketten oder Loops gefunden.</p>
            </div>
        <?php
        endif;
    }

    // -------------------------------------------------------------------------
    // Tab: URL-Validierung
    // -------------------------------------------------------------------------

    /**
     * Render the URL-Validierung tab content.
     *
     * @return void
     */
    private static function render_tab_validation() {
        $results = null;
        $limit   = 50;

        // Handle form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['action'] ) && 'srm_validate_urls' === $_POST['action'] ) {
            check_admin_referer( 'srm_validate_urls_action', 'srm_validate_urls_nonce' );

            $limit   = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
            $results = SRM_Tools::validate_urls( $limit );
        }

        ?>
        <div class="srm-tools-validation">
            <form method="post">
                <?php wp_nonce_field( 'srm_validate_urls_action', 'srm_validate_urls_nonce' ); ?>
                <input type="hidden" name="action" value="srm_validate_urls" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="srm-validate-limit">Anzahl</label></th>
                        <td>
                            <input type="number" id="srm-validate-limit" name="limit"
                                   value="<?php echo absint( $limit ); ?>"
                                   min="1" max="500" step="1" class="small-text" />
                            <?php submit_button( 'Validierung starten', 'primary', 'submit', false ); ?>
                        </td>
                    </tr>
                </table>
            </form>

            <?php if ( null !== $results ) : ?>
                <?php
                $count_ok      = 0;
                $count_problem = 0;

                foreach ( $results as $entry ) {
                    if ( 'ok' === $entry['status'] ) {
                        $count_ok++;
                    } else {
                        $count_problem++;
                    }
                }
                ?>

                <div style="margin: 15px 0;">
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 3px; background: #00a32a; color: #fff; font-size: 13px; margin-right: 8px;">
                        <?php echo absint( $count_ok ); ?> OK
                    </span>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 3px; background: #dc3232; color: #fff; font-size: 13px;">
                        <?php echo absint( $count_problem ); ?> Probleme
                    </span>
                </div>

                <?php if ( ! empty( $results ) ) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">Status</th>
                                <th>Quell-URL</th>
                                <th>Ziel-URL</th>
                                <th>HTTP-Code</th>
                                <th>Info</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $results as $entry ) : ?>
                                <tr>
                                    <td style="text-align: center; font-size: 16px;">
                                        <?php if ( 'ok' === $entry['status'] ) : ?>
                                            <span style="color: #00a32a;" title="OK">&#10003;</span>
                                        <?php elseif ( 'warning' === $entry['status'] ) : ?>
                                            <span style="color: #dba617;" title="Warnung">&#9888;</span>
                                        <?php else : ?>
                                            <span style="color: #dc3232;" title="Fehler">&#10007;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $entry['source_url'] ); ?></td>
                                    <td><?php echo esc_html( $entry['url'] ); ?></td>
                                    <td><?php echo null !== $entry['http_code'] ? absint( $entry['http_code'] ) : '&mdash;'; ?></td>
                                    <td><?php echo esc_html( $entry['message'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Duplikate
    // -------------------------------------------------------------------------

    /**
     * Render the Duplikate tab content.
     *
     * @return void
     */
    private static function render_tab_duplicates() {
        // Auto-analyze duplicates on page load.
        $duplicates = SRM_Tools::find_duplicates();

        if ( ! empty( $duplicates ) ) :
            ?>
            <table class="widefat striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th>Quell-URL</th>
                        <th>Anzahl</th>
                        <th>IDs</th>
                        <th>Ziel-URLs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $duplicates as $duplicate ) : ?>
                        <tr>
                            <td><?php echo esc_html( $duplicate['source_url'] ); ?></td>
                            <td><?php echo absint( $duplicate['count'] ); ?></td>
                            <td>
                                <?php
                                $ids = array_map( 'absint', explode( ',', $duplicate['ids'] ) );
                                $id_links = array();
                                foreach ( $ids as $id ) {
                                    $edit_url   = admin_url( 'admin.php?page=srm-redirects&action=edit&id=' . $id );
                                    $id_links[] = '<a href="' . esc_url( $edit_url ) . '">#' . $id . '</a>';
                                }
                                echo implode( ', ', $id_links ); // Links are already escaped.
                                ?>
                            </td>
                            <td>
                                <?php
                                $target_list = array();
                                foreach ( $duplicate['target_urls'] as $target ) {
                                    $target_list[] = esc_html( $target );
                                }
                                echo implode( '<br>', $target_list );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <div class="notice notice-success" style="margin-top: 15px; padding: 10px 15px;">
                <p>Keine Duplikate gefunden.</p>
            </div>
        <?php
        endif;
    }

    // -------------------------------------------------------------------------
    // Tab: Server-Regeln
    // -------------------------------------------------------------------------

    /**
     * Render the Server-Regeln tab content.
     *
     * @return void
     */
    private static function render_tab_server_rules() {
        $htaccess_rules = SRM_Tools::export_htaccess();
        $nginx_rules    = SRM_Tools::export_nginx();

        ?>
        <div style="display: flex; gap: 20px; margin-top: 15px; flex-wrap: wrap;">

            <div style="flex: 1; min-width: 400px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
                <h2 style="margin-top: 0;">Apache .htaccess</h2>
                <textarea readonly onclick="this.select()"
                          style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; resize: vertical;"
                ><?php echo esc_textarea( $htaccess_rules ); ?></textarea>
                <p class="description" style="margin-top: 10px;">
                    Kopieren Sie diese Regeln in Ihre <code>.htaccess</code>-Datei im WordPress-Stammverzeichnis.
                    Die Regeln sollten <strong>vor</strong> den WordPress-Rewrite-Regeln eingef&uuml;gt werden.
                </p>
            </div>

            <div style="flex: 1; min-width: 400px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
                <h2 style="margin-top: 0;">Nginx Config</h2>
                <textarea readonly onclick="this.select()"
                          style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; resize: vertical;"
                ><?php echo esc_textarea( $nginx_rules ); ?></textarea>
                <p class="description" style="margin-top: 10px;">
                    F&uuml;gen Sie diese Regeln in Ihren Nginx-Server-Block ein.
                    Die Regeln sollten <strong>vor</strong> der <code>location /</code>-Direktive stehen.
                </p>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Gruppen
    // -------------------------------------------------------------------------

    /**
     * Render the Gruppen tab content.
     *
     * @return void
     */
    private static function render_tab_groups() {
        // Handle delete action.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['srm_action'] ) && 'delete_group' === $_GET['srm_action'] ) {
            check_admin_referer( 'srm_delete_group_action', 'srm_delete_group_nonce' );

            $delete_id = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
            if ( $delete_id ) {
                SRM_Groups::delete_group( $delete_id );
                ?>
                <div class="notice notice-success" style="padding: 10px 15px;">
                    <p>Gruppe wurde gel&ouml;scht.</p>
                </div>
                <?php
            }
        }

        // Handle create group form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['action'] ) && 'srm_save_group' === $_POST['action'] ) {
            check_admin_referer( 'srm_save_group_action', 'srm_save_group_nonce' );

            $group_data = array(
                'name'        => isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '',
                'description' => isset( $_POST['group_description'] ) ? sanitize_text_field( wp_unslash( $_POST['group_description'] ) ) : '',
                'color'       => isset( $_POST['group_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['group_color'] ) ) : '#2271b1',
            );

            $saved_id = SRM_Groups::save_group( $group_data );

            if ( false !== $saved_id ) {
                ?>
                <div class="notice notice-success" style="padding: 10px 15px;">
                    <p>Gruppe wurde erfolgreich erstellt.</p>
                </div>
                <?php
            } else {
                ?>
                <div class="notice notice-error" style="padding: 10px 15px;">
                    <p>Gruppe konnte nicht erstellt werden. Bitte geben Sie mindestens einen Namen ein.</p>
                </div>
                <?php
            }
        }

        // List existing groups.
        $groups = SRM_Groups::get_groups();

        ?>
        <h2 style="margin-top: 20px;">Vorhandene Gruppen</h2>

        <?php if ( ! empty( $groups ) ) : ?>
            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 50px;">Farbe</th>
                        <th>Name</th>
                        <th>Beschreibung</th>
                        <th>Redirects</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $groups as $group ) : ?>
                        <?php
                        $color      = ! empty( $group->color ) ? $group->color : '#2271b1';
                        $count      = isset( $group->redirect_count ) ? absint( $group->redirect_count ) : 0;
                        $delete_url = wp_nonce_url(
                            admin_url( 'admin.php?page=srm-tools&tab=groups&srm_action=delete_group&group_id=' . absint( $group->id ) ),
                            'srm_delete_group_action',
                            'srm_delete_group_nonce'
                        );
                        ?>
                        <tr>
                            <td style="text-align: center;">
                                <span style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background-color: <?php echo esc_attr( $color ); ?>;"></span>
                            </td>
                            <td><strong><?php echo esc_html( $group->name ); ?></strong></td>
                            <td><?php echo esc_html( $group->description ); ?></td>
                            <td><?php echo $count; ?></td>
                            <td>
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   class="submitdelete"
                                   onclick="return confirm('Soll diese Gruppe wirklich gel\u00f6scht werden? Zugewiesene Redirects werden nicht gel\u00f6scht, sondern nur die Gruppenzuordnung entfernt.');">
                                    L&ouml;schen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>Keine Gruppen vorhanden.</p>
        <?php endif; ?>

        <hr style="margin: 30px 0;" />

        <h2>Neue Gruppe erstellen</h2>
        <form method="post" style="margin-top: 10px;">
            <?php wp_nonce_field( 'srm_save_group_action', 'srm_save_group_nonce' ); ?>
            <input type="hidden" name="action" value="srm_save_group" />

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="srm-group-name">Name</label></th>
                    <td>
                        <input type="text" id="srm-group-name" name="group_name"
                               value="" class="regular-text" required />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="srm-group-description">Beschreibung</label></th>
                    <td>
                        <textarea id="srm-group-description" name="group_description"
                                  rows="3" class="large-text"></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="srm-group-color">Farbe</label></th>
                    <td>
                        <input type="color" id="srm-group-color" name="group_color"
                               value="#2271b1" />
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Gruppe erstellen' ); ?>
        </form>
        <?php
    }
}
