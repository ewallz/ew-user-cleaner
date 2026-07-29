<?php
/**
 * Local identity based spam scoring.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates users against an immutable rule snapshot.
 */
class EWUC_Scorer {

	/**
	 * Rule snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $snapshot;

	/**
	 * Constructor.
	 *
	 * @param array $snapshot Immutable settings snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Scores one user row.
	 *
	 * @param object $row Row with ID, user_login, user_email.
	 * @return array{score: int, reasons: array<int, string>, allowlisted: bool}
	 */
	public function score( object $row ): array {
		$login  = ewuc_normalize( (string) ( $row->user_login ?? '' ) );
		$email  = ewuc_normalize( (string) ( $row->user_email ?? '' ) );
		$local  = ewuc_email_local( $email );
		$domain = ewuc_email_domain( $email );
		$rules  = $this->snapshot['rules'];

		if ( $this->is_allowlisted( (int) $row->ID, $login, $email, $domain ) ) {
			return array(
				'score'       => 0,
				'reasons'     => array(),
				'allowlisted' => true,
			);
		}

		$score   = 0;
		$reasons = array();
		$labels  = EWUC_Settings::rule_labels();

		if ( $this->rule_active( 'phone_login' ) && $this->is_phone_like( $login, $rules['phone_login'] ) ) {
			$score    += (int) $rules['phone_login']['weight'];
			$reasons[] = $labels['phone_login'];
		}

		if ( $this->rule_active( 'login_email_same' ) && '' !== $login && $login === $local ) {
			$score    += (int) $rules['login_email_same']['weight'];
			$reasons[] = $labels['login_email_same'];
		}

		if ( $this->rule_active( 'phone_email' ) && $this->is_phone_like( $local, $rules['phone_email'] ) ) {
			$score    += (int) $rules['phone_email']['weight'];
			$reasons[] = $labels['phone_email'];
		}

		if ( $this->rule_active( 'domain_list' ) && '' !== $domain
			&& $this->domain_matches( $domain, (array) $this->snapshot['flagged_domains'] ) ) {
			$score    += (int) $rules['domain_list']['weight'];
			$reasons[] = $labels['domain_list'];
		}

		if ( $this->rule_active( 'login_pattern' ) && $this->matches_pattern( $login, (string) $rules['login_pattern']['pattern'] ) ) {
			$score    += (int) $rules['login_pattern']['weight'];
			$reasons[] = $labels['login_pattern'];
		}

		if ( $this->rule_active( 'email_pattern' ) && $this->matches_pattern( $local, (string) $rules['email_pattern']['pattern'] ) ) {
			$score    += (int) $rules['email_pattern']['weight'];
			$reasons[] = $labels['email_pattern'];
		}

		if ( $this->rule_active( 'blocklist' )
			&& ( in_array( $login, (array) $this->snapshot['block_logins'], true )
				|| in_array( $email, (array) $this->snapshot['block_emails'], true ) ) ) {
			$score    += (int) $rules['blocklist']['weight'];
			$reasons[] = $labels['blocklist'];
		}

		return array(
			'score'       => $score,
			'reasons'     => $reasons,
			'allowlisted' => false,
		);
	}

	/**
	 * Whether the score meets the configured threshold.
	 *
	 * @param int $score Computed score.
	 * @return bool
	 */
	public function is_candidate( int $score ): bool {
		$threshold = (int) $this->snapshot['threshold'];

		return $threshold > 0 && $score >= $threshold;
	}

	/**
	 * Whether a rule is enabled and weighted.
	 *
	 * @param string $rule_id Rule key.
	 * @return bool
	 */
	private function rule_active( string $rule_id ): bool {
		$rule = $this->snapshot['rules'][ $rule_id ] ?? array();

		return ! empty( $rule['enabled'] ) && (int) ( $rule['weight'] ?? 0 ) > 0;
	}

	/**
	 * Allowlist check by ID, login, email and domain.
	 *
	 * Every allowlist match short circuits scoring completely: an allowlisted
	 * account scores zero and no other rule is evaluated. Allowed domains are
	 * included here, so a trusted domain outranks every detection rule.
	 *
	 * @param int    $user_id User ID.
	 * @param string $login   Normalized login.
	 * @param string $email   Normalized email.
	 * @param string $domain  Normalized email domain.
	 * @return bool
	 */
	private function is_allowlisted( int $user_id, string $login, string $email, string $domain ): bool {
		if ( in_array( $user_id, array_map( 'intval', (array) $this->snapshot['allow_user_ids'] ), true ) ) {
			return true;
		}

		if ( '' !== $login && in_array( $login, (array) $this->snapshot['allow_logins'], true ) ) {
			return true;
		}

		if ( '' !== $email && in_array( $email, (array) $this->snapshot['allow_emails'], true ) ) {
			return true;
		}

		return $this->domain_matches( $domain, (array) $this->snapshot['allow_domains'] );
	}

	/**
	 * Matches a domain against a list, including its subdomains.
	 *
	 * An entry of "ff.com" matches "ff.com", "xx.ff.com" and "mm.ff.com", but
	 * never "notff.com" or "ff.com.evil.net". Matching is on label boundaries,
	 * not substrings, so a list entry cannot accidentally match a wider domain.
	 *
	 * A leading dot is accepted and ignored, so ".ff.com" behaves exactly like
	 * "ff.com". Both forms are common in blocklists and silently missing the
	 * apex domain is a worse failure than being slightly permissive.
	 *
	 * @param string   $domain  Normalized email domain.
	 * @param string[] $entries List entries.
	 * @return bool
	 */
	private function domain_matches( string $domain, array $entries ): bool {
		if ( '' === $domain ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			$entry = ltrim( ewuc_normalize( (string) $entry ), '.' );

			if ( '' === $entry ) {
				continue;
			}

			if ( $domain === $entry ) {
				return true;
			}

			// Boundary check: only match when preceded by a dot.
			if ( strlen( $domain ) > strlen( $entry ) + 1
				&& substr( $domain, - ( strlen( $entry ) + 1 ) ) === '.' . $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Conservative phone-like detection.
	 *
	 * @param string $value Candidate value.
	 * @param array  $rule  Rule configuration.
	 * @return bool
	 */
	private function is_phone_like( string $value, array $rule ): bool {
		if ( '' === $value ) {
			return false;
		}

		// Only separators commonly used in phone numbers are tolerated.
		$stripped = preg_replace( '/[\s\-\.\(\)\+_]/', '', $value );

		if ( ! is_string( $stripped ) || '' === $stripped ) {
			return false;
		}

		if ( ! ctype_digit( $stripped ) ) {
			return false;
		}

		$length = strlen( $stripped );

		return $length >= (int) $rule['min_digits'] && $length <= (int) $rule['max_digits'];
	}

	/**
	 * Anchored custom pattern evaluation in PHP, never in SQL.
	 *
	 * @param string $value   Candidate value.
	 * @param string $pattern Stored pattern.
	 * @return bool
	 */
	private function matches_pattern( string $value, string $pattern ): bool {
		if ( '' === $value || '' === $pattern ) {
			return false;
		}

		$result = @preg_match( '/^(?:' . $pattern . ')$/u', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return 1 === $result;
	}

	/**
	 * Fingerprint used to detect post-scan changes.
	 *
	 * @param object $row User row.
	 * @return string
	 */
	public static function fingerprint( object $row ): string {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) ( $row->ID ?? '' ),
					(string) ( $row->user_login ?? '' ),
					(string) ( $row->user_email ?? '' ),
					(string) ( $row->user_registered ?? '' ),
				)
			)
		);
	}
}
