<?php
/**
 * Keyset paginated scan engine.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Processes one bounded batch of users per request.
 */
class EWUC_Scanner {

	/**
	 * Wall clock budget for a single batch in seconds.
	 *
	 * @var float
	 */
	const TIME_BUDGET = 12.0;

	/**
	 * Runs one batch for a scan job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_batch( int $job_id ) {
		global $wpdb;

		$job = EWUC_Jobs::get( $job_id );

		if ( ! $job || 'scan' !== $job['type'] ) {
			return new WP_Error( 'ewuc_job_missing', __( 'Scan job not found.', 'ew-user-cleaner' ), array( 'status' => 404 ) );
		}

		if ( 'running' !== $job['status'] ) {
			return array(
				'status'    => $job['status'],
				'processed' => (int) $job['processed'],
				'matched'   => (int) $job['matched'],
				'cursor'    => (int) $job['cursor_user_id'],
				'done'      => in_array( $job['status'], array( 'complete', 'cancelled' ), true ),
			);
		}

		$token = EWUC_Jobs::acquire_lock( $job_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		try {
			$snapshot = json_decode( (string) $job['settings_snapshot'], true );

			if ( ! is_array( $snapshot ) ) {
				EWUC_Jobs::set_status( $job_id, 'cancelled' );

				return new WP_Error( 'ewuc_snapshot_invalid', __( 'The stored rule snapshot is unreadable. Start a new scan.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
			}

			$batch  = ewuc_clamp_int( $snapshot['batch_scan'] ?? 250, 25, 1000, 250 );
			$cursor = (int) $job['cursor_user_id'];
			$upper  = (int) $job['upper_user_id'];
			$scorer = new EWUC_Scorer( $snapshot );
			$start  = microtime( true );

			$processed = 0;
			$matched   = 0;
			$last_id   = $cursor;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_login, user_email, user_registered
					 FROM {$wpdb->users}
					 WHERE ID > %d AND ID <= %d
					 ORDER BY ID ASC
					 LIMIT %d",
					$last_id,
					$upper,
					$batch
				)
			);

			$inserts = array();

			foreach ( (array) $rows as $row ) {
				++$processed;
				$last_id = (int) $row->ID;

				$result = $scorer->score( $row );

				if ( $result['allowlisted'] || ! $scorer->is_candidate( $result['score'] ) ) {
					continue;
				}

				++$matched;
				$inserts[] = array(
					'row'    => $row,
					'result' => $result,
				);

				// Stop early if the request is approaching its work budget.
				if ( microtime( true ) - $start > self::TIME_BUDGET ) {
					break;
				}
			}

			if ( $inserts ) {
				self::store_candidates( $job_id, (string) $job['rule_version'], $inserts );
			}

			if ( $processed > 0 ) {
				// Persist progress and cursor only after the batch result is durable.
				EWUC_Jobs::update(
					$job_id,
					array(
						'cursor_user_id' => $last_id,
						'processed'      => (int) $job['processed'] + $processed,
						'matched'        => (int) $job['matched'] + $matched,
					)
				);
			}

			$done = $last_id >= $upper || 0 === $processed;

			if ( $done ) {
				EWUC_Jobs::set_status( $job_id, 'complete' );
			}

			return array(
				'status'          => $done ? 'complete' : 'running',
				'processed'       => (int) $job['processed'] + $processed,
				'matched'         => (int) $job['matched'] + $matched,
				'cursor'          => $last_id,
				'upper'           => $upper,
				'batch_processed' => $processed,
				'done'            => $done,
			);
		} finally {
			EWUC_Jobs::release_lock( $job_id, (string) $token );
		}
	}

	/**
	 * Upserts candidate rows for the batch.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $rule_version Rule version.
	 * @param array  $inserts      Candidate payloads.
	 * @return void
	 */
	private static function store_candidates( int $job_id, string $rule_version, array $inserts ): void {
		global $wpdb;

		$table = ewuc_table( 'candidates' );
		$now   = ewuc_now();

		foreach ( $inserts as $item ) {
			$row    = $item['row'];
			$result = $item['result'];

			$data = array(
				'job_id'           => $job_id,
				'user_id'          => (int) $row->ID,
				'user_login'       => (string) $row->user_login,
				'user_email'       => (string) $row->user_email,
				'email_domain'     => ewuc_email_domain( (string) $row->user_email ),
				'registered_at'    => (string) $row->user_registered,
				'score'            => (int) $result['score'],
				'reasons'          => (string) wp_json_encode( array_values( $result['reasons'] ) ),
				'rule_version'     => $rule_version,
				'state'            => 'candidate',
				'user_fingerprint' => EWUC_Scorer::fingerprint( $row ),
				'scanned_at'       => $now,
			);

			// Retry safe: the unique (job_id, user_id) key makes repeats idempotent.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
					 (job_id, user_id, user_login, user_email, email_domain, registered_at, score, reasons, rule_version, state, user_fingerprint, scanned_at)
					 VALUES (%d, %d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s)
					 ON DUPLICATE KEY UPDATE
					 score = VALUES(score), reasons = VALUES(reasons), user_login = VALUES(user_login),
					 user_email = VALUES(user_email), email_domain = VALUES(email_domain),
					 user_fingerprint = VALUES(user_fingerprint), scanned_at = VALUES(scanned_at)",
					$data['job_id'],
					$data['user_id'],
					$data['user_login'],
					$data['user_email'],
					$data['email_domain'],
					$data['registered_at'],
					$data['score'],
					$data['reasons'],
					$data['rule_version'],
					$data['state'],
					$data['user_fingerprint'],
					$data['scanned_at']
				)
			);
		}
	}

	/**
	 * Estimates rule impact against a bounded sample.
	 *
	 * @param array $settings Settings array.
	 * @param int   $sample   Sample size.
	 * @return array{sample: int, matched: int}
	 */
	public static function preview( array $settings, int $sample = 200 ): array {
		global $wpdb;

		$sample = ewuc_clamp_int( $sample, 20, 500, 200 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_login, user_email, user_registered FROM {$wpdb->users} ORDER BY ID DESC LIMIT %d",
				$sample
			)
		);

		$scorer  = new EWUC_Scorer( $settings );
		$matched = 0;

		foreach ( (array) $rows as $row ) {
			$result = $scorer->score( $row );

			if ( ! $result['allowlisted'] && $scorer->is_candidate( $result['score'] ) ) {
				++$matched;
			}
		}

		return array(
			'sample'  => count( (array) $rows ),
			'matched' => $matched,
		);
	}
}
