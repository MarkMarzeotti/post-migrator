<?php
/**
 * Class Term Origin
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Tags destination terms with where they came from, and looks them back up
 * by that origin. Mirrors Post_Origin, but for taxonomy terms.
 */
class Term_Origin {
	/**
	 * Term meta key used to store the origin term ID.
	 *
	 * @var string
	 */
	public const ORIGIN_ID_META_KEY = '_post_migrator_origin_term_id';

	/**
	 * Term meta key used to store the origin site URL.
	 *
	 * @var string
	 */
	public const ORIGIN_SITE_META_KEY = '_post_migrator_origin_term_site';

	/**
	 * Tag a destination term with the origin site and term ID it came from.
	 *
	 * @param int    $term_id     The destination term ID.
	 * @param int    $origin_id   The term ID on the origin site.
	 * @param string $origin_site The origin site's URL.
	 * @return void
	 */
	public static function tag( int $term_id, int $origin_id, string $origin_site ) {
		update_term_meta( $term_id, self::ORIGIN_ID_META_KEY, $origin_id );
		update_term_meta( $term_id, self::ORIGIN_SITE_META_KEY, Post_Origin::normalize_site_url( $origin_site ) );
	}

	/**
	 * Find destination terms previously tagged with the given origin site and
	 * term IDs, within a specific taxonomy.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $origin_ids  The origin term IDs to look up.
	 * @param string $taxonomy    The taxonomy to search within.
	 * @return array Map of origin term ID to destination term ID.
	 */
	public static function find_by_origin( string $origin_site, array $origin_ids, string $taxonomy ): array {
		if ( empty( $origin_ids ) ) {
			return array();
		}

		$destination_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to look up terms by origin tracking meta.
					array(
						'key'   => self::ORIGIN_SITE_META_KEY,
						'value' => Post_Origin::normalize_site_url( $origin_site ),
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

		foreach ( (array) $destination_ids as $destination_id ) {
			$origin_id = (int) get_term_meta( (int) $destination_id, self::ORIGIN_ID_META_KEY, true );

			if ( 0 !== $origin_id ) {
				$map[ $origin_id ] = (int) $destination_id;
			}
		}

		return $map;
	}
}
