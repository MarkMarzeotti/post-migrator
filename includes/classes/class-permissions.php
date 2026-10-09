<?php
/**
 * Class Permissions
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Stores and checks whether this site allows other sites to pull or push content.
 */
class Permissions {
	/**
	 * Option name used to store whether pulling is allowed.
	 *
	 * @var string
	 */
	private const ALLOW_PULL_OPTION = 'post_migrator_allow_pull';

	/**
	 * Option name used to store whether pushing is allowed.
	 *
	 * @var string
	 */
	private const ALLOW_PUSH_OPTION = 'post_migrator_allow_push';

	/**
	 * Check whether this site allows other sites to pull content from it.
	 *
	 * @return bool
	 */
	public static function pull_allowed(): bool {
		return (bool) get_option( self::ALLOW_PULL_OPTION, false );
	}

	/**
	 * Check whether this site allows other sites to push content to it.
	 *
	 * @return bool
	 */
	public static function push_allowed(): bool {
		return (bool) get_option( self::ALLOW_PUSH_OPTION, false );
	}

	/**
	 * Save whether pulling and pushing are allowed.
	 *
	 * @param bool $allow_pull Whether to allow other sites to pull content from this site.
	 * @param bool $allow_push Whether to allow other sites to push content to this site.
	 * @return void
	 */
	public static function save( bool $allow_pull, bool $allow_push ) {
		update_option( self::ALLOW_PULL_OPTION, $allow_pull, false );
		update_option( self::ALLOW_PUSH_OPTION, $allow_push, false );
	}
}
