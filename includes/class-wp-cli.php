<?php
/**
 * WP-CLI commands for Smart Redirect Manager.
 *
 * Provides CLI access to redirect management, 404 log operations,
 * statistics, import/export, chain detection, and cleanup utilities.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SRM_WP_CLI
 *
 * Manages redirects, 404 logs, and tools via WP-CLI.
 */
class SRM_WP_CLI extends WP_CLI_Command {

    /**
     * List all redirects.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status. Accepts 'active' or 'inactive'.
     *
     * [--type=<type>]
     * : Filter by source type (e.g. 'manual', 'auto_post', 'cli', 'import').
     *
     * [--search=<search>]
     * : Search term matched against source_url and target_url.
     *
     * [--format=<format>]
     * : Output format. Accepts 'table', 'csv', 'json'. Default 'table'.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp srm list
     *     wp srm list --status=active --format=json
     *     wp srm list --type=manual --search=blog
     *
     * @subcommand list
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function list_( $args, $assoc_args ) {
        $query_args = array(
            'per_page' => 9999,
            'page'     => 1,
        );

        if ( ! empty( $assoc_args['status'] ) ) {
            $query_args['status'] = sanitize_text_field( $assoc_args['status'] );
        }

        if ( ! empty( $assoc_args['type'] ) ) {
            $query_args['type'] = sanitize_text_field( $assoc_args['type'] );
        }

        if ( ! empty( $assoc_args['search'] ) ) {
            $query_args['search'] = sanitize_text_field( $assoc_args['search'] );
        }

        $result = SRM_Database::get_redirects( $query_args );
        $items  = $result['items'];

        if ( empty( $items ) ) {
            WP_CLI::warning( 'Keine Weiterleitungen gefunden.' );
            return;
        }

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $fields = array(
            'id',
            'source_url',
            'target_url',
            'status_code',
            'is_regex',
            'is_active',
            'source_type',
            'hit_count',
            'created_at',
        );

        WP_CLI\Utils\format_items( $format, $items, $fields );

        WP_CLI::log( sprintf( 'Gesamt: %d Weiterleitungen.', $result['total'] ) );
    }

    /**
     * Add a new redirect.
     *
     * ## OPTIONS
     *
     * <source_url>
     * : The source URL to redirect from.
     *
     * <target_url>
     * : The target URL to redirect to.
     *
     * [--code=<code>]
     * : HTTP status code. Default 301.
     * ---
     * default: 301
     * ---
     *
     * [--regex]
     * : Mark the source URL as a regular expression.
     *
     * [--notes=<notes>]
     * : Optional notes for the redirect.
     *
     * ## EXAMPLES
     *
     *     wp srm add /old-page /new-page
     *     wp srm add /old-page /new-page --code=302
     *     wp srm add '/category/(.*)' '/archive/$1' --regex --notes="Category migration"
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function add( $args, $assoc_args ) {
        $source_url = $args[0];
        $target_url = $args[1];

        $data = array(
            'source_url'  => $source_url,
            'target_url'  => $target_url,
            'status_code' => isset( $assoc_args['code'] ) ? absint( $assoc_args['code'] ) : 301,
            'is_regex'    => isset( $assoc_args['regex'] ) ? 1 : 0,
            'is_active'   => 1,
            'source_type' => 'cli',
            'notes'       => isset( $assoc_args['notes'] ) ? sanitize_text_field( $assoc_args['notes'] ) : '',
        );

        $id = SRM_Database::save_redirect( $data );

        if ( false === $id ) {
            WP_CLI::error( 'Redirect konnte nicht erstellt werden.' );
        }

        WP_CLI::success( sprintf( 'Redirect erstellt: ID %d', $id ) );
    }

    /**
     * Delete a redirect.
     *
     * ## OPTIONS
     *
     * <id>
     * : The redirect ID to delete.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp srm delete 42
     *     wp srm delete 42 --yes
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function delete( $args, $assoc_args ) {
        $id = absint( $args[0] );

        if ( ! $id ) {
            WP_CLI::error( 'Ungueltige ID.' );
        }

        $redirect = SRM_Database::get_redirect( $id );

        if ( ! $redirect ) {
            WP_CLI::error( sprintf( 'Redirect mit ID %d nicht gefunden.', $id ) );
        }

        if ( ! isset( $assoc_args['yes'] ) ) {
            WP_CLI::confirm(
                sprintf(
                    'Redirect ID %d (%s -> %s) wirklich loeschen?',
                    $id,
                    $redirect->source_url,
                    $redirect->target_url
                )
            );
        }

        $result = SRM_Database::delete_redirect( $id );

        if ( $result ) {
            WP_CLI::success( sprintf( 'Redirect ID %d geloescht.', $id ) );
        } else {
            WP_CLI::error( sprintf( 'Redirect ID %d konnte nicht geloescht werden.', $id ) );
        }
    }

    /**
     * Test a URL against active redirects.
     *
     * ## OPTIONS
     *
     * <url>
     * : The URL to test.
     *
     * ## EXAMPLES
     *
     *     wp srm test /old-page
     *     wp srm test https://example.com/old-page
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function test( $args, $assoc_args ) {
        $url    = $args[0];
        $result = SRM_Tools::test_url( $url );

        if ( ! $result['found'] ) {
            WP_CLI::warning( sprintf( 'Kein Redirect fuer "%s" gefunden.', $url ) );
            return;
        }

        $redirect = $result['redirect'];

        WP_CLI::log( '--- Redirect gefunden ---' );
        WP_CLI::log( sprintf( 'ID:          %d', $redirect->id ) );
        WP_CLI::log( sprintf( 'Source:      %s', $redirect->source_url ) );
        WP_CLI::log( sprintf( 'Target:      %s', $redirect->target_url ) );
        WP_CLI::log( sprintf( 'Status Code: %d', $redirect->status_code ) );
        WP_CLI::log( sprintf( 'Match Type:  %s', $result['match_type'] ) );
        WP_CLI::log( sprintf( 'Is Regex:    %s', $redirect->is_regex ? 'Ja' : 'Nein' ) );

        if ( ! empty( $result['regex_groups'] ) ) {
            WP_CLI::log( sprintf( 'Regex Groups: %s', implode( ', ', $result['regex_groups'] ) ) );
        }

        if ( ! empty( $result['chain'] ) && count( $result['chain'] ) > 1 ) {
            WP_CLI::log( '' );
            WP_CLI::warning( sprintf( 'Redirect-Kette erkannt (%d Schritte):', count( $result['chain'] ) ) );

            foreach ( $result['chain'] as $i => $step ) {
                WP_CLI::log( sprintf(
                    '  %d. %s -> %s [%d]',
                    $i + 1,
                    $step['source_url'],
                    $step['target_url'],
                    $step['status_code']
                ) );
            }
        }
    }

    /**
     * Detect and optionally fix redirect chains.
     *
     * ## OPTIONS
     *
     * [--fix]
     * : Automatically fix detected chains by pointing all intermediate
     *   redirects directly to the final target URL.
     *
     * ## EXAMPLES
     *
     *     wp srm chains
     *     wp srm chains --fix
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function chains( $args, $assoc_args ) {
        if ( isset( $assoc_args['fix'] ) ) {
            $fixed = SRM_Tools::fix_chains();

            if ( $fixed > 0 ) {
                WP_CLI::success( sprintf( '%d Weiterleitungen korrigiert.', $fixed ) );
            } else {
                WP_CLI::log( 'Keine Ketten zum Korrigieren gefunden.' );
            }

            return;
        }

        $chains = SRM_Tools::find_all_chains();

        if ( empty( $chains ) ) {
            WP_CLI::success( 'Keine Redirect-Ketten gefunden.' );
            return;
        }

        WP_CLI::log( sprintf( '%d Kette(n) gefunden:', count( $chains ) ) );
        WP_CLI::log( '' );

        $table_items = array();

        foreach ( $chains as $chain ) {
            $steps_display = array();

            foreach ( $chain['steps'] as $step ) {
                $steps_display[] = $step->source_url . ' -> ' . $step->target_url;
            }

            $table_items[] = array(
                'start'   => $chain['start'],
                'length'  => $chain['length'],
                'is_loop' => $chain['is_loop'] ? 'Ja' : 'Nein',
                'steps'   => implode( ' | ', $steps_display ),
            );
        }

        WP_CLI\Utils\format_items(
            'table',
            $table_items,
            array( 'start', 'length', 'is_loop', 'steps' )
        );
    }

    /**
     * Show redirect statistics overview.
     *
     * ## EXAMPLES
     *
     *     wp srm stats
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function stats( $args, $assoc_args ) {
        $overview = SRM_Statistics::get_overview();

        WP_CLI::log( '--- Redirect Statistiken ---' );
        WP_CLI::log( sprintf( 'Weiterleitungen gesamt:  %d', $overview['total_redirects'] ) );
        WP_CLI::log( sprintf( 'Davon aktiv:             %d', $overview['active_redirects'] ) );
        WP_CLI::log( sprintf( 'Hits heute:              %d', $overview['hits_today'] ) );
        WP_CLI::log( sprintf( 'Hits letzte 7 Tage:      %d', $overview['hits_7_days'] ) );
        WP_CLI::log( sprintf( 'Offene 404-Fehler:       %d', $overview['open_404'] ) );

        if ( ! empty( $overview['top_redirects'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( '--- Top 5 Redirects (nach Hits) ---' );

            WP_CLI\Utils\format_items(
                'table',
                $overview['top_redirects'],
                array( 'id', 'source_url', 'target_url', 'hit_count' )
            );
        }

        if ( ! empty( $overview['top_404'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( '--- Top 5 404-Fehler ---' );

            WP_CLI\Utils\format_items(
                'table',
                $overview['top_404'],
                array( 'id', 'url', 'count' )
            );
        }
    }

    /**
     * Validate target URLs of active redirects via HTTP HEAD requests.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Maximum number of URLs to validate. Default 50.
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *
     *     wp srm validate
     *     wp srm validate --limit=100
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function validate( $args, $assoc_args ) {
        $limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
        $results = SRM_Tools::validate_urls( $limit );

        if ( empty( $results ) ) {
            WP_CLI::success( 'Keine URLs zum Validieren gefunden.' );
            return;
        }

        WP_CLI\Utils\format_items(
            'table',
            $results,
            array( 'redirect_id', 'source_url', 'url', 'status', 'http_code', 'message' )
        );

        $errors   = 0;
        $warnings = 0;
        $ok       = 0;

        foreach ( $results as $result ) {
            if ( 'error' === $result['status'] ) {
                $errors++;
            } elseif ( 'warning' === $result['status'] ) {
                $warnings++;
            } else {
                $ok++;
            }
        }

        WP_CLI::log( '' );
        WP_CLI::log( sprintf(
            'Ergebnis: %d OK, %d Warnungen, %d Fehler (von %d geprueft).',
            $ok,
            $warnings,
            $errors,
            count( $results )
        ) );
    }

    /**
     * Import redirects from a CSV file.
     *
     * The CSV file must contain at least 'source_url' and 'target_url' columns.
     * Delimiter is auto-detected (semicolon or comma).
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the CSV file to import.
     *
     * ## EXAMPLES
     *
     *     wp srm import /path/to/redirects.csv
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function import( $args, $assoc_args ) {
        $file_path = $args[0];

        if ( ! file_exists( $file_path ) ) {
            WP_CLI::error( sprintf( 'Datei nicht gefunden: %s', $file_path ) );
        }

        if ( ! is_readable( $file_path ) ) {
            WP_CLI::error( sprintf( 'Datei nicht lesbar: %s', $file_path ) );
        }

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( 'csv' !== $extension ) {
            WP_CLI::error( 'Nur CSV-Dateien werden unterstuetzt.' );
        }

        // Read file contents.
        $contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( false === $contents || '' === trim( $contents ) ) {
            WP_CLI::error( 'Die Datei ist leer oder konnte nicht gelesen werden.' );
        }

        // Strip UTF-8 BOM if present.
        if ( 0 === strpos( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        // Split into lines and auto-detect delimiter.
        $lines = preg_split( '/\r\n|\r|\n/', $contents );
        $lines = array_filter( $lines, function ( $line ) {
            return '' !== trim( $line );
        } );

        if ( empty( $lines ) ) {
            WP_CLI::error( 'Die Datei enthaelt keine Daten.' );
        }

        $first_line = reset( $lines );
        $delimiter  = ( false !== strpos( $first_line, ';' ) ) ? ';' : ',';

        // Parse header row and map columns.
        $header_line = array_shift( $lines );
        $headers     = str_getcsv( $header_line, $delimiter );
        $headers     = array_map( 'trim', $headers );
        $headers     = array_map( 'strtolower', $headers );
        $column_map  = array_flip( $headers );

        if ( ! isset( $column_map['source_url'] ) || ! isset( $column_map['target_url'] ) ) {
            WP_CLI::error( 'CSV muss die Spalten "source_url" und "target_url" enthalten.' );
        }

        // Build lookup of existing source URLs for duplicate detection.
        global $wpdb;

        $table              = SRM_Database::get_table_name( 'redirects' );
        $existing_rows      = $wpdb->get_results( "SELECT id, source_url FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_by_source = array();

        if ( $existing_rows ) {
            foreach ( $existing_rows as $existing_row ) {
                $existing_by_source[ $existing_row->source_url ] = (int) $existing_row->id;
            }
        }

        // Process each data row.
        $imported   = 0;
        $updated    = 0;
        $skipped    = 0;
        $errors     = 0;
        $row_number = 1;

        $progress = \WP_CLI\Utils\make_progress_bar( 'Importiere Redirects', count( $lines ) );

        foreach ( $lines as $line ) {
            $row_number++;
            $progress->tick();

            $line = trim( $line );

            if ( '' === $line ) {
                $skipped++;
                continue;
            }

            $fields = str_getcsv( $line, $delimiter );

            $source_url = isset( $column_map['source_url'], $fields[ $column_map['source_url'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['source_url'] ] ) )
                : '';
            $target_url = isset( $column_map['target_url'], $fields[ $column_map['target_url'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['target_url'] ] ) )
                : '';

            if ( '' === $source_url || '' === $target_url ) {
                $skipped++;
                continue;
            }

            $status_code = isset( $column_map['status_code'], $fields[ $column_map['status_code'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['status_code'] ] ) )
                : '301';
            $is_regex    = isset( $column_map['is_regex'], $fields[ $column_map['is_regex'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['is_regex'] ] ) )
                : '0';
            $is_active   = isset( $column_map['is_active'], $fields[ $column_map['is_active'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['is_active'] ] ) )
                : '1';
            $notes       = isset( $column_map['notes'], $fields[ $column_map['notes'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['notes'] ] ) )
                : '';
            $expires_at  = isset( $column_map['expires_at'], $fields[ $column_map['expires_at'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['expires_at'] ] ) )
                : null;

            if ( '' === $expires_at ) {
                $expires_at = null;
            }

            $normalized_source = SRM_Database::normalize_url( $source_url );

            $data = array(
                'source_url'  => $source_url,
                'target_url'  => $target_url,
                'status_code' => absint( $status_code ) > 0 ? absint( $status_code ) : 301,
                'is_regex'    => absint( $is_regex ),
                'is_active'   => absint( $is_active ),
                'source_type' => 'import',
                'notes'       => $notes,
                'expires_at'  => $expires_at,
            );

            $is_update = false;

            if ( isset( $existing_by_source[ $normalized_source ] ) ) {
                $data['id'] = $existing_by_source[ $normalized_source ];
                $is_update  = true;
            }

            $saved_id = SRM_Database::save_redirect( $data );

            if ( false === $saved_id ) {
                $errors++;
                WP_CLI::warning( sprintf( 'Zeile %d: Konnte nicht gespeichert werden (%s).', $row_number, $source_url ) );
                continue;
            }

            if ( $is_update ) {
                $updated++;
            } else {
                $imported++;
                $existing_by_source[ $normalized_source ] = (int) $saved_id;
            }
        }

        $progress->finish();

        WP_CLI::log( '' );
        WP_CLI::log( '--- Import Ergebnis ---' );
        WP_CLI::log( sprintf( 'Importiert:   %d', $imported ) );
        WP_CLI::log( sprintf( 'Aktualisiert: %d', $updated ) );
        WP_CLI::log( sprintf( 'Uebersprungen: %d', $skipped ) );
        WP_CLI::log( sprintf( 'Fehler:       %d', $errors ) );

        if ( $imported > 0 || $updated > 0 ) {
            WP_CLI::success( 'Import abgeschlossen.' );
        }
    }

    /**
     * Export active redirects as Apache .htaccess rules.
     *
     * ## EXAMPLES
     *
     *     wp srm htaccess
     *     wp srm htaccess > .htaccess-redirects
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function htaccess( $args, $assoc_args ) {
        $output = SRM_Tools::export_htaccess();

        if ( empty( $output ) ) {
            WP_CLI::warning( 'Keine aktiven Redirects fuer den Export gefunden.' );
            return;
        }

        WP_CLI::line( $output );
    }

    /**
     * Export active redirects as Nginx configuration rules.
     *
     * ## EXAMPLES
     *
     *     wp srm nginx
     *     wp srm nginx > nginx-redirects.conf
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function nginx( $args, $assoc_args ) {
        $output = SRM_Tools::export_nginx();

        if ( empty( $output ) ) {
            WP_CLI::warning( 'Keine aktiven Redirects fuer den Export gefunden.' );
            return;
        }

        WP_CLI::line( $output );
    }

    /**
     * Migrate redirects from other plugins.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would be migrated without making changes.
     *
     * ## EXAMPLES
     *
     *     wp srm migrate --dry-run
     *     wp srm migrate
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function migrate( $args, $assoc_args ) {
        if ( ! class_exists( 'SRM_Migration' ) ) {
            WP_CLI::error( 'Die Klasse SRM_Migration ist nicht verfuegbar.' );
        }

        if ( isset( $assoc_args['dry-run'] ) ) {
            $preview = SRM_Migration::get_preview();

            if ( empty( $preview ) ) {
                WP_CLI::log( 'Keine migrierbaren Redirects gefunden.' );
                return;
            }

            WP_CLI::log( '--- Migrations-Vorschau (Dry Run) ---' );

            if ( is_array( $preview ) && isset( $preview[0] ) && is_object( $preview[0] ) ) {
                WP_CLI\Utils\format_items(
                    'table',
                    $preview,
                    array_keys( get_object_vars( $preview[0] ) )
                );
            } elseif ( is_array( $preview ) && isset( $preview[0] ) && is_array( $preview[0] ) ) {
                WP_CLI\Utils\format_items(
                    'table',
                    $preview,
                    array_keys( $preview[0] )
                );
            } else {
                WP_CLI::log( print_r( $preview, true ) );
            }

            return;
        }

        $results = SRM_Migration::migrate();

        if ( is_wp_error( $results ) ) {
            WP_CLI::error( $results->get_error_message() );
        }

        if ( is_array( $results ) ) {
            foreach ( $results as $key => $value ) {
                WP_CLI::log( sprintf( '%s: %s', $key, $value ) );
            }

            WP_CLI::success( 'Migration abgeschlossen.' );
        } else {
            WP_CLI::success( 'Migration abgeschlossen.' );
        }
    }

    /**
     * Show 404 error log entries.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Number of entries to show. Default 20.
     * ---
     * default: 20
     * ---
     *
     * ## EXAMPLES
     *
     *     wp srm e404
     *     wp srm e404 --limit=50
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function e404( $args, $assoc_args ) {
        $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
        $result = SRM_404_Logger::get_logs( array(
            'per_page' => $limit,
            'page'     => 1,
            'status'   => 'unresolved',
            'orderby'  => 'count',
            'order'    => 'DESC',
        ) );

        $items = $result['items'];

        if ( empty( $items ) ) {
            WP_CLI::success( 'Keine 404-Eintraege gefunden.' );
            return;
        }

        WP_CLI\Utils\format_items(
            'table',
            $items,
            array( 'id', 'request_url', 'count', 'last_occurred', 'referer', 'is_resolved' )
        );

        WP_CLI::log( sprintf( 'Gesamt: %d 404-Eintraege.', $result['total'] ) );
    }

    /**
     * Delete old 404 log entries.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Delete entries older than this many days. Default 30.
     * ---
     * default: 30
     * ---
     *
     * ## EXAMPLES
     *
     *     wp srm cleanup
     *     wp srm cleanup --days=60
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function cleanup( $args, $assoc_args ) {
        global $wpdb;

        $days       = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 30;
        $table_name = SRM_Database::get_table_name( '404_log' );

        // Count entries that will be deleted.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $days
            )
        );

        if ( 0 === $count ) {
            WP_CLI::success( sprintf( 'Keine 404-Eintraege aelter als %d Tage gefunden.', $days ) );
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $days
            )
        );

        WP_CLI::success( sprintf( '%d 404-Eintraege aelter als %d Tage geloescht.', $count, $days ) );
    }

    /**
     * Clean up old, unused redirects.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview candidates without making changes.
     *
     * [--force]
     * : Force cleanup even if auto-cleanup is disabled in settings.
     *
     * ## EXAMPLES
     *
     *     wp srm cleanup_redirects --dry-run
     *     wp srm cleanup_redirects
     *     wp srm cleanup_redirects --force
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function cleanup_redirects( $args, $assoc_args ) {
        if ( isset( $assoc_args['dry-run'] ) ) {
            $candidates = SRM_Auto_Cleanup::get_cleanup_candidates();

            if ( empty( $candidates ) ) {
                WP_CLI::success( 'Keine Cleanup-Kandidaten gefunden.' );
                return;
            }

            WP_CLI::log( sprintf( '--- %d Cleanup-Kandidaten (Dry Run) ---', count( $candidates ) ) );

            $table_items = array();

            foreach ( $candidates as $candidate ) {
                $table_items[] = array(
                    'id'          => $candidate->id,
                    'source_url'  => $candidate->source_url,
                    'target_url'  => $candidate->target_url,
                    'source_type' => $candidate->source_type,
                    'hit_count'   => $candidate->hit_count,
                    'last_hit'    => $candidate->last_hit ? $candidate->last_hit : '-',
                    'created_at'  => $candidate->created_at,
                );
            }

            WP_CLI\Utils\format_items(
                'table',
                $table_items,
                array( 'id', 'source_url', 'target_url', 'source_type', 'hit_count', 'last_hit', 'created_at' )
            );

            return;
        }

        $force = isset( $assoc_args['force'] );

        // Temporarily enable auto-cleanup if forced.
        if ( $force ) {
            $settings = SRM_Database::get_settings();

            if ( empty( $settings['auto_cleanup_enabled'] ) ) {
                // Temporarily enable for this run.
                $settings['auto_cleanup_enabled'] = true;
                SRM_Database::save_settings( $settings );
            }
        }

        SRM_Auto_Cleanup::run_cleanup();

        $result = get_transient( 'srm_last_cleanup_result' );

        if ( ! empty( $result ) && isset( $result['count'] ) && $result['count'] > 0 ) {
            $action_labels = array(
                'deactivate'  => 'deaktiviert',
                'delete'      => 'geloescht',
                'convert_410' => 'zu 410 Gone konvertiert',
            );

            $action_label = isset( $action_labels[ $result['action'] ] )
                ? $action_labels[ $result['action'] ]
                : $result['action'];

            WP_CLI::success( sprintf(
                '%d Weiterleitungen %s.',
                $result['count'],
                $action_label
            ) );

            if ( ! empty( $result['items'] ) ) {
                WP_CLI\Utils\format_items(
                    'table',
                    $result['items'],
                    array( 'id', 'source_url' )
                );
            }
        } else {
            WP_CLI::log( 'Keine Weiterleitungen zum Bereinigen gefunden.' );
        }
    }
}

WP_CLI::add_command( 'srm', 'SRM_WP_CLI' );
