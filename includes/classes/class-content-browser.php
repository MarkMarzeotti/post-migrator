<?php
/**
 * Class Content Browser
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Queries this site's own content for a migration selection step -- used
 * directly for push's local select step, and indirectly for pull's select
 * step via a REST request that runs this same query on the remote site.
 */
class Content_Browser {
	/**
	 * Number of posts returned per query.
	 *
	 * @var int
	 */
	public const POSTS_PER_PAGE = 50;

	/**
	 * Get the public, browsable post types, keyed by slug.
	 *
	 * @return array
	 */
	public static function get_browsable_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		$options = array();

		foreach ( $post_types as $slug => $object ) {
			$options[ $slug ] = $object->labels->name;
		}

		return $options;
	}

	/**
	 * Query this site's own posts for a migration selection step.
	 *
	 * @param string $post_type The post type to query.
	 * @param string $status    The status to filter by, or '' for any.
	 * @param string $search    The search term, or '' for none.
	 * @return \WP_Query
	 */
	public static function query_posts( string $post_type, string $status, string $search ): \WP_Query {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => '' !== $status ? $status : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => self::POSTS_PER_PAGE,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return new \WP_Query( $args );
	}
}
