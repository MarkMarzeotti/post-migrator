<?php
/**
 * Class Attachment Writer
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Finds or sideloads an attachment on this (destination) site from a packaged attachment payload.
 */
class Attachment_Writer {
	/**
	 * Find or create the destination attachment described by a packaged payload.
	 *
	 * Attachments already migrated from the same origin (tracked via the same
	 * Post_Origin tagging used for ordinary posts) are never re-downloaded.
	 *
	 * @param array  $payload     The packaged attachment payload (see Attachment_Packager::package()).
	 * @param string $origin_site The origin site's URL.
	 * @return int|\WP_Error The destination attachment ID, or a WP_Error on failure.
	 */
	public static function import( array $payload, string $origin_site ) {
		$source_id = isset( $payload['source_id'] ) ? (int) $payload['source_id'] : 0;

		if ( $source_id <= 0 || empty( $payload['source_url'] ) ) {
			return new \WP_Error(
				'post_migrator_invalid_attachment',
				__( 'A source_id and source_url are required to import an attachment.', 'post-migrator' )
			);
		}

		$existing = Post_Origin::find_by_origin( $origin_site, array( $source_id ), 'attachment' );

		if ( isset( $existing[ $source_id ] ) ) {
			return $existing[ $source_id ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = self::download_from_origin( (string) $payload['source_url'], $origin_site );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$file_array = array(
			'name'     => ! empty( $payload['filename'] ) ? sanitize_file_name( (string) $payload['filename'] ) : wp_basename( $tmp_file ),
			'tmp_name' => $tmp_file,
		);

		$post_data = array(
			'post_title'   => isset( $payload['title'] ) ? (string) $payload['title'] : '',
			'post_excerpt' => isset( $payload['caption'] ) ? (string) $payload['caption'] : '',
			'post_content' => isset( $payload['description'] ) ? (string) $payload['description'] : '',
		);

		$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}

			return $attachment_id;
		}

		if ( ! empty( $payload['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $payload['alt_text'] ) );
		}

		Post_Origin::tag( (int) $attachment_id, $source_id, $origin_site );

		return (int) $attachment_id;
	}

	/**
	 * Download a file from the origin site, temporarily allowing WordPress's
	 * HTTP safety check to reach it even if it resolves to a loopback/private
	 * IP address.
	 *
	 * WordPress's download_url() always sets reject_unsafe_urls, so
	 * wp_http_validate_url() rejects any host that resolves outside the
	 * current site's own IP unless it resolves to a "public" address --
	 * which blocks every cross-site download between two sites on the same
	 * machine (e.g. two Local by Flywheel sites, which all resolve to
	 * 127.0.0.1). The origin site is
	 * already authenticated via the connection key before this plugin ever
	 * gets here, so this doesn't grant any new trust -- it only lets that
	 * already-established trust reach past a same-machine-specific false
	 * positive in a generic SSRF guard.
	 *
	 * @param string $url         The file URL to download.
	 * @param string $origin_site The origin site's URL, used to scope the allowance to its exact host.
	 * @return string|\WP_Error Path to the downloaded temp file, or a WP_Error on failure.
	 */
	private static function download_from_origin( string $url, string $origin_site ) {
		$allowed_host = (string) wp_parse_url( $origin_site, PHP_URL_HOST );

		$allow_host = function ( $allow, $host ) use ( $allowed_host ) {
			return $allow || ( '' !== $allowed_host && strtolower( $host ) === strtolower( $allowed_host ) );
		};

		add_filter( 'http_request_host_is_external', $allow_host, 10, 2 );

		try {
			return download_url( $url );
		} finally {
			remove_filter( 'http_request_host_is_external', $allow_host, 10 );
		}
	}
}
