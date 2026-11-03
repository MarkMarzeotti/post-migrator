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

	/**
	 * Arrange a flat list of items into display order for the Select step's
	 * table -- parent-before-children, with a depth for indentation -- when
	 * the post type is hierarchical, matching the convention WordPress
	 * core's own Pages admin list uses. Returned unchanged, each at depth
	 * 0, for a non-hierarchical post type.
	 *
	 * An item whose parent isn't present in $items (filtered out by the
	 * current status/search filter, or simply off this page of results) is
	 * treated as a new root at the depth where its nearest present
	 * ancestor leaves off -- the same simplification core's own
	 * WP_Posts_List_Table falls back to.
	 *
	 * @param array  $items     Items, each an array with at least 'id' and 'parent' keys.
	 * @param string $post_type The items' post type.
	 * @return array List of array( 'item' => original item, 'depth' => int ), in display order.
	 */
	public static function sort_hierarchically( array $items, string $post_type ): array {
		if ( ! is_post_type_hierarchical( $post_type ) ) {
			return array_map(
				function ( $item ) {
					return array(
						'item'  => $item,
						'depth' => 0,
					);
				},
				$items
			);
		}

		$children = array();
		$top      = array();

		foreach ( $items as $item ) {
			$parent_id = (int) $item['parent'];

			if ( $parent_id > 0 ) {
				$children[ $parent_id ][] = $item;
			} else {
				$top[] = $item;
			}
		}

		$ordered = array();
		$visited = array();

		self::walk_hierarchy( $top, $children, 0, $ordered, $visited );

		foreach ( $items as $item ) {
			if ( ! isset( $visited[ (int) $item['id'] ] ) ) {
				self::walk_hierarchy( array( $item ), $children, 0, $ordered, $visited );
			}
		}

		return $ordered;
	}

	/**
	 * Recursively walk a hierarchy level, appending each item (and its
	 * children, depth-first) to $ordered.
	 *
	 * @param array $items    Items at this level.
	 * @param array $children Map of parent ID to that parent's child items.
	 * @param int   $depth    This level's depth.
	 * @param array $ordered  Accumulator, by reference.
	 * @param array $visited  Set of already-placed item IDs, by reference.
	 * @return void
	 */
	private static function walk_hierarchy( array $items, array $children, int $depth, array &$ordered, array &$visited ) {
		foreach ( $items as $item ) {
			$id = (int) $item['id'];

			if ( isset( $visited[ $id ] ) ) {
				continue;
			}

			$visited[ $id ] = true;
			$ordered[]      = array(
				'item'  => $item,
				'depth' => $depth,
			);

			if ( isset( $children[ $id ] ) ) {
				self::walk_hierarchy( $children[ $id ], $children, $depth + 1, $ordered, $visited );
			}
		}
	}
}
