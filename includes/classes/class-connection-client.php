<?php
/**
 * Class Connection Client
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Makes outbound authenticated requests to another site running Post Migrator.
 */
class Connection_Client {
	/**
	 * Validate that a target URL has an acceptable scheme.
	 *
	 * @param string $url The target site URL.
	 * @return bool
	 */
	public static function validate_target_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Test the connection to a target site using its key.
	 *
	 * @param string $target_url The target site's URL.
	 * @param string $target_key The key to present to the target site.
	 * @return array
	 */
	public static function test_connection( string $target_url, string $target_key ): array {
		$endpoint = add_query_arg(
			'rest_route',
			'/' . REST_Controller::API_NAMESPACE . '/ping',
			untrailingslashit( $target_url )
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers'   => array(
					REST_Controller::KEY_HEADER => $target_key,
				),
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => 'connection_unreachable',
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code ) {
			return array(
				'success' => true,
				'code'    => 'connection_ok',
			);
		}

		if ( 401 === $status_code ) {
			return array(
				'success' => false,
				'code'    => 'connection_unauthorized',
			);
		}

		return array(
			'success' => false,
			'code'    => 'connection_unexpected_response',
		);
	}

	/**
	 * Ask the target site which of a batch of posts already exist on it.
	 *
	 * @param string $target_url  The target site's URL.
	 * @param string $target_key  The key to present to the target site.
	 * @param string $origin_site This site's URL.
	 * @param array  $items       Items to match, each with origin_id, post_type, slug, title.
	 * @return array
	 */
	public static function check_matches( string $target_url, string $target_key, string $origin_site, array $items ): array {
		return self::post_json(
			$target_url,
			$target_key,
			'/push/match',
			array(
				'origin_site' => $origin_site,
				'items'       => $items,
			)
		);
	}

	/**
	 * Search the target site's content, for manual match override.
	 *
	 * @param string $target_url The target site's URL.
	 * @param string $target_key The key to present to the target site.
	 * @param string $post_type  The post type to search within.
	 * @param string $search     The search term.
	 * @param int    $per_page   The maximum number of results to return.
	 * @return array
	 */
	public static function search_destination_content( string $target_url, string $target_key, string $post_type, string $search, int $per_page = 20 ): array {
		return self::post_json(
			$target_url,
			$target_key,
			'/push/search',
			array(
				'post_type' => $post_type,
				'search'    => $search,
				'per_page'  => $per_page,
			)
		);
	}

	/**
	 * Push a batch of posts to the target site, creating or overwriting as directed.
	 *
	 * @param string $target_url  The target site's URL.
	 * @param string $target_key  The key to present to the target site.
	 * @param string $origin_site This site's URL.
	 * @param array  $items       Items to apply, each with origin_id, action, destination_id, slug_strategy, post.
	 * @return array
	 */
	public static function push_posts( string $target_url, string $target_key, string $origin_site, array $items ): array {
		return self::post_json(
			$target_url,
			$target_key,
			'/push/apply',
			array(
				'origin_site' => $origin_site,
				'items'       => $items,
			)
		);
	}

	/**
	 * POST a JSON body to a Post Migrator route on the target site, authenticated with its key.
	 *
	 * @param string $target_url The target site's URL.
	 * @param string $target_key The key to present to the target site.
	 * @param string $route      The route to call, relative to the Post Migrator namespace (e.g. "/push/match").
	 * @param array  $body       The request body.
	 * @return array
	 */
	private static function post_json( string $target_url, string $target_key, string $route, array $body ): array {
		$endpoint = add_query_arg(
			'rest_route',
			'/' . REST_Controller::API_NAMESPACE . $route,
			untrailingslashit( $target_url )
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => array(
					'Content-Type'              => 'application/json',
					REST_Controller::KEY_HEADER => $target_key,
				),
				'body'      => wp_json_encode( $body ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => 'connection_unreachable',
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );
		$decoded     = is_array( $decoded ) ? $decoded : array();

		if ( 200 === $status_code ) {
			return array_merge(
				array(
					'success' => true,
					'code'    => 'connection_ok',
				),
				$decoded
			);
		}

		if ( 401 === $status_code || 403 === $status_code ) {
			return array_merge(
				array(
					'success' => false,
					'code'    => 'connection_unauthorized',
				),
				$decoded
			);
		}

		return array_merge(
			array(
				'success' => false,
				'code'    => 'connection_unexpected_response',
			),
			$decoded
		);
	}
}
