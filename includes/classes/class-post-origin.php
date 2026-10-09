<?php
/**
 * Class Post Origin
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Tags destination posts with where they came from, and looks them back up by that origin.
 */
class Post_Origin {
	/**
	 * Post meta key used to store the origin post ID.
	 *
	 * @var string
	 */
	public const ORIGIN_ID_META_KEY = '_post_migrator_origin_id';

	/**
	 * Post meta key used to store the origin site URL.
	 *
	 * @var string
	 */
	public const ORIGIN_SITE_META_KEY = '_post_migrator_origin_site';

	/**
	 * Tag a destination post with the origin site and post ID it came from.
	 *
	 * @param int    $post_id     The destination post ID.
	 * @param int    $origin_id   The post ID on the origin site.
	 * @param string $origin_site The origin site's URL.
	 * @return void
	 */
	public static function tag( int $post_id, int $origin_id, string $origin_site ) {
		update_post_meta( $post_id, self::ORIGIN_ID_META_KEY, $origin_id );
		update_post_meta( $post_id, self::ORIGIN_SITE_META_KEY, self::normalize_site_url( $origin_site ) );
	}

	/**
	 * Normalize a site URL so origin comparisons aren't tripped up by trailing
	 * slashes or inconsistent casing.
	 *
	 * @param string $url The site URL to normalize.
	 * @return string
	 */
	public static function normalize_site_url( string $url ): string {
		return untrailingslashit( strtolower( $url ) );
	}

	/**
	 * Find destination posts previously tagged with the given origin site and
	 * post IDs, for a specific post type.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $origin_ids  The origin post IDs to look up.
	 * @param string $post_type   The post type to search within.
	 * @return array Map of origin post ID to destination post ID.
	 */
	public static function find_by_origin( string $origin_site, array $origin_ids, string $post_type ): array {
		if ( empty( $origin_ids ) ) {
			return array();
		}

		$destination_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to look up posts by origin tracking meta.
					array(
						'key'   => self::ORIGIN_SITE_META_KEY,
						'value' => self::normalize_site_url( $origin_site ),
					),
					array(
						'key'     => self::ORIGIN_ID_META_KEY,
						'value'   => $origin_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$map = array();

		foreach ( $destination_ids as $destination_id ) {
			$origin_id = (int) get_post_meta( $destination_id, self::ORIGIN_ID_META_KEY, true );

			if ( 0 !== $origin_id ) {
				$map[ $origin_id ] = (int) $destination_id;
			}
		}

		return $map;
	}
}
