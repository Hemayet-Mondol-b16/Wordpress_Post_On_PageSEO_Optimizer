<?php
/**
 * Encryption of API keys at rest.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts API keys before they are written to the database, so a leaked database
 * dump or backup does not expose usable keys. The encryption key is derived from the
 * site's secret salts in wp-config.php, which are not stored in the database.
 *
 * Uses libsodium (secretbox) when available, otherwise OpenSSL AES-256-GCM.
 */
final class BZPSO_Crypto {

	const SODIUM_PREFIX  = 'bzpso-s1:';
	const OPENSSL_PREFIX = 'bzpso-o1:';
	const OPENSSL_CIPHER = 'aes-256-gcm';

	/**
	 * 32-byte key derived from the site's AUTH salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'post-seo-optimizer|api-keys', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt a value. Returns '' if no encryption backend is available, so callers
	 * never fall back to storing plaintext.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( 24 ); // SODIUM_CRYPTO_SECRETBOX_NONCEBYTES.
				return self::SODIUM_PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}

			if ( self::openssl_available() ) {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plaintext, self::OPENSSL_CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $cipher ) {
					return self::OPENSSL_PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Decrypt a stored value. Returns '' when it cannot be decrypted (for example after
	 * the security salts were changed).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		try {
			if ( 0 === strpos( $stored, self::SODIUM_PREFIX ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$raw = base64_decode( substr( $stored, strlen( self::SODIUM_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $raw || strlen( $raw ) <= 24 ) {
					return '';
				}
				$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), self::key() );
				return false === $plain ? '' : $plain;
			}

			if ( 0 === strpos( $stored, self::OPENSSL_PREFIX ) && self::openssl_available() ) {
				$raw = base64_decode( substr( $stored, strlen( self::OPENSSL_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $raw || strlen( $raw ) <= 28 ) {
					return '';
				}
				$plain = openssl_decrypt( substr( $raw, 28 ), self::OPENSSL_CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
				return false === $plain ? '' : $plain;
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Whether AES-256-GCM via OpenSSL is usable.
	 *
	 * @return bool
	 */
	private static function openssl_available() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true );
	}
}
