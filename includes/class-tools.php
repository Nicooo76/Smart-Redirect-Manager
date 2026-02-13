<?php
/**
 * Tools for Smart Redirect Manager.
 *
 * Provides URL testing, redirect chain detection, duplicate finding,
 * target URL validation, and server configuration export utilities.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SRM_Tools
 *
 * Utility methods for testing, analyzing, and exporting redirects.
 */
class SRM_Tools {

    // -------------------------------------------------------------------------
    // URL Testing
    // -------------------------------------------------------------------------

    /**
     * Test a URL against all active redirects.
     *
     * Normalizes the given URL, checks for exact matches first, then regex
     * matches. When a match is found, conditions are evaluated and the full
     * redirect chain is traced.
     *
     * @param string $url The URL to test.
     * @return array {
     *     @type bool        $found        Whether a matching redirect was found.
     *     @type string|null $match_type   'exact', 'regex', or null.
     *     @type object|null $redirect     The matched redirect row, or null.
     *     @type array       $regex_groups Captured regex groups (empty for exact matches).
     *     @type array       $chain        The full redirect chain from trace_chain().
     * }
     */
    public static function test_url( $url ) {
        $normalized = SRM_Database::normalize_url( $url );
        $redirects  = SRM_Database::get_active_redirects();

        $result = array(
            'found'        => false,
            'match_type'   => null,
            'redirect'     => null,
            'regex_groups' => array(),
            'chain'        => array(),
        );

        if ( empty( $redirects ) ) {
            return $result;
        }

        $matched_redirect = null;
        $match_type       = null;
        $regex_groups     = array();

        // First pass: exact matches (non-regex redirects).
        foreach ( $redirects as $redirect ) {
            if ( ! empty( $redirect->is_regex ) ) {
                continue;
            }

            $source_normalized = SRM_Database::normalize_url( $redirect->source_url );

            if ( $source_normalized === $normalized ) {
                $matched_redirect = $redirect;
                $match_type       = 'exact';
                break;
            }
        }

        // Second pass: regex matches.
        if ( null === $matched_redirect ) {
            foreach ( $redirects as $redirect ) {
                if ( empty( $redirect->is_regex ) ) {
                    continue;
                }

                $pattern = '@' . str_replace( '@', '\\@', $redirect->source_url ) . '@';

                if ( preg_match( $pattern, $normalized, $matches ) ) {
                    $matched_redirect = $redirect;
                    $match_type       = 'regex';
                    $regex_groups     = $matches;
                    break;
                }
            }
        }

        // No match found.
        if ( null === $matched_redirect ) {
            return $result;
        }

        // Check conditions if the SRM_Conditions class is available.
        if ( class_exists( 'SRM_Conditions' ) && ! SRM_Conditions::check_conditions( $matched_redirect->id ) ) {
            return $result;
        }

        $result['found']        = true;
        $result['match_type']   = $match_type;
        $result['redirect']     = $matched_redirect;
        $result['regex_groups'] = $regex_groups;
        $result['chain']        = self::trace_chain( $normalized );

        return $result;
    }

    // -------------------------------------------------------------------------
    // Chain Detection
    // -------------------------------------------------------------------------

    /**
     * Find all redirect chains among active redirects.
     *
     * A chain exists when redirect A's target_url is the source_url of
     * redirect B. This method builds the full chain for every redirect whose
     * target leads to at least one additional redirect.
     *
     * @return array Array of chains, each containing:
     *     @type string $start   The starting source URL of the chain.
     *     @type array  $steps   Ordered array of redirect step objects.
     *     @type int    $length  Number of steps in the chain.
     *     @type bool   $is_loop Whether the chain forms a loop.
     */
    public static function find_all_chains() {
        $redirects = SRM_Database::get_active_redirects();

        if ( empty( $redirects ) ) {
            return array();
        }

        // Build a lookup map: normalized source_url => redirect object.
        $source_map = array();
        foreach ( $redirects as $redirect ) {
            // Skip regex redirects — chain detection requires exact URL matching.
            if ( ! empty( $redirect->is_regex ) ) {
                continue;
            }
            $normalized_source = SRM_Database::normalize_url( $redirect->source_url );
            $source_map[ $normalized_source ] = $redirect;
        }

        $chains  = array();
        $visited = array();

        foreach ( $source_map as $source_url => $redirect ) {
            // Skip if this URL was already part of a previously traced chain.
            if ( isset( $visited[ $source_url ] ) ) {
                continue;
            }

            $normalized_target = SRM_Database::normalize_url( $redirect->target_url );

            // Only start tracing if the target is itself a source of another redirect.
            if ( ! isset( $source_map[ $normalized_target ] ) ) {
                continue;
            }

            // Trace the chain starting from this redirect.
            $steps   = array();
            $seen    = array();
            $current = $source_url;
            $is_loop = false;

            while ( isset( $source_map[ $current ] ) ) {
                if ( isset( $seen[ $current ] ) ) {
                    $is_loop = true;
                    break;
                }

                $seen[ $current ]    = true;
                $visited[ $current ] = true;

                $step      = $source_map[ $current ];
                $steps[]   = $step;
                $current   = SRM_Database::normalize_url( $step->target_url );
            }

            if ( count( $steps ) > 1 || $is_loop ) {
                $chains[] = array(
                    'start'   => $source_url,
                    'steps'   => $steps,
                    'length'  => count( $steps ),
                    'is_loop' => $is_loop,
                );
            }
        }

        return $chains;
    }

