<?php
/**
 * Quarantine view.
 *
 * @package EWUC
 *
 * @var array<string, mixed> $settings Plugin settings.
 * @var string               $base_url Menu base URL.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$ewuc_page    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$ewuc_order   = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'quarantined';
$ewuc_dir     = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
$ewuc_search  = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$ewuc_domain  = isset( $_GET['domain'] ) ? sanitize_text_field( (string) $_GET['domain'] ) : '';
$ewuc_perpage = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$ewuc_per_page = ewuc_clamp_int( $ewuc_perpage, 20, 100, 50 );

$ewuc_result = EWUC_Quarantine::query(
	array(
		'page'     => $ewuc_page,
		'per_page' => $ewuc_per_page,
		'orderby'  => $ewuc_order,
		'order'    => $ewuc_dir,
		'search'   => $ewuc_search,
		'domain'   => $ewuc_domain,
	)
);

$ewuc_total     = (int) $ewuc_result['total'];
$ewuc_pages     = (int) ceil( $ewuc_total / $ewuc_per_page );
$ewuc_active    = EWUC_Quarantine::count_active();
$ewuc_filtering = '' !== $ewuc_search || '' !== $ewuc_domain;
?>

<p class="ewuc-note">
	<?php esc_html_e( 'Quarantined accounts still exist and keep all their data, but they cannot sign in. Restoring returns the account to normal with the same user ID. Purging is permanent and always creates an encrypted backup first.', 'ew-user-cleaner' ); ?>
</p>

<form method="get" class="ewuc-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( EWUC_Admin::SLUG ); ?>" />
	<input type="hidden" name="tab" value="quarantine" />

	<label for="ewuc-q-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'ew-user-cleaner' ); ?></label>
	<input type="search" id="ewuc-q-search" name="s" value="<?php echo esc_attr( $ewuc_search ); ?>"
		placeholder="<?php esc_attr_e( 'User ID, name or email prefix', 'ew-user-cleaner' ); ?>" />

	<label for="ewuc-q-domain" class="screen-reader-text"><?php esc_html_e( 'Email domain', 'ew-user-cleaner' ); ?></label>
	<input type="text" id="ewuc-q-domain" name="domain" value="<?php echo esc_attr( $ewuc_domain ); ?>"
		placeholder="<?php esc_attr_e( 'Email domain, e.g. example.com', 'ew-user-cleaner' ); ?>" />

	<label for="ewuc-q-orderby" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-q-orderby" name="orderby">
		<?php
		$ewuc_sorts = array(
			'quarantined' => __( 'Quarantine date', 'ew-user-cleaner' ),
			'user_id'     => __( 'User ID', 'ew-user-cleaner' ),
			'login'       => __( 'Username', 'ew-user-cleaner' ),
			'email'       => __( 'Email', 'ew-user-cleaner' ),
		);

		foreach ( $ewuc_sorts as $ewuc_key => $ewuc_label ) :
			?>
			<option value="<?php echo esc_attr( $ewuc_key ); ?>" <?php selected( $ewuc_order, $ewuc_key ); ?>>
				<?php echo esc_html( $ewuc_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label for="ewuc-q-dir" class="screen-reader-text"><?php esc_html_e( 'Direction', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-q-dir" name="order">
		<option value="desc" <?php selected( $ewuc_dir, 'desc' ); ?>><?php esc_html_e( 'Descending', 'ew-user-cleaner' ); ?></option>
		<option value="asc" <?php selected( $ewuc_dir, 'asc' ); ?>><?php esc_html_e( 'Ascending', 'ew-user-cleaner' ); ?></option>
	</select>

	<label for="ewuc-q-perpage" class="screen-reader-text"><?php esc_html_e( 'Rows per page', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-q-perpage" name="per_page">
		<?php foreach ( array( 20, 50, 100 ) as $ewuc_size ) : ?>
			<option value="<?php echo esc_attr( (string) $ewuc_size ); ?>" <?php selected( $ewuc_per_page, $ewuc_size ); ?>>
				<?php
				printf(
					/* translators: %s: rows per page. */
					esc_html__( '%s per page', 'ew-user-cleaner' ),
					esc_html( number_format_i18n( $ewuc_size ) )
				);
				?>
			</option>
		<?php endforeach; ?>
	</select>

	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ew-user-cleaner' ); ?></button>

	<?php if ( $ewuc_filtering ) : ?>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'quarantine' ), $base_url ) ); ?>">
			<?php esc_html_e( 'Clear filters', 'ew-user-cleaner' ); ?>
		</a>
	<?php endif; ?>
</form>

