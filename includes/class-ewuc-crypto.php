<?php
/**
 * Authenticated encryption for backup payloads.
 *
 * @package EWUC
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts sensitive account state.
 *
 * Keys are derived from WordPress secret material and are never stored beside
 * the ciphertext. If no AEAD implementation is available the plugin fails
 * closed so purges cannot proceed without a recoverable backup.
 */
class EWUC_Crypto {

	/**
	 * Key version for future rotation.
	 *
	 * @var int
	 */
	const KEY_VERSION = 1;

	/**
	 * Returns the active cipher identifier or an empty string.
	 *
	 * @return string
	 */
	public static function cipher(): string {
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return 'xchacha20poly1305';
		}

		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true ) ) {
			return 'aes-256-gcm';
		}

		return '';
	}

	/**
	 * Whether secure encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return '' !== self::cipher() && '' !== self::secret_material();
	}

	/**
	 * Raw secret material from wp-config constants.
	 *
	 * @return string
	 */
	private static function secret_material(): string {
		$parts = array();

		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'NONCE_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$value = (string) constant( $constant );

				if ( '' !== $value && 'put your unique phrase here' !== $value ) {
					$parts[] = $value;
				}
			}
		}

		return implode( '|', $parts );
	}

	/**
	 * Derives the encryption key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hkdf(
			'sha256',
			self::secret_material(),
			32,
			'ewuc-backup-v' . self::KEY_VERSION,
			(string) get_current_blog_id()
		);
	}

	/**
	 * Encrypts a payload array.
	 *
	 * @param array $payload Plain payload.
	 * @return array{cipher: string, data: string, checksum: string}|WP_Error
	 */
	public static function encrypt( array $payload ) {
		$cipher = self::cipher();

		if ( ! self::is_available() ) {
			return new WP_Error(
				'ewuc_no_encryption',
				__( 'Secure encryption is unavailable, so backups cannot be created. Purging is blocked.', 'ew-user-cleaner' ),
				array( 'status' => 500 )
			);
		}

		$plain = (string) wp_json_encode( $payload );
		$key   = self::key();

		if ( 'xchacha20poly1305' === $cipher ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $nonce, $nonce, $key );
		} else {
			$nonce      = random_bytes( 12 );
			$tag        = '';
			$ciphertext = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $nonce );

			if ( false === $ciphertext ) {
				return new WP_Error( 'ewuc_encrypt_failed', __( 'Backup encryption failed.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
			}

			$ciphertext .= $tag;
		}

		$data = base64_encode( $nonce . $ciphertext );

		return array(
			'cipher'   => $cipher,
			'data'     => $data,
			'checksum' => hash( 'sha256', $data ),
		);
	}

	/**
	 * Decrypts a stored payload.
	 *
	 * @param string $data     Base64 payload.
	 * @param string $cipher   Cipher used at rest.
	 * @param string $checksum Stored checksum.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function decrypt( string $data, string $cipher, string $checksum ) {
		if ( ! hash_equals( $checksum, hash( 'sha256', $data ) ) ) {
			return new WP_Error( 'ewuc_backup_tampered', __( 'The backup checksum does not match. Restore refused.', 'ew-user-cleaner' ), array( 'status' => 409 ) );
		}

		$raw = base64_decode( $data, true );

		if ( false === $raw ) {
			return new WP_Error( 'ewuc_backup_unreadable', __( 'The backup payload is unreadable.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		$key = self::key();

		if ( 'xchacha20poly1305' === $cipher ) {
			if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
				return new WP_Error( 'ewuc_cipher_missing', __( 'The cipher used for this backup is unavailable on this server.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
			}

			$nonce_length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
			$nonce        = substr( $raw, 0, $nonce_length );
			$ciphertext   = substr( $raw, $nonce_length );
			$plain        = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $nonce, $nonce, $key );
		} elseif ( 'aes-256-gcm' === $cipher ) {
			$nonce      = substr( $raw, 0, 12 );
			$body       = substr( $raw, 12 );
			$tag        = substr( $body, -16 );
			$ciphertext = substr( $body, 0, -16 );
			$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $nonce );
		} else {
			return new WP_Error( 'ewuc_cipher_unknown', __( 'Unknown backup cipher.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		if ( false === $plain || null === $plain ) {
			return new WP_Error( 'ewuc_backup_auth_failed', __( 'The backup failed authentication and may have been altered.', 'ew-user-cleaner' ), array( 'status' => 409 ) );
		}

		$decoded = json_decode( (string) $plain, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ewuc_backup_corrupt', __( 'The backup contents could not be parsed.', 'ew-user-cleaner' ), array( 'status' => 500 ) );
		}

		return $decoded;
	}
}
