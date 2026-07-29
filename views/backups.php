<?php
/**
 * Backup management view.
 *
 * @package EWUC
 */

defined( 'ABSPATH' ) || exit;

$ewuc_batches = EWUC_Backup::batches();
?>

<p class="ewuc-note">
	<?php esc_html_e( 'Every purge writes an encrypted backup of the account state before deletion. Backups stay until you delete them. Restoring a purged account creates a new user ID; only quarantine restores keep the original ID.', 'ew-user-cleaner' ); ?>
</p>

<p class="ewuc-big"><?php echo esc_html( EWUC_Admin::format_bytes( EWUC_Backup::total_bytes() ) ); ?></p>

<div class="ewuc-tablewrap">
	<table class="wp-list-table widefat striped ewuc-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Backup batches', 'ew-user-cleaner' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Batch', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Accounts', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Size', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'ew-user-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $ewuc_batches ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No backups stored.', 'ew-user-cleaner' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $ewuc_batches as $ewuc_batch ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $ewuc_batch['batch_id'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( (int) $ewuc_batch['users'] ) ); ?></td>
					<td><?php echo esc_html( EWUC_Admin::format_bytes( (int) $ewuc_batch['bytes'] ) ); ?></td>
					<td><?php echo esc_html( (string) $ewuc_batch['created_at'] ); ?></td>
					<td>
						<?php if ( current_user_can( 'ewuc_purge_users' ) ) : ?>
							<button type="button" class="button ewuc-danger" data-ewuc-delete-batch="<?php echo esc_attr( (string) $ewuc_batch['batch_id'] ); ?>">
								<?php esc_html_e( 'Delete backup', 'ew-user-cleaner' ); ?>
							</button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<h2><?php esc_html_e( 'Restore a purged account', 'ew-user-cleaner' ); ?></h2>

<?php if ( current_user_can( 'ewuc_restore_users' ) ) : ?>
	<p class="ewuc-note">
		<?php esc_html_e( 'Enter the original user ID. The account is rebuilt from its most recent backup with a new user ID, and unresolved references are reported.', 'ew-user-cleaner' ); ?>
	</p>

	<p class="ewuc-actions">
		<label for="ewuc-restore-id" class="screen-reader-text"><?php esc_html_e( 'Original user ID', 'ew-user-cleaner' ); ?></label>
		<input type="number" min="1" step="1" id="ewuc-restore-id" placeholder="<?php esc_attr_e( 'Original user ID', 'ew-user-cleaner' ); ?>" />
		<button type="button" class="button button-primary" data-ewuc-restore-backup><?php esc_html_e( 'Restore from backup', 'ew-user-cleaner' ); ?></button>
	</p>
<?php endif; ?>

<p class="ewuc-message" data-ewuc-backup-message role="status" aria-live="polite"></p>
