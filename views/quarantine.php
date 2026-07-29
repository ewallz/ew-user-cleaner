<?php
/**
 * Quarantine view.
 *
 * @package EWUC
 *
 * @var array<string, mixed> $settings Plugin settings.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ewuc_page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$ewuc_result = EWUC_Quarantine::query( $ewuc_page, 50 );
$ewuc_pages  = (int) ceil( ( (int) $ewuc_result['total'] ) / 50 );
?>

<p class="ewuc-note">
	<?php esc_html_e( 'Quarantined accounts still exist and keep all their data, but they cannot sign in. Restoring returns the account to normal with the same user ID. Purging is permanent and always creates an encrypted backup first.', 'ew-user-cleaner' ); ?>
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
				data-total="<?php echo esc_attr( (string) EWUC_Quarantine::count_active() ); ?>">
				<?php
				printf(
					/* translators: %s: number of quarantined accounts. */
					esc_html__( 'Purge all %s quarantined', 'ew-user-cleaner' ),
					esc_html( number_format_i18n( EWUC_Quarantine::count_active() ) )
				);
				?>
			</button>

			<span class="ewuc-note">
				<?php
				printf(
					/* translators: %d: batch size. */
					esc_html__( 'Up to %d accounts per purge request. "Purge all" repeats in batches while this page stays open.', 'ew-user-cleaner' ),
					(int) $settings['batch_purge']
				);
				?>
			</span>
		<?php endif; ?>
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
					<tr><td colspan="5"><?php esc_html_e( 'Nothing is quarantined.', 'ew-user-cleaner' ); ?></td></tr>
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
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => max( 1, $ewuc_page ),
					'total'   => $ewuc_pages,
				)
			)
		);
		?>
	</nav>
<?php endif; ?>
