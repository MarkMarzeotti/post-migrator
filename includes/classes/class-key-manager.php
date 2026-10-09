<?php
/**
 * Class Key Manager
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Generates, stores, and verifies this site's own shared connection key.
 */
class Key_Manager {
	/**
	 * Option name used to store the generated key.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'post_migrator_key';

	/**
	 * Get this site's key, generating and storing one if it doesn't exist yet.
	 *
	 * @return string
	 */
	public static function get_key(): string {
		$key = get_option( self::OPTION_NAME, '' );

		if ( ! is_string( $key ) || '' === $key ) {
			$key = self::regenerate_key();
		}

		return $key;
	}

	/**
	 * Generate a new key and persist it, replacing any existing key.
	 *
	 * @return string
	 */
	public static function regenerate_key(): string {
		$key = self::generate_key();

		update_option( self::OPTION_NAME, $key, false );

		return $key;
	}

	/**
	 * Check whether a provided key matches this site's stored key.
	 *
	 * @param string $provided The key to verify.
	 * @return bool
	 */
	public static function verify_key( string $provided ): bool {
		return hash_equals( self::get_key(), $provided );
	}

	/**
	 * Generate a new cryptographically random, URL-safe key.
	 *
	 * @return string
	 */
	private static function generate_key(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Not used for obfuscation; encoding random bytes into a URL-safe token.
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
