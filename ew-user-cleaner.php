<?php
/**
 * Plugin Name:       EW User Cleaner
 * Plugin URI:        https://www.ewallzsolutions.com
 * Description:       Find, review, quarantine, purge and restore likely spam user registrations using manually initiated, resumable batch jobs.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            eWallz Solutions
 * Author URI:        https://www.ewallzsolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ew-user-cleaner
 * Domain Path:       /languages
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'EWUC_VERSION', '1.2.0' );
define( 'EWUC_PLUGIN_FILE', __FILE__ );
define( 'EWUC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWUC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EWUC_REST_NAMESPACE', 'ew-user-cleaner/v1' );

/**
 * Minimal PSR-4 style loader for the plugin classes.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function ewuc_autoload( string $class_name ): void {
	if ( 0 !== strpos( $class_name, 'EWUC_' ) ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', substr( $class_name, 5 ) ) );
	$file = EWUC_PLUGIN_DIR . 'includes/class-ewuc-' . $slug . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'ewuc_autoload' );

require_once EWUC_PLUGIN_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'EWUC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EWUC_Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		EWUC_Plugin::instance()->boot();
	}
);
