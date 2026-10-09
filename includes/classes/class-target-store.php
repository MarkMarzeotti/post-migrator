<?php
/**
 * Class Target Store
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Persists the target site URL and key the current user last entered, so the
 * Test Connection form stays filled in across submissions.
 */
class Target_Store {
	/**
	 * User meta key used to store the target site URL.
	 *
	 * @var string
	 */
	private const URL_META_KEY = 'post_migrator_target_url';

	/**
	 * User meta key used to store the target site key.
	 *
	 * @var string
	 */
	private const KEY_META_KEY = 'post_migrator_target_key';

	/**
	 * User meta key used to store the fingerprint of the last-verified target URL/key pair.
	 *
	 * @var string
	 */
	private const VERIFIED_FINGERPRINT_META_KEY = 'post_migrator_target_verified_fingerprint';

	/**
	 * Get the target site URL last entered by the current user.
	 *
	 * @return string
	 */
	public static function get_target_url(): string {
		$url = get_user_meta( get_current_user_id(), self::URL_META_KEY, true );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Get the target site key last entered by the current user.
	 *
	 * @return string
	 */
	public static function get_target_key(): string {
		$key = get_user_meta( get_current_user_id(), self::KEY_META_KEY, true );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Save the target site URL and key for the current user.
	 *
	 * @param string $url The target site URL.
	 * @param string $key The target site key.
	 * @return void
	 */
	public static function save( string $url, string $key ) {
		update_user_meta( get_current_user_id(), self::URL_META_KEY, $url );
		update_user_meta( get_current_user_id(), self::KEY_META_KEY, $key );
	}

	/**
	 * Mark the currently saved target URL/key as having just passed a connection test.
	 *
	 * @return void
	 */
	public static function mark_verified() {
		update_user_meta(
			get_current_user_id(),
			self::VERIFIED_FINGERPRINT_META_KEY,
			self::fingerprint( self::get_target_url(), self::get_target_key() )
		);
	}

	/**
	 * Check whether the currently saved target URL/key is the one that last passed a
	 * connection test. Self-invalidates whenever `save()` stores a different URL/key.
	 *
	 * @return bool
	 */
	public static function is_verified(): bool {
		$stored = get_user_meta( get_current_user_id(), self::VERIFIED_FINGERPRINT_META_KEY, true );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, self::fingerprint( self::get_target_url(), self::get_target_key() ) );
	}

	/**
	 * Build a fingerprint identifying a target URL/key pair.
	 *
	 * @param string $url The target site URL.
	 * @param string $key The target site key.
	 * @return string
	 */
	private static function fingerprint( string $url, string $key ): string {
		return hash( 'sha256', $url . '|' . $key );
	}
}
