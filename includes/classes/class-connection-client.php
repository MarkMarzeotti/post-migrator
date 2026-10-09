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
}
