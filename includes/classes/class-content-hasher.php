<?php
/**
 * Class Content Hasher
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Computes a deterministic content hash for a post, covering everything
 * this plugin migrates (core fields, meta, terms, immediate parent), and
 * classifies a source/destination pair's diff state for the Select step's
 * "Changes" column.
 */
class Content_Hasher {
	/**
	 * Diff state: no matching destination post exists at all.
	 *
	 * @var string
	 */
	public const STATE_NEW = 'new';

	/**
	 * Diff state: source and destination content hashes are identical.
	 *
	 * @var string
	 */
	public const STATE_UNCHANGED = 'unchanged';

	/**
	 * Diff state: content differs, and the source is at least as new as the destination.
	 *
	 * @var string
	 */
	public const STATE_READY = 'ready';

	/**
	 * Diff state: content differs, and the destination has independent/newer changes.
	 *
	 * @var string
	 */
	public const STATE_CONFLICT = 'conflict';

	/**
	 * Compute content hashes for a batch of already-queried posts.
	 *
	 * Callers should pass posts from a WP_Query/get_posts() call that left
	 * meta/term caches primed (the default), so the per-post segment
	 * builders' get_post_meta()/wp_get_post_terms() calls are cache hits,
	 * not new queries. The one query that isn't free this way -- looking up
	 * each post's immediate parent for the parent segment -- is pre-warmed
	 * here in a single batched call.
	 *
	 * @param \WP_Post[] $posts The posts to hash.
	 * @return array Map of post ID to content hash.
	 */
	public static function hash_batch( array $posts ): array {
		self::prime_parents( $posts );

		$hashes = array();

		foreach ( $posts as $post ) {
			$hashes[ $post->ID ] = self::hash( $post );
		}

		return $hashes;
	}

	/**
	 * Compute one post's content hash from its ordered, named segments.
	 *
	 * @param \WP_Post $post The post to hash.
	 * @return string
	 */
	public static function hash( \WP_Post $post ): string {
		$parts = array();

		foreach ( self::segments() as $name => $callable ) {
			$parts[] = $name . ':' . hash( 'sha256', (string) call_user_func( $callable, $post ) );
		}

		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Classify a source/destination pair into one of the four diff states.
	 *
	 * @param string|null $destination_hash         The destination post's current content hash, or null if no destination match exists.
	 * @param string      $source_hash               The source post's current content hash.
	 * @param string|null $destination_modified_gmt  The destination post's current post_modified_gmt, or null if no destination match exists.
	 * @param string      $source_modified_gmt       The source post's current post_modified_gmt.
	 * @return string One of the STATE_* constants.
	 */
	public static function classify( ?string $destination_hash, string $source_hash, ?string $destination_modified_gmt, string $source_modified_gmt ): string {
		if ( null === $destination_hash ) {
			return self::STATE_NEW;
		}

		if ( hash_equals( $destination_hash, $source_hash ) ) {
			return self::STATE_UNCHANGED;
		}

		if ( null !== $destination_modified_gmt && $destination_modified_gmt > $source_modified_gmt ) {
			return self::STATE_CONFLICT;
		}

		return self::STATE_READY;
	}

	/**
	 * Ordered, named, filterable list of segment-builder callables. Each
	 * receives a WP_Post and returns a string. Filterable so a future change
	 * to what's batched/hashed is a small, localized edit rather than a
	 * redesign of this class.
	 *
	 * @return array Map of segment name to callable.
	 */
	private static function segments(): array {
		return apply_filters(
			'post_migrator_content_hash_segments',
			array(
				'core_fields' => array( self::class, 'hash_core_fields' ),
				'meta'        => array( self::class, 'hash_meta' ),
				'terms'       => array( self::class, 'hash_terms' ),
				'parent'      => array( self::class, 'hash_parent' ),
			)
		);
	}

	/**
	 * Core editorial fields segment.
	 *
	 * @param \WP_Post $post The post to hash.
	 * @return string
	 */
	private static function hash_core_fields( \WP_Post $post ): string {
		return $post->post_title . "\x1f" . $post->post_content . "\x1f" . $post->post_excerpt;
	}

	/**
	 * Post meta segment. Reuses Post_Packager::package_meta() directly, so
	 * this hash is based on exactly the meta that actually migrates (already
	 * Meta_Exclusion-filtered, already correctly reconstructed for
	 * array-valued meta) -- no duplicated logic.
	 *
	 * @param \WP_Post $post The post to hash.
	 * @return string
	 */
	private static function hash_meta( \WP_Post $post ): string {
		$meta = Post_Packager::package_meta( $post );
		ksort( $meta );

		return (string) wp_json_encode( $meta );
	}

	/**
	 * Taxonomy terms segment. Assigned term slugs (not IDs, which aren't
	 * meaningful cross-site), sorted, keyed by taxonomy.
	 *
	 * @param \WP_Post $post The post to hash.
	 * @return string
	 */
	private static function hash_terms( \WP_Post $post ): string {
		$by_taxonomy = array();

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$slugs = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );

			if ( is_array( $slugs ) && ! empty( $slugs ) ) {
				sort( $slugs );
				$by_taxonomy[ $taxonomy ] = $slugs;
			}
		}

		ksort( $by_taxonomy );

		return (string) wp_json_encode( $by_taxonomy );
	}

	/**
	 * Immediate parent segment, for hierarchical post types only -- same
	 * scope as Post_Parent_Writer. Hashes the parent's own identity
	 * (post_type/slug/title), never its raw ID, which isn't meaningful
	 * cross-site.
	 *
	 * @param \WP_Post $post The post to hash.
	 * @return string
	 */
	private static function hash_parent( \WP_Post $post ): string {
		if ( ! is_post_type_hierarchical( $post->post_type ) || $post->post_parent <= 0 ) {
			return '';
		}

		$parent = get_post( $post->post_parent );

		return $parent ? $parent->post_type . "\x1f" . $parent->post_name . "\x1f" . $parent->post_title : '';
	}

	/**
	 * Warm the post cache for every distinct immediate parent referenced by
	 * a batch, in one query, so hash_parent()'s get_post() calls are cache
	 * hits rather than one query per row.
	 *
	 * @param \WP_Post[] $posts The posts about to be hashed.
	 * @return void
	 */
	private static function prime_parents( array $posts ) {
		$parent_ids = array();

		foreach ( $posts as $post ) {
			if ( is_post_type_hierarchical( $post->post_type ) && $post->post_parent > 0 ) {
				$parent_ids[] = $post->post_parent;
			}
		}

		$parent_ids = array_values( array_unique( $parent_ids ) );

		if ( empty( $parent_ids ) ) {
			return;
		}

		get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'include'        => $parent_ids,
			)
		);
	}
}
