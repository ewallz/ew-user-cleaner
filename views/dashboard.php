<?php
/**
 * Dashboard view.
 *
 * @package EWUC
 *
 * @var array<string, mixed>|null $job      Latest scan job.
 * @var array<string, mixed>      $settings Plugin settings.
 * @var string                    $base_url Menu base URL.
 */

defined( 'ABSPATH' ) || exit;

$ewuc_counts     = $job ? EWUC_Candidates::counts( (int) $job['id'] ) : array();
$ewuc_quarantine = EWUC_Quarantine::count_active();
$ewuc_backups    = EWUC_Backup::total_bytes();
$ewuc_stale      = $job && (string) $job['rule_version'] !== EWUC_Settings::rule_version( $settings );
?>

<div class="ewuc-grid">
	<section class="ewuc-card" aria-labelledby="ewuc-scan-heading">
		<h2 id="ewuc-scan-heading"><?php esc_html_e( 'Scan', 'ew-user-cleaner' ); ?></h2>

		<?php if ( $ewuc_stale ) : ?>
			<p class="ewuc-warning">
				<?php esc_html_e( 'The rules changed since this scan ran. Results are stale until you run a new scan.', 'ew-user-cleaner' ); ?>
			</p>
		<?php endif; ?>

		<p class="ewuc-note">
			<?php esc_html_e( 'Scans run only while this page is open. Closing the page pauses the job and saves progress so you can resume later.', 'ew-user-cleaner' ); ?>
		</p>

		<dl class="ewuc-stats">
			<div>
				<dt><?php esc_html_e( 'Status', 'ew-user-cleaner' ); ?></dt>
				<dd data-ewuc-status><?php echo esc_html( $job ? (string) $job['status'] : __( 'no scan yet', 'ew-user-cleaner' ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Users processed', 'ew-user-cleaner' ); ?></dt>
				<dd data-ewuc-processed><?php echo esc_html( number_format_i18n( $job ? (int) $job['processed'] : 0 ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Candidates found', 'ew-user-cleaner' ); ?></dt>
				<dd data-ewuc-matched><?php echo esc_html( number_format_i18n( $job ? (int) $job['matched'] : 0 ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Highest user ID in scope', 'ew-user-cleaner' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $job ? (int) $job['upper_user_id'] : 0 ) ); ?></dd>
			</div>
		</dl>

		<div class="ewuc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
			aria-valuenow="0" aria-label="<?php esc_attr_e( 'Scan progress', 'ew-user-cleaner' ); ?>">
			<span data-ewuc-bar></span>
		</div>

		<p class="ewuc-actions">
			<?php if ( current_user_can( 'ewuc_scan_users' ) && ewuc_destructive_allowed() && ! empty( $settings['configured'] ) ) : ?>
				<button type="button" class="button button-primary" data-ewuc-start><?php esc_html_e( 'Start new scan', 'ew-user-cleaner' ); ?></button>
				<button type="button" class="button" data-ewuc-resume><?php esc_html_e( 'Resume', 'ew-user-cleaner' ); ?></button>
				<button type="button" class="button" data-ewuc-pause><?php esc_html_e( 'Pause', 'ew-user-cleaner' ); ?></button>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'settings' ), $base_url ) ); ?>">
					<?php esc_html_e( 'Configure rules', 'ew-user-cleaner' ); ?>
				</a>
			<?php endif; ?>
		</p>

		<p class="ewuc-message" data-ewuc-message role="status" aria-live="polite"></p>
	</section>

	<section class="ewuc-card" aria-labelledby="ewuc-review-heading">
		<h2 id="ewuc-review-heading"><?php esc_html_e( 'Review queue', 'ew-user-cleaner' ); ?></h2>

		<dl class="ewuc-stats">
			<div>
				<dt><?php esc_html_e( 'Awaiting review', 'ew-user-cleaner' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $ewuc_counts['candidate'] ?? 0 ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Dismissed', 'ew-user-cleaner' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $ewuc_counts['dismissed'] ?? 0 ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Quarantined', 'ew-user-cleaner' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $ewuc_quarantine ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Purged', 'ew-user-cleaner' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $ewuc_counts['purged'] ?? 0 ) ); ?></dd>
			</div>
		</dl>

		<p class="ewuc-actions">
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'candidates' ), $base_url ) ); ?>">
				<?php esc_html_e( 'Review candidates', 'ew-user-cleaner' ); ?>
			</a>
		</p>
	</section>

	<section class="ewuc-card" aria-labelledby="ewuc-storage-heading">
		<h2 id="ewuc-storage-heading"><?php esc_html_e( 'Backup storage', 'ew-user-cleaner' ); ?></h2>
		<p class="ewuc-big"><?php echo esc_html( EWUC_Admin::format_bytes( $ewuc_backups ) ); ?></p>
		<p class="ewuc-note">
			<?php esc_html_e( 'Backups contain personal data and are kept until you delete them manually. Remove batches you no longer need.', 'ew-user-cleaner' ); ?>
		</p>
		<p class="ewuc-actions">
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'backups' ), $base_url ) ); ?>">
				<?php esc_html_e( 'Manage backups', 'ew-user-cleaner' ); ?>
			</a>
		</p>
	</section>
