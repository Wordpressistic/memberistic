<?php
/**
 * At-rest encryption for everything the SDK persists to wp_options:
 * activation token, verification key, masked key, and the cached
 * validation response. AES-256-GCM, keyed by a SHA-256 derivation of
 * wp_salt( 'auth' ) so the key never has to be stored anywhere itself and
 * rotates if the site's salts do.
 *
 * Encrypt never fails silently — if the environment cannot encrypt
 * (extension missing, CSPRNG unavailable) it throws, because writing
 * license state to disk as plaintext is worse than failing loudly.
 * Decrypt fails closed: a tampered, truncated, or foreign-key ciphertext
 * returns null rather than a fatal error, so callers always have a safe
 * "nothing stored" path.
 *
 * @package WPistic\Sdk
 */

namespace WPistic\Sdk\Security;

/**
 * Encrypts and decrypts SDK state persisted to wp_options.
 */
class TokenStorage {

	private const CIPHER     = 'aes-256-gcm';
	private const IV_LENGTH  = 12;
	private const TAG_LENGTH = 16;

	/**
	 * The 32-byte raw encryption key, derived from the site's auth salt.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Defaults to wp_salt( 'auth' ); override only for tests.
	 */
	public function __construct( ?string $key_material = null ) {
		if ( null === $key_material ) {
			$key_material = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		}
		$this->key = hash( 'sha256', $key_material, true );
	}

	/**
	 * Encrypt a plaintext string for storage.
	 *
	 * @param string $plaintext The plaintext to encrypt.
	 * @throws \RuntimeException When the environment cannot encrypt.
	 * @return string
	 */
	public function encrypt( string $plaintext ): string {
		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );
		if ( false === $ciphertext || self::TAG_LENGTH !== strlen( $tag ) ) {
			throw new \RuntimeException( 'WPistic SDK: unable to encrypt license state (openssl_encrypt failed).' );
		}

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a stored value produced by encrypt().
	 *
	 * @param string $stored The encoded ciphertext previously returned by encrypt().
	 * @return string|null The original plaintext, or null on any failure/tamper.
	 */
	public function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return null;
		}

		$raw = base64_decode( $stored, true );
		// An empty plaintext still produces IV + TAG bytes with a zero-length
		// ciphertext, so the minimum valid length is inclusive, not exclusive.
		if ( false === $raw || strlen( $raw ) < self::IV_LENGTH + self::TAG_LENGTH ) {
			return null;
		}

		$iv         = substr( $raw, 0, self::IV_LENGTH );
		$tag        = substr( $raw, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $raw, self::IV_LENGTH + self::TAG_LENGTH );

		$plaintext = @openssl_decrypt( $ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? null : $plaintext;
	}
}
