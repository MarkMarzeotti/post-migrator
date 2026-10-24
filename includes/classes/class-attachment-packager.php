<?php
/**
 * Class Attachment Packager
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Builds the transferable payload for an attachment referenced by a migrated post.
 */
class Attachment_Packager {
	/**
	 * Package an attachment into the payload shape expected by Attachment_Writer::import().
	 *
	 * No _wp_attachment_metadata is included -- it contains source-site-relative
	 * sizes/paths that are meaningless on the destination, which regenerates
	 * its own after sideloading instead.
	 *
	 * @param \WP_Post $attachment The attachment to package.
	 * @return array
	 */
	public static function package( \WP_Post $attachment ): array {
		return array(
			'source_id'   => $attachment->ID,
			'source_url'  => (string) wp_get_attachment_url( $attachment->ID ),
			'filename'    => wp_basename( (string) get_attached_file( $attachment->ID ) ),
			'mime_type'   => $attachment->post_mime_type,
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt_text'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		);
	}
}
