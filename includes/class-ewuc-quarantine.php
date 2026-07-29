<?php
/**
 * Reversible quarantine.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Blocks authentication without deleting the user record.
 */
class EWUC_Quarantine {

	/**
	 * Cache key for the quarantine map.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'ewuc_quarantine_map';

	/**
	 * Registers authentication hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'authenticate', array( __CLASS__, 'block_authentication' ), 99, 1 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_user_authentication' ), 10, 1 );
	}

	/**
	 * Blocks login for quarantined accounts.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_authentication( $user ) {
		if ( $user instanceof WP_User && self::is_quarantined( (int) $user->ID ) ) {
			return self::denied();
		}

		return $user;
	}

	/**
	 * Secondary guard for password based logins.
	 *
	 * @param WP_User|WP_Error $user Authentication result.
	 * @return WP_User|WP_Error
	 */
	public static function block_user_authentication( $user ) {
		if ( $user instanceof WP_User && self::is_quarantined( (int) $user->ID ) ) {
			return self::denied();
		}

		return $user;
	}

	/**
	 * Generic failure that does not disclose the classification.
	 *
	 * @return WP_Error
	 */
	private static function denied(): WP_Error {
		return new WP_Error(
			'ewuc_login_blocked',
			__( '<strong>Error:</strong> The username or password is incorrect.', 'ew-user-cleaner' )
		);
	}

