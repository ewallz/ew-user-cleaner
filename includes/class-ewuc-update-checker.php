<?php
/**
 * Manual-only GitHub release notifications.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub for a newer release and renders a notice on the Plugins page.
 *
 * This intentionally does not integrate with WordPress's update transient or
 * upgrader. The notice links to GitHub for a manual download only.
 */
class EWUC_Update_Checker {

	/** GitHub API endpoint for the latest stable release. */
	private const API_URL = 'https://api.github.com/repos/ewallz/ew-user-cleaner/releases/latest';

	/** Fixed release URL prefix; response URLs are never trusted. */
	private const RELEASE_URL = 'https://github.com/ewallz/ew-user-cleaner/releases/tag/';

	/** Site-transient key for validated release metadata. */
	private const CACHE_KEY = 'ewuc_github_release';

	/** Successful checks are cached for twelve hours. */
	private const SUCCESS_TTL = 12 * HOUR_IN_SECONDS;

	/** Failed checks are cached briefly to avoid hammering GitHub. */
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Validated release currently being rendered.
	 *
	 * @var array{version: string, tag: string, url: string, published_at: string}|null
	 */
	private static ?array $release = null;

	/**
	 * Registers the Plugins-page hook only.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'load-plugins.php', array( __CLASS__, 'maybe_register_notice' ) );
	}

	/**
	 * Checks for a release and registers this plugin's row notice when needed.
	 *
	 * @return void
	 */
	public static function maybe_register_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$release = self::latest_release();

		if ( null === $release || ! version_compare( $release['version'], EWUC_VERSION, '>' ) ) {
			return;
		}

		self::$release = $release;

		add_action(
			'after_plugin_row_' . plugin_basename( EWUC_PLUGIN_FILE ),
			array( __CLASS__, 'render_notice_row' ),
			10,
			3
		);
	}

	/**
	 * Returns validated latest-release metadata, using a bounded site cache.
	 *
	 * @return array{version: string, tag: string, url: string, published_at: string}|null
	 */
	private static function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			if ( 'success' === $cached['status'] && isset( $cached['release'] ) && is_array( $cached['release'] ) ) {
				return self::validate_cached_release( $cached['release'] );
			}

			if ( 'failure' === $cached['status'] ) {
				return null;
			}
		}

		$response = wp_safe_remote_get(
			self::API_URL,
			array(
				'timeout'             => 5,
				'redirection'         => 2,
				'limit_response_size' => 262144,
				'headers'             => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'EW-User-Cleaner/' . EWUC_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::cache_failure();
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = is_array( $payload ) ? self::release_from_payload( $payload ) : null;

		if ( null === $release ) {
			self::cache_failure();
			return null;
		}

		set_site_transient(
			self::CACHE_KEY,
			array(
				'status'  => 'success',
				'release' => $release,
			),
			self::SUCCESS_TTL
		);

		return $release;
	}

	/**
	 * Converts an untrusted GitHub payload into minimal validated metadata.
	 *
	 * @param array<string, mixed> $payload GitHub API payload.
	 * @return array{version: string, tag: string, url: string, published_at: string}|null
	 */
	private static function release_from_payload( array $payload ): ?array {
		$tag = isset( $payload['tag_name'] ) && is_string( $payload['tag_name'] )
			? trim( $payload['tag_name'] )
			: '';

		$version = preg_replace( '/^[vV]/', '', $tag, 1 );

		if ( ! is_string( $version ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}

		$published_at = isset( $payload['published_at'] ) && is_string( $payload['published_at'] )
			? sanitize_text_field( $payload['published_at'] )
			: '';

		return array(
			'version'      => $version,
			'tag'          => $tag,
			'url'          => self::RELEASE_URL . rawurlencode( $tag ),
			'published_at' => $published_at,
		);
	}

	/**
	 * Re-validates cached data before it reaches the admin interface.
	 *
	 * @param array<string, mixed> $release Cached release data.
	 * @return array{version: string, tag: string, url: string, published_at: string}|null
	 */
	private static function validate_cached_release( array $release ): ?array {
		if ( ! isset( $release['tag'] ) || ! is_string( $release['tag'] ) ) {
			return null;
		}

		return self::release_from_payload(
			array(
				'tag_name'     => $release['tag'],
				'published_at' => isset( $release['published_at'] ) && is_string( $release['published_at'] )
					? $release['published_at']
					: '',
			)
		);
	}

	/**
	 * Caches a failed check so page refreshes do not repeatedly call GitHub.
	 *
	 * @return void
	 */
	private static function cache_failure(): void {
		set_site_transient(
			self::CACHE_KEY,
			array( 'status' => 'failure' ),
			self::FAILURE_TTL
		);
	}

	/**
	 * Renders a core-style row with a manual GitHub download link.
	 *
	 * @param string $plugin_file Plugin path relative to the plugins directory.
	 * @param array  $plugin_data Plugin header data.
	 * @param string $status      Current plugin-list status filter.
	 * @return void
	 */
	public static function render_notice_row( string $plugin_file, array $plugin_data, string $status ): void {
		unset( $status );

		if ( plugin_basename( EWUC_PLUGIN_FILE ) !== $plugin_file || null === self::$release ) {
			return;
		}

		global $wp_list_table;

		$columns = is_object( $wp_list_table ) && method_exists( $wp_list_table, 'get_column_count' )
			? max( 1, (int) $wp_list_table->get_column_count() )
			: 4;

		$is_active = ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) )
			|| ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) );
		$row_class = 'plugin-update-tr' . ( $is_active ? ' active' : '' );
		$name      = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : 'EW User Cleaner';
		$version   = self::$release['version'];
		$url       = self::$release['url'];

		printf(
			'<tr class="%1$s" id="ew-user-cleaner-manual-update" data-plugin="%2$s"><td colspan="%3$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>',
			esc_attr( $row_class ),
			esc_attr( $plugin_file ),
			$columns
		);

		printf(
			/* translators: 1: plugin name, 2: new version number. */
			esc_html__( '%1$s version %2$s is available.', 'ew-user-cleaner' ),
			esc_html( $name ),
			esc_html( $version )
		);

		echo ' ';

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer external">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $url ),
			esc_html__( 'View release and download manually', 'ew-user-cleaner' ),
			esc_html__( '(opens in a new tab)', 'ew-user-cleaner' )
		);

		echo ' <em>' . esc_html__( 'Automatic update is not enabled for this plugin.', 'ew-user-cleaner' ) . '</em>';
		echo '</p></div></td></tr>';
	}
}
