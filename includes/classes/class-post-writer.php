<?php
/**
 * Class Post Writer
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Creates or overwrites posts on this (destination) site from pushed post data.
 */
class Post_Writer {
	/**
	 * Apply a batch of pushed items, creating or overwriting posts as directed.
	 *
	 * A single item's failure does not abort the rest of the batch.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $items       Items to apply, each with origin_id, action, destination_id, slug_strategy, post.
	 * @return array Per-item outcomes.
	 */
	public static function apply_batch( string $origin_site, array $items ): array {
		$results = array();

		foreach ( $items as $item ) {
			$results[] = self::apply_item( $origin_site, $item );
		}

		return $results;
	}

	/**
	 * Apply a single pushed item.
	 *
	 * @param string $origin_site The origin site's URL.
	 * @param array  $item        The item to apply.
	 * @return array
	 */
	private static function apply_item( string $origin_site, array $item ): array {
		$origin_id      = isset( $item['origin_id'] ) ? (int) $item['origin_id'] : 0;
		$action         = 'update' === ( $item['action'] ?? '' ) ? 'update' : 'create';
		$destination_id = isset( $item['destination_id'] ) ? (int) $item['destination_id'] : 0;
		$slug_strategy  = 'destination' === ( $item['slug_strategy'] ?? '' ) ? 'destination' : 'source';
		$post           = is_array( $item['post'] ?? null ) ? $item['post'] : array();
		$post_type      = isset( $post['post_type'] ) ? sanitize_key( $post['post_type'] ) : '';

		if ( ! post_type_exists( $post_type ) ) {
			return self::error_result( $origin_id, __( 'Unknown post type.', 'post-migrator' ) );
		}

		if ( 'update' === $action && $destination_id <= 0 ) {
			return self::error_result( $origin_id, __( 'An existing post ID is required to update a post.', 'post-migrator' ) );
		}

		$postarr = array(
			'post_type'      => $post_type,
			'post_title'     => isset( $post['post_title'] ) ? wp_unslash( (string) $post['post_title'] ) : '',
			'post_content'   => isset( $post['post_content'] ) ? wp_unslash( (string) $post['post_content'] ) : '',
			'post_excerpt'   => isset( $post['post_excerpt'] ) ? wp_unslash( (string) $post['post_excerpt'] ) : '',
			'post_status'    => isset( $post['post_status'] ) ? sanitize_key( $post['post_status'] ) : 'draft',
			'menu_order'     => isset( $post['menu_order'] ) ? (int) $post['menu_order'] : 0,
			'comment_status' => isset( $post['comment_status'] ) ? sanitize_key( $post['comment_status'] ) : 'closed',
			'ping_status'    => isset( $post['ping_status'] ) ? sanitize_key( $post['ping_status'] ) : 'closed',
		);

		if ( ! empty( $post['post_date_gmt'] ) ) {
			$postarr['post_date_gmt'] = sanitize_text_field( (string) $post['post_date_gmt'] );
		}

		// Slug transfers by default; "keep destination's current slug" means omitting
		// post_name entirely so wp_update_post() leaves the existing value untouched.
		// post_parent is intentionally never read here -- cross-site hierarchy mapping
		// is future work (see the plan's Deferred section).
		if ( 'create' === $action || 'source' === $slug_strategy ) {
			if ( ! empty( $post['post_name'] ) ) {
				$postarr['post_name'] = sanitize_title( (string) $post['post_name'] );
			}
		}

		$author_login = isset( $post['author_login'] ) ? sanitize_user( (string) $post['author_login'], true ) : '';
		$author       = '' !== $author_login ? get_user_by( 'login', $author_login ) : false;

		$postarr['post_author'] = $author ? $author->ID : Push_Settings::get_default_author();

		if ( 'update' === $action ) {
			$postarr['ID'] = $destination_id;
		}

		// A valid connection key is treated as full site-level trust (consistent
		// with the key already acting as a manage_options-equivalent bearer
		// credential everywhere else in this plugin), so pushed content is
		// written exactly as sent rather than silently stripped by kses.
		kses_remove_filters();

		try {
			$result = 'update' === $action
				? wp_update_post( $postarr, true )
				: wp_insert_post( $postarr, true );
		} finally {
			kses_init_filters();
		}

		if ( is_wp_error( $result ) ) {
			return self::error_result( $origin_id, $result->get_error_message(), 'update' === $action ? $destination_id : null );
		}

		Post_Origin::tag( (int) $result, $origin_id, $origin_site );

		return array(
			'origin_id'      => $origin_id,
			'destination_id' => (int) $result,
			'action_taken'   => 'update' === $action ? 'updated' : 'created',
			'error'          => null,
		);
	}

	/**
	 * Build an error outcome for a single item.
	 *
	 * @param int      $origin_id      The origin post ID.
	 * @param string   $message        The error message.
	 * @param int|null $destination_id The destination post ID, if known.
	 * @return array
	 */
	private static function error_result( int $origin_id, string $message, ?int $destination_id = null ): array {
		return array(
			'origin_id'      => $origin_id,
			'destination_id' => $destination_id,
			'action_taken'   => 'error',
			'error'          => $message,
		);
	}
}
