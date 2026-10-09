<?php
/**
 * Class Push Settings
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Stores and resolves the default author assigned to content created on this site by a push.
 */
class Push_Settings {
	/**
	 * Option name used to store the default author user ID.
	 *
	 * @var string
	 */
	private const DEFAULT_AUTHOR_OPTION = 'post_migrator_push_default_author';

	/**
	 * Get the user ID to use as the author when no matching author login exists.
	 *
	 * Falls back to the first administrator on this site if no default has
	 * been configured, or if the configured user no longer exists.
	 *
	 * @return int
	 */
	public static function get_default_author(): int {
		$user_id = (int) get_option( self::DEFAULT_AUTHOR_OPTION, 0 );

		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			return $user_id;
		}

		$administrators = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return ! empty( $administrators ) ? (int) $administrators[0] : 0;
	}

	/**
	 * Save the default author user ID.
	 *
	 * @param int $user_id The user ID to use as the default author.
	 * @return void
	 */
	public static function save_default_author( int $user_id ) {
		update_option( self::DEFAULT_AUTHOR_OPTION, $user_id, false );
	}
}
