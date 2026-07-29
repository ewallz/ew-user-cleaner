<?php
/**
 * Audit log view.
 *
 * @package EWUC
 */

defined( 'ABSPATH' ) || exit;

$ewuc_events = EWUC_Audit::recent( 100 );
?>

<p class="ewuc-note">
	<?php esc_html_e( 'Every configuration change and destructive action is recorded here. Personal data and secrets are excluded.', 'ew-user-cleaner' ); ?>
</p>

<div class="ewuc-tablewrap">
	<table class="wp-list-table widefat striped ewuc-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Audit events', 'ew-user-cleaner' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actor', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Target', 'ew-user-cleaner' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outcome', 'ew-user-cleaner' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $ewuc_events ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No events recorded yet.', 'ew-user-cleaner' ); ?></td></tr>
			<?php endif; ?>

			<?php
			foreach ( $ewuc_events as $ewuc_event ) :
				$ewuc_actor = get_userdata( (int) $ewuc_event['actor_id'] );
				?>
				<tr>
					<td><?php echo esc_html( (string) $ewuc_event['created_at'] ); ?></td>
					<td><?php echo esc_html( $ewuc_actor ? $ewuc_actor->user_login : '—' ); ?></td>
					<td><code><?php echo esc_html( (string) $ewuc_event['action'] ); ?></code></td>
					<td>
						<?php
						if ( $ewuc_event['object_id'] ) {
							echo esc_html( (string) $ewuc_event['object_type'] . ' #' . (string) $ewuc_event['object_id'] );
						} else {
							echo '—';
						}
						?>
					</td>
					<td>
						<span class="ewuc-pill<?php echo 'ok' === $ewuc_event['outcome'] ? '' : ' ewuc-pill-warn'; ?>">
							<?php echo esc_html( (string) $ewuc_event['outcome'] ); ?>
						</span>
						<?php if ( ! empty( $ewuc_event['error_code'] ) ) : ?>
							<span class="ewuc-muted"><?php echo esc_html( (string) $ewuc_event['error_code'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
