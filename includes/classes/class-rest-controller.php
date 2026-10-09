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

		register_rest_route(
			self::API_NAMESPACE,
			'/push/match',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_push_match' ),
				'permission_callback' => array( self::class, 'authenticate_push_request' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/push/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_push_search' ),
				'permission_callback' => array( self::class, 'authenticate_push_request' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/push/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_push_apply' ),
				'permission_callback' => array( self::class, 'authenticate_push_request' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/pull/list',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_pull_list' ),
				'permission_callback' => array( self::class, 'authenticate_pull_request' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/pull/fetch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_pull_fetch' ),
				'permission_callback' => array( self::class, 'authenticate_pull_request' ),
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
	 * Handle the batch match-check route, used by a pushing site to find out
	 * which of its selected posts already exist on this site.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_push_match( \WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$origin_site = isset( $params['origin_site'] ) ? (string) $params['origin_site'] : '';
		$items       = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();

		if ( '' === $origin_site || empty( $items ) ) {
			return new \WP_Error(
				'post_migrator_invalid_request',
				__( 'origin_site and items are required.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $items ) > 100 ) {
			return new \WP_Error(
				'post_migrator_too_many_items',
				__( 'No more than 100 items may be matched at once.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		$sanitized = array();

		foreach ( $items as $item ) {
			if ( ! isset( $item['origin_id'], $item['post_type'] ) ) {
				continue;
			}

			$sanitized[] = array(
				'origin_id' => (int) $item['origin_id'],
				'post_type' => sanitize_key( $item['post_type'] ),
				'slug'      => isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '',
				'title'     => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
			);
		}

		// Cast to object so an empty or coincidentally sequential-from-zero
		// set of origin IDs still encodes as a JSON object, not an array.
		return new \WP_REST_Response(
			array( 'matches' => (object) Content_Matcher::match_batch( $origin_site, $sanitized ) ),
			200
		);
	}

	/**
	 * Handle the manual-override search route, used by a pushing site to let
	 * the user pick a specific post on this site to overwrite.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_push_search( \WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : '';
		$search    = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$per_page  = isset( $params['per_page'] ) ? (int) $params['per_page'] : 20;

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'post_migrator_invalid_request',
				__( 'A valid post_type is required.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response(
			array( 'results' => Content_Matcher::search( $post_type, $search, $per_page ) ),
			200
		);
	}

	/**
	 * Handle the create/overwrite route, used by a pushing site to write content to this site.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_push_apply( \WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$origin_site = isset( $params['origin_site'] ) ? (string) $params['origin_site'] : '';
		$items       = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();

		if ( '' === $origin_site || empty( $items ) ) {
			return new \WP_Error(
				'post_migrator_invalid_request',
				__( 'origin_site and items are required.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response(
			array( 'results' => Post_Writer::apply_batch( $origin_site, $items ) ),
			200
		);
	}

	/**
	 * Handle the content-browsing route, used by a pulling site to list this
	 * site's own content for its selection step.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_pull_list( \WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : '';
		$status    = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';
		$search    = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'post_migrator_invalid_request',
				__( 'A valid post_type is required.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		$query   = Content_Browser::query_posts( $post_type, $status, $search );
		$results = array();

		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'        => $post->ID,
				'post_type' => $post->post_type,
				'title'     => get_the_title( $post ),
				'slug'      => $post->post_name,
				'status'    => $post->post_status,
				'modified'  => get_the_modified_date( '', $post ),
			);
		}

		return new \WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * Handle the content-fetching route, used by a pulling site to read the
	 * full, current content of selected posts on this site.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_pull_fetch( \WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$post_ids = isset( $params['post_ids'] ) && is_array( $params['post_ids'] ) ? array_map( 'absint', $params['post_ids'] ) : array();
		$post_ids = array_values( array_filter( $post_ids ) );

		if ( empty( $post_ids ) ) {
			return new \WP_Error(
				'post_migrator_invalid_request',
				__( 'post_ids is required.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $post_ids ) > 100 ) {
			return new \WP_Error(
				'post_migrator_too_many_items',
				__( 'No more than 100 items may be fetched at once.', 'post-migrator' ),
				array( 'status' => 400 )
			);
		}

		$items = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$items[ $post_id ] = array(
				'post_type' => $post->post_type,
				'title'     => get_the_title( $post ),
				'slug'      => $post->post_name,
				'post'      => Post_Packager::package( $post ),
			);
		}

		// Cast to object so an empty or coincidentally sequential-from-zero
		// set of post IDs still encodes as a JSON object, not an array.
		return new \WP_REST_Response( array( 'items' => (object) $items ), 200 );
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
	 * Used as the permission_callback for the routes that let another site
	 * read content from this site.
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