<p class="ewuc-note">
	<?php
	printf(
		/* translators: %1$s: matching rows, %2$s: total quarantined. */
		esc_html__( '%1$s matching rows of %2$s quarantined. Search matches a user ID, or a username, display name or email prefix, so large sites stay fast.', 'ew-user-cleaner' ),
		esc_html( number_format_i18n( $ewuc_total ) ),
		esc_html( number_format_i18n( $ewuc_active ) )
	);
	?>
</p>

<form data-ewuc-quarantine-form>
	<div class="ewuc-bulkbar">
		<?php if ( current_user_can( 'ewuc_restore_users' ) ) : ?>
			<button type="button" class="button" data-ewuc-restore><?php esc_html_e( 'Restore selected', 'ew-user-cleaner' ); ?></button>
		<?php endif; ?>

		<?php if ( current_user_can( 'ewuc_purge_users' ) && ewuc_destructive_allowed() && EWUC_Crypto::is_available() ) : ?>
			<button type="button" class="button ewuc-danger" data-ewuc-purge>
				<?php esc_html_e( 'Purge selected permanently', 'ew-user-cleaner' ); ?>
			</button>
			<button type="button" class="button ewuc-danger" data-ewuc-purge-all
				data-total="<?php echo esc_attr( (string) $ewuc_active ); ?>">
				<?php
				printf(
					/* translators: %s: number of quarantined accounts. */
					esc_html__( 'Purge all %s quarantined', 'ew-user-cleaner' ),
					esc_html( number_format_i18n( $ewuc_active ) )
				);
				?>
			</button>

			<span class="ewuc-note">
				<?php
				printf(
					/* translators: %d: batch size. */
					esc_html__( 'Up to %d accounts per purge request. "Purge all" ignores the filters above and repeats in batches while this page stays open.', 'ew-user-cleaner' ),
					(int) $settings['batch_purge']
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<div class="ewuc-jobprogress" data-ewuc-progress="purge" hidden>
		<div class="ewuc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
			aria-label="<?php esc_attr_e( 'Purge progress', 'ew-user-cleaner' ); ?>">
			<span data-ewuc-progress-bar></span>
		</div>
		<p class="ewuc-progress-label">
			<strong data-ewuc-progress-percent>0%</strong>
			<span class="ewuc-note" data-ewuc-progress-text></span>
		</p>
	</div>

	<div class="ewuc-tablewrap">
		<table class="wp-list-table widefat striped ewuc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Quarantined users', 'ew-user-cleaner' ); ?></caption>
			<thead>
				<tr>
					<td class="check-column">
						<input type="checkbox" data-ewuc-toggle-all
							aria-label="<?php esc_attr_e( 'Select all rows on this page', 'ew-user-cleaner' ); ?>" />
					</td>
					<th scope="col"><?php esc_html_e( 'User', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quarantined', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'ew-user-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $ewuc_result['items'] ) : ?>
					<tr>
						<td colspan="5">
							<?php
							echo esc_html(
								$ewuc_filtering
									? __( 'No quarantined accounts match these filters.', 'ew-user-cleaner' )
									: __( 'Nothing is quarantined.', 'ew-user-cleaner' )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php
				foreach ( $ewuc_result['items'] as $ewuc_row ) :
					$ewuc_actor = get_userdata( (int) $ewuc_row['quarantined_by'] );
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( (string) $ewuc_row['user_id'] ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: username. */ __( 'Select %s', 'ew-user-cleaner' ), (string) $ewuc_row['user_login'] ) ); ?>" />
						</th>
						<td>
							<strong><?php echo esc_html( (string) $ewuc_row['user_login'] ); ?></strong>
							<span class="ewuc-muted">#<?php echo esc_html( (string) $ewuc_row['user_id'] ); ?></span>
						</td>
						<td><?php echo esc_html( (string) $ewuc_row['user_email'] ); ?></td>
						<td><?php echo esc_html( (string) $ewuc_row['quarantined_at'] ); ?></td>
						<td><?php echo esc_html( $ewuc_actor ? $ewuc_actor->user_login : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="ewuc-message" data-ewuc-quarantine-message role="status" aria-live="polite"></p>
</form>

<?php if ( $ewuc_pages > 1 ) : ?>
	<nav class="ewuc-pagination" aria-label="<?php esc_attr_e( 'Quarantine pages', 'ew-user-cleaner' ); ?>">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => max( 1, $ewuc_page ),
					'total'     => $ewuc_pages,
					'mid_size'  => 2,
					'prev_text' => __( 'Previous', 'ew-user-cleaner' ),
					'next_text' => __( 'Next', 'ew-user-cleaner' ),
				)
			)
		);
		?>
	</nav>
<?php endif; ?>
