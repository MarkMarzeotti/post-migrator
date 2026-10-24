<?php
/**
 * Class Reference Resolver
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Finds and remaps references to other content (attachments, other posts)
 * inside a freshly-written destination post's meta and block content.
 *
 * Runs as a second pass after a post's meta has already been copied
 * wholesale: every meta key/value and every parsed block is walked once,
 * classifying each reference-bearing value by its ACF field type (or, for
 * the featured image, a hardcoded special case) and remapping the source
 * site's ID to the correct destination ID -- sideloading attachments on
 * demand via Attachment_Writer, with a per-batch cache so the same
 * attachment is never resolved twice.
 */
class Reference_Resolver {
	/**
	 * ACF field types whose values are single attachment IDs.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_FIELD_TYPES = array( 'image', 'file' );

	/**
	 * Per-batch cache of source attachment ID to destination attachment ID.
	 * A value of 0 means the attachment was looked up and could not be resolved.
	 *
	 * @var array<int, int>
	 */
	private static $attachment_cache = array();

	/**
	 * Per-batch cache of source attachment ID to the attachment's original
	 * source_url, used for the raw-content URL rewrite pass.
	 *
	 * @var array<int, string>
	 */
	private static $attachment_source_urls = array();

	/**
	 * Clear the per-batch reference resolution caches.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$attachment_cache       = array();
		self::$attachment_source_urls = array();
	}

	/**
	 * Record that a bundled attachment has already been imported, so the
	 * reference-resolution pass doesn't need to look it up again.
	 *
	 * @param int    $source_id      The attachment's ID on the origin site.
	 * @param int    $destination_id The attachment's ID on this (destination) site.
	 * @param string $source_url     The attachment's original URL on the origin site.
	 * @return void
	 */
	public static function remember_attachment( int $source_id, int $destination_id, string $source_url = '' ) {
		self::$attachment_cache[ $source_id ] = $destination_id;

		if ( '' !== $source_url ) {
			self::$attachment_source_urls[ $source_id ] = $source_url;
		}
	}

	/**
	 * Resolve every attachment/post reference in a destination post's meta and content.
	 *
	 * @param int    $destination_post_id The destination post ID.
	 * @param string $origin_site         The origin site's URL.
	 * @return void
	 */
	public static function resolve_for_post( int $destination_post_id, string $origin_site ) {
		self::resolve_postmeta( $destination_post_id, $origin_site );
		self::resolve_blocks( $destination_post_id, $origin_site );
		self::rewrite_raw_urls( $destination_post_id );
	}

