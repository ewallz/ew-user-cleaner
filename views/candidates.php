<?php
/**
 * Candidate review view.
 *
 * @package EWUC
 *
 * @var array<string, mixed>|null $job      Latest scan job.
 * @var array<string, mixed>      $settings Plugin settings.
 * @var string                    $base_url Menu base URL.
 */

defined( 'ABSPATH' ) || exit;

$ewuc_job_id = $job ? (int) $job['id'] : 0;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$ewuc_state   = isset( $_GET['state'] ) ? sanitize_key( (string) $_GET['state'] ) : 'candidate';
$ewuc_order   = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'score';
$ewuc_dir     = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
$ewuc_page    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$ewuc_search  = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
$ewuc_perpage = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$ewuc_result = EWUC_Candidates::query(
	array(
		'job_id'   => $ewuc_job_id,
		'state'    => $ewuc_state,
		'orderby'  => $ewuc_order,
		'order'    => $ewuc_dir,
		'page'     => $ewuc_page,
		'per_page' => $ewuc_perpage,
		'search'   => $ewuc_search,
	)
);

$ewuc_total    = (int) $ewuc_result['total'];
$ewuc_per_page = ewuc_clamp_int( $ewuc_perpage, 20, 100, 50 );
$ewuc_pages    = (int) ceil( $ewuc_total / $ewuc_per_page );
$ewuc_labels   = array(
	'current_user'    => __( 'Signed in admin', 'ew-user-cleaner' ),
	'user_one'        => __( 'Site owner', 'ew-user-cleaner' ),
	'protected_role'  => __( 'Protected role', 'ew-user-cleaner' ),
	'protected_cap'   => __( 'Privileged', 'ew-user-cleaner' ),
	'reassign_target' => __( 'Reassign target', 'ew-user-cleaner' ),
	'owns_data'       => __( 'Owns data', 'ew-user-cleaner' ),
	'missing'         => __( 'Deleted', 'ew-user-cleaner' ),
);
?>

<?php if ( ! $ewuc_job_id ) : ?>
	<div class="notice notice-info"><p><?php esc_html_e( 'Run a scan first to build the candidate list.', 'ew-user-cleaner' ); ?></p></div>
<?php else : ?>

<form method="get" class="ewuc-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( EWUC_Admin::SLUG ); ?>" />
	<input type="hidden" name="tab" value="candidates" />

	<label for="ewuc-state" class="screen-reader-text"><?php esc_html_e( 'State', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-state" name="state">
		<?php foreach ( EWUC_Candidates::states() as $ewuc_option ) : ?>
			<option value="<?php echo esc_attr( $ewuc_option ); ?>" <?php selected( $ewuc_state, $ewuc_option ); ?>>
				<?php echo esc_html( $ewuc_option ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label for="ewuc-orderby" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-orderby" name="orderby">
		<?php
		$ewuc_sorts = array(
			'score'      => __( 'Score', 'ew-user-cleaner' ),
			'user_id'    => __( 'User ID', 'ew-user-cleaner' ),
			'registered' => __( 'Registered', 'ew-user-cleaner' ),
			'login'      => __( 'Username', 'ew-user-cleaner' ),
			'domain'     => __( 'Email domain', 'ew-user-cleaner' ),
			'scanned'    => __( 'Scan date', 'ew-user-cleaner' ),
		);

		foreach ( $ewuc_sorts as $ewuc_key => $ewuc_label ) :
			?>
			<option value="<?php echo esc_attr( $ewuc_key ); ?>" <?php selected( $ewuc_order, $ewuc_key ); ?>>
				<?php echo esc_html( $ewuc_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label for="ewuc-dir" class="screen-reader-text"><?php esc_html_e( 'Direction', 'ew-user-cleaner' ); ?></label>
	<select id="ewuc-dir" name="order">
		<option value="desc" <?php selected( $ewuc_dir, 'desc' ); ?>><?php esc_html_e( 'Descending', 'ew-user-cleaner' ); ?></option>
		<option value="asc" <?php selected( $ewuc_dir, 'asc' ); ?>><?php esc_html_e( 'Ascending', 'ew-user-cleaner' ); ?></option>
	</select>

	<label for="ewuc-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'ew-user-cleaner' ); ?></label>
	<input type="search" id="ewuc-search" name="s" value="<?php echo esc_attr( $ewuc_search ); ?>"
		placeholder="<?php esc_attr_e( 'User ID or name prefix', 'ew-user-cleaner' ); ?>" />

	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ew-user-cleaner' ); ?></button>

	<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'ewuc_export' => 1, 'job_id' => $ewuc_job_id, 'state' => $ewuc_state ), $base_url ), 'ewuc_export' ) ); ?>">
		<?php esc_html_e( 'Export CSV', 'ew-user-cleaner' ); ?>
	</a>
</form>

