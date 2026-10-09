<?php
/**
 * Class REST Controller
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers the Post Migrator REST API routes and authenticates incoming requests.
 */
class REST_Controller {
	/**
	 * REST API namespace for all Post Migrator routes.
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'post-migrator/v1';

	/**
	 * Header used to carry the shared connection key.
	 *
	 * @var string
	 */
	public const KEY_HEADER = 'X-Post-Migrator-Key';

	/**
	 * Instance of self.
	 *
	 * @var self
	 */
	protected static $instance;

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Get Singleton Instance.
	 *
	 * @param mixed ...$args Args assigned to instance in constructor.
	 * @return self
	 */
	public static function get_instance( ...$args ) {
		if ( is_null( self::$instance ) ) {
			static::$instance = new static( ...$args );
		}

		return static::$instance;
	}

	/**
	 * Register the Post Migrator REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( self::class, 'authenticate_request' ),
			)
		);
	}

	/**
	 * Handle the connection handshake route.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_ping( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the REST callback signature.
		return new \WP_REST_Response(
			array(
				'site_url' => home_url(),
				'version'  => POST_MIGRATOR_VERSION,
				'time'     => time(),
			),
			200
		);
	}

	/**
	 * Authenticate an incoming request using the shared connection key.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error
	 */
	public static function authenticate_request( \WP_REST_Request $request ) {
		$provided_key = $request->get_header( self::KEY_HEADER );

		if ( empty( $provided_key ) ) {
			return new \WP_Error(
				'post_migrator_missing_key',
				__( 'The X-Post-Migrator-Key header is required.', 'post-migrator' ),
				array( 'status' => 401 )
			);
		}

		if ( ! Key_Manager::verify_key( $provided_key ) ) {
			return new \WP_Error(
				'post_migrator_invalid_key',
				__( 'The provided key is not valid for this site.', 'post-migrator' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Authenticate an incoming request that pulls content from this site.
	 *
	 * Intended as the permission_callback for future routes that let another
	 * site read content from this site.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error
	 */
	public static function authenticate_pull_request( \WP_REST_Request $request ) {
		$authenticated = self::authenticate_request( $request );

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		if ( ! Permissions::pull_allowed() ) {
			return new \WP_Error(
				'post_migrator_pull_disabled',
				__( 'This site does not allow other sites to pull content from it.', 'post-migrator' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Authenticate an incoming request that pushes content to this site.
	 *
	 * Intended as the permission_callback for future routes that let another
	 * site write content to this site.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error
	 */
	public static function authenticate_push_request( \WP_REST_Request $request ) {
		$authenticated = self::authenticate_request( $request );

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		if ( ! Permissions::push_allowed() ) {
			return new \WP_Error(
				'post_migrator_push_disabled',
				__( 'This site does not allow other sites to push content to it.', 'post-migrator' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
