<?php
/**
 * Streaming CSV export.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Exports candidate rows in bounded pages.
 */
class EWUC_Export {

	/**
	 * Handles the admin export request.
	 *
	 * @return void
	 */
	public static function maybe_export(): void {
		if ( ! isset( $_GET['ewuc_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'ewuc_review_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to export user data.', 'ew-user-cleaner' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ewuc_export' );

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		$state  = isset( $_GET['state'] ) ? sanitize_key( (string) $_GET['state'] ) : 'candidate';

		self::stream( $job_id, $state );
	}

	/**
	 * Streams the CSV response.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $state  Candidate state.
	 * @return void
	 */
	private static function stream( int $job_id, string $state ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ewuc-candidates-' . gmdate( 'Ymd-His' ) . '.csv' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );

		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'ew-user-cleaner' ) );
		}

		fputcsv(
			$handle,
			array( 'user_id', 'user_login', 'user_email', 'email_domain', 'registered_at', 'score', 'reasons', 'state', 'protection' )
		);

		$page = 1;

		do {
			$result = EWUC_Candidates::query(
				array(
					'job_id'   => $job_id,
					'state'    => $state,
					'page'     => $page,
					'per_page' => 100,
					'orderby'  => 'user_id',
					'order'    => 'asc',
				)
			);

			foreach ( $result['items'] as $row ) {
				fputcsv(
					$handle,
					array(
						ewuc_csv_cell( $row['user_id'] ),
						ewuc_csv_cell( $row['user_login'] ),
						ewuc_csv_cell( $row['user_email'] ),
						ewuc_csv_cell( $row['email_domain'] ),
						ewuc_csv_cell( $row['registered_at'] ),
						ewuc_csv_cell( $row['score'] ),
						ewuc_csv_cell( implode( '; ', (array) $row['reasons'] ) ),
						ewuc_csv_cell( $row['state'] ),
						ewuc_csv_cell( $row['protected_code'] ),
					)
				);
			}

			++$page;
			$has_more = count( $result['items'] ) === 100;
		} while ( $has_more && $page <= 10000 );

		fclose( $handle );

		EWUC_Audit::log(
			'candidates_exported',
			array(
				'job_id'  => $job_id,
				'context' => array( 'state' => $state ),
			)
		);

		exit;
	}
}
