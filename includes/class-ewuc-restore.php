<?php
/**
 * Post-purge account reconstruction.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a purged account from its encrypted backup.
 *
 * The original user ID cannot be guaranteed. Quarantine restore is the only
 * same-ID rollback path.
 */
class EWUC_Restore {

	/**
	 * Restores one purged user from a backup row.
	 *
	 * @param int $backup_id Backup row ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( int $backup_id ) {
		global $wpdb;

		$table = ewuc_table( 'backups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $backup_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'ewuc_backup_missing', __( 'Backup not found.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		$payload = EWUC_Crypto::decrypt(
			(string) $row['payload'],
			(string) $row['cipher'],
			(string) $row['payload_checksum']
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$account = isset( $payload['account'] ) && is_array( $payload['account'] ) ? $payload['account'] : array();
		$login   = (string) ( $account['user_login'] ?? '' );
		$email   = (string) ( $account['user_email'] ?? '' );

		if ( '' === $login || '' === $email ) {
			return new WP_Error( 'ewuc_backup_incomplete', __( 'The backup does not contain the required account fields.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		if ( get_user_by( 'login', $login ) ) {
			return new WP_Error(
				'ewuc_login_conflict',
				sprintf(
					/* translators: %s: username. */
					__( 'The username "%s" is already in use. Resolve the conflict manually before restoring.', 'ew-user-cleaner' ),
					$login
				),
				array( 'status' => 409 )
			);
		}

		if ( get_user_by( 'email', $email ) ) {
			return new WP_Error(
				'ewuc_email_conflict',
				__( 'That email address now belongs to another account. Resolve the conflict manually before restoring.', 'ew-user-cleaner' ),
				array( 'status' => 409 )
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login'      => $login,
				'user_email'      => $email,
				'user_pass'       => wp_generate_password( 24, true, true ),
				'user_nicename'   => (string) ( $account['user_nicename'] ?? '' ),
				'user_url'        => (string) ( $account['user_url'] ?? '' ),
				'display_name'    => (string) ( $account['display_name'] ?? $login ),
				'user_registered' => (string) ( $account['user_registered'] ?? ewuc_now() ),
				'role'            => '',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user_id = (int) $user_id;

		// Restore the original password hash directly so the user keeps their credentials.
		if ( ! empty( $account['user_pass'] ) ) {
			$wpdb->update(
				$wpdb->users,
				array( 'user_pass' => (string) $account['user_pass'] ),
				array( 'ID' => $user_id ),
				array( '%s' ),
				array( '%d' )
			);
			clean_user_cache( $user_id );
		}

		self::restore_meta( $user_id, isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array() );

		$user = new WP_User( $user_id );
		$user->set_role( '' );

		foreach ( (array) ( $payload['roles'] ?? array() ) as $role ) {
			$role = sanitize_key( (string) $role );

			if ( '' !== $role && wp_roles()->is_role( $role ) ) {
				$user->add_role( $role );
			}
		}

		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		$unresolved = array();

		foreach ( (array) ( $payload['references'] ?? array() ) as $reference ) {
			$type = (string) ( $reference['type'] ?? '' );

			if ( ! self::relink( $type, (int) $row['source_user_id'], $user_id ) ) {
				$unresolved[] = $type;
			}
		}

		EWUC_Backup::mark_restored( (int) $row['id'], $user_id );
		EWUC_Candidates::set_state_all_jobs( (int) $row['source_user_id'], 'restored' );

		$partial = ! empty( $unresolved );

		EWUC_Audit::log(
			'user_restored_from_backup',
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'outcome'     => $partial ? 'partial' : 'ok',
				'context'     => array(
					'source_user_id' => (int) $row['source_user_id'],
					'new_user_id'    => $user_id,
					'unresolved'     => $unresolved,
				),
			)
		);

		return array(
			'source_user_id' => (int) $row['source_user_id'],
			'new_user_id'    => $user_id,
			'partial'        => $partial,
			'unresolved'     => $unresolved,
		);
	}

	/**
	 * Restores allowed meta values.
	 *
	 * @param int   $user_id User ID.
	 * @param array $meta    Meta map.
	 * @return void
	 */
	private static function restore_meta( int $user_id, array $meta ): void {
		$denylist = EWUC_Backup::meta_denylist();
		$prefix   = $GLOBALS['wpdb']->prefix;

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;

			if ( in_array( $key, $denylist, true ) ) {
				continue;
			}

			// Capabilities are re-applied through the role API instead.
			if ( $prefix . 'capabilities' === $key || $prefix . 'user_level' === $key ) {
				continue;
			}

			delete_user_meta( $user_id, $key );

			foreach ( (array) $values as $value ) {
				add_user_meta( $user_id, $key, $value );
			}
		}
	}

	/**
	 * Relinks a supported reference type to the new user ID.
	 *
	 * @param string $type    Reference type.
	 * @param int    $old_id  Original user ID.
	 * @param int    $new_id  New user ID.
	 * @return bool Whether the reference was handled.
	 */
	private static function relink( string $type, int $old_id, int $new_id ): bool {
		/**
		 * Allows extensions to relink their own user references.
		 *
		 * @param bool|null $handled Null when unhandled.
		 * @param string    $type    Reference type.
		 * @param int       $old_id  Original user ID.
		 * @param int       $new_id  New user ID.
		 */
		$handled = apply_filters( 'ewuc_relink_reference', null, $type, $old_id, $new_id );

		if ( is_bool( $handled ) ) {
			return $handled;
		}

		// Core deletion already reassigned or removed these rows, so the original
		// ownership cannot be recovered automatically.
		return false;
	}
}
