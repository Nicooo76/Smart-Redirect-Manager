<?php
/**
 * Admin Redirects Page
 *
 * Renders the redirect list table and the add/edit form in the
 * WordPress admin area. All user-facing text is in German.
 *
 * @package SmartRedirectManager
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRM_Admin_Redirects
 *
 * Provides static methods for the redirect list view and the
 * single-redirect form (new / edit).
 */
class SRM_Admin_Redirects {

	// -----------------------------------------------------------------
	// Router
	// -----------------------------------------------------------------

	/**
	 * Main render entry point.
	 *
	 * Inspects the `action` GET parameter and delegates to either the
	 * form view or the list view.
	 *
	 * @return void
	 */
	public static function render() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action || 'new' === $action ) {
			self::render_form();
		} else {
			self::render_list();
		}
	}

	// -----------------------------------------------------------------
	// List View
	// -----------------------------------------------------------------

	/**
	 * Render the redirect list page.
	 *
	 * Includes a filter bar, bulk actions, the wp-list-table style table,
	 * and pagination.
	 *
	 * @return void
	 */
	public static function render_list() {

		// Current filter values.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$type    = isset( $_GET['source_type'] ) ? sanitize_text_field( wp_unslash( $_GET['source_type'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = 25;

		// Fetch redirects.
		$result = SRM_Database::get_redirects( array(
			'search'   => $search,
			'type'     => $type,
			'status'   => $status,
			'page'     => $paged,
			'per_page' => $per_page,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		) );

		$items = $result['items'];
		$total = $result['total'];

		// Pre-fetch groups for badge display.
		$groups     = SRM_Groups::get_groups();
		$groups_map = array();
		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group ) {
				$groups_map[ (int) $group->id ] = $group;
			}
		}

		?>
		<div class="wrap">

			<h1 class="wp-heading-inline">Alle Redirects</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects&action=new' ) ); ?>" class="page-title-action">Neue Weiterleitung</a>
			<hr class="wp-header-end">

			<?php
			// Auto-cleanup notice.
			$cleanup_result = get_transient( 'srm_last_cleanup_result' );
			if ( ! empty( $cleanup_result ) ) :
				$cleanup_count  = isset( $cleanup_result['count'] ) ? (int) $cleanup_result['count'] : 0;
				$cleanup_action = isset( $cleanup_result['action'] ) ? sanitize_text_field( $cleanup_result['action'] ) : '';
				$cleanup_date   = isset( $cleanup_result['date'] ) ? esc_html( $cleanup_result['date'] ) : '';

				$action_labels = array(
					'deactivate'  => 'deaktiviert',
					'delete'      => 'geloescht',
					'convert_410' => 'zu 410 Gone konvertiert',
				);
				$action_label  = isset( $action_labels[ $cleanup_action ] ) ? $action_labels[ $cleanup_action ] : $cleanup_action;

				if ( $cleanup_count > 0 ) :
					?>
					<div class="notice notice-info is-dismissible">
						<p>
							<?php
							printf(
								'Auto-Cleanup: %d Weiterleitungen wurden %s (%s).',
								$cleanup_count,
								esc_html( $action_label ),
								$cleanup_date
							);
							?>
						</p>
					</div>
					<?php
				endif;
			endif;
			?>

			<!-- Filter Bar -->
			<form method="get" class="srm-filter-bar" style="display:flex;align-items:center;gap:8px;margin:16px 0;">
				<input type="hidden" name="page" value="srm-redirects">

				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Suchen&hellip;" class="regular-text">

				<select name="source_type">
					<option value="">Alle Typen</option>
					<option value="manual"<?php selected( $type, 'manual' ); ?>>Manuell</option>
					<option value="auto_post"<?php selected( $type, 'auto_post' ); ?>>Auto Beitrag</option>
					<option value="auto_term"<?php selected( $type, 'auto_term' ); ?>>Auto Taxonomie</option>
					<option value="auto_410"<?php selected( $type, 'auto_410' ); ?>>Auto 410</option>
					<option value="from_404"<?php selected( $type, 'from_404' ); ?>>Aus 404</option>
					<option value="import"<?php selected( $type, 'import' ); ?>>Import</option>
					<option value="migration"<?php selected( $type, 'migration' ); ?>>Migration</option>
					<option value="cli"<?php selected( $type, 'cli' ); ?>>CLI</option>
				</select>

				<select name="status">
					<option value="">Alle</option>
					<option value="active"<?php selected( $status, 'active' ); ?>>Aktiv</option>
					<option value="inactive"<?php selected( $status, 'inactive' ); ?>>Inaktiv</option>
				</select>

				<?php submit_button( 'Filtern', 'secondary', 'filter_action', false ); ?>

				<?php if ( '' !== $search || '' !== $type || '' !== $status ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects' ) ); ?>" class="button button-link">Zuruecksetzen</a>
				<?php endif; ?>
			</form>

			<!-- Bulk Actions (top) -->
			<form method="post" id="srm-bulk-form">
				<?php wp_nonce_field( 'srm_bulk_action', 'srm_bulk_nonce' ); ?>
				<div class="tablenav top" style="display:flex;align-items:center;gap:8px;">
					<select name="srm_bulk_action">
						<option value="">Aktion waehlen</option>
						<option value="activate">Aktivieren</option>
						<option value="deactivate">Deaktivieren</option>
						<option value="delete">Loeschen</option>
					</select>
					<?php submit_button( 'Anwenden', 'secondary', 'do_bulk', false ); ?>

					<span class="displaying-num" style="margin-left:auto;">
						<?php printf( '%s Eintraege', number_format_i18n( $total ) ); ?>
					</span>
				</div>

				<!-- Table -->
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column">
								<input type="checkbox" id="cb-select-all-1">
							</td>
							<th class="manage-column" style="width:60px;">Status</th>
							<th class="manage-column">Quell-URL</th>
							<th class="manage-column">Ziel-URL</th>
							<th class="manage-column" style="width:55px;">Code</th>
							<th class="manage-column" style="width:110px;">Typ</th>
							<th class="manage-column" style="width:55px;">Hits</th>
							<th class="manage-column" style="width:100px;">Gruppe</th>
							<th class="manage-column" style="width:100px;">Erstellt</th>
							<th class="manage-column" style="width:120px;">Aktionen</th>
						</tr>
					</thead>
					<tfoot>
						<tr>
							<td class="manage-column column-cb check-column">
								<input type="checkbox" id="cb-select-all-2">
							</td>
							<th class="manage-column">Status</th>
							<th class="manage-column">Quell-URL</th>
							<th class="manage-column">Ziel-URL</th>
							<th class="manage-column">Code</th>
							<th class="manage-column">Typ</th>
							<th class="manage-column">Hits</th>
							<th class="manage-column">Gruppe</th>
							<th class="manage-column">Erstellt</th>
							<th class="manage-column">Aktionen</th>
						</tr>
					</tfoot>
					<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr>
								<td colspan="10" style="text-align:center;padding:20px;">
									Keine Weiterleitungen gefunden.
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<!-- Checkbox -->
									<th scope="row" class="check-column">
										<input type="checkbox" name="redirect_ids[]" value="<?php echo esc_attr( $item->id ); ?>">
									</th>

									<!-- Status -->
									<td>
										<button type="button"
											class="srm-toggle-status"
											data-id="<?php echo esc_attr( $item->id ); ?>"
											data-active="<?php echo esc_attr( $item->is_active ); ?>"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'srm_toggle_status' ) ); ?>"
											title="<?php echo $item->is_active ? 'Deaktivieren' : 'Aktivieren'; ?>"
											style="background:none;border:none;cursor:pointer;font-size:18px;">
											<?php if ( $item->is_active ) : ?>
												<span style="color:#00a32a;">&#10003;</span>
											<?php else : ?>
												<span style="color:#d63638;">&#10005;</span>
											<?php endif; ?>
										</button>
									</td>

									<!-- Quell-URL -->
									<td>
										<strong>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects&action=edit&id=' . $item->id ) ); ?>">
												<?php echo esc_html( $item->source_url ); ?>
											</a>
										</strong>
										<?php if ( ! empty( $item->is_regex ) ) : ?>
											<span class="srm-badge" style="background:#8c8f94;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">Regex</span>
										<?php endif; ?>
										<?php if ( ! empty( $item->expires_at ) ) : ?>
											<span title="Laeuft ab: <?php echo esc_attr( $item->expires_at ); ?>" style="margin-left:4px;font-size:14px;cursor:help;">&#128339;</span>
										<?php endif; ?>
									</td>

									<!-- Ziel-URL -->
									<td>
										<?php
										if ( (int) $item->status_code === 410 ) {
											echo '<em style="color:#d63638;">(410 Gone)</em>';
										} else {
											$target_display = esc_html( $item->target_url );
											if ( strlen( $item->target_url ) > 60 ) {
												$target_display = esc_html( substr( $item->target_url, 0, 57 ) . '...' );
											}
											echo '<span title="' . esc_attr( $item->target_url ) . '">' . $target_display . '</span>';
										}
										?>
									</td>

									<!-- Code -->
									<td>
										<?php
										$code       = (int) $item->status_code;
										$code_color = '#2271b1'; // default blue
										if ( 301 === $code ) {
											$code_color = '#00a32a';
										} elseif ( 302 === $code || 307 === $code ) {
											$code_color = '#dba617';
										} elseif ( 308 === $code ) {
											$code_color = '#2271b1';
										} elseif ( 410 === $code ) {
											$code_color = '#d63638';
										}
										?>
										<span class="srm-badge" style="background:<?php echo esc_attr( $code_color ); ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;">
											<?php echo esc_html( $code ); ?>
										</span>
									</td>

									<!-- Typ -->
									<td><?php echo esc_html( self::get_type_label( $item->source_type ) ); ?></td>

									<!-- Hits -->
									<td><strong><?php echo esc_html( number_format_i18n( (int) $item->hit_count ) ); ?></strong></td>

									<!-- Gruppe -->
									<td>
										<?php
										$gid = (int) $item->group_id;
										if ( $gid && isset( $groups_map[ $gid ] ) ) {
											$grp   = $groups_map[ $gid ];
											$color = ! empty( $grp->color ) ? $grp->color : '#2271b1';
											printf(
												'<span class="srm-badge" style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">%s</span>',
												esc_attr( $color ),
												esc_html( $grp->name )
											);
										} else {
											echo '&mdash;';
										}
										?>
									</td>

									<!-- Erstellt -->
									<td>
										<?php
										if ( ! empty( $item->created_at ) ) {
											echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->created_at ) ) );
										} else {
											echo '&mdash;';
										}
										?>
									</td>

									<!-- Aktionen -->
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects&action=edit&id=' . $item->id ) ); ?>">Bearbeiten</a>
										&nbsp;|&nbsp;
										<a href="#"
											class="srm-delete-redirect"
											data-id="<?php echo esc_attr( $item->id ); ?>"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'srm_delete_redirect_' . $item->id ) ); ?>"
											data-confirm="Soll diese Weiterleitung wirklich geloescht werden?"
											style="color:#d63638;">Loeschen</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<!-- Bulk Actions (bottom) -->
				<div class="tablenav bottom" style="display:flex;align-items:center;gap:8px;">
					<select name="srm_bulk_action_bottom">
						<option value="">Aktion waehlen</option>
						<option value="activate">Aktivieren</option>
						<option value="deactivate">Deaktivieren</option>
						<option value="delete">Loeschen</option>
					</select>
					<?php submit_button( 'Anwenden', 'secondary', 'do_bulk_bottom', false ); ?>
				</div>
			</form>

			<!-- Pagination -->
			<?php
			$total_pages = ceil( $total / $per_page );

			if ( $total_pages > 1 ) {
				$pagination = paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $paged,
					'type'      => 'plain',
				) );

				if ( $pagination ) {
					echo '<div class="tablenav-pages" style="margin-top:12px;">' . $pagination . '</div>';
				}
			}
			?>

		</div><!-- .wrap -->
		<?php
	}

	// -----------------------------------------------------------------
	// Form View (New / Edit)
	// -----------------------------------------------------------------

	/**
	 * Render the add/edit redirect form.
	 *
	 * Loads existing data when editing and provides all fields described
	 * in the plugin specification.
	 *
	 * @return void
	 */
	public static function render_form() {

		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$redirect = null;

		if ( $id ) {
			$redirect = SRM_Database::get_redirect( $id );

			if ( ! $redirect ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>Weiterleitung nicht gefunden.</p></div></div>';
				return;
			}
		}

		$is_edit = ! empty( $redirect );

		// Default values for a new redirect.
		$source_url  = $is_edit ? $redirect->source_url  : '';
		$target_url  = $is_edit ? $redirect->target_url  : '';
		$status_code = $is_edit ? (int) $redirect->status_code : 301;
		$is_regex    = $is_edit ? (int) $redirect->is_regex    : 0;
		$is_active   = $is_edit ? (int) $redirect->is_active   : 1;
		$group_id    = $is_edit ? (int) $redirect->group_id    : 0;
		$expires_at  = $is_edit && ! empty( $redirect->expires_at ) ? $redirect->expires_at : '';
		$notes       = $is_edit ? $redirect->notes : '';
		$conditions  = $is_edit && ! empty( $redirect->conditions ) ? $redirect->conditions : array();

		// Fetch groups for dropdown.
		$groups = SRM_Groups::get_groups();

		?>
		<div class="wrap">

			<h1><?php echo $is_edit ? 'Weiterleitung bearbeiten' : 'Neue Weiterleitung'; ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="srm-redirect-form">
				<input type="hidden" name="action" value="srm_save_redirect">
				<?php wp_nonce_field( 'srm_save_redirect', 'srm_redirect_nonce' ); ?>

				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $id ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">

					<!-- Quell-URL -->
					<tr>
						<th scope="row">
							<label for="srm-source-url">Quell-URL</label>
						</th>
						<td>
							<input type="text"
								id="srm-source-url"
								name="source_url"
								value="<?php echo esc_attr( $source_url ); ?>"
								class="large-text"
								required
								placeholder="/alter-pfad oder Regex-Pattern">
						</td>
					</tr>

					<!-- Ziel-URL -->
					<tr>
						<th scope="row">
							<label for="srm-target-url">Ziel-URL</label>
						</th>
						<td>
							<input type="text"
								id="srm-target-url"
								name="target_url"
								value="<?php echo esc_attr( $target_url ); ?>"
								class="large-text"
								required
								placeholder="/neuer-pfad oder https://...">
						</td>
					</tr>

					<!-- HTTP Status-Code -->
					<tr>
						<th scope="row">
							<label for="srm-status-code">HTTP Status-Code</label>
						</th>
						<td>
							<select id="srm-status-code" name="status_code">
								<option value="301"<?php selected( $status_code, 301 ); ?>>301 - Permanent verschoben</option>
								<option value="302"<?php selected( $status_code, 302 ); ?>>302 - Temporaer verschoben</option>
								<option value="307"<?php selected( $status_code, 307 ); ?>>307 - Temporaere Umleitung</option>
								<option value="308"<?php selected( $status_code, 308 ); ?>>308 - Permanente Umleitung</option>
								<option value="410"<?php selected( $status_code, 410 ); ?>>410 - Dauerhaft entfernt (Gone)</option>
							</select>
						</td>
					</tr>

					<!-- Regulaerer Ausdruck -->
					<tr>
						<th scope="row">Regulaerer Ausdruck</th>
						<td>
							<label for="srm-is-regex">
								<input type="checkbox"
									id="srm-is-regex"
									name="is_regex"
									value="1"
									<?php checked( $is_regex, 1 ); ?>>
								Quell-URL als regulaeren Ausdruck behandeln
							</label>
						</td>
					</tr>

					<!-- Aktiv -->
					<tr>
						<th scope="row">Aktiv</th>
						<td>
							<label for="srm-is-active">
								<input type="checkbox"
									id="srm-is-active"
									name="is_active"
									value="1"
									<?php checked( $is_active, 1 ); ?>>
								Weiterleitung ist aktiv
							</label>
						</td>
					</tr>

					<!-- Regex-Tester -->
					<tr id="srm-regex-tester-row" style="<?php echo $is_regex ? '' : 'display:none;'; ?>">
						<th scope="row">Regex-Tester</th>
						<td>
							<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
								<input type="text"
									id="srm-regex-test-url"
									placeholder="Test-URL eingeben, z.B. /alter-pfad/123"
									class="regular-text">
								<button type="button" id="srm-regex-test-btn" class="button">Testen</button>
							</div>
							<div id="srm-regex-test-result" style="padding:4px 0;font-style:italic;"></div>
						</td>
					</tr>

					<!-- Gruppe -->
					<tr>
						<th scope="row">
							<label for="srm-group-id">Gruppe</label>
						</th>
						<td>
							<select id="srm-group-id" name="group_id">
								<option value="0">-- Keine Gruppe --</option>
								<?php if ( ! empty( $groups ) ) : ?>
									<?php foreach ( $groups as $group ) : ?>
										<option value="<?php echo esc_attr( $group->id ); ?>"<?php selected( $group_id, (int) $group->id ); ?>>
											<?php echo esc_html( $group->name ); ?>
										</option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</td>
					</tr>

					<!-- Ablaufdatum -->
					<tr>
						<th scope="row">
							<label for="srm-expires-at">Ablaufdatum</label>
						</th>
						<td>
							<?php
							$expires_value = '';
							if ( ! empty( $expires_at ) ) {
								// Convert MySQL datetime to datetime-local format.
								$expires_value = date( 'Y-m-d\TH:i', strtotime( $expires_at ) );
							}
							?>
							<input type="datetime-local"
								id="srm-expires-at"
								name="expires_at"
								value="<?php echo esc_attr( $expires_value ); ?>">
							<p class="description">Optional. Die Weiterleitung wird nach diesem Datum automatisch deaktiviert.</p>
						</td>
					</tr>

					<!-- Notizen -->
					<tr>
						<th scope="row">
							<label for="srm-notes">Notizen</label>
						</th>
						<td>
							<textarea id="srm-notes"
								name="notes"
								rows="4"
								class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
						</td>
					</tr>

				</table>

				<?php
				// ---------------------------------------------------------
				// Conditions section (only when editing)
				// ---------------------------------------------------------
				if ( $is_edit ) :
				?>
					<h3>Bedingungen (Conditions)</h3>
					<p class="description" style="margin-bottom:12px;">
						Die Weiterleitung wird nur ausgefuehrt, wenn alle Bedingungen erfuellt sind. Ohne Bedingungen greift die Weiterleitung immer.
					</p>

					<table class="widefat" id="srm-conditions-table" style="max-width:900px;">
						<thead>
							<tr>
								<th style="width:180px;">Typ</th>
								<th style="width:140px;">Operator</th>
								<th>Wert</th>
								<th style="width:60px;"></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $conditions ) ) : ?>
								<?php foreach ( $conditions as $index => $cond ) : ?>
									<tr class="srm-condition-row">
										<td>
											<select name="conditions[<?php echo $index; ?>][condition_type]" class="srm-condition-type">
												<?php self::render_condition_type_options( $cond->condition_type ); ?>
											</select>
										</td>
										<td>
											<select name="conditions[<?php echo $index; ?>][condition_operator]" class="srm-condition-operator">
												<?php self::render_condition_operator_options( $cond->condition_operator ); ?>
											</select>
										</td>
										<td>
											<input type="text"
												name="conditions[<?php echo $index; ?>][condition_value]"
												value="<?php echo esc_attr( $cond->condition_value ); ?>"
												class="regular-text srm-condition-value">
										</td>
										<td>
											<button type="button" class="button srm-remove-condition" title="Bedingung entfernen">&times;</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<p style="margin-top:8px;">
						<button type="button" id="srm-add-condition" class="button">+ Bedingung hinzufuegen</button>
					</p>

					<!-- Template row (hidden, cloned via JS) -->
					<script type="text/html" id="tmpl-srm-condition-row">
						<tr class="srm-condition-row">
							<td>
								<select name="conditions[{{INDEX}}][condition_type]" class="srm-condition-type">
									<?php self::render_condition_type_options(); ?>
								</select>
							</td>
							<td>
								<select name="conditions[{{INDEX}}][condition_operator]" class="srm-condition-operator">
									<?php self::render_condition_operator_options(); ?>
								</select>
							</td>
							<td>
								<input type="text" name="conditions[{{INDEX}}][condition_value]" value="" class="regular-text srm-condition-value">
							</td>
							<td>
								<button type="button" class="button srm-remove-condition" title="Bedingung entfernen">&times;</button>
							</td>
						</tr>
					</script>
				<?php endif; ?>

				<?php
				// ---------------------------------------------------------
				// Meta info box (only when editing)
				// ---------------------------------------------------------
				if ( $is_edit ) :
				?>
					<div class="postbox" style="max-width:500px;margin-top:24px;">
						<div class="postbox-header">
							<h2 style="padding:8px 12px;margin:0;">Informationen</h2>
						</div>
						<div class="inside">
							<table class="form-table" style="margin:0;">
								<tr>
									<th scope="row">Erstellt</th>
									<td>
										<?php
										if ( ! empty( $redirect->created_at ) ) {
											echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $redirect->created_at ) ) );
										} else {
											echo '&mdash;';
										}
										?>
									</td>
								</tr>
								<tr>
									<th scope="row">Typ</th>
									<td><?php echo esc_html( self::get_type_label( $redirect->source_type ) ); ?></td>
								</tr>
								<tr>
									<th scope="row">Hits</th>
									<td><?php echo esc_html( number_format_i18n( (int) $redirect->hit_count ) ); ?></td>
								</tr>
								<tr>
									<th scope="row">Letzter Hit</th>
									<td>
										<?php
										if ( ! empty( $redirect->last_hit ) ) {
											echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $redirect->last_hit ) ) );
										} else {
											echo 'Noch nie';
										}
										?>
									</td>
								</tr>
								<?php if ( ! empty( $redirect->post_id ) ) : ?>
									<tr>
										<th scope="row">Post-ID</th>
										<td>
											<?php echo esc_html( $redirect->post_id ); ?>
											<?php
											$post_title = get_the_title( $redirect->post_id );
											if ( $post_title ) {
												echo ' &mdash; ' . esc_html( $post_title );
											}
											?>
										</td>
									</tr>
								<?php endif; ?>
							</table>
						</div>
					</div>
				<?php endif; ?>

				<p class="submit">
					<?php submit_button( 'Weiterleitung speichern', 'primary', 'submit', false ); ?>
					&nbsp;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=srm-redirects' ) ); ?>" class="button">Zurueck zur Liste</a>
				</p>

			</form>

		</div><!-- .wrap -->
		<?php
	}

	// -----------------------------------------------------------------
	// Helper: Type Label
	// -----------------------------------------------------------------

	/**
	 * Return a human-readable German label for a given source_type.
	 *
	 * @param string $type The source_type value.
	 * @return string Translated label.
	 */
	public static function get_type_label( $type ) {

		$labels = array(
			'manual'    => 'Manuell',
			'auto_post' => 'Auto Beitrag',
			'auto_term' => 'Auto Taxonomie',
			'auto_410'  => 'Auto 410',
			'from_404'  => 'Aus 404',
			'import'    => 'Import',
			'migration' => 'Migration',
			'cli'       => 'CLI',
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
	}

	// -----------------------------------------------------------------
	// Helper: Condition Type Options
	// -----------------------------------------------------------------

	/**
	 * Render <option> elements for condition type selects.
	 *
	 * @param string $selected Currently selected value.
	 * @return void
	 */
	private static function render_condition_type_options( $selected = '' ) {

		$types = array(
			'user_agent'     => 'User-Agent',
			'referrer'       => 'Referrer',
			'login_status'   => 'Login-Status',
			'user_role'      => 'Benutzerrolle',
			'device_type'    => 'Geraetetyp',
			'language'       => 'Sprache',
			'cookie'         => 'Cookie',
			'query_param'    => 'Query-Parameter',
			'ip_range'       => 'IP-Bereich',
			'server_name'    => 'Server-Name',
			'request_method' => 'HTTP-Methode',
			'time_range'     => 'Uhrzeit-Bereich',
			'day_of_week'    => 'Wochentag',
		);

		echo '<option value="">-- Bitte waehlen --</option>';

		foreach ( $types as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
	}

	// -----------------------------------------------------------------
	// Helper: Condition Operator Options
	// -----------------------------------------------------------------

	/**
	 * Render <option> elements for condition operator selects.
	 *
	 * @param string $selected Currently selected value.
	 * @return void
	 */
	private static function render_condition_operator_options( $selected = '' ) {

		$operators = array(
			'equals'       => 'ist gleich',
			'not_equals'   => 'ist nicht gleich',
			'contains'     => 'enthaelt',
			'not_contains' => 'enthaelt nicht',
			'starts_with'  => 'beginnt mit',
			'regex'        => 'Regex',
			'exists'       => 'existiert',
			'not_exists'   => 'existiert nicht',
			'in_range'     => 'im Bereich',
		);

		foreach ( $operators as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
	}
}
