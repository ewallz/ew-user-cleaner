<?php
/**
 * Activation, schema and capability management.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Installs plugin tables and capabilities.
 */
class EWUC_Installer {

	/**
	 * Schema version option key.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'ewuc_schema_version';

	/**
	 * Current schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Plugin capabilities.
	 *
	 * @return string[]
	 */
	public static function capabilities(): array {
		return array(
			'ewuc_manage_settings',
			'ewuc_scan_users',
			'ewuc_review_users',
			'ewuc_quarantine_users',
			'ewuc_purge_users',
			'ewuc_restore_users',
		);
	}

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			wp_die( esc_html__( 'EW User Cleaner requires PHP 8.0 or newer.', 'ew-user-cleaner' ) );
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
			wp_die( esc_html__( 'EW User Cleaner requires WordPress 6.4 or newer.', 'ew-user-cleaner' ) );
		}

		self::install_tables();
		self::add_capabilities();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Runs on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Quarantine login blocking depends on this plugin remaining active.
		// Data is intentionally retained so nothing becomes unrecoverable.
		delete_transient( 'ewuc_quarantine_map' );
	}

	/**
	 * Ensures the schema is current on load.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( self::SCHEMA_VERSION === $installed ) {
			return;
		}

		self::install_tables();
		self::add_capabilities();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Creates or migrates plugin tables.
	 *
	 * @return void
	 */
	public static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = ewuc_table( 'jobs' );
		$cands   = ewuc_table( 'candidates' );
		$quar    = ewuc_table( 'quarantine' );
		$backups = ewuc_table( 'backups' );
		$audit   = ewuc_table( 'audit' );

		$sql = array();

		$sql[] = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			cursor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			upper_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			rule_version VARCHAR(40) NOT NULL DEFAULT '',
			settings_snapshot LONGTEXT NULL,
			processed BIGINT UNSIGNED NOT NULL DEFAULT 0,
			matched BIGINT UNSIGNED NOT NULL DEFAULT 0,
			failed BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lock_token VARCHAR(64) NOT NULL DEFAULT '',
			lock_expires_at DATETIME NULL,
			error_summary TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_type (status, type),
			KEY created_at (created_at)
		) {$charset}";

		$sql[] = "CREATE TABLE {$cands} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			user_email VARCHAR(100) NOT NULL DEFAULT '',
			email_domain VARCHAR(100) NOT NULL DEFAULT '',
			registered_at DATETIME NULL,
			score INT UNSIGNED NOT NULL DEFAULT 0,
			reasons TEXT NULL,
			rule_version VARCHAR(40) NOT NULL DEFAULT '',
			state VARCHAR(20) NOT NULL DEFAULT 'candidate',
			protected_code VARCHAR(40) NOT NULL DEFAULT '',
			user_fingerprint CHAR(64) NOT NULL DEFAULT '',
			scanned_at DATETIME NOT NULL,
			reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reviewed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY job_user (job_id, user_id),
			KEY job_state_score (job_id, state, score, user_id),
			KEY job_registered (job_id, registered_at, user_id),
			KEY job_domain (job_id, email_domain, user_id),
			KEY job_protected (job_id, protected_code, user_id),
			KEY user_id (user_id)
		) {$charset}";

		$sql[] = "CREATE TABLE {$quar} (
			user_id BIGINT UNSIGNED NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			pre_state LONGTEXT NULL,
			state_fingerprint CHAR(64) NOT NULL DEFAULT '',
			quarantined_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			quarantined_at DATETIME NOT NULL,
			restored_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			restored_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY (user_id),
			KEY status (status, quarantined_at)
		) {$charset}";

		$sql[] = "CREATE TABLE {$backups} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(40) NOT NULL,
			source_user_id BIGINT UNSIGNED NOT NULL,
			restored_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			user_email VARCHAR(100) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			payload_checksum CHAR(64) NOT NULL,
			payload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			schema_version INT UNSIGNED NOT NULL DEFAULT 1,
			cipher VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'ready',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY batch_user (batch_id, source_user_id),
			KEY batch_status (batch_id, status),
			KEY source_user_id (source_user_id),
			KEY created_at (created_at)
		) {$charset}";

		$sql[] = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(60) NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			outcome VARCHAR(20) NOT NULL DEFAULT 'ok',
			error_code VARCHAR(60) NOT NULL DEFAULT '',
			context TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at, id),
			KEY actor_created (actor_id, created_at),
			KEY action_created (action, created_at),
			KEY job_id (job_id, id)
		) {$charset}";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Grants capabilities to administrators.
	 *
	 * @return void
	 */
	public static function add_capabilities(): void {
		$role = get_role( 'administrator' );

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( self::capabilities() as $capability ) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Removes capabilities from all roles.
	 *
	 * @return void
	 */
	public static function remove_capabilities(): void {
		foreach ( wp_roles()->roles as $role_name => $unused ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof WP_Role ) {
				continue;
			}

			foreach ( self::capabilities() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