	/**
	 * Indexed quarantine lookup with cache invalidation.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_quarantined( int $user_id ): bool {
		global $wpdb;

		$map = wp_cache_get( self::CACHE_KEY, 'ewuc' );

		if ( ! is_array( $map ) ) {
			$table = ewuc_table( 'quarantine' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( "SELECT user_id FROM {$table} WHERE status = 'active'" );
			$map = array_map( 'intval', (array) $ids );

			wp_cache_set( self::CACHE_KEY, $map, 'ewuc', 300 );
		}

		return in_array( $user_id, $map, true );
	}

	/**
	 * Clears the quarantine cache.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		wp_cache_delete( self::CACHE_KEY, 'ewuc' );
	}

	/**
	 * Quarantines a single user after re-validating protections.
	 *
	 * @param int   $user_id  User ID.
	 * @param int   $job_id   Source job ID.
	 * @param array $settings Settings array.
	 * @return true|WP_Error
	 */
	public static function add( int $user_id, int $job_id, array $settings, bool $override = false ) {
		global $wpdb;

		if ( ! ewuc_destructive_allowed() ) {
			return new WP_Error( 'ewuc_multisite', __( 'Quarantine is disabled on multisite in this version.', 'ew-user-cleaner' ), array( 'status' => 400 ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'ewuc_user_missing', __( 'User no longer exists.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		if ( self::is_quarantined( $user_id ) ) {
			return true;
		}

		$protection = EWUC_Protection::evaluate( $user_id, $settings );

		if ( '' !== $protection['code'] ) {
			EWUC_Candidates::set_protection( $job_id, $user_id, $protection['code'] );

			// Hard protections can never be overridden, even explicitly.
			$hard = in_array(
				$protection['code'],
				array( 'current_user', 'user_one', 'protected_role', 'protected_cap', 'reassign_target', 'missing' ),
				true
			);

			if ( $hard || ! $override ) {
				return new WP_Error(
					'ewuc_protected',
					$protection['label'],
					array(
						'status' => 409,
						'code'   => $protection['code'],
					)
				);
			}
		}

		$pre_state = array(
			'roles'               => array_values( (array) $user->roles ),
			'capabilities'        => (array) $user->caps,
			'user_status'         => (int) $user->user_status,
			'captured_at'         => ewuc_now(),
			'schema'              => 1,
		);

		$encrypted = EWUC_Crypto::encrypt( $pre_state );

		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$inserted = $wpdb->insert(
			ewuc_table( 'quarantine' ),
			array(
				'user_id'           => $user_id,
				'job_id'            => $job_id,
				'pre_state'         => (string) wp_json_encode( $encrypted ),
				'state_fingerprint' => hash( 'sha256', (string) wp_json_encode( $pre_state['roles'] ) ),
				'quarantined_by'    => get_current_user_id(),
				'quarantined_at'    => ewuc_now(),
				'status'            => 'active',
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'ewuc_quarantine_failed', __( 'Could not record the quarantine entry.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		self::flush_cache();

		// Force the user out of every active session.
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		EWUC_Candidates::set_state( $job_id, $user_id, 'quarantined' );

		EWUC_Audit::log(
			'user_quarantined',
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'job_id'      => $job_id,
			)
		);

		return true;
	}

	/**
	 * Restores a quarantined user with the original ID.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function restore( int $user_id ) {
		global $wpdb;

		$row = self::get( $user_id );

		if ( ! $row ) {
			return new WP_Error( 'ewuc_not_quarantined', __( 'This user is not quarantined.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'ewuc_user_missing', __( 'User no longer exists.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		$stored = json_decode( (string) $row['pre_state'], true );
		$notice = '';

		if ( is_array( $stored ) && isset( $stored['data'], $stored['cipher'], $stored['checksum'] ) ) {
			$pre_state = EWUC_Crypto::decrypt( (string) $stored['data'], (string) $stored['cipher'], (string) $stored['checksum'] );

			if ( is_wp_error( $pre_state ) ) {
				return $pre_state;
			}

			$expected = hash( 'sha256', (string) wp_json_encode( array_values( (array) $user->roles ) ) );

			if ( ! hash_equals( (string) $row['state_fingerprint'], $expected )
				&& array_values( (array) $user->roles ) !== (array) $pre_state['roles'] ) {
				// Roles changed while quarantined; do not silently overwrite.
				$notice = 'role_conflict';
			}
		}

		$wpdb->update(
			ewuc_table( 'quarantine' ),
			array(
				'status'      => 'restored',
				'restored_by' => get_current_user_id(),
				'restored_at' => ewuc_now(),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		self::flush_cache();
		EWUC_Candidates::set_state_all_jobs( $user_id, 'restored' );

		EWUC_Audit::log(
			'user_restored_from_quarantine',
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'outcome'     => '' === $notice ? 'ok' : 'partial',
				'error_code'  => $notice,
			)
		);

		if ( 'role_conflict' === $notice ) {
			return new WP_Error(
				'ewuc_role_conflict',
				__( 'Login was restored, but this account\'s roles changed while quarantined. Review the roles manually.', 'ew-user-cleaner' ),
				array( 'status' => 200 )
			);
		}

		return true;
	}

	/**
	 * Reads a quarantine row.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $user_id ): ?array {
		global $wpdb;

		$table = ewuc_table( 'quarantine' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status = 'active'", $user_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lists quarantined users.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Page size.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table    = ewuc_table( 'quarantine' );
		$per_page = ewuc_clamp_int( $per_page, 20, 100, 50 );
		$page     = ewuc_clamp_int( $page, 1, 100000, 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.user_id, q.job_id, q.quarantined_at, q.quarantined_by, u.user_login, u.user_email
				 FROM {$table} AS q
				 INNER JOIN {$wpdb->users} AS u ON u.ID = q.user_id
				 WHERE q.status = 'active'
				 ORDER BY q.quarantined_at DESC, q.user_id ASC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Returns the next bounded page of quarantined user IDs.
	 *
	 * Uses a keyset cursor on user_id so a "purge all" loop always advances.
	 * Rows that were skipped stay active, so an unbounded repeat of the same
	 * query would never terminate.
	 *
	 * @param int $after_id Exclusive lower bound.
	 * @param int $limit    Maximum rows.
	 * @return int[]
	 */
	public static function next_batch( int $after_id, int $limit ): array {
		global $wpdb;

		$table = ewuc_table( 'quarantine' );
		$limit = ewuc_clamp_int( $limit, 1, 100, 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table}
				 WHERE status = 'active' AND user_id > %d
				 ORDER BY user_id ASC
				 LIMIT %d",
				max( 0, $after_id ),
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Marks a quarantine row as purged.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function mark_purged( int $user_id ): void {
		global $wpdb;

		$wpdb->update(
			ewuc_table( 'quarantine' ),
			array( 'status' => 'purged' ),
			array( 'user_id' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::flush_cache();
	}

	/**
	 * Counts active quarantined users.
	 *
	 * @return int
	 */
	public static function count_active(): int {
		global $wpdb;

		$table = ewuc_table( 'quarantine' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
	}
}