    /**
     * Find only redirect chains that form loops.
     *
     * @return array Array of loop chains (same structure as find_all_chains()).
     */
    public static function find_loops() {
        $chains = self::find_all_chains();

        return array_values(
            array_filter(
                $chains,
                function ( $chain ) {
                    return $chain['is_loop'];
                }
            )
        );
    }

    /**
     * Trace the redirect chain starting from a given URL.
     *
     * Follows the chain until no further redirect is found, a loop is
     * detected, or the maximum depth is reached.
     *
     * @param string $url       The starting URL to trace from.
     * @param int    $max_depth Maximum number of hops to follow. Default 10.
     * @return array Ordered array of chain steps, each containing:
     *     @type string $source_url  The source URL of this hop.
     *     @type string $target_url  The target URL of this hop.
     *     @type int    $status_code The HTTP status code.
     *     @type int    $redirect_id The redirect row ID.
     */
    public static function trace_chain( $url, $max_depth = 10 ) {
        $redirects = SRM_Database::get_active_redirects();

        if ( empty( $redirects ) ) {
            return array();
        }

        // Build a lookup map: normalized source_url => redirect object.
        $source_map = array();
        foreach ( $redirects as $redirect ) {
            if ( ! empty( $redirect->is_regex ) ) {
                continue;
            }
            $normalized_source = SRM_Database::normalize_url( $redirect->source_url );
            $source_map[ $normalized_source ] = $redirect;
        }

        $steps   = array();
        $seen    = array();
        $current = SRM_Database::normalize_url( $url );
        $depth   = 0;

        while ( $depth < $max_depth && isset( $source_map[ $current ] ) ) {
            if ( isset( $seen[ $current ] ) ) {
                // Loop detected — stop tracing.
                break;
            }

            $seen[ $current ] = true;

            $redirect = $source_map[ $current ];
            $steps[]  = array(
                'source_url'  => $current,
                'target_url'  => $redirect->target_url,
                'status_code' => (int) $redirect->status_code,
                'redirect_id' => (int) $redirect->id,
            );

            $current = SRM_Database::normalize_url( $redirect->target_url );
            $depth++;
        }

        return $steps;
    }

