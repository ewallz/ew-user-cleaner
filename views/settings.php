<?php
/**
 * Settings view.
 *
 * @package EWUC
 *
 * @var array<string, mixed> $settings Plugin settings.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'ewuc_manage_settings' ) ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'You are not allowed to change these settings.', 'ew-user-cleaner' ) . '</p></div>';
	return;
}

$ewuc_labels   = EWUC_Settings::rule_labels();
$ewuc_roles    = wp_roles()->get_names();
$ewuc_help_url = add_query_arg( array( 'tab' => 'help' ), menu_page_url( EWUC_Admin::SLUG, false ) );
?>

<p class="ewuc-note">
	<?php
	printf(
		/* translators: %s: link to the Help tab. */
		esc_html__( 'Not sure what to type in the pattern fields? The %s has 20 ready made patterns you can copy, plus examples of what each one matches.', 'ew-user-cleaner' ),
		'<a href="' . esc_url( $ewuc_help_url ) . '">' . esc_html__( 'Help tab', 'ew-user-cleaner' ) . '</a>'
	);
	?>
</p>

<form method="post" class="ewuc-settings">
	<?php wp_nonce_field( 'ewuc_save_settings' ); ?>
	<input type="hidden" name="ewuc_action" value="save_settings" />

	<section class="ewuc-card">
		<h2><?php esc_html_e( 'Scoring policy', 'ew-user-cleaner' ); ?></h2>
		<p class="ewuc-note">
			<?php esc_html_e( 'A user becomes a review candidate when the total weight of its matched rules reaches the threshold. These signals are probabilistic, so nothing is deleted automatically.', 'ew-user-cleaner' ); ?>
		</p>

		<p>
			<label for="ewuc-threshold"><strong><?php esc_html_e( 'Candidate threshold', 'ew-user-cleaner' ); ?></strong></label><br />
			<input type="number" id="ewuc-threshold" name="ewuc[threshold]" min="1" max="100" step="1"
				value="<?php echo esc_attr( (string) $settings['threshold'] ); ?>" required />
			<span class="ewuc-note"><?php esc_html_e( 'Set a value above the weight of any single rule to require combined signals.', 'ew-user-cleaner' ); ?></span>
		</p>

		<table class="widefat striped ewuc-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Detection rules', 'ew-user-cleaner' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Enabled', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rule', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Weight', 'ew-user-cleaner' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Options', 'ew-user-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $settings['rules'] as $ewuc_id => $ewuc_rule ) : ?>
					<tr>
						<td>
							<input type="checkbox" id="ewuc-rule-<?php echo esc_attr( $ewuc_id ); ?>"
								name="ewuc[rules][<?php echo esc_attr( $ewuc_id ); ?>][enabled]" value="1"
								<?php checked( ! empty( $ewuc_rule['enabled'] ) ); ?> />
						</td>
						<td>
							<label for="ewuc-rule-<?php echo esc_attr( $ewuc_id ); ?>">
								<?php echo esc_html( $ewuc_labels[ $ewuc_id ] ?? $ewuc_id ); ?>
							</label>
						</td>
						<td>
							<input type="number" min="0" max="50" step="1" class="small-text"
								name="ewuc[rules][<?php echo esc_attr( $ewuc_id ); ?>][weight]"
								value="<?php echo esc_attr( (string) $ewuc_rule['weight'] ); ?>"
								aria-label="<?php esc_attr_e( 'Weight', 'ew-user-cleaner' ); ?>" />
						</td>
						<td>
							<?php if ( isset( $ewuc_rule['min_digits'] ) ) : ?>
								<label><?php esc_html_e( 'Min digits', 'ew-user-cleaner' ); ?>
									<input type="number" min="4" max="20" step="1" class="small-text"
										name="ewuc[rules][<?php echo esc_attr( $ewuc_id ); ?>][min_digits]"
										value="<?php echo esc_attr( (string) $ewuc_rule['min_digits'] ); ?>" />
								</label>
								<label><?php esc_html_e( 'Max digits', 'ew-user-cleaner' ); ?>
									<input type="number" min="4" max="30" step="1" class="small-text"
										name="ewuc[rules][<?php echo esc_attr( $ewuc_id ); ?>][max_digits]"
										value="<?php echo esc_attr( (string) $ewuc_rule['max_digits'] ); ?>" />
								</label>
							<?php elseif ( isset( $ewuc_rule['pattern'] ) ) : ?>
								<input type="text" class="regular-text" maxlength="120"
									name="ewuc[rules][<?php echo esc_attr( $ewuc_id ); ?>][pattern]"
									value="<?php echo esc_attr( (string) $ewuc_rule['pattern'] ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. [a-z]{3}[0-9]{6}', 'ew-user-cleaner' ); ?>"
									aria-label="<?php esc_attr_e( 'Pattern', 'ew-user-cleaner' ); ?>" />
							<?php else : ?>
								<span class="ewuc-muted">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="ewuc-card">
		<h2><?php esc_html_e( 'Lists', 'ew-user-cleaner' ); ?></h2>
		<p class="ewuc-note"><?php esc_html_e( 'One entry per line. Allowlist entries are checked first and always win: a matching user ID, username, email or email domain scores zero and no other rule is evaluated.', 'ew-user-cleaner' ); ?></p>

		<div class="ewuc-fieldgrid">
			<?php
			$ewuc_lists = array(
				'flagged_domains' => array(
					'label'   => __( 'Flagged email domains', 'ew-user-cleaner' ),
					'help'    => __( 'Adds score when the address ends in one of these domains, including subdomains, so ff.com also covers xx.ff.com. A leading dot is optional and changes nothing. Requires the "Email domain is in the flagged domain list" rule above to be enabled.', 'ew-user-cleaner' ),
					'example' => 'tempmail.com',
				),
				'allow_domains'   => array(
					'label'   => __( 'Allowed email domains', 'ew-user-cleaner' ),
					'help'    => __( 'Skipped entirely: an address on one of these domains is never flagged, whatever the other rules say. Subdomains are included. Use this for your company and customer domains, but avoid large public providers if you want to catch spam registered there.', 'ew-user-cleaner' ),
					'example' => 'microsoft.com',
				),
				'allow_logins'    => array(
					'label'   => __( 'Allowed usernames', 'ew-user-cleaner' ),
					'help'    => __( 'These accounts are skipped entirely, whatever the rules say.', 'ew-user-cleaner' ),
					'example' => 'support_team',
				),
				'allow_emails'    => array(
					'label'   => __( 'Allowed emails', 'ew-user-cleaner' ),
					'help'    => __( 'Exact addresses that are always skipped. One full address per line.', 'ew-user-cleaner' ),
					'example' => 'user@microsoft.com',
				),
				'block_logins'    => array(
					'label'   => __( 'Blocked usernames', 'ew-user-cleaner' ),
					'help'    => __( 'Adds the blocklist score. Still reviewed, never deleted automatically.', 'ew-user-cleaner' ),
					'example' => 'casino_bot',
				),
				'block_emails'    => array(
					'label'   => __( 'Blocked emails', 'ew-user-cleaner' ),
					'help'    => __( 'Exact addresses that get the blocklist score. One full address per line.', 'ew-user-cleaner' ),
					'example' => 'spammer@tempmail.com',
				),
			);

			foreach ( $ewuc_lists as $ewuc_key => $ewuc_list ) :
				$ewuc_id   = 'ewuc-' . $ewuc_key;
				$ewuc_desc = $ewuc_id . '-desc';
				?>
				<p>
					<label for="<?php echo esc_attr( $ewuc_id ); ?>"><strong><?php echo esc_html( $ewuc_list['label'] ); ?></strong></label><br />
					<textarea id="<?php echo esc_attr( $ewuc_id ); ?>" name="ewuc[<?php echo esc_attr( $ewuc_key ); ?>]"
						rows="4" class="large-text code"
						aria-describedby="<?php echo esc_attr( $ewuc_desc ); ?>"
						placeholder="<?php echo esc_attr( $ewuc_list['example'] ); ?>"><?php echo esc_textarea( implode( "\n", (array) $settings[ $ewuc_key ] ) ); ?></textarea>
					<span class="ewuc-help" id="<?php echo esc_attr( $ewuc_desc ); ?>">
						<span class="ewuc-help-text"><?php echo esc_html( $ewuc_list['help'] ); ?></span>
						<span class="ewuc-help-eg">
							<?php
							printf(
								/* translators: %s: example value. */
								esc_html__( 'e.g. %s', 'ew-user-cleaner' ),
								'<code>' . esc_html( $ewuc_list['example'] ) . '</code>'
							);
							?>
						</span>
					</span>
				</p>
			<?php endforeach; ?>

			<p>
				<label for="ewuc-allow-ids"><strong><?php esc_html_e( 'Always protected user IDs', 'ew-user-cleaner' ); ?></strong></label><br />
				<textarea id="ewuc-allow-ids" name="ewuc[allow_user_ids]" rows="4" class="large-text code"
					aria-describedby="ewuc-allow-ids-desc" placeholder="1"><?php echo esc_textarea( implode( "\n", array_map( 'strval', (array) $settings['allow_user_ids'] ) ) ); ?></textarea>
				<span class="ewuc-help" id="ewuc-allow-ids-desc">
					<span class="ewuc-help-text"><?php esc_html_e( 'Numeric user IDs that are never flagged or deleted. One per line.', 'ew-user-cleaner' ); ?></span>
					<span class="ewuc-help-eg">
						<?php
						printf(
							/* translators: %s: example value. */
							esc_html__( 'e.g. %s', 'ew-user-cleaner' ),
							'<code>1</code>'
						);
						?>
					</span>
				</span>
			</p>
		</div>
	</section>

	<section class="ewuc-card">
		<h2><?php esc_html_e( 'Safety', 'ew-user-cleaner' ); ?></h2>

		<fieldset>
			<legend><strong><?php esc_html_e( 'Protected roles', 'ew-user-cleaner' ); ?></strong></legend>
			<div class="ewuc-checkgrid">
				<?php foreach ( $ewuc_roles as $ewuc_role => $ewuc_name ) : ?>
					<label>
						<input type="checkbox" name="ewuc[protected_roles][]" value="<?php echo esc_attr( $ewuc_role ); ?>"
							<?php checked( in_array( $ewuc_role, (array) $settings['protected_roles'], true ) ); ?>
							<?php disabled( 'administrator' === $ewuc_role ); ?> />
						<?php echo esc_html( $ewuc_name ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="ewuc-note"><?php esc_html_e( 'Administrators are always protected.', 'ew-user-cleaner' ); ?></p>
		</fieldset>

		<p>
			<label>
				<input type="checkbox" name="ewuc[protect_user_one]" value="1" <?php checked( ! empty( $settings['protect_user_one'] ) ); ?> />
				<?php esc_html_e( 'Always protect user ID 1', 'ew-user-cleaner' ); ?>
			</label>
		</p>

		<p>
			<label for="ewuc-reassign"><strong><?php esc_html_e( 'Content reassignment user ID', 'ew-user-cleaner' ); ?></strong></label><br />
			<input type="number" id="ewuc-reassign" min="0" step="1" name="ewuc[reassign_user_id]"
				value="<?php echo esc_attr( (string) $settings['reassign_user_id'] ); ?>" />
			<span class="ewuc-note"><?php esc_html_e( 'Required when overriding protection. Authored content is transferred to this account instead of being deleted.', 'ew-user-cleaner' ); ?></span>
		</p>
	</section>

	<section class="ewuc-card">
		<h2><?php esc_html_e( 'Performance', 'ew-user-cleaner' ); ?></h2>

		<div class="ewuc-fieldgrid">
			<p>
				<label for="ewuc-batch-scan"><?php esc_html_e( 'Users per scan request', 'ew-user-cleaner' ); ?></label><br />
				<input type="number" id="ewuc-batch-scan" min="25" max="1000" step="25" name="ewuc[batch_scan]"
					value="<?php echo esc_attr( (string) $settings['batch_scan'] ); ?>" />
			</p>
			<p>
				<label for="ewuc-batch-quarantine"><?php esc_html_e( 'Users per quarantine request', 'ew-user-cleaner' ); ?></label><br />
				<input type="number" id="ewuc-batch-quarantine" min="5" max="100" step="5" name="ewuc[batch_quarantine]"
					value="<?php echo esc_attr( (string) $settings['batch_quarantine'] ); ?>" />
			</p>
			<p>
				<label for="ewuc-batch-purge"><?php esc_html_e( 'Users per purge request', 'ew-user-cleaner' ); ?></label><br />
				<input type="number" id="ewuc-batch-purge" min="1" max="50" step="1" name="ewuc[batch_purge]"
					value="<?php echo esc_attr( (string) $settings['batch_purge'] ); ?>" />
			</p>
		</div>
	</section>

	<section class="ewuc-card">
		<h2><?php esc_html_e( 'Uninstall', 'ew-user-cleaner' ); ?></h2>
		<p>
			<label>
				<input type="checkbox" name="ewuc[remove_data_on_uninstall]" value="1"
					<?php checked( ! empty( $settings['remove_data_on_uninstall'] ) ); ?> />
				<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'ew-user-cleaner' ); ?>
			</label>
		</p>
		<p class="ewuc-warning">
			<?php esc_html_e( 'This removes candidates, quarantine records, backups and the audit log. Uninstall will refuse while accounts are still quarantined, because deactivating the plugin already restores their ability to sign in.', 'ew-user-cleaner' ); ?>
		</p>
	</section>

	<p class="ewuc-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ew-user-cleaner' ); ?></button>
		<button type="button" class="button" data-ewuc-preview><?php esc_html_e( 'Estimate impact', 'ew-user-cleaner' ); ?></button>
	</p>

	<p class="ewuc-message" data-ewuc-preview-message role="status" aria-live="polite"></p>
</form>
