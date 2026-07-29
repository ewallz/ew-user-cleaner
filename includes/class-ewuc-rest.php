<?php
/**
 * REST endpoints for manually initiated batch work.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin REST API.
 */
class EWUC_Rest {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$namespace = EWUC_REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_scan' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_scan_users' ),
			)
		);

		register_rest_route(
			$namespace,
			'/scan/(?P<id>\d+)/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_batch' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_scan_users' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/scan/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'set_scan_status' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_scan_users' ),
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'status' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'running', 'paused', 'cancelled' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_candidates' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_review_users' ),
				'args'                => self::candidate_args(),
			)
		);

		register_rest_route(
			$namespace,
			'/candidates/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dismiss' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_review_users' ),
				'args'                => self::selection_args(),
			)
		);

		register_rest_route(
			$namespace,
			'/quarantine',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'quarantine' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_quarantine_users' ),
				'args'                => array_merge(
					self::selection_args(),
					array(
						'override' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);

		register_rest_route(
			$namespace,
			'/quarantine/all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'quarantine_all_step' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_quarantine_users' ),
				'args'                => array_merge(
					self::candidate_args(),
					array(
						'confirm'  => array(
							'required' => true,
							'type'     => 'string',
						),
						'after'    => array(
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'override' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);

		register_rest_route(
			$namespace,
			'/quarantine/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_quarantine' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_restore_users' ),
				'args'                => self::selection_args(),
			)
		);

		register_rest_route(
			$namespace,
			'/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'purge' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_purge_users' ),
				'args'                => array_merge(
					self::selection_args(),
					array(
						'confirm'  => array(
							'required' => true,
							'type'     => 'string',
						),
						'override' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'reassign' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			$namespace,
			'/purge/all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'purge_all_step' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_purge_users' ),
				'args'                => array(
					'confirm' => array(
						'required' => true,
						'type'     => 'string',
					),
					'after'   => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/backups/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_backup' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_restore_users' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/backups/user/(?P<user>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_backup_by_user' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_restore_users' ),
				'args'                => array(
					'user' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/backups/(?P<batch>[A-Za-z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_backup_batch' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_purge_users' ),
				'args'                => array(
					'batch' => array(
						'required'          => true,
						'sanitize_callback' => static fn( $value ): string => preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => static fn(): bool => current_user_can( 'ewuc_manage_settings' ),
			)
		);
	}

	/**
	 * Candidate listing arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function candidate_args(): array {
		return array(
			'job_id'    => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'page'      => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'default'           => 50,
				'sanitize_callback' => 'absint',
			),
			'orderby'   => array(
				'default' => 'score',
				'enum'    => array( 'score', 'user_id', 'registered', 'login', 'domain', 'scanned' ),
			),
			'order'     => array(
				'default' => 'desc',
				'enum'    => array( 'asc', 'desc' ),
			),
			'state'     => array(
				'default'           => 'candidate',
				'sanitize_callback' => 'sanitize_key',
			),
			'domain'    => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'min_score' => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'    => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Shared selection arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function selection_args(): array {
		return array(
			'job_id'   => array(
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'user_ids' => array(
				'required' => true,
				'type'     => 'array',
				'items'    => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Normalizes and bounds a submitted selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $limit   Maximum IDs.
	 * @return int[]
	 */
	private static function user_ids( WP_REST_Request $request, int $limit ): array {
		$ids = (array) $request->get_param( 'user_ids' );
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		return array_slice( array_unique( $ids ), 0, $limit );
	}

	/**
	 * Creates a scan job.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_scan() {
		if ( ! ewuc_destructive_allowed() ) {
			return new WP_Error( 'ewuc_multisite', __( 'Scanning is disabled on multisite in this version.', 'ew-user-cleaner' ), array( 'status' => 400 ) );
		}

		list( $job_id, $error ) = EWUC_Jobs::create_scan( EWUC_Settings::get() );

		if ( $error instanceof WP_Error ) {
			return $error;
		}

		return rest_ensure_response(
			array(
				'job_id' => $job_id,
				'status' => 'running',
			)
		);
	}

	/**
	 * Processes one scan batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run_batch( WP_REST_Request $request ) {
		$result = EWUC_Scanner::run_batch( absint( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Pauses, resumes or cancels a scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_scan_status( WP_REST_Request $request ) {
		$job_id = absint( $request['id'] );
		$job    = EWUC_Jobs::get( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'ewuc_job_missing', __( 'Scan job not found.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		if ( 'complete' === $job['status'] ) {
			return new WP_Error( 'ewuc_job_complete', __( 'This scan already finished.', 'ew-user-cleaner' ), array( 'status' => 409 ) );
		}

		EWUC_Jobs::set_status( $job_id, (string) $request->get_param( 'status' ) );

		return rest_ensure_response( array( 'status' => (string) $request->get_param( 'status' ) ) );
	}

	/**
	 * Lists candidates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_candidates( WP_REST_Request $request ) {
		$result = EWUC_Candidates::query(
			array(
				'job_id'    => absint( $request->get_param( 'job_id' ) ),
				'page'      => absint( $request->get_param( 'page' ) ),
				'per_page'  => absint( $request->get_param( 'per_page' ) ),
				'orderby'   => (string) $request->get_param( 'orderby' ),
				'order'     => (string) $request->get_param( 'order' ),
				'state'     => (string) $request->get_param( 'state' ),
				'domain'    => (string) $request->get_param( 'domain' ),
				'min_score' => (string) $request->get_param( 'min_score' ),
				'search'    => (string) $request->get_param( 'search' ),
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Dismisses candidates as legitimate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function dismiss( WP_REST_Request $request ) {
		$job_id = absint( $request->get_param( 'job_id' ) );
		$ids    = self::user_ids( $request, 200 );

		foreach ( $ids as $user_id ) {
			EWUC_Candidates::set_state( $job_id, $user_id, 'dismissed' );
		}

		EWUC_Audit::log(
			'candidates_dismissed',
			array(
				'job_id'  => $job_id,
				'context' => array( 'count' => count( $ids ) ),
			)
		);

		return rest_ensure_response( array( 'dismissed' => count( $ids ) ) );
	}

	/**
	 * Quarantines a bounded selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quarantine( WP_REST_Request $request ) {
		$settings = EWUC_Settings::get();
		$job_id   = absint( $request->get_param( 'job_id' ) );
		$ids      = self::user_ids( $request, (int) $settings['batch_quarantine'] );

		$override = (bool) $request->get_param( 'override' );

		// Overriding owned-data protection requires a validated destination user
		// so content is reassigned instead of deleted at purge time.
		if ( $override && absint( $settings['reassign_user_id'] ) < 1 ) {
			return new WP_Error(
				'ewuc_reassign_required',
				__( 'Set a content reassignment user in Settings before overriding protection.', 'ew-user-cleaner' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( self::quarantine_ids( $ids, $job_id, $settings, $override ) );
	}

	/**
	 * Quarantines a bounded list of IDs, collecting per-row outcomes.
	 *
	 * @param int[] $ids      User IDs.
	 * @param int   $job_id   Job ID.
	 * @param array $settings Settings array.
	 * @param bool  $override Whether soft protections were overridden.
	 * @return array{quarantined: int[], skipped: array<int, array<string, mixed>>}
	 */
	private static function quarantine_ids( array $ids, int $job_id, array $settings, bool $override ): array {
		$done    = array();
		$skipped = array();

		foreach ( $ids as $user_id ) {
			$result = EWUC_Quarantine::add( $user_id, $job_id, $settings, $override );

			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'user_id' => $user_id,
					'reason'  => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$done[] = $user_id;
		}

		return array(
			'quarantined' => $done,
			'skipped'     => $skipped,
		);
	}

	/**
	 * Quarantines one bounded page of every candidate matching the filters.
	 *
	 * The client repeats this call with the returned cursor until done is true,
	 * exactly like the quarantine purge-all loop. Nothing runs in the
	 * background and each request stays inside the configured batch size.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quarantine_all_step( WP_REST_Request $request ) {
		if ( strtoupper( trim( (string) $request->get_param( 'confirm' ) ) ) !== 'QUARANTINE ALL' ) {
			return new WP_Error(
				'ewuc_confirm_required',
				__( 'Type QUARANTINE ALL to confirm quarantining every matching account.', 'ew-user-cleaner' ),
				array( 'status' => 400 )
			);
		}

		$settings = EWUC_Settings::get();
		$job_id   = absint( $request->get_param( 'job_id' ) );
		$override = (bool) $request->get_param( 'override' );

		if ( $override && absint( $settings['reassign_user_id'] ) < 1 ) {
			return new WP_Error(
				'ewuc_reassign_required',
				__( 'Set a content reassignment user in Settings before overriding protection.', 'ew-user-cleaner' ),
				array( 'status' => 400 )
			);
		}

		// Only ever act on rows still awaiting review, whatever the browsing
		// filter said. Quarantining a dismissed or already purged row would
		// silently undo an explicit decision.
		$filters = array(
			'job_id'    => $job_id,
			'state'     => 'candidate',
			'domain'    => (string) $request->get_param( 'domain' ),
			'min_score' => (string) $request->get_param( 'min_score' ),
			'search'    => (string) $request->get_param( 'search' ),
		);

		$after = absint( $request->get_param( 'after' ) );
		$ids   = EWUC_Candidates::next_batch( $filters, $after, (int) $settings['batch_quarantine'] );

		if ( ! $ids ) {
			return rest_ensure_response(
				array(
					'done'        => true,
					'cursor'      => $after,
					'quarantined' => array(),
					'skipped'     => array(),
					'remaining'   => 0,
				)
			);
		}

		// Advance past everything just examined, including protected rows that
		// stay in the candidate state, so the loop cannot stall.
		$cursor = max( $ids );
		$result = self::quarantine_ids( $ids, $job_id, $settings, $override );

		$result['done']      = false;
		$result['cursor']    = $cursor;
		$result['remaining'] = EWUC_Candidates::count_matching(
			array_merge( $filters, array( 'after' => $cursor ) )
		);

		EWUC_Audit::log(
			'candidates_quarantined_bulk',
			array(
				'job_id'  => $job_id,
				'context' => array(
					'quarantined' => count( $result['quarantined'] ),
					'skipped'     => count( $result['skipped'] ),
				),
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Restores quarantined users.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function restore_quarantine( WP_REST_Request $request ) {
		$ids      = self::user_ids( $request, 50 );
		$restored = array();
		$notices  = array();

		foreach ( $ids as $user_id ) {
			$result = EWUC_Quarantine::restore( $user_id );

			if ( is_wp_error( $result ) ) {
				$notices[] = array(
					'user_id' => $user_id,
					'reason'  => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);

				if ( 'ewuc_role_conflict' === $result->get_error_code() ) {
					$restored[] = $user_id;
				}
				continue;
			}

			$restored[] = $user_id;
		}

		return rest_ensure_response(
			array(
				'restored' => $restored,
				'notices'  => $notices,
			)
		);
	}

	/**
	 * Permanently purges quarantined users.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge( WP_REST_Request $request ) {
		$expected = 'DELETE';

		if ( strtoupper( trim( (string) $request->get_param( 'confirm' ) ) ) !== $expected ) {
			return new WP_Error(
				'ewuc_confirm_required',
				__( 'Type DELETE to confirm permanent removal.', 'ew-user-cleaner' ),
				array( 'status' => 400 )
			);
		}

		$settings = EWUC_Settings::get();
		$ids      = self::user_ids( $request, (int) $settings['batch_purge'] );

		$result = EWUC_Purge::run(
			$ids,
			$settings,
			(bool) $request->get_param( 'override' ),
			absint( $request->get_param( 'reassign' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Purges one bounded page of every quarantined account.
	 *
	 * The client repeats this call with the returned cursor until done is true.
	 * Nothing runs in the background, and each request stays inside the
	 * configured purge batch size.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge_all_step( WP_REST_Request $request ) {
		if ( strtoupper( trim( (string) $request->get_param( 'confirm' ) ) ) !== 'DELETE ALL' ) {
			return new WP_Error(
				'ewuc_confirm_required',
				__( 'Type DELETE ALL to confirm purging every quarantined account.', 'ew-user-cleaner' ),
				array( 'status' => 400 )
			);
		}

		$settings = EWUC_Settings::get();
		$after    = absint( $request->get_param( 'after' ) );
		$ids      = EWUC_Quarantine::next_batch( $after, (int) $settings['batch_purge'] );

		if ( ! $ids ) {
			return rest_ensure_response(
				array(
					'done'      => true,
					'cursor'    => $after,
					'purged'    => array(),
					'skipped'   => array(),
					'failed'    => array(),
					'remaining' => EWUC_Quarantine::count_active(),
				)
			);
		}

		// Advance past everything we just looked at, including skipped rows,
		// so the loop cannot stall on an unpurgeable account.
		$cursor = max( $ids );

		$result = EWUC_Purge::run( $ids, $settings );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['done']      = false;
		$result['cursor']    = $cursor;
		$result['remaining'] = EWUC_Quarantine::count_active();

		return rest_ensure_response( $result );
	}

	/**
	 * Restores a purged account from backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_backup( WP_REST_Request $request ) {
		$result = EWUC_Restore::run( absint( $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restores the newest backup for an original user ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_backup_by_user( WP_REST_Request $request ) {
		$row = EWUC_Backup::latest_for_user( absint( $request['user'] ) );

		if ( ! $row ) {
			return new WP_Error( 'ewuc_backup_missing', __( 'No backup exists for that user ID.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		$result = EWUC_Restore::run( (int) $row['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Deletes a backup batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_backup_batch( WP_REST_Request $request ) {
		$deleted = EWUC_Backup::delete_batch( (string) $request['batch'] );

		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/**
	 * Estimates rule impact.
	 *
	 * @return WP_REST_Response
	 */
	public static function preview() {
		return rest_ensure_response( EWUC_Scanner::preview( EWUC_Settings::get() ) );
	}
}
