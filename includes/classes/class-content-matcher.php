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
		$grouped = self::group_by_post_type( $items );
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

			$confident = self::match_confident( $origin_site, $group, $post_type );

			foreach ( $group as $item ) {
				$origin_id = $item['origin_id'];
				$match     = $confident[ $origin_id ] ?? null;

				if ( null !== $match ) {
					$results[ $origin_id ] = array(
						'post_type_registered' => true,
						'origin_match'         => 'origin' === $match['via'] ? self::post_summary( $match['destination_id'] ) : null,
						'slug_match'           => 'slug' === $match['via'] ? self::post_summary( $match['destination_id'] ) : null,
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
	 * Find confident matches (origin-tag, then slug) only, skipping the
	 * title-suggestion fallback -- used by the diff feature, which has no
	 * post to diff against from a mere suggestion.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $items       Items to match, each with origin_id, post_type, slug.
	 * @return array Map of origin post ID to array( 'destination_id' => int, 'via' => 'origin'|'slug' ).
	 */
	public static function find_confident_matches( string $origin_site, array $items ): array {
		$results = array();

		foreach ( self::group_by_post_type( $items ) as $post_type => $group ) {
			if ( post_type_exists( $post_type ) ) {
				$results += self::match_confident( $origin_site, $group, $post_type );
			}
		}

		return $results;
	}

	/**
	 * Diff a batch of origin posts against this (destination) site's
	 * matching content, for the Select step's "Changes" column.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $items       Items to diff, each with origin_id, post_type, slug, content_hash, modified_gmt (the latter two describing the caller's own post).
	 * @return array Map of origin post ID to array( 'state' => string, 'destination_id' => int|null ).
	 */
	public static function diff_batch( string $origin_site, array $items ): array {
		$matches = self::find_confident_matches( $origin_site, $items );

		$destination_ids = array_values( wp_list_pluck( $matches, 'destination_id' ) );

		$destination_posts = empty( $destination_ids ) ? array() : get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'include'        => $destination_ids,
			)
		);

		$destination_by_id = array();

		foreach ( $destination_posts as $post ) {
			$destination_by_id[ $post->ID ] = $post;
		}

		$hashes  = Content_Hasher::hash_batch( $destination_posts );
		$results = array();

		foreach ( $items as $item ) {
			$match            = $matches[ $item['origin_id'] ] ?? null;
			$destination_id   = null !== $match ? $match['destination_id'] : null;
			$destination_post = null !== $destination_id ? ( $destination_by_id[ $destination_id ] ?? null ) : null;
			$destination_hash = $destination_post ? ( $hashes[ $destination_post->ID ] ?? null ) : null;
			$destination_mod  = $destination_post ? $destination_post->post_modified_gmt : null;

			$results[ $item['origin_id'] ] = array(
				'state'          => Content_Hasher::classify( $destination_hash, $item['content_hash'], $destination_mod, $item['modified_gmt'] ),
				'destination_id' => $destination_id,
			);
		}

		return $results;
	}

	/**
	 * Group a batch of items by post_type.
	 *
	 * @param array $items Items, each with at least a post_type key.
	 * @return array Map of post_type to its items.
	 */
	private static function group_by_post_type( array $items ): array {
		$grouped = array();

		foreach ( $items as $item ) {
			$grouped[ $item['post_type'] ][] = $item;
		}

		return $grouped;
	}

	/**
	 * Find confident matches (origin-tag, then slug) for one post_type group.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $group       Items of a single post_type, each with origin_id, slug.
	 * @param string $post_type   The post type.
	 * @return array Map of origin post ID to array( 'destination_id' => int, 'via' => 'origin'|'slug' ).
	 */
	private static function match_confident( string $origin_site, array $group, string $post_type ): array {
		$origin_map = Post_Origin::find_by_origin( $origin_site, wp_list_pluck( $group, 'origin_id' ), $post_type );

		$needs_slug_lookup = array();

		foreach ( $group as $item ) {
			if ( ! isset( $origin_map[ $item['origin_id'] ] ) ) {
				$needs_slug_lookup[] = $item['slug'];
			}
		}

		$slug_map = self::find_by_slug( $needs_slug_lookup, $post_type );
		$matches  = array();

		foreach ( $group as $item ) {
			$origin_id = $item['origin_id'];

			if ( isset( $origin_map[ $origin_id ] ) ) {
				$matches[ $origin_id ] = array(
					'destination_id' => $origin_map[ $origin_id ],
					'via'            => 'origin',
				);
			} elseif ( isset( $slug_map[ $item['slug'] ] ) ) {
				$matches[ $origin_id ] = array(
					'destination_id' => $slug_map[ $item['slug'] ],
					'via'            => 'slug',
				);
			}
		}

		return $matches;
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
