<?php
/**
 * Candidate queries and state transitions.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Reads and updates indexed candidate rows.
 */
class EWUC_Candidates {

	/**
	 * Sort keys mapped to indexed SQL fragments.
	 *
	 * @return array<string, string>
	 */
	private static function orderby_map(): array {
		return array(
			'score'      => 'score',
			'user_id'    => 'user_id',
			'registered' => 'registered_at',
			'login'      => 'user_login',
			'domain'     => 'email_domain',
			'scanned'    => 'scanned_at',
		);
	}

	/**
	 * Allowed candidate states.
	 *
	 * @return string[]
	 */
	public static function states(): array {
		return array( 'candidate', 'dismissed', 'quarantined', 'purged', 'restored', 'manual_attention' );
	}

	/**
	 * Builds the shared WHERE clause for candidate filters.
	 *
	 * Every read path uses this so a bulk action can never operate on a wider
	 * set of rows than the list the reviewer was actually looking at.
	 *
	 * @param array $args Query arguments.
	 * @return array{clause: string, params: array<int, mixed>}
	 */
	private static function build_where( array $args ): array {
		global $wpdb;

		$where  = array( 'job_id = %d' );
		$params = array( absint( $args['job_id'] ?? 0 ) );

		if ( ! empty( $args['state'] ) && in_array( $args['state'], self::states(), true ) ) {
			$where[]  = 'state = %s';
			$params[] = $args['state'];
		}

		if ( ! empty( $args['domain'] ) ) {
			$where[]  = 'email_domain = %s';
			$params[] = ewuc_normalize( (string) $args['domain'] );
		}

		if ( isset( $args['min_score'] ) && '' !== $args['min_score'] ) {
			$where[]  = 'score >= %d';
			$params[] = absint( $args['min_score'] );
		}

		// Keyset cursor for batched bulk actions.
		if ( ! empty( $args['after'] ) ) {
			$where[]  = 'user_id > %d';
			$params[] = absint( $args['after'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search = ewuc_normalize( (string) $args['search'] );

			if ( is_numeric( $search ) ) {
				$where[]  = 'user_id = %d';
				$params[] = absint( $search );
			} else {
				// Prefix search only; leading wildcards cannot use an index.
				$where[]  = '( user_login LIKE %s OR user_email LIKE %s )';
				$like     = $wpdb->esc_like( $search ) . '%';
				$params[] = $like;
				$params[] = $like;
			}
		}

		return array(
			'clause' => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Queries candidates using bounded pagination.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args ): array {
		global $wpdb;

		$table = ewuc_table( 'candidates' );

		$per_page = ewuc_clamp_int( $args['per_page'] ?? 50, 20, 100, 50 );
		$page     = ewuc_clamp_int( $args['page'] ?? 1, 1, 100000, 1 );
		$orderby  = self::orderby_map()[ $args['orderby'] ?? 'score' ] ?? 'score';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

		$filter = self::build_where( $args );
		$clause = $filter['clause'];
		$params = $filter['params'];
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", ...$params )
		);

		$query  = "SELECT id, job_id, user_id, user_login, user_email, email_domain, registered_at, score, reasons, state, protected_code, reviewed_at
			FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order}, user_id ASC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results(
			$wpdb->prepare( $query, ...array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		$items = array();

		foreach ( (array) $rows as $row ) {
			$row['reasons'] = json_decode( (string) $row['reasons'], true );
			$row['reasons'] = is_array( $row['reasons'] ) ? $row['reasons'] : array();
			$items[]        = $row;
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts candidates matching the supplied filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count_matching( array $args ): int {
		global $wpdb;

		$table  = ewuc_table( 'candidates' );
		$filter = self::build_where( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$filter['clause']}", ...$filter['params'] )
		);
	}

	/**
	 * Returns the next bounded page of matching candidate user IDs.
	 *
	 * Uses a keyset cursor on user_id, like the quarantine purge loop. A
	 * "quarantine all" pass must always advance: protected rows keep their
	 * candidate state, so re-running the same offset query would never
	 * terminate.
	 *
	 * @param array $args     Query arguments.
	 * @param int   $after_id Exclusive lower bound.
	 * @param int   $limit    Maximum rows.
	 * @return int[]
	 */
	public static function next_batch( array $args, int $after_id, int $limit ): array {
		global $wpdb;

		$table  = ewuc_table( 'candidates' );
		$limit  = ewuc_clamp_int( $limit, 1, 100, 25 );
		$filter = self::build_where( array_merge( $args, array( 'after' => max( 0, $after_id ) ) ) );
		$params = array_merge( $filter['params'], array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE {$filter['clause']} ORDER BY user_id ASC LIMIT %d",
				...$params
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Counts candidates grouped by state.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, int>
	 */
	public static function counts( int $job_id ): array {
		global $wpdb;

		$table = ewuc_table( 'candidates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT state, COUNT(*) AS total FROM {$table} WHERE job_id = %d GROUP BY state", $job_id ),
			ARRAY_A
		);

		$counts = array_fill_keys( self::states(), 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['state'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Fetches candidate rows for a bounded set of users.
	 *
	 * @param int   $job_id   Job ID.
	 * @param int[] $user_ids User IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_many( int $job_id, array $user_ids ): array {
		global $wpdb;

		$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );

		if ( ! $user_ids ) {
			return array();
		}

		$table        = ewuc_table( 'candidates' );
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_id = %d AND user_id IN ({$placeholders})",
				...array_merge( array( $job_id ), $user_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sets the state of a candidate row.
	 *
	 * @param int    $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @param string $state   New state.
	 * @param string $code    Protection code.
	 * @return void
	 */
	public static function set_state( int $job_id, int $user_id, string $state, string $code = '' ): void {
		global $wpdb;

		if ( ! in_array( $state, self::states(), true ) ) {
			return;
		}

		$wpdb->update(
			ewuc_table( 'candidates' ),
			array(
				'state'          => $state,
				'protected_code' => substr( sanitize_key( $code ), 0, 40 ),
				'reviewed_by'    => get_current_user_id(),
				'reviewed_at'    => ewuc_now(),
			),
			array(
				'job_id'  => $job_id,
				'user_id' => $user_id,
			),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Marks every candidate row for a user, across jobs.
	 *
	 * @param int    $user_id User ID.
	 * @param string $state   New state.
	 * @return void
	 */
	public static function set_state_all_jobs( int $user_id, string $state ): void {
		global $wpdb;

		if ( ! in_array( $state, self::states(), true ) ) {
			return;
		}

		$wpdb->update(
			ewuc_table( 'candidates' ),
			array(
				'state'       => $state,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => ewuc_now(),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Refreshes the cached protection code for display.
	 *
	 * @param int    $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @param string $code    Protection code.
	 * @return void
	 */
	public static function set_protection( int $job_id, int $user_id, string $code ): void {
		global $wpdb;

		$wpdb->update(
			ewuc_table( 'candidates' ),
			array( 'protected_code' => substr( sanitize_key( $code ), 0, 40 ) ),
			array(
				'job_id'  => $job_id,
				'user_id' => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}
}