</div>

<section class="ewuc-card ewuc-promo" aria-labelledby="ewuc-promo-heading">
	<div class="ewuc-promo-intro">
		<h2 id="ewuc-promo-heading"><?php esc_html_e( 'About this plugin', 'ew-user-cleaner' ); ?></h2>

		<p class="ewuc-promo-lead">
			<?php esc_html_e( 'EW User Cleaner finds likely spam registrations by scoring each account against rules you control, then lets you review them and quarantine or permanently purge them. Quarantine blocks sign in without deleting anything, and every purge is backed up first, so cleanup stays reversible.', 'ew-user-cleaner' ); ?>
		</p>

		<p class="ewuc-note">
			<?php
			printf(
				/* translators: %s: developer name. */
				esc_html__( 'Developed by %s, WordPress customization experts. Need a custom plugin, a performance audit, or help with this one? Talk to us.', 'ew-user-cleaner' ),
				'<strong>eWallz Solutions</strong>'
			);
			?>
		</p>
	</div>

	<div class="ewuc-promo-links">
		<?php
		/*
		 * External destinations are hardcoded, never built from user input.
		 * noopener/noreferrer keeps the opened tab from reaching back into
		 * this admin session.
		 */
		$ewuc_promo_links = array(
			array(
				'url'      => 'https://www.ewallzsolutions.com',
				'label'    => __( 'Visit our website', 'ew-user-cleaner' ),
				'primary'  => true,
				'external' => true,
			),
			array(
				'url'      => 'mailto:hello@ewallzsolutions.com',
				'label'    => __( 'Email support', 'ew-user-cleaner' ),
				'primary'  => false,
				'external' => false,
			),
			array(
				'url'      => 'https://wa.me/60355230791',
				'label'    => __( 'WhatsApp support', 'ew-user-cleaner' ),
				'primary'  => false,
				'external' => true,
			),
		);

		foreach ( $ewuc_promo_links as $ewuc_link ) :
			?>
			<a class="<?php echo esc_attr( $ewuc_link['primary'] ? 'button button-primary' : 'button' ); ?>"
				href="<?php echo esc_url( $ewuc_link['url'] ); ?>"
				<?php if ( $ewuc_link['external'] ) : ?>
					target="_blank" rel="noopener noreferrer external"
				<?php endif; ?>>
				<?php echo esc_html( $ewuc_link['label'] ); ?>
				<?php if ( $ewuc_link['external'] ) : ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'ew-user-cleaner' ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>

		<span class="ewuc-note ewuc-promo-contact">
			hello@ewallzsolutions.com
		</span>
	</div>
</section>
