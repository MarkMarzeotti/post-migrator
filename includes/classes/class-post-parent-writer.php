<?php
/**
 * Class Post Parent Writer
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Resolves and assigns a pushed/pulled post's post_parent on this
 * (destination) site, for hierarchical post types only.
 *
 * Runs as a second pass over the whole batch, after every item has already
 * been created/updated and Post_Origin::tag()'d by Post_Writer::apply_batch()'s
 * main loop. This -- rather than resolving inline per-item, as
 * Post_Terms_Writer does for terms -- is what lets a parent and child pushed
 * in the *same* batch resolve correctly regardless of which order they
 * happen to appear in: by the time this pass runs, every item's destination
 * ID already exists, so a parent only just created earlier in this same
 * batch is found via the in-batch map below. Multi-level chains
 * (grandparent/parent/child all new, any order) fall out of this for free,
 * with no topological sort needed.
 *
 * Deliberately does not auto-create or auto-include a parent the user never
 * selected -- same lookup-only philosophy as Reference_Resolver's
 * post_object/taxonomy reference resolution. An unresolvable parent is
 * logged and the item is left/forced top-level rather than blocking the
 * rest of the batch.
 */
class Post_Parent_Writer {
	/**
	 * Resolve and write post_parent for every hierarchical-post-type item in
	 * a just-applied batch.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $items       The batch's input items, as passed to Post_Writer::apply_batch(), index-aligned with $results.
	 * @param array  $results     The batch's per-item outcomes, as built by Post_Writer::apply_batch()'s main loop.
	 * @return void
	 */
	public static function write_batch( string $origin_site, array $items, array $results ) {
		$items = array_values( $items );

		$pending      = array();
		$in_batch_map = array();

		foreach ( $items as $index => $item ) {
			$result = $results[ $index ] ?? null;

			if ( ! is_array( $result ) || empty( $result['destination_id'] ) || ! empty( $result['error'] ) ) {
				continue;
			}

			$origin_id      = isset( $result['origin_id'] ) ? (int) $result['origin_id'] : 0;
			$destination_id = (int) $result['destination_id'];

			if ( $origin_id > 0 ) {
				$in_batch_map[ $origin_id ] = $destination_id;
			}

			$post      = is_array( $item['post'] ?? null ) ? $item['post'] : array();
			$post_type = isset( $post['post_type'] ) ? sanitize_key( $post['post_type'] ) : '';

			if ( '' === $post_type || ! post_type_exists( $post_type ) || ! is_post_type_hierarchical( $post_type ) ) {
				continue;
			}

			$pending[] = array(
				'destination_id'          => $destination_id,
				'source_parent_post_id'   => isset( $post['source_parent_post_id'] ) ? (int) $post['source_parent_post_id'] : 0,
				'source_parent_post_type' => isset( $post['source_parent_post_type'] ) ? sanitize_key( $post['source_parent_post_type'] ) : '',
			);
		}

		if ( empty( $pending ) ) {
			return;
		}

		$needed_by_type = array();

		foreach ( $pending as $entry ) {
			$source_parent_id = $entry['source_parent_post_id'];

			if ( $source_parent_id <= 0 || isset( $in_batch_map[ $source_parent_id ] ) ) {
				continue;
			}

			$needed_by_type[ $entry['source_parent_post_type'] ][] = $source_parent_id;
		}

		$resolved_map = $in_batch_map;

		foreach ( $needed_by_type as $post_type => $source_ids ) {
			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			// '+=' preserves $in_batch_map's values on key collision -- a
			// same-batch match should always win over a stale prior-run tag.
			$resolved_map += Post_Origin::find_by_origin( $origin_site, array_values( array_unique( $source_ids ) ), $post_type );
		}

		foreach ( $pending as $entry ) {
			self::apply_parent( $entry, $resolved_map, $origin_site );
		}
	}

	/**
	 * Resolve and write a single item's post_parent.
	 *
	 * @param array  $entry        {destination_id, source_parent_post_id, source_parent_post_type}.
	 * @param array  $resolved_map Map of origin post ID to destination post ID, combining this batch's own results with any prior-migration matches.
	 * @param string $origin_site  The origin site's URL.
	 * @return void
	 */
	private static function apply_parent( array $entry, array $resolved_map, string $origin_site ) {
		$destination_id   = $entry['destination_id'];
		$source_parent_id = $entry['source_parent_post_id'];

		// A source_parent_post_id of 0 is written explicitly, not skipped --
		// wholesale-replace convergence semantics (same as how
		// Post_Terms_Writer always calls wp_set_object_terms() even with an
		// empty array), so detaching a parent on the source converges on re-push.
		if ( $source_parent_id <= 0 ) {
			wp_update_post(
				array(
					'ID'          => $destination_id,
					'post_parent' => 0,
				)
			);

			return;
		}

		if ( isset( $resolved_map[ $source_parent_id ] ) ) {
			wp_update_post(
				array(
					'ID'          => $destination_id,
					'post_parent' => $resolved_map[ $source_parent_id ],
				)
			);

			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No results-UI channel exists yet to surface an unresolved parent.
		error_log( sprintf( '[Post Migrator] Could not resolve post_parent %1$d (post_type %2$s) from %3$s for destination post %4$d: parent has not been migrated to this site. Left as a top-level post.', $source_parent_id, $entry['source_parent_post_type'], $origin_site, $destination_id ) );

		wp_update_post(
			array(
				'ID'          => $destination_id,
				'post_parent' => 0,
			)
		);
	}
}
