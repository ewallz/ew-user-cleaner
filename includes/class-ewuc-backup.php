<?php
/**
 * Encrypted pre-purge backups.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Captures and verifies recoverable account state.
 */
class EWUC_Backup {

	/**
	 * Payload schema version.
	 *
	 * @var int
	 */
	const SCHEMA = 1;

	/**
	 * Meta keys that must never be restored.
	 *
	 * @var string[]
	 */
	private const META_DENYLIST = array(
		'session_tokens',
		'wp_user-settings',
		'wp_user-settings-time',
		'_application_passwords',
		'community-events-location',
		'wp_dashboard_quick_press_last_post_id',
	);

	/**
	 * Creates a backup record for one user.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $batch_id Batch identifier.
	 * @return true|WP_Error
	 */
	public static function create( int $user_id, string $batch_id ) {
		global $wpdb;

		if ( ! EWUC_Crypto::is_available() ) {
			return new WP_Error(
				'ewuc_no_encryption',
				__( 'Secure encryption is unavailable, so purging is blocked.', 'ew-user-cleaner' ),
				array( 'status' => 500 )
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'ewuc_user_missing', __( 'User no longer exists.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'schema'       => self::SCHEMA,
			'site'         => get_current_blog_id(),
			'source_id'    => $user_id,
			'account'      => array(
				'user_login'      => $user->user_login,
				'user_pass'       => $user->user_pass,
				'user_nicename'   => $user->user_nicename,
				'user_email'      => $user->user_email,
				'user_url'        => $user->user_url,
				'user_registered' => $user->user_registered,
				'display_name'    => $user->display_name,
				'user_status'     => (int) $user->user_status,
			),
			'roles'        => array_values( (array) $user->roles ),
			'meta'         => self::collect_meta( $user_id ),
			'references'   => EWUC_Protection::references( $user_id ),
			'quarantine'   => EWUC_Quarantine::get( $user_id ) ? true : false,
			'actor_id'     => get_current_user_id(),
			'captured_at'  => ewuc_now(),
		);

		$encrypted = EWUC_Crypto::encrypt( $payload );

		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$data = array(
			'batch_id'         => $batch_id,
			'source_user_id'   => $user_id,
			'user_login'       => $user->user_login,
			'user_email'       => $user->user_email,
			'payload'          => $encrypted['data'],
			'payload_checksum' => $encrypted['checksum'],
			'payload_bytes'    => strlen( $encrypted['data'] ),
			'schema_version'   => self::SCHEMA,
			'cipher'           => $encrypted['cipher'],
			'status'           => 'ready',
			'created_by'       => get_current_user_id(),
			'created_at'       => ewuc_now(),
		);

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (batch_id, source_user_id, user_login, user_email, payload, payload_checksum, payload_bytes, schema_version, cipher, status, created_by, created_at)
				 VALUES (%s, %d, %s, %s, %s, %s, %d, %d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE payload = VALUES(payload), payload_checksum = VALUES(payload_checksum),
				 payload_bytes = VALUES(payload_bytes), cipher = VALUES(cipher), status = VALUES(status), created_at = VALUES(created_at)",
				$data['batch_id'],
				$data['source_user_id'],
				$data['user_login'],
				$data['user_email'],
				$data['payload'],
				$data['payload_checksum'],
				$data['payload_bytes'],
				$data['schema_version'],
				$data['cipher'],
				$data['status'],
				$data['created_by'],
				$data['created_at']
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'ewuc_backup_failed', __( 'The backup could not be stored.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		// Verify the stored record round-trips before the caller deletes anything.
		$verified = self::verify( $batch_id, $user_id );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		EWUC_Audit::log(
			'backup_created',
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'context'     => array(
					'batch_id' => $batch_id,
					'bytes'    => $data['payload_bytes'],
				),
			)
		);

		return true;
	}

	/**
	 * Reads and validates a stored backup.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param int    $user_id  Source user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function verify( string $batch_id, int $user_id ) {
		global $wpdb;

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %s AND source_user_id = %d",
				$batch_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'ewuc_backup_missing', __( 'No backup exists for this user.', 'ew-user-cleaner' ), array( 'status' => 409 ) );
		}

		return EWUC_Crypto::decrypt(
			(string) $row['payload'],
			(string) $row['cipher'],
			(string) $row['payload_checksum']
		);
	}

	/**
	 * Returns the newest backup row for a user.
	 *
	 * @param int $user_id Source user ID.
	 * @return array<string, mixed>|null
	 */
	public static function latest_for_user( int $user_id ): ?array {
		global $wpdb;

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_user_id = %d ORDER BY id DESC LIMIT 1", $user_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lists backup batches with aggregate sizes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function batches(): array {
		global $wpdb;

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT batch_id, COUNT(*) AS users, SUM(payload_bytes) AS bytes, MAX(created_at) AS created_at
			 FROM {$table} GROUP BY batch_id ORDER BY created_at DESC LIMIT 100",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total stored backup size in bytes.
	 *
	 * @return int
	 */
	public static function total_bytes(): int {
		global $wpdb;

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(payload_bytes), 0) FROM {$table}" );
	}

	/**
	 * Deletes a batch. Backups are retained until this is called explicitly.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return int Rows removed.
	 */
	public static function delete_batch( string $batch_id ): int {
		global $wpdb;

		$deleted = (int) $wpdb->delete( ewuc_table( 'backups' ), array( 'batch_id' => $batch_id ), array( '%s' ) );

		EWUC_Audit::log(
			'backup_batch_deleted',
			array(
				'context' => array(
					'batch_id' => $batch_id,
					'rows'     => $deleted,
				),
			)
		);

		return $deleted;
	}

	/**
	 * Records the reconstructed user ID.
	 *
	 * @param int $backup_id   Backup row ID.
	 * @param int $new_user_id New user ID.
	 * @return void
	 */
	public static function mark_restored( int $backup_id, int $new_user_id ): void {
		global $wpdb;

		$wpdb->update(
			ewuc_table( 'backups' ),
			array(
				'restored_user_id' => $new_user_id,
				'status'           => 'restored',
			),
			array( 'id' => $backup_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Collects restorable user meta.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, array<int, mixed>>
	 */
	private static function collect_meta( int $user_id ): array {
		$all   = get_user_meta( $user_id );
		$clean = array();

		foreach ( (array) $all as $key => $values ) {
			if ( in_array( $key, self::META_DENYLIST, true ) ) {
				continue;
			}

			$clean[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $clean;
	}

	/**
	 * Meta keys excluded from backup and restore.
	 *
	 * @return string[]
	 */
	public static function meta_denylist(): array {
		return self::META_DENYLIST;
	}
}
