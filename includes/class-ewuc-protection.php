<?php
/**
 * Protected account and owned data detection.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether a user may be quarantined or purged.
 */
class EWUC_Protection {

	/**
	 * Capabilities that always protect an account.
	 *
	 * @var string[]
	 */
	private const PROTECTED_CAPS = array( 'manage_options', 'edit_users', 'delete_users', 'promote_users', 'manage_woocommerce' );

	/**
	 * Evaluates a user.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $settings Settings array.
	 * @return array{code: string, label: string, references: array<int, array<string, mixed>>}
	 */
	public static function evaluate( int $user_id, array $settings ): array {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return self::result( 'missing', __( 'User no longer exists', 'ew-user-cleaner' ) );
		}

		if ( get_current_user_id() === $user_id ) {
			return self::result( 'current_user', __( 'Currently signed in administrator', 'ew-user-cleaner' ) );
		}

		if ( ! empty( $settings['protect_user_one'] ) && 1 === $user_id ) {
			return self::result( 'user_one', __( 'Primary site owner account', 'ew-user-cleaner' ) );
		}

		if ( (int) $settings['reassign_user_id'] === $user_id ) {
			return self::result( 'reassign_target', __( 'Configured content reassignment account', 'ew-user-cleaner' ) );
		}

		$protected_roles = array_map( 'strval', (array) $settings['protected_roles'] );

		if ( array_intersect( $protected_roles, (array) $user->roles ) ) {
			return self::result( 'protected_role', __( 'Holds a protected role', 'ew-user-cleaner' ) );
		}

		foreach ( self::PROTECTED_CAPS as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return self::result( 'protected_cap', __( 'Holds a privileged capability', 'ew-user-cleaner' ) );
			}
		}

		$references = self::references( $user_id );

		if ( $references ) {
			return self::result( 'owns_data', __( 'Owns content or commerce records', 'ew-user-cleaner' ), $references );
		}

		/**
		 * Allows extensions to veto quarantine or purge for a user.
		 *
		 * @param string $code    Empty string when unprotected.
		 * @param int    $user_id User ID.
		 */
		$external = (string) apply_filters( 'ewuc_protection_code', '', $user_id );

		if ( '' !== $external ) {
			return self::result( sanitize_key( $external ), __( 'Protected by another plugin', 'ew-user-cleaner' ) );
		}

		return self::result( '', '' );
	}

	/**
	 * Whether the user is protected from normal bulk actions.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function is_protected( int $user_id, array $settings ): bool {
		$result = self::evaluate( $user_id, $settings );

		return '' !== $result['code'];
	}

	/**
	 * Bounded existence checks for supported relationships.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function references( int $user_id ): array {
		global $wpdb;

		$references = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$has_post = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d LIMIT 1", $user_id )
		);

		if ( $has_post ) {
			$references[] = array(
				'type'  => 'post',
				'label' => __( 'Authored content', 'ew-user-cleaner' ),
			);
		}

		$has_comment = $wpdb->get_var(
			$wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d LIMIT 1", $user_id )
		);

		if ( $has_comment ) {
			$references[] = array(
				'type'  => 'comment',
				'label' => __( 'Comments', 'ew-user-cleaner' ),
			);
		}

		$has_link = $wpdb->get_var(
			$wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner = %d LIMIT 1", $user_id )
		);

		if ( $has_link ) {
			$references[] = array(
				'type'  => 'link',
				'label' => __( 'Links', 'ew-user-cleaner' ),
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( self::has_woocommerce_orders( $user_id ) ) {
			$references[] = array(
				'type'  => 'wc_order',
				'label' => __( 'WooCommerce orders', 'ew-user-cleaner' ),
			);
		}

		/**
		 * Allows extensions to declare user owned records.
		 *
		 * @param array $references Reference descriptors.
		 * @param int   $user_id    User ID.
		 */
		$references = (array) apply_filters( 'ewuc_user_references', $references, $user_id );

		return array_values( $references );
	}

	/**
	 * HPOS aware WooCommerce order existence check.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function has_woocommerce_orders( int $user_id ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'return'      => 'ids',
				'status'      => 'any',
			)
		);

		return ! empty( $orders );
	}

	/**
	 * Builds the result payload.
	 *
	 * @param string $code       Protection code.
	 * @param string $label      Human readable label.
	 * @param array  $references Reference descriptors.
	 * @return array{code: string, label: string, references: array<int, array<string, mixed>>}
	 */
	private static function result( string $code, string $label, array $references = array() ): array {
		return array(
			'code'       => $code,
			'label'      => $label,
			'references' => $references,
		);
	}
}
