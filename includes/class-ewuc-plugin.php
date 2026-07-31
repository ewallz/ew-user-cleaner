<?php
/**
 * Plugin bootstrap.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin services together.
 */
class EWUC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var EWUC_Plugin|null
	 */
	private static ?EWUC_Plugin $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return EWUC_Plugin
	 */
	public static function instance(): EWUC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		load_plugin_textdomain( 'ew-user-cleaner', false, dirname( plugin_basename( EWUC_PLUGIN_FILE ) ) . '/languages' );

		EWUC_Installer::maybe_upgrade();

		// Login blocking must run on the front end and admin, not only on plugin screens.
		EWUC_Quarantine::hooks();

		add_action( 'rest_api_init', array( 'EWUC_Rest', 'register_routes' ) );

		if ( is_admin() ) {
			EWUC_Admin::hooks();
			EWUC_Users_Column::hooks();
			EWUC_Update_Checker::hooks();
		}
	}
}
