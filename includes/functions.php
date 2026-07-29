<?php
/**
 * Shared helper functions.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Returns the prefixed plugin table name.
 *
 * @param string $table Logical table name.
 * @return string
 */
function ewuc_table( string $table ): string {
	global $wpdb;

	$allowed = array( 'jobs', 'candidates', 'quarantine', 'backups', 'audit' );

	if ( ! in_array( $table, $allowed, true ) ) {
		// Never interpolate caller supplied identifiers into SQL.
		wp_die( esc_html__( 'Invalid table requested.', 'ew-user-cleaner' ) );
	}

	return $wpdb->prefix . 'ewuc_' . $table;
}

/**
 * Whether destructive operations are permitted in this environment.
 *
 * Multisite is explicitly unsupported in v1 because wp_delete_user() only
 * detaches the user from the current site instead of deleting the record.
 *
 * @return bool
 */
function ewuc_destructive_allowed(): bool {
	return ! is_multisite();
}

/**
 * Current UTC timestamp in MySQL format.
 *
 * @return string
 */
function ewuc_now(): string {
	return current_time( 'mysql', true );
}

/**
 * Clamp an integer between bounds.
 *
 * @param mixed $value Raw value.
 * @param int   $min   Minimum.
 * @param int   $max   Maximum.
 * @param int   $fallback Fallback when not numeric.
 * @return int
 */
function ewuc_clamp_int( $value, int $min, int $max, int $fallback ): int {
	if ( ! is_numeric( $value ) ) {
		return $fallback;
	}

	$value = (int) $value;

	return max( $min, min( $max, $value ) );
}

/**
 * Normalizes a string for pattern evaluation.
 *
 * @param string $value Raw value.
 * @return string
 */
function ewuc_normalize( string $value ): string {
	$value = trim( $value );

	if ( function_exists( 'mb_strtolower' ) ) {
		return mb_strtolower( $value, 'UTF-8' );
	}

	return strtolower( $value );
}

/**
 * Extracts the domain part of an email address.
 *
 * @param string $email Email address.
 * @return string
 */
function ewuc_email_domain( string $email ): string {
	$position = strrpos( $email, '@' );

	if ( false === $position ) {
		return '';
	}

	return ewuc_normalize( substr( $email, $position + 1 ) );
}

/**
 * Extracts the local part of an email address.
 *
 * @param string $email Email address.
 * @return string
 */
function ewuc_email_local( string $email ): string {
	$position = strrpos( $email, '@' );

	if ( false === $position ) {
		return ewuc_normalize( $email );
	}

	return ewuc_normalize( substr( $email, 0, $position ) );
}

/**
 * Escapes a CSV cell so spreadsheet software cannot execute it as a formula.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function ewuc_csv_cell( $value ): string {
	$value = (string) $value;

	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		$value = "'" . $value;
	}

	return $value;
}
