<?php
/**
 * Class Meta Exclusion
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Decides which post meta keys are left out of a wholesale meta copy.
 */
class Meta_Exclusion {
	/**
	 * Meta keys never copied wholesale from the source post to the destination post.
	 *
	 * @var string[]
	 */
	private const DEFAULT_EXCLUSIONS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
	);

	/**
	 * Whether a meta key should be excluded from a wholesale copy.
	 *
	 * @param string $meta_key The meta key.
	 * @return bool
	 */
	public static function is_excluded( string $meta_key ): bool {
		$exclusions = apply_filters( 'post_migrator_meta_exclusions', self::default_exclusions() );

		$excluded = in_array( $meta_key, $exclusions, true ) || 0 === strpos( $meta_key, '_wp_old_' );

		return (bool) apply_filters( 'post_migrator_exclude_meta_key', $excluded, $meta_key );
	}

	/**
	 * The default exclusion list, including this plugin's own origin-tracking keys.
	 *
	 * These must never be wholesale-copied: doing so would overwrite the
	 * destination post's own freshly-written origin tag with the source
	 * post's (which, if the source post was itself previously migrated from
	 * a third site, would wrongly point at that third site instead).
	 *
	 * @return string[]
	 */
	private static function default_exclusions(): array {
		return array_merge(
			self::DEFAULT_EXCLUSIONS,
			array(
				Post_Origin::ORIGIN_ID_META_KEY,
				Post_Origin::ORIGIN_SITE_META_KEY,
			)
		);
	}
}
