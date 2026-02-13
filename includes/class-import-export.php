<?php
/**
 * Import and Export handler for Smart Redirect Manager.
 *
 * Provides CSV export for redirects and 404 log entries,
 * as well as CSV import with duplicate detection and
 * automatic delimiter detection.
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
 * Class SRM_Import_Export
 *
 * Handles CSV import and export operations for the Smart Redirect Manager plugin.
 */
class SRM_Import_Export {

    // -------------------------------------------------------------------------
    // Export: Redirects
    // -------------------------------------------------------------------------

    /**
     * Export all redirects as a CSV file download.
     *
     * Sets appropriate headers, outputs a UTF-8 BOM, writes a header row
     * followed by one row per redirect, and terminates execution.
     *
     * @return void
     */
    public static function export_redirects() {
        $date     = gmdate( 'Y-m-d' );
        $filename = 'redirects-export-' . $date . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for proper encoding in spreadsheet applications.
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row.
        fputcsv(
            $output,
            array(
                'source_url',
                'target_url',
                'status_code',
                'is_regex',
                'is_active',
                'source_type',
                'hit_count',
                'notes',
                'expires_at',
                'created_at',
            ),
            ';'
        );

        // Fetch all redirects without pagination.
        global $wpdb;

        $table   = SRM_Database::get_table_name( 'redirects' );
        $results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $results ) {
            foreach ( $results as $row ) {
                fputcsv(
                    $output,
                    array(
                        $row->source_url,
                        $row->target_url,
                        $row->status_code,
                        $row->is_regex,
                        $row->is_active,
                        $row->source_type,
                        $row->hit_count,
                        $row->notes,
                        $row->expires_at,
                        $row->created_at,
                    ),
                    ';'
                );
            }
        }

