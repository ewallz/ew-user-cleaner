<?php
/**
 * Settings storage, validation and rule versioning.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Validated plugin settings.
 */
class EWUC_Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION = 'ewuc_settings';

	/**
	 * Maximum allowed length for a custom pattern.
	 *
	 * @var int
	 */
	const MAX_PATTERN_LENGTH = 120;

	/**
	 * Rule identifiers supported by the scoring engine.
	 *
	 * @return array<string, string>
	 */
	public static function rule_labels(): array {
		return array(
			'phone_login'      => __( 'Username looks like a phone number', 'ew-user-cleaner' ),
			'login_email_same' => __( 'Username equals email local part', 'ew-user-cleaner' ),
			'phone_email'      => __( 'Email local part looks like a phone number', 'ew-user-cleaner' ),
			'domain_list'      => __( 'Email domain is in the flagged domain list', 'ew-user-cleaner' ),
			'login_pattern'    => __( 'Username matches custom pattern', 'ew-user-cleaner' ),
			'email_pattern'    => __( 'Email local part matches custom pattern', 'ew-user-cleaner' ),
			'blocklist'        => __( 'Blocklist entry', 'ew-user-cleaner' ),
		);
	}

	/**
	 * Default settings. No threshold is configured so scanning stays disabled
	 * until an administrator defines the scoring policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'configured'         => false,
			'threshold'          => 0,
			'rules'              => array(
				'phone_login'      => array(
					'enabled'    => true,
					'weight'     => 2,
					'min_digits' => 8,
					'max_digits' => 15,
				),
				'login_email_same' => array(
					'enabled' => true,
					'weight'  => 1,
				),
				'phone_email'      => array(
					'enabled'    => true,
					'weight'     => 2,
					'min_digits' => 8,
					'max_digits' => 15,
				),
				'domain_list'      => array(
					'enabled' => false,
					'weight'  => 2,
				),
				'login_pattern'    => array(
					'enabled' => false,
					'weight'  => 2,
					'pattern' => '',
				),
				'email_pattern'    => array(
					'enabled' => false,
					'weight'  => 2,
					'pattern' => '',
				),
				'blocklist'        => array(
					'enabled' => true,
					'weight'  => 3,
				),
			),
			'flagged_domains'    => array(),
			'allow_domains'      => array(),
			'allow_logins'       => array(),
			'allow_emails'       => array(),
			'allow_user_ids'     => array(),
			'block_logins'       => array(),
			'block_emails'       => array(),
			'protected_roles'    => array( 'administrator', 'editor', 'shop_manager' ),
			'protect_user_one'   => true,
			'reassign_user_id'   => 0,
			'batch_scan'         => 250,
			'batch_quarantine'   => 25,
			'batch_purge'        => 10,
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Returns stored settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		$settings['rules'] = self::merge_rules( isset( $stored['rules'] ) && is_array( $stored['rules'] ) ? $stored['rules'] : array() );

		return $settings;
	}

	/**
	 * Merges stored rules with defaults.
	 *
	 * @param array $stored Stored rules.
	 * @return array<string, array<string, mixed>>
	 */
	private static function merge_rules( array $stored ): array {
		$defaults = self::defaults()['rules'];
		$merged   = array();

		foreach ( $defaults as $rule_id => $rule ) {
			$merged[ $rule_id ] = isset( $stored[ $rule_id ] ) && is_array( $stored[ $rule_id ] )
				? array_merge( $rule, $stored[ $rule_id ] )
				: $rule;
		}

		return $merged;
	}

	/**
	 * Stable version string for the current scoring policy.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	public static function rule_version( array $settings ): string {
		$relevant = array(
			'threshold'       => $settings['threshold'],
			'rules'           => $settings['rules'],
			'flagged_domains' => $settings['flagged_domains'],
			'allow_domains'   => $settings['allow_domains'],
			'allow_logins'    => $settings['allow_logins'],
			'allow_emails'    => $settings['allow_emails'],
			'allow_user_ids'  => $settings['allow_user_ids'],
			'block_logins'    => $settings['block_logins'],
			'block_emails'    => $settings['block_emails'],
		);

		return substr( hash( 'sha256', (string) wp_json_encode( $relevant ) ), 0, 32 );
	}

	/**
	 * Validates and saves raw settings input.
	 *
	 * @param array $raw Raw input.
	 * @return array{0: bool, 1: array<int, string>, 2: array<int, string>}
	 */
	public static function save( array $raw ): array {
		$current  = self::get();
		$errors   = array();
		$notices  = array();
		$settings = $current;

		$settings['threshold'] = ewuc_clamp_int( $raw['threshold'] ?? 0, 0, 100, 0 );

		$rules = array();

		foreach ( $current['rules'] as $rule_id => $rule ) {
			$input = isset( $raw['rules'][ $rule_id ] ) && is_array( $raw['rules'][ $rule_id ] ) ? $raw['rules'][ $rule_id ] : array();

			$rule['enabled'] = ! empty( $input['enabled'] );
			$rule['weight']  = ewuc_clamp_int( $input['weight'] ?? $rule['weight'], 0, 50, (int) $rule['weight'] );

			if ( isset( $rule['min_digits'] ) ) {
				$rule['min_digits'] = ewuc_clamp_int( $input['min_digits'] ?? $rule['min_digits'], 4, 20, (int) $rule['min_digits'] );
				$rule['max_digits'] = ewuc_clamp_int( $input['max_digits'] ?? $rule['max_digits'], $rule['min_digits'], 30, (int) $rule['max_digits'] );
			}

			if ( isset( $rule['pattern'] ) ) {
				$pattern = isset( $input['pattern'] ) ? trim( (string) wp_unslash( $input['pattern'] ) ) : '';

				if ( '' !== $pattern ) {
					if ( strlen( $pattern ) > self::MAX_PATTERN_LENGTH ) {
						$errors[] = sprintf(
							/* translators: %s: rule label. */
							__( 'The pattern for "%s" is too long.', 'ew-user-cleaner' ),
							self::rule_labels()[ $rule_id ] ?? $rule_id
						);
						$pattern = '';
					} elseif ( ! self::pattern_is_safe( $pattern ) ) {
						$errors[] = sprintf(
							/* translators: %s: rule label. */
							__( 'The pattern for "%s" is not a valid or supported expression.', 'ew-user-cleaner' ),
							self::rule_labels()[ $rule_id ] ?? $rule_id
						);
						$pattern = '';
					}
				}

				$rule['pattern'] = $pattern;

				if ( '' === $pattern ) {
					$rule['enabled'] = false;
				}
			}

			$rules[ $rule_id ] = $rule;
		}

		$settings['rules'] = $rules;

		$settings['flagged_domains'] = self::sanitize_list( $raw['flagged_domains'] ?? '', 'domain' );
		$settings['allow_domains']   = self::sanitize_list( $raw['allow_domains'] ?? '', 'domain' );
		$settings['allow_logins']    = self::sanitize_list( $raw['allow_logins'] ?? '', 'login' );
		$settings['allow_emails']    = self::sanitize_list( $raw['allow_emails'] ?? '', 'email' );
		$settings['block_logins']    = self::sanitize_list( $raw['block_logins'] ?? '', 'login' );
		$settings['block_emails']    = self::sanitize_list( $raw['block_emails'] ?? '', 'email' );
		$settings['allow_user_ids']  = self::sanitize_ids( $raw['allow_user_ids'] ?? '' );

		$conflicts = array_intersect( $settings['allow_logins'], $settings['block_logins'] );

		if ( $conflicts ) {
			$errors[] = __( 'A username cannot appear in both the allowlist and the blocklist.', 'ew-user-cleaner' );
		}

		$conflicts = array_intersect( $settings['allow_emails'], $settings['block_emails'] );

		if ( $conflicts ) {
			$errors[] = __( 'An email cannot appear in both the allowlist and the blocklist.', 'ew-user-cleaner' );
		}

		$roles                        = array_keys( wp_roles()->roles );
		$submitted_roles              = isset( $raw['protected_roles'] ) && is_array( $raw['protected_roles'] ) ? array_map( 'sanitize_key', $raw['protected_roles'] ) : array();
		$settings['protected_roles']  = array_values( array_intersect( $submitted_roles, $roles ) );
		$settings['protect_user_one'] = ! empty( $raw['protect_user_one'] );

		if ( ! in_array( 'administrator', $settings['protected_roles'], true ) ) {
			$settings['protected_roles'][] = 'administrator';
		}

		$reassign = absint( $raw['reassign_user_id'] ?? 0 );

		if ( $reassign > 0 && ! get_userdata( $reassign ) ) {
			$errors[] = __( 'The content reassignment user does not exist.', 'ew-user-cleaner' );
			$reassign = 0;
		}

		$settings['reassign_user_id'] = $reassign;

		$settings['batch_scan']       = ewuc_clamp_int( $raw['batch_scan'] ?? 250, 25, 1000, 250 );
		$settings['batch_quarantine'] = ewuc_clamp_int( $raw['batch_quarantine'] ?? 25, 5, 100, 25 );
		$settings['batch_purge']      = ewuc_clamp_int( $raw['batch_purge'] ?? 10, 1, 50, 10 );

		$settings['remove_data_on_uninstall'] = ! empty( $raw['remove_data_on_uninstall'] );

		$has_enabled_rule = false;

		foreach ( $settings['rules'] as $rule ) {
			if ( ! empty( $rule['enabled'] ) && (int) $rule['weight'] > 0 ) {
				$has_enabled_rule = true;
				break;
			}
		}

		if ( ! $has_enabled_rule ) {
			$errors[] = __( 'Enable at least one rule with a weight above zero.', 'ew-user-cleaner' );
		}

		if ( $settings['threshold'] < 1 ) {
			$errors[] = __( 'Set a candidate threshold of at least 1 before scanning.', 'ew-user-cleaner' );
		}

		if ( ! empty( $settings['rules']['domain_list']['enabled'] ) && empty( $settings['flagged_domains'] ) ) {
			$errors[] = __( 'The domain rule is enabled but the flagged domain list is empty.', 'ew-user-cleaner' );
		}

		// The reverse is not an error, but silently ignoring a populated list is
		// the kind of thing that looks like a scanning bug.
		if ( empty( $settings['rules']['domain_list']['enabled'] ) && ! empty( $settings['flagged_domains'] ) ) {
			$notices[] = __( 'Your flagged domain list was saved, but the rule "Email domain is in the flagged domain list" is turned off, so those domains are ignored while scanning. Enable that rule to use them.', 'ew-user-cleaner' );
		}

		$settings['configured'] = empty( $errors );

		update_option( self::OPTION, $settings, false );

		EWUC_Audit::log(
			'settings_saved',
			array(
				'outcome' => $errors ? 'invalid' : 'ok',
				'context' => array(
					'threshold'    => $settings['threshold'],
					'rule_version' => self::rule_version( $settings ),
					'configured'   => $settings['configured'],
				),
			)
		);

		return array( empty( $errors ), $errors, $notices );
	}

	/**
	 * Rejects unsupported or dangerous expressions.
	 *
	 * @param string $pattern Raw pattern.
	 * @return bool
	 */
	private static function pattern_is_safe( string $pattern ): bool {
		// Nested unbounded quantifiers are the common catastrophic backtracking shape.
		if ( preg_match( '/(\(\?R\)|\(\?[0-9]|\\\\[gk]<)/', $pattern ) ) {
			return false;
		}

		if ( preg_match( '/(\([^)]*[+*][^)]*\)\s*[+*])/', $pattern ) ) {
			return false;
		}

		// Suppress the compilation warning; the return value tells us if it compiled.
		$result = @preg_match( '/^(?:' . $pattern . ')$/u', 'ewuc-probe' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false !== $result && PREG_NO_ERROR === preg_last_error();
	}

	/**
	 * Normalizes a newline separated list.
	 *
	 * @param mixed  $raw  Raw textarea value.
	 * @param string $type Value type.
	 * @return string[]
	 */
	private static function sanitize_list( $raw, string $type ): array {
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$values = preg_split( '/[\r\n,]+/', (string) wp_unslash( $raw ) );
		$clean  = array();

		foreach ( (array) $values as $value ) {
			$value = ewuc_normalize( (string) $value );

			if ( '' === $value || strlen( $value ) > 100 ) {
				continue;
			}

			if ( 'email' === $type ) {
				$value = sanitize_email( $value );

				if ( ! is_email( $value ) ) {
					continue;
				}
			} elseif ( 'domain' === $type ) {
				// Drop a pasted scheme, credentials, path, port or @ prefix.
				$value    = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $value );
				$at       = strrpos( $value, '@' );
				$value    = false === $at ? $value : substr( $value, $at + 1 );
				$value    = preg_split( '~[/:?#]~', $value )[0] ?? '';
				$value = preg_replace( '/[^a-z0-9.\-]/', '', (string) $value );

				if ( ! is_string( $value ) ) {
					continue;
				}

				// A leading dot is redundant now that entries always cover the
				// apex domain and its subdomains, so store the canonical form.
				$value = trim( $value, '.-' );

				// Require at least one dot and a plausible TLD.
				if ( '' === $value || ! preg_match( '/^(?:[a-z0-9\-]+\.)+[a-z]{2,}$/', $value ) ) {
					continue;
				}
			} else {
				$value = sanitize_user( $value, true );

				if ( '' === $value ) {
					continue;
				}
			}

			$clean[] = $value;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Normalizes a list of user IDs.
	 *
	 * @param mixed $raw Raw value.
	 * @return int[]
	 */
	private static function sanitize_ids( $raw ): array {
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$ids   = preg_split( '/[^0-9]+/', $raw );
		$clean = array();

		foreach ( (array) $ids as $id ) {
			$id = absint( $id );

			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
