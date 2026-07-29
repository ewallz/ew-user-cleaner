<?php
/**
 * Permanent purge with mandatory backup and reassignment.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Deletes quarantined users in small verified batches.
 */
class EWUC_Purge {

	/**
	 * Purges a bounded set of quarantined users.
	 *
	 * @param int[]  $user_ids  User IDs.
	 * @param array  $settings  Settings array.
	 * @param bool   $override  Whether protected users may be purged.
	 * @param int    $reassign  Destination user for owned content.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( array $user_ids, array $settings, bool $override = false, int $reassign = 0 ) {
		if ( ! ewuc_destructive_allowed() ) {
			return new WP_Error( 'ewuc_multisite', __( 'Purging is disabled on multisite in this version.', 'ew-user-cleaner' ), array( 'status' => 400 ) );
		}

		if ( ! EWUC_Crypto::is_available() ) {
			return new WP_Error(
				'ewuc_no_encryption',
				__( 'Secure encryption is unavailable, so purging is blocked.', 'ew-user-cleaner' ),
				array( 'status' => 500 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$limit    = (int) $settings['batch_purge'];
		$user_ids = array_slice( array_values( array_unique( array_map( 'absint', $user_ids ) ) ), 0, $limit );

		if ( ! $user_ids ) {
			return new WP_Error( 'ewuc_no_users', __( 'Select at least one quarantined user.', 'ew-user-cleaner' ), array( 'status' => 400 ) );
		}

		if ( $override ) {
			$reassign = absint( $reassign );

			if ( $reassign < 1 || ! get_userdata( $reassign ) ) {
				return new WP_Error(
					'ewuc_reassign_required',
					__( 'Select an existing destination user before overriding protection.', 'ew-user-cleaner' ),
					array( 'status' => 400 )
				);
			}

			if ( in_array( $reassign, $user_ids, true ) ) {
				return new WP_Error( 'ewuc_reassign_conflict', __( 'The destination user cannot also be purged.', 'ew-user-cleaner' ), array( 'status' => 400 ) );
			}
		} else {
			$reassign = absint( $settings['reassign_user_id'] );
		}

		$batch_id = 'purge-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$results  = array(
			'batch_id' => $batch_id,
			'purged'   => array(),
			'skipped'  => array(),
			'failed'   => array(),
		);

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user instanceof WP_User ) {
				$results['skipped'][] = array(
					'user_id' => $user_id,
					'reason'  => 'missing',
				);
				continue;
			}

			if ( ! EWUC_Quarantine::get( $user_id ) ) {
				$results['skipped'][] = array(
					'user_id' => $user_id,
					'reason'  => 'not_quarantined',
				);
				continue;
			}

			$protection = EWUC_Protection::evaluate( $user_id, $settings );

			if ( '' !== $protection['code'] ) {
				$hard = in_array( $protection['code'], array( 'current_user', 'user_one', 'protected_role', 'protected_cap', 'reassign_target' ), true );

				// Owned data is purgeable only when a destination exists, because the
				// override was already confirmed when the account was quarantined.
				$reassignable = 'owns_data' === $protection['code'] && $reassign > 0;

				if ( $hard || ( ! $override && ! $reassignable ) ) {
					$results['skipped'][] = array(
						'user_id' => $user_id,
						'reason'  => $protection['code'],
					);
					continue;
				}
			}

			$backup = EWUC_Backup::create( $user_id, $batch_id );

			if ( is_wp_error( $backup ) ) {
				$results['failed'][] = array(
					'user_id' => $user_id,
					'reason'  => $backup->get_error_code(),
				);

				EWUC_Audit::log(
					'purge_blocked',
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
						'outcome'     => 'error',
						'error_code'  => $backup->get_error_code(),
					)
				);
				continue;
			}

			// Always pass a destination so authored content is reassigned, never deleted.
			$deleted = $reassign > 0
				? wp_delete_user( $user_id, $reassign )
				: wp_delete_user( $user_id );

			$still_exists = (bool) get_userdata( $user_id );

			if ( ! $deleted || $still_exists ) {
				$results['failed'][] = array(
					'user_id' => $user_id,
					'reason'  => 'delete_failed',
				);

				EWUC_Audit::log(
					'purge_failed',
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
						'outcome'     => 'error',
						'error_code'  => 'delete_failed',
					)
				);
				continue;
			}

			EWUC_Quarantine::mark_purged( $user_id );
			EWUC_Candidates::set_state_all_jobs( $user_id, 'purged' );

			$results['purged'][] = $user_id;

			EWUC_Audit::log(
				'user_purged',
				array(
					'object_type' => 'user',
					'object_id'   => $user_id,
					'context'     => array(
						'batch_id' => $batch_id,
						'reassign' => $reassign,
						'override' => $override,
					),
				)
			);
		}

		EWUC_Quarantine::flush_cache();

		return $results;
	}
}
