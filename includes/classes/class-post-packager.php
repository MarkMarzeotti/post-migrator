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
		);
	}
}