<p class="ewuc-note">
	<?php
	printf(
		/* translators: %s: candidate count. */
		esc_html__( '%s matching rows. Search matches user IDs or name prefixes so large sites stay fast.', 'ew-user-cleaner' ),
		esc_html( number_format_i18n( $ewuc_total ) )
	);
	?>
</p>

<form data-ewuc-bulk data-job="<?php echo esc_attr( (string) $ewuc_job_id ); ?>">
	<div class="ewuc-bulkbar">
		<?php if ( current_user_can( 'ewuc_quarantine_users' ) && ewuc_destructive_allowed() ) : ?>
			<button type="button" class="button button-primary" data-ewuc-quarantine>
				<?php esc_html_e( 'Quarantine selected', 'ew-user-cleaner' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="button" data-ewuc-dismiss><?php esc_html_e( 'Mark as legitimate', 'ew-user-cleaner' ); ?></button>

		<?php
		if ( current_user_can( 'ewuc_quarantine_users' ) && ewuc_destructive_allowed() && 'candidate' === $ewuc_state ) :
			$ewuc_pending = EWUC_Candidates::count_matching(
				array(
					'job_id' => $ewuc_job_id,
					'state'  => 'candidate',
					'search' => $ewuc_search,
				)
			);
			?>
			<button type="button" class="button ewuc-danger" data-ewuc-quarantine-all
				data-total="<?php echo esc_attr( (string) $ewuc_pending ); ?>"
				data-search="<?php echo esc_attr( $ewuc_search ); ?>"
				<?php disabled( 0, $ewuc_pending ); ?>>
				<?php
				printf(
					/* translators: %s: number of pending candidates. */
					esc_html__( 'Quarantine all %s matching', 'ew-user-cleaner' ),
					esc_html( number_format_i18n( $ewuc_pending ) )
				);
				?>
			</button>
		<?php endif; ?>

		<?php if ( current_user_can( 'ewuc_quarantine_users' ) && ewuc_destructive_allowed() && (int) $settings['reassign_user_id'] > 0 ) : ?>
			<label class="ewuc-note">
				<input type="checkbox" data-ewuc-override />
				<?php
				printf(
					/* translators: %d: destination user ID. */
					esc_html__( 'Override "owns data" protection and reassign content to user #%d', 'ew-user-cleaner' ),
					(int) $settings['reassign_user_id']
				);
				?>
			</label>
		<?php endif; ?>

		<span class="ewuc-note">
			<?php
			printf(
				/* translators: %d: batch size. */
				esc_html__( 'Selected actions apply to the rows checked on this page only, up to %d per request. "Quarantine all" ignores your checkboxes and works through every awaiting-review row that matches the current search, in batches, while this page stays open. Privileged accounts are always skipped.', 'ew-user-cleaner' ),
				(int) $settings['batch_quarantine']
			);
			?>
		</span>
	</div>

	<div class="ewuc-tablewrap">
		<table class="wp-list-table widefat striped ewuc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Spam candidates', 'ew-user-cleaner' ); ?></caption>
			<thead>
				<tr>
					<td class="check-column">
						<input type="checkbox" data-ewuc-toggle-all
							aria-label="<?php esc_attr_e( 'Select all rows on this page', 'ew-user-cleaner' ); ?>" />
					</td>
					<th scope="col"><?php esc_html_e( 'User', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Registered', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Why it matched', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'ew-user-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $ewuc_result['items'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No rows match these filters.', 'ew-user-cleaner' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $ewuc_result['items'] as $ewuc_row ) : ?>
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
						<td><?php echo esc_html( (string) $ewuc_row['registered_at'] ); ?></td>
						<td><span class="ewuc-score"><?php echo esc_html( (string) $ewuc_row['score'] ); ?></span></td>
						<td>
							<ul class="ewuc-reasons">
								<?php foreach ( (array) $ewuc_row['reasons'] as $ewuc_reason ) : ?>
									<li><?php echo esc_html( (string) $ewuc_reason ); ?></li>
								<?php endforeach; ?>
							</ul>
						</td>
						<td>
							<span class="ewuc-pill"><?php echo esc_html( (string) $ewuc_row['state'] ); ?></span>
							<?php if ( ! empty( $ewuc_row['protected_code'] ) ) : ?>
								<span class="ewuc-pill ewuc-pill-warn">
									<?php echo esc_html( $ewuc_labels[ (string) $ewuc_row['protected_code'] ] ?? (string) $ewuc_row['protected_code'] ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="ewuc-message" data-ewuc-bulk-message role="status" aria-live="polite"></p>
</form>

<?php if ( $ewuc_pages > 1 ) : ?>
	<nav class="ewuc-pagination" aria-label="<?php esc_attr_e( 'Candidate pages', 'ew-user-cleaner' ); ?>">
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

<?php endif; ?>
