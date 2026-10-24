<?php
/**
 * Class Post Terms Writer
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Resolves and assigns a pushed/pulled post's taxonomy terms on this
 * (destination) site, creating missing terms (and their ancestor chain) as
 * needed, and replacing each taxonomy's object-term assignment wholesale so
 * re-pushing/re-pulling converges to the source's current term set.
 */
class Post_Terms_Writer {
	/**
	 * Per-batch cache of "<taxonomy>:<source_term_id>" to destination term ID.
	 *
	 * @var array<string, int>
	 */
	private static $term_cache = array();

	/**
	 * Clear the per-batch term resolution cache.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$term_cache = array();
	}

	/**
	 * Resolve and assign a post's packaged terms.
	 *
	 * @param int    $post_id            The destination post ID.
	 * @param array  $terms_by_taxonomy  Packaged terms, keyed by taxonomy, as built by Post_Packager::package_terms().
	 * @param string $origin_site        The origin site's URL.
	 * @return void
	 */
	public static function write( int $post_id, array $terms_by_taxonomy, string $origin_site ) {
		foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No results-UI channel exists yet to surface a skipped taxonomy.
				error_log( sprintf( '[Post Migrator] Skipped taxonomy "%1$s": not registered on this site.', $taxonomy ) );

				continue;
			}

			self::write_taxonomy( $post_id, $taxonomy, $terms, $origin_site );
		}
	}

	/**
	 * Resolve and assign every term packaged for a single taxonomy.
	 *
	 * @param int    $post_id     The destination post ID.
	 * @param string $taxonomy    The taxonomy.
	 * @param array  $terms       Term payloads for this taxonomy, root-first.
	 * @param string $origin_site The origin site's URL.
	 * @return void
	 */
	private static function write_taxonomy( int $post_id, string $taxonomy, array $terms, string $origin_site ) {
		$origin_ids = array();

		foreach ( $terms as $term ) {
			if ( is_array( $term ) && isset( $term['source_term_id'] ) ) {
				$origin_ids[] = (int) $term['source_term_id'];
			}
		}

		$origin_matches = Term_Origin::find_by_origin( $origin_site, $origin_ids, $taxonomy );

		$resolved          = array();
		$destination_terms = array();

		foreach ( $terms as $term ) {
			if ( ! is_array( $term ) || ! isset( $term['source_term_id'] ) ) {
				continue;
			}

			$source_term_id = (int) $term['source_term_id'];
			$destination_id = self::resolve_term(
				$source_term_id,
				$term,
				$taxonomy,
				$origin_site,
				$origin_matches,
				$resolved
			);

			if ( $destination_id <= 0 ) {
				continue;
			}

			$resolved[ $source_term_id ] = $destination_id;

			self::$term_cache[ $taxonomy . ':' . $source_term_id ] = $destination_id;

			if ( ! empty( $term['directly_assigned'] ) ) {
				$destination_terms[] = $destination_id;
			}
		}

		wp_set_object_terms( $post_id, array_values( array_unique( $destination_terms ) ), $taxonomy, false );
	}

	/**
	 * Resolve a single term payload to a destination term ID, in priority
	 * order: per-batch cache, origin-tag match, slug fallback, create.
	 *
	 * @param int    $source_term_id The term's ID on the origin site.
	 * @param array  $term           The term payload.
	 * @param string $taxonomy       The taxonomy.
	 * @param string $origin_site    The origin site's URL.
	 * @param array  $origin_matches Batched origin-tag matches for this taxonomy.
	 * @param array  $resolved       Source-to-destination map built so far this call, for resolving parents.
	 * @return int The destination term ID, or 0 if it could not be resolved/created.
	 */
	private static function resolve_term( int $source_term_id, array $term, string $taxonomy, string $origin_site, array $origin_matches, array $resolved ): int {
		$cache_key = $taxonomy . ':' . $source_term_id;

		if ( isset( self::$term_cache[ $cache_key ] ) ) {
			return self::$term_cache[ $cache_key ];
		}

		if ( isset( $origin_matches[ $source_term_id ] ) ) {
			Term_Origin::tag( $origin_matches[ $source_term_id ], $source_term_id, $origin_site );

			return $origin_matches[ $source_term_id ];
		}

		$slug     = isset( $term['slug'] ) ? sanitize_title( (string) $term['slug'] ) : '';
		$existing = '' !== $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;

		if ( $existing instanceof \WP_Term ) {
			Term_Origin::tag( $existing->term_id, $source_term_id, $origin_site );

			return $existing->term_id;
		}

		$source_parent_id      = isset( $term['source_parent_term_id'] ) ? (int) $term['source_parent_term_id'] : 0;
		$destination_parent_id = 0;

		if ( $source_parent_id > 0 ) {
			if ( isset( $resolved[ $source_parent_id ] ) ) {
				$destination_parent_id = $resolved[ $source_parent_id ];
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No results-UI channel exists yet to surface a malformed/truncated term payload.
				error_log( sprintf( '[Post Migrator] Term %1$d (taxonomy %2$s) referenced parent %3$d, which was not resolved first -- creating as a top-level term.', $source_term_id, $taxonomy, $source_parent_id ) );
			}
		}

		// A valid connection key is treated as full site-level trust (see
		// class-post-writer.php), so a pushed term's name/description are
		// written exactly as sent rather than silently stripped by kses.
		kses_remove_filters();

		try {
			$result = wp_insert_term(
				isset( $term['name'] ) ? (string) $term['name'] : $slug,
				$taxonomy,
				array(
					'slug'        => $slug,
					'description' => isset( $term['description'] ) ? (string) $term['description'] : '',
					'parent'      => $destination_parent_id,
				)
			);
		} finally {
			kses_init_filters();
		}

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No results-UI channel exists yet to surface a failed term creation.
			error_log( sprintf( '[Post Migrator] Failed to create term "%1$s" (taxonomy %2$s): %3$s', $slug, $taxonomy, $result->get_error_message() ) );

			return 0;
		}

		$new_term_id = (int) $result['term_id'];

		Term_Origin::tag( $new_term_id, $source_term_id, $origin_site );

		return $new_term_id;
	}
}
