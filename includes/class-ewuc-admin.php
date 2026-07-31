<?php
/**
 * Admin screens and asset loading.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders the plugin admin interface.
 */
class EWUC_Admin {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const SLUG = 'ew-user-cleaner';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_init', array( 'EWUC_Export', 'maybe_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the menu pages.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_users_page(
			__( 'EW User Cleaner', 'ew-user-cleaner' ),
			__( 'User Cleaner', 'ew-user-cleaner' ),
			'ewuc_review_users',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'dashboard';
		$allowed = array( 'dashboard', 'candidates', 'quarantine', 'backups', 'audit', 'settings', 'help' );

		return in_array( $tab, $allowed, true ) ? $tab : 'dashboard';
	}

	/**
	 * Loads assets on plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( 'users_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ewuc-admin',
			EWUC_PLUGIN_URL . 'assets/admin.css',
			array(),
			EWUC_VERSION
		);

		wp_enqueue_script(
			'ewuc-admin',
			EWUC_PLUGIN_URL . 'assets/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			EWUC_VERSION,
			true
		);

		$latest = EWUC_Jobs::latest_scan();

		wp_localize_script(
			'ewuc-admin',
			'ewucData',
			array(
				'root'            => esc_url_raw( rest_url( EWUC_REST_NAMESPACE ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'jobId'           => $latest ? (int) $latest['id'] : 0,
				'jobStatus'       => $latest ? (string) $latest['status'] : '',
				'canScan'         => current_user_can( 'ewuc_scan_users' ),
				'batchQuarantine' => (int) EWUC_Settings::get()['batch_quarantine'],
				'batchPurge'      => (int) EWUC_Settings::get()['batch_purge'],
				'i18n'            => array(
					'scanning'  => __( 'Scanning…', 'ew-user-cleaner' ),
					'paused'    => __( 'Paused. Progress is saved.', 'ew-user-cleaner' ),
					'complete'  => __( 'Scan complete.', 'ew-user-cleaner' ),
					'failed'    => __( 'The last batch failed. You can resume.', 'ew-user-cleaner' ),
					'confirm'   => __( 'Type DELETE to confirm permanent removal.', 'ew-user-cleaner' ),
					'processed'   => __( 'Processed', 'ew-user-cleaner' ),
					'copy'        => __( 'Copy', 'ew-user-cleaner' ),
					'copiedShort' => __( 'Copied', 'ew-user-cleaner' ),
					'copied'      => __( 'Copied. Paste it into the matching field on the Settings tab.', 'ew-user-cleaner' ),
					'copyFailed'  => __( 'Could not copy automatically. Select the text and copy it manually.', 'ew-user-cleaner' ),
					/* translators: this exact phrase must be typed to confirm. */
					'confirmAll'  => __( 'This permanently deletes EVERY quarantined account, in batches, while this page stays open. Each account is backed up first. Type DELETE ALL to continue.', 'ew-user-cleaner' ),
					'purgeAllStop'    => __( 'Stop purging', 'ew-user-cleaner' ),
					'purgeAllStopped' => __( 'Stopped. Purged accounts stay purged.', 'ew-user-cleaner' ),
					'purgeAllRetry'   => __( 'Purge all (retry)', 'ew-user-cleaner' ),
					/* translators: %1$s: purged, %2$s: skipped, %3$s: failed, %4$s: remaining. */
					'purgeAllProgress'   => __( 'Purged %1$s, skipped %2$s, failed %3$s. %4$s still quarantined.', 'ew-user-cleaner' ),
					'nothingQuarantined' => __( 'Nothing is quarantined.', 'ew-user-cleaner' ),
					/* translators: %1$s: rows processed, %2$s: total rows. */
					'progressCount'      => __( '%1$s of %2$s processed', 'ew-user-cleaner' ),
					'progressStarting'   => __( 'Starting…', 'ew-user-cleaner' ),
					'progressDone'       => __( 'Finished. Reloading the list…', 'ew-user-cleaner' ),
					'progressStopped'    => __( 'Stopped before finishing.', 'ew-user-cleaner' ),
					'quarantineWorking'  => __( 'Quarantining…', 'ew-user-cleaner' ),
					'purgeWorking'       => __( 'Purging…', 'ew-user-cleaner' ),
					/* translators: %1$s: quarantined, %2$s: skipped. */
					'quarantineProgress' => __( 'Quarantined %1$s, skipped %2$s.', 'ew-user-cleaner' ),
					/* translators: %1$s: purged, %2$s: skipped, %3$s: failed. */
					'purgeProgress'      => __( 'Purged %1$s, skipped %2$s, failed %3$s.', 'ew-user-cleaner' ),
					/* translators: %s: number of matching accounts. This exact phrase must be typed to confirm. */
					'confirmQuarantineAll'  => __( 'This quarantines all %s accounts awaiting review that match your current search, in batches, while this page stays open. Quarantine is reversible: no account is deleted and nothing is purged. Type QUARANTINE ALL to continue.', 'ew-user-cleaner' ),
					'quarantineAllStop'     => __( 'Stop quarantining', 'ew-user-cleaner' ),
					'quarantineAllStopped'  => __( 'Stopped. Accounts already quarantined stay quarantined.', 'ew-user-cleaner' ),
					/* translators: %1$s: quarantined, %2$s: skipped, %3$s: remaining. */
					'quarantineAllProgress' => __( 'Quarantined %1$s, skipped %2$s. %3$s left to process.', 'ew-user-cleaner' ),
					'nothingToQuarantine'   => __( 'No accounts are awaiting review.', 'ew-user-cleaner' ),
				),
			)
		);
	}

	/**
	 * Handles form posts.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		if ( ! isset( $_POST['ewuc_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) $_POST['ewuc_action'] );

		if ( 'save_settings' === $action ) {
			if ( ! current_user_can( 'ewuc_manage_settings' ) ) {
				wp_die( esc_html__( 'You are not allowed to change these settings.', 'ew-user-cleaner' ), '', array( 'response' => 403 ) );
			}

			check_admin_referer( 'ewuc_save_settings' );

			$raw = isset( $_POST['ewuc'] ) && is_array( $_POST['ewuc'] ) ? wp_unslash( $_POST['ewuc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			list( $saved, $errors, $notices ) = EWUC_Settings::save( $raw );

			set_transient(
				'ewuc_notice_' . get_current_user_id(),
				array(
					'saved'   => $saved,
					'errors'  => array_map( 'wp_strip_all_tags', $errors ),
					'notices' => array_map( 'wp_strip_all_tags', $notices ),
				),
				60
			);

			wp_safe_redirect( add_query_arg( array( 'tab' => 'settings' ), menu_page_url( self::SLUG, false ) ) );
			exit;
		}
	}

	/**
	 * Renders the current tab.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'ewuc_review_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'ew-user-cleaner' ), '', array( 'response' => 403 ) );
		}

		$tab      = self::tab();
		$settings = EWUC_Settings::get();
		$job      = EWUC_Jobs::latest_scan();
		$base_url = menu_page_url( self::SLUG, false );

		$tabs = array(
			'dashboard'  => __( 'Dashboard', 'ew-user-cleaner' ),
			'candidates' => __( 'Candidates', 'ew-user-cleaner' ),
			'quarantine' => __( 'Quarantine', 'ew-user-cleaner' ),
			'backups'    => __( 'Backups', 'ew-user-cleaner' ),
			'audit'      => __( 'Audit', 'ew-user-cleaner' ),
			'settings'   => __( 'Settings', 'ew-user-cleaner' ),
			'help'       => __( 'Help', 'ew-user-cleaner' ),
		);

		echo '<div class="wrap ewuc-wrap">';
		echo '<h1 class="ewuc-title">' . esc_html__( 'EW User Cleaner', 'ew-user-cleaner' ) . '</h1>';

		self::render_environment_notices();

		echo '<nav class="nav-tab-wrapper ewuc-tabs" aria-label="' . esc_attr__( 'User Cleaner sections', 'ew-user-cleaner' ) . '">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
				$tab === $slug ? ' nav-tab-active' : '',
				esc_url( add_query_arg( array( 'tab' => $slug ), $base_url ) ),
				esc_html( $label )
			);
		}

		echo '</nav>';

		$view = EWUC_PLUGIN_DIR . 'views/' . $tab . '.php';

		if ( is_readable( $view ) ) {
			include $view;
		}

		echo '</div>';
	}

	/**
	 * Shows blocking environment warnings.
	 *
	 * @return void
	 */
	private static function render_environment_notices(): void {
		if ( ! ewuc_destructive_allowed() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Multisite is detected. Scanning, quarantine, purging and restoring are disabled in this version because deleting a network user requires different handling.', 'ew-user-cleaner' ) . '</p></div>';
		}

		if ( ! EWUC_Crypto::is_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Secure encryption is unavailable, so backups cannot be created and purging is blocked. Check that libsodium or OpenSSL AES-256-GCM is enabled and that wp-config.php contains unique security keys.', 'ew-user-cleaner' ) . '</p></div>';
		}

		$settings = EWUC_Settings::get();

		if ( empty( $settings['configured'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Scanning is disabled until you enable at least one rule and set a candidate threshold in Settings.', 'ew-user-cleaner' ) . '</p></div>';
		}

		$notice = get_transient( 'ewuc_notice_' . get_current_user_id() );

		if ( is_array( $notice ) ) {
			delete_transient( 'ewuc_notice_' . get_current_user_id() );

			if ( ! empty( $notice['errors'] ) ) {
				echo '<div class="notice notice-error"><ul>';

				foreach ( (array) $notice['errors'] as $error ) {
					echo '<li>' . esc_html( (string) $error ) . '</li>';
				}

				echo '</ul></div>';
			} elseif ( ! empty( $notice['saved'] ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'ew-user-cleaner' ) . '</p></div>';
			}

			// Advisory notices: the save succeeded, but a setting will not do
			// what the administrator probably expects.
			if ( ! empty( $notice['notices'] ) ) {
				echo '<div class="notice notice-warning"><ul>';

				foreach ( (array) $notice['notices'] as $advisory ) {
					echo '<li>' . esc_html( (string) $advisory ) . '</li>';
				}

				echo '</ul></div>';
			}
		}
	}

	/**
	 * Formats bytes for display.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( int $bytes ): string {
		return size_format( max( 0, $bytes ), 1 ) ?: '0 B';
	}
}
