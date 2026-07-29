<?php
/**
 * Resumable job records and locking.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Manages manually initiated batch jobs.
 */
class EWUC_Jobs {

	/**
	 * Lock lifetime in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 60;

	/**
	 * Creates a scan job with an immutable settings snapshot.
	 *
	 * @param array $settings Current settings.
	 * @return array{0: int, 1: WP_Error|null}
	 */
	public static function create_scan( array $settings ): array {
		global $wpdb;

		if ( empty( $settings['configured'] ) ) {
			return array( 0, new WP_Error( 'ewuc_not_configured', __( 'Configure and save the scoring rules before scanning.', 'ew-user-cleaner' ), array( 'status' => 400 ) ) );
		}

		$upper = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$snapshot = array(
			'threshold'       => $settings['threshold'],
			'rules'           => $settings['rules'],
			'flagged_domains' => $settings['flagged_domains'],
			'allow_domains'   => $settings['allow_domains'],
			'allow_logins'    => $settings['allow_logins'],
			'allow_emails'    => $settings['allow_emails'],
			'allow_user_ids'  => $settings['allow_user_ids'],
			'block_logins'    => $settings['block_logins'],
			'block_emails'    => $settings['block_emails'],
			'batch_scan'      => $settings['batch_scan'],
		);

		$now = ewuc_now();

		$wpdb->insert(
			ewuc_table( 'jobs' ),
			array(
				'type'              => 'scan',
				'status'            => 'running',
				'cursor_user_id'    => 0,
				'upper_user_id'     => $upper,
				'rule_version'      => EWUC_Settings::rule_version( $settings ),
				'settings_snapshot' => (string) wp_json_encode( $snapshot ),
				'created_by'        => get_current_user_id(),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$job_id = (int) $wpdb->insert_id;

		EWUC_Audit::log(
			'scan_created',
			array(
				'object_type' => 'job',
				'object_id'   => $job_id,
				'job_id'      => $job_id,
				'context'     => array( 'upper_user_id' => $upper ),
			)
		);

		return array( $job_id, null );
	}

	/**
	 * Fetches a job row.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $job_id ): ?array {
		global $wpdb;

		$table = ewuc_table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the most recent scan job.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest_scan(): ?array {
		global $wpdb;

		$table = ewuc_table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE type = 'scan' ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Acquires or renews a short lived job lock.
	 *
	 * @param int $job_id Job ID.
	 * @return string|WP_Error Lock token on success.
	 */
	public static function acquire_lock( int $job_id ) {
		global $wpdb;

		$table = ewuc_table( 'jobs' );
		$token = wp_generate_password( 32, false, false );
		$now   = ewuc_now();
		$until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL );

		// Only claim when no live lock exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, lock_expires_at = %s, updated_at = %s
				 WHERE id = %d AND status = 'running'
				 AND ( lock_expires_at IS NULL OR lock_expires_at < %s )",
				$token,
				$until,
				$now,
				$job_id,
				$now
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'ewuc_job_busy', __( 'This job is already processing a batch. Try again in a moment.', 'ew-user-cleaner' ), array( 'status' => 409 ) );
		}

		return $token;
	}

	/**
	 * Releases a job lock.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $token  Lock token.
	 * @return void
	 */
	public static function release_lock( int $job_id, string $token ): void {
		global $wpdb;

		$table = ewuc_table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = '', lock_expires_at = NULL, updated_at = %s WHERE id = %d AND lock_token = %s",
				ewuc_now(),
				$job_id,
				$token
			)
		);
	}

	/**
	 * Updates job progress fields.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Column data.
	 * @return void
	 */
	public static function update( int $job_id, array $data ): void {
		global $wpdb;

		$allowed = array(
			'status'         => '%s',
			'cursor_user_id' => '%d',
			'processed'      => '%d',
			'matched'        => '%d',
			'failed'         => '%d',
			'error_summary'  => '%s',
		);

		$values  = array();
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				$values[ $key ]  = $value;
				$formats[]       = $allowed[ $key ];
			}
		}

		if ( ! $values ) {
			return;
		}

		$values['updated_at'] = ewuc_now();
		$formats[]            = '%s';

		$wpdb->update( ewuc_table( 'jobs' ), $values, array( 'id' => $job_id ), $formats, array( '%d' ) );
	}

	/**
	 * Marks a job paused or cancelled.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status New status.
	 * @return void
	 */
	public static function set_status( int $job_id, string $status ): void {
		$allowed = array( 'running', 'paused', 'cancelled', 'complete' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}

		self::update( $job_id, array( 'status' => $status ) );

		EWUC_Audit::log(
			'job_status_' . $status,
			array(
				'object_type' => 'job',
				'object_id'   => $job_id,
				'job_id'      => $job_id,
			)
		);
	}
}
