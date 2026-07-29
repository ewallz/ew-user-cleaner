<?php
/**
 * Append-only audit log.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Records destructive and configuration events.
 */
class EWUC_Audit {

	/**
	 * Keys that must never be persisted in audit context.
	 *
	 * @var string[]
	 */
	private const DENIED_KEYS = array( 'user_pass', 'payload', 'password', 'nonce', '_wpnonce', 'session_tokens', 'user_activation_key' );

	/**
	 * Writes an audit record.
	 *
	 * @param string $action      Action slug.
	 * @param array  $args        Record arguments.
	 * @return void
	 */
	public static function log( string $action, array $args = array() ): void {
		global $wpdb;

		$context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array();

		foreach ( self::DENIED_KEYS as $denied ) {
			unset( $context[ $denied ] );
		}

		$wpdb->insert(
			ewuc_table( 'audit' ),
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 60 ),
				'object_type' => isset( $args['object_type'] ) ? substr( sanitize_key( (string) $args['object_type'] ), 0, 20 ) : '',
				'object_id'   => isset( $args['object_id'] ) ? absint( $args['object_id'] ) : 0,
				'job_id'      => isset( $args['job_id'] ) ? absint( $args['job_id'] ) : 0,
				'outcome'     => isset( $args['outcome'] ) ? substr( sanitize_key( (string) $args['outcome'] ), 0, 20 ) : 'ok',
				'error_code'  => isset( $args['error_code'] ) ? substr( sanitize_key( (string) $args['error_code'] ), 0, 60 ) : '',
				'context'     => $context ? wp_json_encode( $context ) : null,
				'created_at'  => ewuc_now(),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Reads recent audit entries.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = ewuc_clamp_int( $limit, 1, 200, 50 );
		$table = ewuc_table( 'audit' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin owned table, table name from allowlist.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
