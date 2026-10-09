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
}