    /**
     * Fix all redirect chains by pointing intermediate redirects directly to
     * the final target URL.
     *
     * Only non-loop chains are fixed. Loop chains are skipped because they
     * have no definitive final target.
     *
     * @return int Number of redirects that were updated.
     */
    public static function fix_chains() {
        global $wpdb;

        $chains = self::find_all_chains();

        if ( empty( $chains ) ) {
            return 0;
        }

        $table         = SRM_Database::get_table_name( 'redirects' );
        $fixed_count   = 0;

        foreach ( $chains as $chain ) {
            // Skip loops — there is no definitive final target.
            if ( $chain['is_loop'] ) {
                continue;
            }

            $steps = $chain['steps'];

            if ( count( $steps ) < 2 ) {
                continue;
            }

            // The final target is the target_url of the last step.
            $last_step    = end( $steps );
            $final_target = $last_step->target_url;

            // Update all steps except the last one to point to the final target.
            for ( $i = 0, $count = count( $steps ) - 1; $i < $count; $i++ ) {
                $step = $steps[ $i ];

                // Only update if the target is different from the final target.
                if ( $step->target_url !== $final_target ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $table,
                        array( 'target_url' => $final_target ),
                        array( 'id' => (int) $step->id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $fixed_count++;
                }
            }
        }

        if ( $fixed_count > 0 ) {
            SRM_Database::invalidate_cache();
        }

        return $fixed_count;
    }

    // -------------------------------------------------------------------------
    // Duplicate Detection
    // -------------------------------------------------------------------------

    /**
     * Find redirects with duplicate source URLs.
     *
     * @return array Array of duplicates, each containing:
     *     @type string $source_url  The duplicated source URL.
     *     @type int    $count       Number of redirects sharing this source URL.
     *     @type string $ids         Comma-separated list of redirect IDs.
     *     @type array  $target_urls Array of target URLs for the duplicates.
     */
    public static function find_duplicates() {
        global $wpdb;

        $table = SRM_Database::get_table_name( 'redirects' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $duplicates = $wpdb->get_results(
            "SELECT source_url, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
             FROM {$table}
             GROUP BY source_url
             HAVING cnt > 1"
        );

        if ( empty( $duplicates ) ) {
            return array();
        }

        $results = array();

        foreach ( $duplicates as $duplicate ) {
            $ids         = array_map( 'absint', explode( ',', $duplicate->ids ) );
            $target_urls = array();

            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $targets = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT target_url FROM {$table} WHERE id IN ({$placeholders})",
                        $ids
                    )
                );

                $target_urls = $targets ? $targets : array();
            }

            $results[] = array(
                'source_url'  => $duplicate->source_url,
                'count'       => (int) $duplicate->cnt,
                'ids'         => $duplicate->ids,
                'target_urls' => $target_urls,
            );
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // URL Validation
    // -------------------------------------------------------------------------

    /**
     * Validate target URLs of active redirects by performing HTTP HEAD requests.
     *
     * Only external (absolute) target URLs are checked. Relative paths are
     * skipped because they cannot be validated via HTTP independently.
     *
     * @param int $limit Maximum number of redirects to validate. Default 50.
     * @return array Array of validation results, each containing:
     *     @type string   $url         The target URL that was checked.
     *     @type string   $source_url  The corresponding source URL.
     *     @type int      $redirect_id The redirect row ID.
     *     @type string   $status      'ok', 'warning', or 'error'.
     *     @type int|null $http_code   The HTTP response code, or null on failure.
     *     @type string   $message     Human-readable status description.
     */
    public static function validate_urls( $limit = 50 ) {
        $limit     = absint( $limit );
        $redirects = SRM_Database::get_active_redirects();

        if ( empty( $redirects ) ) {
            return array();
        }

        $results = array();
        $checked = 0;

        foreach ( $redirects as $redirect ) {
            if ( $checked >= $limit ) {
                break;
            }

            $target_url = $redirect->target_url;

            // Skip empty or relative target URLs.
            if ( empty( $target_url ) || 0 !== strpos( $target_url, 'http' ) ) {
                continue;
            }

            $checked++;

            $response = wp_remote_head(
                $target_url,
                array(
                    'timeout'     => 5,
                    'sslverify'   => false,
                    'redirection' => 0,
                )
            );

            if ( is_wp_error( $response ) ) {
                $results[] = array(
                    'url'         => $target_url,
                    'source_url'  => $redirect->source_url,
                    'redirect_id' => (int) $redirect->id,
                    'status'      => 'error',
                    'http_code'   => null,
                    'message'     => $response->get_error_message(),
                );
                continue;
            }

            $http_code = (int) wp_remote_retrieve_response_code( $response );
            $status    = 'ok';
            $message   = '';

            if ( $http_code >= 200 && $http_code < 300 ) {
                $status  = 'ok';
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Target URL is reachable (HTTP %d).', 'smart-redirect-manager' ),
                    $http_code
                );
            } elseif ( $http_code >= 300 && $http_code < 400 ) {
                $status  = 'warning';
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Target URL returns a redirect (HTTP %d).', 'smart-redirect-manager' ),
                    $http_code
                );
            } elseif ( $http_code >= 400 && $http_code < 500 ) {
                $status  = 'error';
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Target URL returns a client error (HTTP %d).', 'smart-redirect-manager' ),
                    $http_code
                );
            } elseif ( $http_code >= 500 ) {
                $status  = 'error';
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Target URL returns a server error (HTTP %d).', 'smart-redirect-manager' ),
                    $http_code
                );
            } else {
                $status  = 'warning';
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Target URL returned unexpected status (HTTP %d).', 'smart-redirect-manager' ),
                    $http_code
                );
            }

            $results[] = array(
                'url'         => $target_url,
                'source_url'  => $redirect->source_url,
                'redirect_id' => (int) $redirect->id,
                'status'      => $status,
                'http_code'   => $http_code,
                'message'     => $message,
            );
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Server Configuration Export
    // -------------------------------------------------------------------------

    /**
     * Export all active redirects as Apache .htaccess rules.
     *
     * Non-regex redirects are exported as simple Redirect directives. Regex
     * redirects are exported as RewriteRule directives. The output includes
     * the RewriteEngine On directive.
     *
     * @return string The complete .htaccess rules block.
     */
    public static function export_htaccess() {
        $redirects = SRM_Database::get_active_redirects();

        if ( empty( $redirects ) ) {
            return '';
        }

        $lines = array();

        $lines[] = '# Smart Redirect Manager - Generated .htaccess rules';
        $lines[] = '# Generated on ' . current_time( 'Y-m-d H:i:s' );
        $lines[] = '';
        $lines[] = 'RewriteEngine On';
        $lines[] = '';

        $simple_rules = array();
        $regex_rules  = array();

        foreach ( $redirects as $redirect ) {
            $status_code = (int) $redirect->status_code;

            // Skip 410 Gone — not a redirect in the traditional sense.
            if ( 410 === $status_code ) {
                continue;
            }

            if ( empty( $redirect->is_regex ) ) {
                // Simple redirect directive.
                $source = $redirect->source_url;
                $target = $redirect->target_url;

                $simple_rules[] = sprintf(
                    'Redirect %d %s %s',
                    $status_code,
                    $source,
                    $target
                );
            } else {
                // Regex-based RewriteRule.
                $pattern = $redirect->source_url;
                $target  = $redirect->target_url;

                // Determine the redirect flag based on status code.
                $flag = 'R=' . $status_code;

                $regex_rules[] = sprintf(
                    'RewriteRule ^%s$ %s [%s,L]',
                    $pattern,
                    $target,
                    $flag
                );
            }
        }

        // Add simple redirects first.
        if ( ! empty( $simple_rules ) ) {
            $lines[] = '# Simple redirects';
            foreach ( $simple_rules as $rule ) {
                $lines[] = $rule;
            }
            $lines[] = '';
        }

        // Add regex-based rules.
        if ( ! empty( $regex_rules ) ) {
            $lines[] = '# Regex redirects';
            foreach ( $regex_rules as $rule ) {
                $lines[] = $rule;
            }
            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    /**
     * Export all active redirects as an Nginx configuration block.
     *
     * Non-regex redirects are exported as exact location blocks. Regex
     * redirects are exported as rewrite directives.
     *
     * @return string The complete Nginx configuration block.
     */
    public static function export_nginx() {
        $redirects = SRM_Database::get_active_redirects();

        if ( empty( $redirects ) ) {
            return '';
        }

        $lines = array();

        $lines[] = '# Smart Redirect Manager - Generated Nginx rules';
        $lines[] = '# Generated on ' . current_time( 'Y-m-d H:i:s' );
        $lines[] = '';

        foreach ( $redirects as $redirect ) {
            $status_code = (int) $redirect->status_code;

            // Skip 410 Gone — not a standard redirect.
            if ( 410 === $status_code ) {
                continue;
            }

            $target = $redirect->target_url;

            if ( empty( $redirect->is_regex ) ) {
                // Exact location match.
                $source = $redirect->source_url;

                $lines[] = sprintf(
                    'location = %s { return %d %s; }',
                    $source,
                    $status_code,
                    $target
                );
            } else {
                // Regex rewrite rule.
                $pattern = $redirect->source_url;

                // Nginx uses "permanent" for 301 and "redirect" for 302.
                if ( 301 === $status_code ) {
                    $flag = 'permanent';
                } elseif ( 302 === $status_code ) {
                    $flag = 'redirect';
                } else {
                    // For other codes (307, 308), fall back to a location block
                    // with regex since rewrite only supports permanent/redirect.
                    $lines[] = sprintf(
                        'location ~ ^%s$ { return %d %s; }',
                        $pattern,
                        $status_code,
                        $target
                    );
                    continue;
                }

                $lines[] = sprintf(
                    'rewrite ^%s$ %s %s;',
                    $pattern,
                    $target,
                    $flag
                );
            }
        }

        $lines[] = '';

        return implode( "\n", $lines );
    }
}