	/**
	 * Collect the attachment IDs a post references via its own meta (ACF
	 * image/gallery/file fields, plus the featured image), without
	 * remapping anything -- used when packaging a post to decide which
	 * attachments need to be bundled alongside it.
	 *
	 * @param \WP_Post $post The post to scan.
	 * @return int[]
	 */
	public static function collect_attachment_ids_from_meta( \WP_Post $post ): array {
		$ids = array();

		$thumbnail_id = (int) get_post_meta( $post->ID, '_thumbnail_id', true );

		if ( $thumbnail_id > 0 ) {
			$ids[] = $thumbnail_id;
		}

		foreach ( get_post_meta( $post->ID ) as $meta_key => $meta_values ) {
			if ( 0 === strpos( (string) $meta_key, '_' ) ) {
				continue;
			}

			$field_type = ACF_Field_Inspector::get_field_type_for_meta( $post->ID, (string) $meta_key );

			if ( null === $field_type ) {
				continue;
			}

			array_push( $ids, ...self::extract_attachment_ids( $field_type, $meta_values[0] ) );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Collect the attachment IDs referenced inside a post's block content
	 * (core/image, core/gallery, and any acf/* block's field data), without
	 * remapping anything.
	 *
	 * @param \WP_Post $post The post to scan.
	 * @return int[]
	 */
	public static function collect_attachment_ids_from_blocks( \WP_Post $post ): array {
		$ids = array();

		self::walk_blocks(
			parse_blocks( $post->post_content ),
			function ( array $block ) use ( &$ids ) {
				array_push( $ids, ...self::block_attachment_ids( $block ) );

				return $block;
			}
		);

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Walk and remap this destination post's own meta.
	 *
	 * @param int    $destination_post_id The destination post ID.
	 * @param string $origin_site         The origin site's URL.
	 * @return void
	 */
	private static function resolve_postmeta( int $destination_post_id, string $origin_site ) {
		$thumbnail_id = (int) get_post_meta( $destination_post_id, '_thumbnail_id', true );

		if ( $thumbnail_id > 0 ) {
			$resolved = self::resolve_attachment_reference( $thumbnail_id, $origin_site );

			if ( null !== $resolved ) {
				update_post_meta( $destination_post_id, '_thumbnail_id', $resolved );
			} else {
				delete_post_meta( $destination_post_id, '_thumbnail_id' );
			}
		}

		foreach ( get_post_meta( $destination_post_id ) as $meta_key => $meta_values ) {
			$meta_key = (string) $meta_key;

			if ( 0 === strpos( $meta_key, '_' ) ) {
				continue;
			}

			$field_type = ACF_Field_Inspector::get_field_type_for_meta( $destination_post_id, $meta_key );

			if ( null === $field_type ) {
				continue;
			}

			$value     = $meta_values[0];
			$new_value = self::remap_value_by_field_type( $field_type, $value, $origin_site );

			if ( $new_value !== $value ) {
				update_post_meta( $destination_post_id, $meta_key, $new_value );
			}
		}
	}

	/**
	 * Walk and remap this destination post's block content.
	 *
	 * @param int    $destination_post_id The destination post ID.
	 * @param string $origin_site         The origin site's URL.
	 * @return void
	 */
	private static function resolve_blocks( int $destination_post_id, string $origin_site ) {
		$post = get_post( $destination_post_id );

		if ( ! $post ) {
			return;
		}

		$changed = false;

		$blocks = self::walk_blocks(
			parse_blocks( $post->post_content ),
			function ( array $block ) use ( $origin_site, &$changed ) {
				return self::remap_block( $block, $origin_site, $changed );
			}
		);

		if ( $changed ) {
			wp_update_post(
				array(
					'ID'           => $destination_post_id,
					'post_content' => serialize_blocks( $blocks ),
				)
			);
		}
	}

	/**
	 * Best-effort fixup for attachment URLs embedded as literal strings
	 * (raw <img src> HTML in classic/freeform content, or a block's saved
	 * HTML which core/image stores verbatim in innerContent rather than
	 * regenerating it from attrs). For every attachment resolved so far in
	 * this batch, replace occurrences of its exact original URL with its
	 * destination URL.
	 *
	 * Known limitation: an image referenced only via raw HTML, never via any
	 * structured block/meta reference anywhere in the batch, is never
	 * discovered or migrated at all, so there is nothing here to replace it
	 * with.
	 *
	 * @param int $destination_post_id The destination post ID.
	 * @return void
	 */
	private static function rewrite_raw_urls( int $destination_post_id ) {
		if ( empty( self::$attachment_source_urls ) ) {
			return;
		}

		$post = get_post( $destination_post_id );

		if ( ! $post ) {
			return;
		}

		$content = $post->post_content;
		$updated = $content;

		foreach ( self::$attachment_source_urls as $source_id => $source_url ) {
			$destination_id = self::$attachment_cache[ $source_id ] ?? 0;

			if ( ! $destination_id || '' === $source_url ) {
				continue;
			}

			$destination_url = (string) wp_get_attachment_url( $destination_id );

			if ( '' === $destination_url ) {
				continue;
			}

			$updated = str_replace( $source_url, $destination_url, $updated );
		}

		if ( $updated !== $content ) {
			wp_update_post(
				array(
					'ID'           => $destination_post_id,
					'post_content' => $updated,
				)
			);
		}
	}

	/**
	 * Recursively apply a visitor to every block and its innerBlocks.
	 *
	 * @param array    $blocks  Parsed blocks, as returned by parse_blocks().
	 * @param callable $visitor Called with each block; returns the (possibly modified) block.
	 * @return array
	 */
	private static function walk_blocks( array $blocks, callable $visitor ): array {
		foreach ( $blocks as &$block ) {
			$block = $visitor( $block );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::walk_blocks( $block['innerBlocks'], $visitor );
			}
		}

		return $blocks;
	}

	/**
	 * Remap a single block's attachment/post references, dispatching by block name.
	 *
	 * @param array  $block       The block.
	 * @param string $origin_site The origin site's URL.
	 * @param bool   $changed     Set to true if anything in the block was remapped.
	 * @return array
	 */
	private static function remap_block( array $block, string $origin_site, bool &$changed ): array {
		$block_name = (string) ( $block['blockName'] ?? '' );

		if ( 'core/image' === $block_name ) {
			return self::remap_image_block( $block, $origin_site, $changed );
		}

		if ( 'core/gallery' === $block_name ) {
			return self::remap_gallery_block( $block, $origin_site, $changed );
		}

		if ( 0 === strpos( $block_name, 'acf/' ) ) {
			return self::remap_acf_block( $block, $origin_site, $changed );
		}

		return $block;
	}

	/**
	 * Remap a core/image block's attachment ID and URL attributes.
	 *
	 * @param array  $block       The block.
	 * @param string $origin_site The origin site's URL.
	 * @param bool   $changed     Set to true if the block was remapped.
	 * @return array
	 */
	private static function remap_image_block( array $block, string $origin_site, bool &$changed ): array {
		$source_id = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;

		if ( $source_id <= 0 ) {
			return $block;
		}

		$destination_id = self::resolve_attachment_reference( $source_id, $origin_site );

		// An unresolved reference is cleared rather than left pointing at the
		// origin site -- consistent with how postmeta/ACF-field references
		// are handled, and because silently leaving it would hotlink the
		// destination page back to a site that may not stay reachable.
		if ( null === $destination_id ) {
			unset( $block['attrs']['id'] );

			if ( isset( $block['attrs']['url'] ) ) {
				$block['attrs']['url'] = '';
			}

			$block['innerHTML'] = self::strip_img_src( $block['innerHTML'] ?? '' );

			if ( isset( $block['innerContent'][0] ) ) {
				$block['innerContent'][0] = self::strip_img_src( $block['innerContent'][0] );
			}

			$changed = true;

			return $block;
		}

		$block['attrs']['id'] = $destination_id;

		if ( ! empty( $block['attrs']['url'] ) ) {
			$block['attrs']['url'] = (string) wp_get_attachment_url( $destination_id );
		}

		$changed = true;

		return $block;
	}

	/**
	 * Blank out an <img> tag's src attribute inside a block's raw saved HTML.
	 *
	 * @param string $html The block's innerHTML/innerContent fragment.
	 * @return string
	 */
	private static function strip_img_src( string $html ): string {
		return (string) preg_replace( '/(<img\b[^>]*\bsrc=)"[^"]*"/i', '$1""', $html );
	}

	/**
	 * Remap a core/gallery block's legacy attrs.ids array. Inner core/image
	 * blocks (the newer Gallery block shape) are already handled by the
	 * generic recursion into innerBlocks.
	 *
	 * @param array  $block       The block.
	 * @param string $origin_site The origin site's URL.
	 * @param bool   $changed     Set to true if the block was remapped.
	 * @return array
	 */
	private static function remap_gallery_block( array $block, string $origin_site, bool &$changed ): array {
		if ( empty( $block['attrs']['ids'] ) || ! is_array( $block['attrs']['ids'] ) ) {
			return $block;
		}

		$mapped = array();

		foreach ( $block['attrs']['ids'] as $source_id ) {
			$source_id      = (int) $source_id;
			$destination_id = $source_id > 0 ? self::resolve_attachment_reference( $source_id, $origin_site ) : null;

			// An unresolved reference is dropped rather than left pointing at
			// the origin site -- consistent with the postmeta/ACF-field path.
			if ( null !== $destination_id ) {
				$mapped[] = $destination_id;
			}
		}

		$block['attrs']['ids'] = $mapped;
		$changed               = true;

		return $block;
	}

	/**
	 * Remap an ACF block's field data. ACF blocks store their field values
	 * directly inside attrs.data (not postmeta), each with a sibling
	 * "_<name>" entry holding the field key, mirroring the postmeta convention.
	 *
	 * @param array  $block       The block.
	 * @param string $origin_site The origin site's URL.
	 * @param bool   $changed     Set to true if anything in the block was remapped.
	 * @return array
	 */
	private static function remap_acf_block( array $block, string $origin_site, bool &$changed ): array {
		if ( empty( $block['attrs']['data'] ) || ! is_array( $block['attrs']['data'] ) ) {
			return $block;
		}

		$data = $block['attrs']['data'];

		foreach ( $data as $field_name => $value ) {
			$field_name = (string) $field_name;

			if ( 0 === strpos( $field_name, '_' ) ) {
				continue;
			}

			$field_key = isset( $data[ '_' . $field_name ] ) ? (string) $data[ '_' . $field_name ] : '';

			if ( '' === $field_key ) {
				continue;
			}

			$field_type = ACF_Field_Inspector::get_field_type_for_block_field( $field_key );

			if ( null === $field_type ) {
				continue;
			}

			$new_value = self::remap_value_by_field_type( $field_type, $value, $origin_site );

			if ( $new_value !== $value ) {
				$data[ $field_name ] = $new_value;
				$changed             = true;
			}
		}

		$block['attrs']['data'] = $data;

		return $block;
	}

	/**
	 * Remap a single field value according to its ACF field type.
	 *
	 * Image/file fields hold a single attachment ID, gallery fields hold an
	 * array of attachment IDs, post_object fields hold a single post ID. Any
	 * other field type is returned unchanged. An unresolvable reference is
	 * cleared (not left pointing at a stale source-site ID, which could
	 * coincidentally collide with an unrelated real destination post/attachment).
	 *
	 * @param string $field_type  The ACF field type.
	 * @param mixed  $value       The field's current value.
	 * @param string $origin_site The origin site's URL.
	 * @return mixed
	 */
	private static function remap_value_by_field_type( string $field_type, $value, string $origin_site ) {
		if ( in_array( $field_type, self::ATTACHMENT_FIELD_TYPES, true ) ) {
			$source_id = is_numeric( $value ) ? (int) $value : 0;

			if ( $source_id <= 0 ) {
				return $value;
			}

			$destination_id = self::resolve_attachment_reference( $source_id, $origin_site );

			return null !== $destination_id ? $destination_id : '';
		}

		if ( 'gallery' === $field_type ) {
			$values = is_array( $value ) ? $value : array();
			$mapped = array();

			foreach ( $values as $item_id ) {
				$source_id = is_numeric( $item_id ) ? (int) $item_id : 0;

				if ( $source_id <= 0 ) {
					continue;
				}

				$destination_id = self::resolve_attachment_reference( $source_id, $origin_site );

				if ( null !== $destination_id ) {
					$mapped[] = $destination_id;
				}
			}

			return $mapped;
		}

		if ( 'post_object' === $field_type ) {
			$source_id = is_numeric( $value ) ? (int) $value : 0;

			if ( $source_id <= 0 ) {
				return $value;
			}

			$destination_id = self::resolve_post_reference( $source_id, $origin_site );

			return null !== $destination_id ? $destination_id : '';
		}

		return $value;
	}

	/**
	 * Extract the attachment IDs a single meta/field value represents, given its ACF field type.
	 *
	 * @param string $field_type The ACF field type.
	 * @param mixed  $value      The field's value.
	 * @return int[]
	 */
	private static function extract_attachment_ids( string $field_type, $value ): array {
		if ( in_array( $field_type, self::ATTACHMENT_FIELD_TYPES, true ) ) {
			$id = is_numeric( $value ) ? (int) $value : 0;

			return $id > 0 ? array( $id ) : array();
		}

		if ( 'gallery' === $field_type ) {
			$values = is_array( $value ) ? $value : array();

			return array_values( array_filter( array_map( 'intval', $values ) ) );
		}

		return array();
	}

	/**
	 * Extract the attachment IDs a single block references (core/image,
	 * core/gallery, or an acf/* block's field data).
	 *
	 * @param array $block The block.
	 * @return int[]
	 */
	private static function block_attachment_ids( array $block ): array {
		$block_name = (string) ( $block['blockName'] ?? '' );
		$ids        = array();

		if ( 'core/image' === $block_name && ! empty( $block['attrs']['id'] ) ) {
			$ids[] = (int) $block['attrs']['id'];

			return $ids;
		}

		if ( 'core/gallery' === $block_name && ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
			return array_map( 'intval', $block['attrs']['ids'] );
		}

		if ( 0 === strpos( $block_name, 'acf/' ) && ! empty( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] ) ) {
			$data = $block['attrs']['data'];

			foreach ( $data as $field_name => $value ) {
				$field_name = (string) $field_name;

				if ( 0 === strpos( $field_name, '_' ) ) {
					continue;
				}

				$field_key = isset( $data[ '_' . $field_name ] ) ? (string) $data[ '_' . $field_name ] : '';

				if ( '' === $field_key ) {
					continue;
				}

				$field_type = ACF_Field_Inspector::get_field_type_for_block_field( $field_key );

				if ( null === $field_type ) {
					continue;
				}

				array_push( $ids, ...self::extract_attachment_ids( $field_type, $value ) );
			}
		}

		return $ids;
	}

	/**
	 * Resolve a source attachment ID to its destination attachment ID,
	 * backed by the per-batch cache so the same attachment is only ever
	 * looked up once per batch.
	 *
	 * @param int    $source_attachment_id The attachment's ID on the origin site.
	 * @param string $origin_site          The origin site's URL.
	 * @return int|null
	 */
	private static function resolve_attachment_reference( int $source_attachment_id, string $origin_site ): ?int {
		if ( array_key_exists( $source_attachment_id, self::$attachment_cache ) ) {
			$destination_id = self::$attachment_cache[ $source_attachment_id ];

			return $destination_id > 0 ? $destination_id : null;
		}

		// Not covered by this batch's bundle -- fall back to a direct lookup,
		// which still catches an attachment already migrated in a prior run.
		$existing = Post_Origin::find_by_origin( $origin_site, array( $source_attachment_id ), 'attachment' );

		if ( isset( $existing[ $source_attachment_id ] ) ) {
			self::$attachment_cache[ $source_attachment_id ] = $existing[ $source_attachment_id ];

			return $existing[ $source_attachment_id ];
		}

		self::$attachment_cache[ $source_attachment_id ] = 0;

		self::log_unresolved(
			sprintf(
				'Could not resolve attachment %1$d from %2$s: no bundled payload or existing migrated copy found.',
				$source_attachment_id,
				$origin_site
			)
		);

		return null;
	}

	/**
	 * Resolve a source post ID to its destination post ID via origin-tracking
	 * meta. Deliberately does not auto-migrate the referenced post if it
	 * hasn't been migrated yet -- that would bypass the review step the user
	 * goes through for every other post, and risks unbounded/cyclic scope
	 * creep. The caller is left to clear the reference and surface a warning.
	 *
	 * @param int    $source_post_id The post's ID on the origin site.
	 * @param string $origin_site    The origin site's URL.
	 * @return int|null
	 */
	private static function resolve_post_reference( int $source_post_id, string $origin_site ): ?int {
		// 'any' post_type: a post_object field can point at any post type,
		// and Post_Origin::find_by_origin() forwards this straight into
		// WP_Query, which treats 'any' as "any registered post type."
		$existing = Post_Origin::find_by_origin( $origin_site, array( $source_post_id ), 'any' );

		if ( isset( $existing[ $source_post_id ] ) ) {
			return $existing[ $source_post_id ];
		}

		self::log_unresolved(
			sprintf(
				'Could not resolve post reference %1$d from %2$s: referenced post has not been migrated to this site.',
				$source_post_id,
				$origin_site
			)
		);

		return null;
	}

	/**
	 * Log an unresolved reference. There is no results-UI channel for this
	 * yet, so this is a diagnostic breadcrumb only.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private static function log_unresolved( string $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No results-UI channel exists yet to surface unresolved references; this is the diagnostic fallback.
		error_log( '[Post Migrator] ' . $message );
	}
}