        fclose( $output );
        exit;
    }

    // -------------------------------------------------------------------------
    // Export: 404 Log
    // -------------------------------------------------------------------------

    /**
     * Export all 404 log entries as a CSV file download.
     *
     * Sets appropriate headers, outputs a UTF-8 BOM, writes a header row
     * followed by one row per log entry, and terminates execution.
     *
     * @return void
     */
    public static function export_404_log() {
        $date     = gmdate( 'Y-m-d' );
        $filename = '404-log-export-' . $date . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for proper encoding in spreadsheet applications.
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row.
        fputcsv(
            $output,
            array(
                'request_url',
                'referer',
                'user_agent',
                'ip_address',
                'count',
                'last_occurred',
                'is_resolved',
                'created_at',
            ),
            ';'
        );

        // Fetch all 404 log entries without pagination.
        global $wpdb;

        $table   = SRM_Database::get_table_name( '404_log' );
        $results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $results ) {
            foreach ( $results as $row ) {
                fputcsv(
                    $output,
                    array(
                        $row->request_url,
                        $row->referer,
                        $row->user_agent,
                        $row->ip_address,
                        $row->count,
                        $row->last_occurred,
                        $row->is_resolved,
                        $row->created_at,
                    ),
                    ';'
                );
            }
        }

        fclose( $output );
        exit;
    }

    // -------------------------------------------------------------------------
    // Import: Redirects
    // -------------------------------------------------------------------------

    /**
     * Import redirects from an uploaded CSV file.
     *
     * Supports both semicolon and comma delimiters (auto-detected).
     * Performs duplicate checking against existing source URLs and updates
     * existing records rather than creating duplicates.
     *
     * @param array $file A single element from the $_FILES superglobal.
     * @return array {
     *     Import result summary.
     *
     *     @type int   $imported Number of newly created redirects.
     *     @type int   $updated  Number of existing redirects that were updated.
     *     @type int   $skipped  Number of rows skipped (empty or missing required fields).
     *     @type array $errors   Array of error message strings.
     * }
     */
    public static function import_redirects( $file ) {
        $result = array(
            'imported' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => array(),
        );

        // ------------------------------------------------------------------
        // 1. Validate file.
        // ------------------------------------------------------------------
        if ( empty( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            $result['errors'][] = __( 'No valid file uploaded.', 'smart-redirect-manager' );
            return $result;
        }

        // Check file extension.
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( 'csv' !== $extension ) {
            $result['errors'][] = __( 'Invalid file type. Only .csv files are allowed.', 'smart-redirect-manager' );
            return $result;
        }

        // ------------------------------------------------------------------
        // 2. Read file contents.
        // ------------------------------------------------------------------
        $contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( false === $contents || '' === trim( $contents ) ) {
            $result['errors'][] = __( 'The uploaded file is empty or could not be read.', 'smart-redirect-manager' );
            return $result;
        }

        // Strip UTF-8 BOM if present.
        if ( 0 === strpos( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        // ------------------------------------------------------------------
        // 3. Split into lines and auto-detect delimiter.
        // ------------------------------------------------------------------
        $lines = preg_split( '/\r\n|\r|\n/', $contents );
        $lines = array_filter( $lines, function ( $line ) {
            return '' !== trim( $line );
        } );

        if ( empty( $lines ) ) {
            $result['errors'][] = __( 'The uploaded file contains no data.', 'smart-redirect-manager' );
            return $result;
        }

        $first_line = reset( $lines );
        $delimiter  = ( false !== strpos( $first_line, ';' ) ) ? ';' : ',';

        // ------------------------------------------------------------------
        // 4. Parse header row and map columns.
        // ------------------------------------------------------------------
        $header_line = array_shift( $lines );
        $headers     = str_getcsv( $header_line, $delimiter );
        $headers     = array_map( 'trim', $headers );
        $headers     = array_map( 'strtolower', $headers );

        $column_map = array_flip( $headers );

        // Validate required columns.
        if ( ! isset( $column_map['source_url'] ) || ! isset( $column_map['target_url'] ) ) {
            $result['errors'][] = __( 'CSV is missing required columns: source_url and target_url.', 'smart-redirect-manager' );
            return $result;
        }

        // ------------------------------------------------------------------
        // 5. Build a lookup of existing source URLs for duplicate detection.
        // ------------------------------------------------------------------
        global $wpdb;

        $table             = SRM_Database::get_table_name( 'redirects' );
        $existing_rows     = $wpdb->get_results( "SELECT id, source_url FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_by_source = array();

        if ( $existing_rows ) {
            foreach ( $existing_rows as $existing_row ) {
                $existing_by_source[ $existing_row->source_url ] = (int) $existing_row->id;
            }
        }

        // ------------------------------------------------------------------
        // 6. Process each data row.
        // ------------------------------------------------------------------
        $row_number = 1; // 1 = first data row (header was row 0).

        foreach ( $lines as $line ) {
            $row_number++;

            $line = trim( $line );

            // Skip empty rows.
            if ( '' === $line ) {
                $result['skipped']++;
                continue;
            }

            $fields = str_getcsv( $line, $delimiter );

            // Extract values by column index.
            $source_url  = isset( $column_map['source_url'] ) && isset( $fields[ $column_map['source_url'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['source_url'] ] ) )
                : '';
            $target_url  = isset( $column_map['target_url'] ) && isset( $fields[ $column_map['target_url'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['target_url'] ] ) )
                : '';

            // Skip rows with missing required fields.
            if ( '' === $source_url || '' === $target_url ) {
                $result['skipped']++;
                continue;
            }

            // Optional fields with defaults.
            $status_code = isset( $column_map['status_code'] ) && isset( $fields[ $column_map['status_code'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['status_code'] ] ) )
                : '301';
            $is_regex    = isset( $column_map['is_regex'] ) && isset( $fields[ $column_map['is_regex'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['is_regex'] ] ) )
                : '0';
            $is_active   = isset( $column_map['is_active'] ) && isset( $fields[ $column_map['is_active'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['is_active'] ] ) )
                : '1';
            $notes       = isset( $column_map['notes'] ) && isset( $fields[ $column_map['notes'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['notes'] ] ) )
                : '';
            $expires_at  = isset( $column_map['expires_at'] ) && isset( $fields[ $column_map['expires_at'] ] )
                ? sanitize_text_field( trim( $fields[ $column_map['expires_at'] ] ) )
                : null;

            if ( '' === $expires_at ) {
                $expires_at = null;
            }

            // Normalize source URL for duplicate detection.
            $normalized_source = SRM_Database::normalize_url( $source_url );

            // Build data array for save_redirect().
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

            // Duplicate check: if a redirect with the same source_url exists, update it.
            $is_update = false;

            if ( isset( $existing_by_source[ $normalized_source ] ) ) {
                $data['id'] = $existing_by_source[ $normalized_source ];
                $is_update  = true;
            }

            // Save the redirect.
            $saved_id = SRM_Database::save_redirect( $data );

            if ( false === $saved_id ) {
                /* translators: %d: CSV row number */
                $result['errors'][] = sprintf(
                    __( 'Failed to save redirect at row %d (source: %s).', 'smart-redirect-manager' ),
                    $row_number,
                    $source_url
                );
                continue;
            }

            if ( $is_update ) {
                $result['updated']++;
            } else {
                $result['imported']++;

                // Track newly imported source URL for intra-file duplicate detection.
                $existing_by_source[ $normalized_source ] = (int) $saved_id;
            }
        }

        return $result;
    }
}
