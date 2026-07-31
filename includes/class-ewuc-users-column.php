<?php
/**
 * Quarantine indicator column on the core Users list table.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Adds an optional "Quarantined" column to wp-admin/users.php.
 *
 * The column is registered with the core list table, so WordPress lists it in
 * Screen Options automatically and each administrator can hide or show it
 * without a plugin setting.
 */
class EWUC_Users_Column {

	/**
	 * Column identifier.
	 *
	 * @var string
	 */
	const COLUMN = 'ewuc_quarantined';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_action( 'admin_print_styles-users.php', array( __CLASS__, 'print_styles' ) );
	}

	/**
	 * Whether the current user may see quarantine state.
	 *
	 * @return bool
	 */
	private static function allowed(): bool {
		return current_user_can( 'ewuc_review_users' );
	}

	/**
	 * Adds the column header.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		if ( ! self::allowed() ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = __( 'Quarantined', 'ew-user-cleaner' );

		return $columns;
	}

	/**
	 * Renders the cell value.
	 *
	 * Empty for normal accounts, so the column reads as a short exception list
	 * rather than a wall of "No".
	 *
	 * @param string $output      Current output.
	 * @param string $column_name Column identifier.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_column( $output, $column_name, $user_id ): string {
		if ( self::COLUMN !== $column_name ) {
			return (string) $output;
		}

		if ( ! self::allowed() || ! EWUC_Quarantine::is_quarantined( (int) $user_id ) ) {
			return '';
		}

		return sprintf(
			'<span class="ewuc-users-flag">%s</span>',
			esc_html__( 'Yes', 'ew-user-cleaner' )
		);
	}

	/**
	 * Minimal inline styling so the plugin admin bundle is not loaded here.
	 *
	 * @return void
	 */
	public static function print_styles(): void {
		if ( ! self::allowed() ) {
			return;
		}

		echo '<style>.column-' . esc_attr( self::COLUMN ) . '{width:9%}'
			. '.ewuc-users-flag{background:#fcf0f1;border-radius:3px;color:#b32d2e;font-weight:600;padding:2px 8px}</style>';
	}
}
