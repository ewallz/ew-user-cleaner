<?php
/**
 * Uninstall handler.
 *
 * Data is retained by default. Removal only happens when the administrator
 * enabled it, and it is refused while accounts remain quarantined because
 * deactivating the plugin already restores their ability to sign in.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class-ewuc-installer.php';

// Release metadata is disposable cache, not retained plugin data.
delete_site_transient( 'ewuc_github_release' );

$ewuc_settings = get_option( 'ewuc_settings', array() );

if ( ! is_array( $ewuc_settings ) || empty( $ewuc_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$ewuc_quarantine_table = $wpdb->prefix . 'ewuc_quarantine';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$ewuc_active = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM ' . $ewuc_quarantine_table . ' WHERE status = %s',
		'active'
	)
);

if ( $ewuc_active > 0 ) {
	// Refuse destructive cleanup while quarantine state still matters.
	return;
}

foreach ( array( 'audit', 'backups', 'quarantine', 'candidates', 'jobs' ) as $ewuc_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ewuc_' . $ewuc_table );
}

delete_option( 'ewuc_settings' );
delete_option( 'ewuc_schema_version' );

EWUC_Installer::remove_capabilities();
