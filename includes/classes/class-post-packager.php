<?php
/**
 * Class Post Packager
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Builds the transferable payload for a post, used by whichever site is
 * sending content -- the pushing site when pushing, or the site being
 * pulled from when pulling.
 */
class Post_Packager {
	/**
	 * Package a post into the payload shape expected by Post_Writer::apply_batch().
	 *
	 * @param \WP_Post $post The post to package.
	 * @return array
	 */
	public static function package( \WP_Post $post ): array {
		$author       = get_userdata( $post->post_author );
		$author_login = $author ? $author->user_login : '';

		return array(
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_date_gmt'  => $post->post_date_gmt,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'author_login'   => $author_login,
			// Cast to object so an empty map still encodes as a JSON object, not an array.
			'meta'           => (object) self::package_meta( $post ),
			'attachments'    => (object) self::collect_referenced_attachments( $post ),
			'terms'          => (object) self::package_terms( $post ),
		);
	}

	/**
	 * Collect a post's own meta, minus anything Meta_Exclusion rules out.
	 *
	 * @param \WP_Post $post The post to package meta for.
	 * @return array
	 */
	public static function package_meta( \WP_Post $post ): array {
		$meta = array();

		// get_post_meta( $post_id ) with no key returns every row's value as
		// a raw, un-unserialized string -- unlike the single/multi-key form,
		// which runs each value through maybe_unserialize(). Re-fetching per
		// key (served from the already-primed meta cache, no extra queries)
		// is what correctly reconstructs array-valued meta (e.g. ACF gallery
		// or multi-select fields) instead of packaging their serialized form.
		foreach ( array_keys( get_post_meta( $post->ID ) ) as $meta_key ) {
			$meta_key = (string) $meta_key;

			if ( Meta_Exclusion::is_excluded( $meta_key ) ) {
				continue;
			}

			$meta_values       = get_post_meta( $post->ID, $meta_key, false );
			$meta[ $meta_key ] = count( $meta_values ) > 1 ? $meta_values : $meta_values[0];
		}

		return $meta;
	}

	/**
	 * Package every attachment a post references via its meta or block
	 * content (ACF image/gallery/file fields, the featured image, and
	 * core/image, core/gallery, and acf/* blocks), keyed by source attachment ID.
	 *
	 * @param \WP_Post $post The post to collect referenced attachments for.
	 * @return array
	 */
	public static function collect_referenced_attachments( \WP_Post $post ): array {
		$ids = array_values(
			array_unique(
				array_merge(
					Reference_Resolver::collect_attachment_ids_from_meta( $post ),
					Reference_Resolver::collect_attachment_ids_from_blocks( $post )
				)
			)
		);

		$attachments = array();

		foreach ( $ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$attachments[ (string) $attachment_id ] = Attachment_Packager::package( $attachment );
		}

		return $attachments;
	}

	/**
	 * Package every taxonomy term assigned to a post, plus each assigned
	 * term's full ancestor chain (so hierarchical parents can be
	 * resolved/created on the destination before their children), keyed by
	 * taxonomy and sorted root-first by ancestor depth.
	 *
	 * @param \WP_Post $post The post to collect terms for.
	 * @return array
	 */
	public static function package_terms( \WP_Post $post ): array {
		$terms_by_taxonomy = array();

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$direct_terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );

			if ( ! is_array( $direct_terms ) || empty( $direct_terms ) ) {
				continue;
			}

			$direct_ids = array();

			foreach ( $direct_terms as $term ) {
				$direct_ids[] = $term->term_id;
			}

			$all_terms = array();

			foreach ( $direct_terms as $term ) {
				$all_terms[ $term->term_id ] = $term;

				foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
					if ( isset( $all_terms[ $ancestor_id ] ) ) {
						continue;
					}

					$ancestor = get_term( $ancestor_id, $taxonomy );

					if ( $ancestor instanceof \WP_Term ) {
						$all_terms[ $ancestor_id ] = $ancestor;
					}
				}
			}

			uasort(
				$all_terms,
				function ( \WP_Term $a, \WP_Term $b ) use ( $taxonomy ) {
					return count( get_ancestors( $a->term_id, $taxonomy, 'taxonomy' ) ) <=> count( get_ancestors( $b->term_id, $taxonomy, 'taxonomy' ) );
				}
			);

			$payloads = array();

			foreach ( $all_terms as $term ) {
				$payloads[] = array(
					'source_term_id'        => $term->term_id,
					'source_parent_term_id' => (int) $term->parent,
					'name'                  => $term->name,
					'slug'                  => $term->slug,
					'taxonomy'              => $taxonomy,
					'description'           => $term->description,
					'directly_assigned'     => in_array( $term->term_id, $direct_ids, true ),
				);
			}

			$terms_by_taxonomy[ $taxonomy ] = $payloads;
		}

		return $terms_by_taxonomy;
	}
}
