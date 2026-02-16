<?php
/**
 * Admin 404 Log
 *
 * Renders the 404 error log admin page with filtering,
 * pagination, and redirect creation modal.
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
 * Class SRM_Admin_404_Log
 *
 * Provides the admin UI for viewing and managing 404 error log entries.
 */
class SRM_Admin_404_Log {

	/**
	 * Render the 404 error log page.
	 *
	 * @return void
	 */
	public static function render() {

		// ---------------------------------------------------------------------
		// 1. Read filter and sort parameters from the query string.
		// ---------------------------------------------------------------------
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 25;
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'last_occurred';
		$order    = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : 'DESC';

		$allowed_orderby = array( 'request_url', 'referer', 'count', 'last_occurred', 'is_resolved' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'last_occurred';
		}
		$order = ( $order === 'ASC' ) ? 'ASC' : 'DESC';

		// ---------------------------------------------------------------------
		// 2. Fetch log entries via SRM_404_Logger.
		// ---------------------------------------------------------------------
		$result = SRM_404_Logger::get_logs( array(
			'page'     => $paged,
			'per_page' => $per_page,
			'search'   => $search,
			'status'   => $status,
			'orderby'  => $orderby,
			'order'    => $order,
		) );

		$items       = $result['items'];
		$total_items = $result['total'];
		$total_pages = ceil( $total_items / $per_page );

		// Base URL for sort links (preserve filter and pagination).
		$base_url = add_query_arg( array(
			'page'   => 'srm-404-log',
			's'      => $search,
			'status' => $status,
			'paged'  => $paged > 1 ? $paged : false,
		), admin_url( 'admin.php' ) );
		$base_url = remove_query_arg( 'orderby', $base_url );
		$base_url = remove_query_arg( 'order', $base_url );

		$sort_link = function( $column, $label ) use ( $base_url, $orderby, $order ) {
			$next_order = ( $orderby === $column && $order === 'DESC' ) ? 'ASC' : 'DESC';
			$url = add_query_arg( array( 'orderby' => $column, 'order' => $next_order ), $base_url );
			$arrow = '';
			if ( $orderby === $column ) {
				$arrow = ( $order === 'ASC' ) ? ' &uarr;' : ' &darr;';
			}
			return '<a href="' . esc_url( $url ) . '" style="text-decoration:none;">' . esc_html( $label ) . $arrow . '</a>';
		};

		// ---------------------------------------------------------------------
		// 3. Build pagination links (preserve sort and filters).
		// ---------------------------------------------------------------------
		$pagination_base = add_query_arg( array(
			'page'    => 'srm-404-log',
			's'       => $search,
			'status'  => $status,
			'orderby' => $orderby,
			'order'   => $order,
		), admin_url( 'admin.php' ) );
		$pagination_base = add_query_arg( 'paged', '%#%', $pagination_base );

		$pagination = paginate_links( array(
			'base'      => $pagination_base,
			'format'    => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'total'     => $total_pages,
			'current'   => $paged,
			'type'      => 'plain',
		) );

		// ---------------------------------------------------------------------
		// 4. Page output.
		// ---------------------------------------------------------------------
		?>
		<div class="wrap">

			<h1>404-Fehler</h1>

			<!-- Filter bar -->
			<form method="get" style="display: flex; align-items: center; gap: 8px; margin: 16px 0;">
				<input type="hidden" name="page" value="srm-404-log" />
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />

				<input
					type="search"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="URL oder Referer suchen&hellip;"
					style="min-width: 250px;"
				/>

				<select name="status">
					<option value="all"<?php selected( $status, 'all' ); ?>>Alle</option>
					<option value="unresolved"<?php selected( $status, 'unresolved' ); ?>>Ungel&ouml;st</option>
					<option value="resolved"<?php selected( $status, 'resolved' ); ?>>Gel&ouml;st</option>
				</select>

				<?php submit_button( 'Filtern', 'secondary', 'filter', false ); ?>

				<a
					href="<?php echo esc_url( admin_url( 'admin-post.php?action=srm_export_404' ) ); ?>"
					class="button"
					style="margin-left: auto;"
				>CSV Export</a>
			</form>

			<!-- Results table (sortable columns) -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 30%;"><?php echo $sort_link( 'request_url', 'URL' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th style="width: 20%;"><?php echo $sort_link( 'referer', 'Referer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th style="width: 8%;"><?php echo $sort_link( 'count', 'Aufrufe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th style="width: 14%;"><?php echo $sort_link( 'last_occurred', 'Zuletzt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th style="width: 8%;"><?php echo $sort_link( 'is_resolved', 'Status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th style="width: 20%;">Aktionen</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="6">Keine 404-Fehler gefunden.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$is_resolved = ! empty( $item->is_resolved );
							$full_url    = esc_attr( $item->request_url );
							$short_url   = esc_html( mb_strimwidth( $item->request_url, 0, 60, '...' ) );
							$referer_raw = isset( $item->referer ) ? $item->referer : '';
							$short_ref   = esc_html( mb_strimwidth( $referer_raw, 0, 40, '...' ) );
							$last_date   = isset( $item->last_occurred )
								? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->last_occurred ) )
								: '&mdash;';
							?>
							<tr>
								<!-- URL -->
								<td title="<?php echo $full_url; ?>">
									<code><?php echo $short_url; ?></code>
								</td>

								<!-- Referer -->
								<td title="<?php echo esc_attr( $referer_raw ); ?>">
									<?php if ( ! empty( $referer_raw ) && filter_var( $referer_raw, FILTER_VALIDATE_URL ) ) : ?>
										<a href="<?php echo esc_url( $referer_raw ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo $short_ref; ?>
										</a>
									<?php elseif ( ! empty( $referer_raw ) ) : ?>
										<?php echo $short_ref; ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>

								<!-- Aufrufe -->
								<td>
									<strong><?php echo absint( $item->count ); ?></strong>
								</td>

								<!-- Zuletzt -->
								<td><?php echo esc_html( $last_date ); ?></td>

								<!-- Status -->
								<td>
									<?php if ( $is_resolved ) : ?>
										<span style="background: #46b450; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
											Gel&ouml;st
										</span>
									<?php else : ?>
										<span style="background: #dc3232; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">
											Offen
										</span>
									<?php endif; ?>
								</td>

								<!-- Aktionen -->
								<td>
									<?php if ( ! $is_resolved ) : ?>
										<button
											type="button"
											class="button button-small srm-open-redirect-modal"
											data-id="<?php echo absint( $item->id ); ?>"
											data-url="<?php echo $full_url; ?>"
										>Redirect erstellen</button>

										<a
											href="#"
											class="srm-resolve-404"
											data-id="<?php echo absint( $item->id ); ?>"
											style="margin-left: 4px;"
										>Als gel&ouml;st markieren</a>
									<?php endif; ?>

									<a
										href="#"
										class="srm-delete-404"
										data-id="<?php echo absint( $item->id ); ?>"
										style="color: #a00; margin-left: 4px;"
									>L&ouml;schen</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $pagination ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of items */
								'%s Eintr&auml;ge',
								number_format_i18n( $total_items )
							);
							?>
						</span>
						<?php echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- Modal: Redirect erstellen -->
			<div id="srm-404-modal" style="display: none;">
				<div class="srm-modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 100000;"></div>
				<div class="srm-modal" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%); background: #fff; padding: 24px 32px; border-radius: 6px; z-index: 100001; min-width: 420px; max-width: 90vw; box-shadow: 0 4px 20px rgba(0,0,0,.3);">
					<h2 style="margin-top: 0;">Redirect erstellen aus 404-Fehler</h2>

					<p>
						Quell-URL: <span id="srm-modal-source-url" style="font-weight: 600;"></span>
					</p>

					<input type="hidden" id="srm-modal-404-id" value="" />

					<p>
						<label for="srm-modal-target-url"><strong>Ziel-URL:</strong></label><br />
						<input
							type="text"
							id="srm-modal-target-url"
							placeholder="/ziel-seite"
							class="regular-text"
							style="width: 100%; margin-top: 4px;"
						/>
					</p>

					<div style="display: flex; gap: 8px; margin-top: 16px;">
						<button type="button" class="button button-primary" id="srm-modal-submit">
							Redirect erstellen
						</button>
						<button type="button" class="button" id="srm-modal-cancel">
							Abbrechen
						</button>
					</div>

					<div id="srm-modal-result" style="margin-top: 12px;"></div>
				</div>
			</div>

		</div><!-- .wrap -->
		<?php
	}
}
