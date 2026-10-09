<?php
/**
 * Class Content Matcher
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Finds existing posts on this (destination) site that correspond to posts being pushed to it.
 */
class Content_Matcher {
	/**
	 * Match a batch of origin posts against this site's content.
	 *
	 * For each item, checks in priority order: origin-tracking meta (set by a
	 * previous push from the same origin site), then an exact slug match, then
	 * (only if neither of those hit) a handful of weak title suggestions.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $items       Items to match, each with origin_id, post_type, slug, title.
	 * @return array Map of origin post ID to match data.
	 */
	public static function match_batch( string $origin_site, array $items ): array {
		$grouped = array();

		foreach ( $items as $item ) {
			$grouped[ $item['post_type'] ][] = $item;
		}

		$results = array();

		foreach ( $grouped as $post_type => $group ) {
			if ( ! post_type_exists( $post_type ) ) {
				foreach ( $group as $item ) {
					$results[ $item['origin_id'] ] = array(
						'post_type_registered' => false,
						'origin_match'         => null,
						'slug_match'           => null,
						'title_matches'        => array(),
					);
				}

				continue;
			}

			$origin_map = Post_Origin::find_by_origin( $origin_site, wp_list_pluck( $group, 'origin_id' ), $post_type );

			$needs_slug_lookup = array();

			foreach ( $group as $item ) {
				if ( ! isset( $origin_map[ $item['origin_id'] ] ) ) {
					$needs_slug_lookup[] = $item['slug'];
				}
			}

			$slug_map = self::find_by_slug( $needs_slug_lookup, $post_type );

			foreach ( $group as $item ) {
				$origin_id = $item['origin_id'];

				if ( isset( $origin_map[ $origin_id ] ) ) {
					$results[ $origin_id ] = array(
						'post_type_registered' => true,
						'origin_match'         => self::post_summary( $origin_map[ $origin_id ] ),
						'slug_match'           => null,
						'title_matches'        => array(),
					);

					continue;
				}

				if ( isset( $slug_map[ $item['slug'] ] ) ) {
					$results[ $origin_id ] = array(
						'post_type_registered' => true,
						'origin_match'         => null,
						'slug_match'           => self::post_summary( $slug_map[ $item['slug'] ] ),
						'title_matches'        => array(),
					);

					continue;
				}

				$results[ $origin_id ] = array(
					'post_type_registered' => true,
					'origin_match'         => null,
					'slug_match'           => null,
					'title_matches'        => self::find_by_title( $item['title'], $post_type ),
				);
			}
		}

		return $results;
	}

	/**
	 * Search this site's content of a given post type, for manual match override.
	 *
	 * A purely numeric search is treated as an exact post ID lookup first;
	 * otherwise this searches title/content/excerpt, supplemented by a slug
	 * substring search, deduped.
	 *
	 * @param string $post_type The post type to search within.
	 * @param string $search    The search term.
	 * @param int    $per_page  The maximum number of results to return.
	 * @return array
	 */
	public static function search( string $post_type, string $search, int $per_page = 20 ): array {
		$per_page = min( max( $per_page, 1 ), 50 );

		if ( '' === $search ) {
			return array();
		}

		if ( ctype_digit( $search ) ) {
			$post = get_post( (int) $search );

			if ( $post && $post_type === $post->post_type ) {
				return array( self::search_result( $post ) );
			}
		}

		$results = array();
		$seen    = array();

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => $per_page,
				's'                      => $search,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post ) {
			$results[]         = self::search_result( $post );
			$seen[ $post->ID ] = true;
		}

		if ( count( $results ) < $per_page ) {
			global $wpdb;

			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$remaining = $per_page - count( $results );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WP_Query equivalent for a partial post_name match.
			$slug_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name LIKE %s LIMIT %d",
					$post_type,
					$like,
					$remaining
				)
			);

			foreach ( $slug_ids as $slug_id ) {
				$slug_id = (int) $slug_id;

				if ( isset( $seen[ $slug_id ] ) ) {
					continue;
				}

				$post = get_post( $slug_id );

				if ( $post ) {
					$results[]        = self::search_result( $post );
					$seen[ $slug_id ] = true;
				}
			}
		}

		return $results;
	}

	/**
	 * Find posts of a given post type with slugs exactly matching any of the given slugs.
	 *
	 * @param array  $slugs     The slugs to look up.
	 * @param string $post_type The post type to search within.
	 * @return array Map of slug to post ID.
	 */
	private static function find_by_slug( array $slugs, string $post_type ): array {
		$slugs = array_values( array_unique( array_filter( $slugs ) ) );

		if ( empty( $slugs ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post_name__in'  => $slugs,
			)
		);

		$map = array();

		foreach ( $posts as $post ) {
			$map[ $post->post_name ] = $post->ID;
		}

		return $map;
	}

	/**
	 * Find a small number of posts whose title loosely matches the given title.
	 *
	 * @param string $title     The title to search for.
	 * @param string $post_type The post type to search within.
	 * @param int    $limit     The maximum number of suggestions to return.
	 * @return array
	 */
	private static function find_by_title( string $title, string $post_type, int $limit = 5 ): array {
		if ( '' === $title ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				's'                      => $title,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( array( self::class, 'search_result' ), $query->posts );
	}

	/**
	 * Build the summary shape returned for an origin or slug match.
	 *
	 * @param int $post_id The matched post's ID.
	 * @return array
	 */
	private static function post_summary( int $post_id ): array {
		return array(
			'destination_id' => $post_id,
			'title'          => get_the_title( $post_id ),
			'status'         => get_post_status( $post_id ),
		);
	}

	/**
	 * Build the result shape returned for a search/suggestion match.
	 *
	 * @param \WP_Post $post The matched post.
	 * @return array
	 */
	private static function search_result( \WP_Post $post ): array {
		return array(
			'destination_id' => $post->ID,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'post_type'      => $post->post_type,
		);
	}
}
