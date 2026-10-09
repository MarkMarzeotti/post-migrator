<?php
/**
 * Class Form Handler
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Handles the Post Migrator admin form submissions.
 */
class Form_Handler {
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
		add_action( 'admin_post_post_migrator_regenerate_key', array( $this, 'handle_regenerate_key' ) );
		add_action( 'admin_post_post_migrator_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_post_migrator_save_permissions', array( $this, 'handle_save_permissions' ) );
		add_action( 'admin_post_post_migrator_save_push_settings', array( $this, 'handle_save_push_settings' ) );
		add_action( 'admin_post_post_migrator_push_select', array( $this, 'handle_push_select' ) );
		add_action( 'admin_post_post_migrator_push_manual_search', array( $this, 'handle_push_manual_search' ) );
		add_action( 'admin_post_post_migrator_push_confirm', array( $this, 'handle_push_confirm' ) );
		add_action( 'admin_post_post_migrator_push_cancel', array( $this, 'handle_push_cancel' ) );
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
	 * Handle the "Regenerate" key form submission.
	 *
	 * @return void
	 */
	public function handle_regenerate_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_regenerate_key' );

		Key_Manager::regenerate_key();

		$this->redirect_with_notice( 'key_regenerated', Dashboard_Page::PAGE_SLUG );
	}

	/**
	 * Handle the "Test Connection" form submission.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_test_connection' );

		$target_url = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '';
		$target_url = esc_url_raw( $target_url );
		$target_key = isset( $_POST['target_key'] ) ? sanitize_text_field( wp_unslash( $_POST['target_key'] ) ) : '';

		Target_Store::save( $target_url, $target_key );

		if ( '' === $target_url || '' === $target_key || ! Connection_Client::validate_target_url( $target_url ) ) {
			$this->redirect_with_notice( 'connection_invalid_url', Migrate_Page::PAGE_SLUG );
		}

		$result = Connection_Client::test_connection( $target_url, $target_key );

		if ( $result['success'] ) {
			Target_Store::mark_verified();
		}

		$this->redirect_with_notice( $result['code'], Migrate_Page::PAGE_SLUG );
	}

	/**
	 * Handle the "Save Permissions" form submission.
	 *
	 * @return void
	 */
	public function handle_save_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_save_permissions' );

		$allow_pull = isset( $_POST['allow_pull'] );
		$allow_push = isset( $_POST['allow_push'] );

		Permissions::save( $allow_pull, $allow_push );

		$this->redirect_with_notice( 'permissions_saved', Dashboard_Page::PAGE_SLUG );
	}

	/**
	 * Handle the "Save Push Settings" form submission.
	 *
	 * @return void
	 */
	public function handle_save_push_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_save_push_settings' );

		$default_author = isset( $_POST['default_author'] ) ? absint( $_POST['default_author'] ) : 0;

		Push_Settings::save_default_author( $default_author );

		$this->redirect_with_notice( 'push_settings_saved', Dashboard_Page::PAGE_SLUG );
	}

	/**
	 * Handle the "Continue" form submission on the Push selection step: check
	 * the selected posts against the target site and move to the review step.
	 *
	 * @return void
	 */
	public function handle_push_select() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_push_select' );

		if ( ! Target_Store::is_verified() ) {
			$this->redirect_with_notice( 'push_connection_not_verified', Migrate_Page::PAGE_SLUG );
		}

		$post_ids = array();

		if ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
			$post_ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['post_ids'] ) ) ) );
		}

		if ( empty( $post_ids ) ) {
			$this->redirect_with_notice( 'push_no_posts_selected', Push_Page::PAGE_SLUG );
		}

		$items = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$items[ $post->ID ] = array(
				'post_type'      => $post->post_type,
				'slug'           => $post->post_name,
				'title'          => get_the_title( $post ),
				'origin_match'   => null,
				'slug_match'     => null,
				'title_matches'  => array(),
				'manual_matches' => array(),
			);
		}

		if ( empty( $items ) ) {
			$this->redirect_with_notice( 'push_no_posts_selected', Push_Page::PAGE_SLUG );
		}

		$match_request_items = array();

		foreach ( $items as $origin_id => $item ) {
			$match_request_items[] = array(
				'origin_id' => $origin_id,
				'post_type' => $item['post_type'],
				'slug'      => $item['slug'],
				'title'     => $item['title'],
			);
		}

		$match_result = Connection_Client::check_matches(
			Target_Store::get_target_url(),
			Target_Store::get_target_key(),
			home_url(),
			$match_request_items
		);

		if ( ! $match_result['success'] ) {
			$this->redirect_with_notice( 'push_match_check_failed', Push_Page::PAGE_SLUG );
		}

		foreach ( $items as $origin_id => &$item ) {
			$match                 = $match_result['matches'][ (string) $origin_id ] ?? array();
			$item['origin_match']  = $match['origin_match'] ?? null;
			$item['slug_match']    = $match['slug_match'] ?? null;
			$item['title_matches'] = $match['title_matches'] ?? array();
		}
		unset( $item );

		$token = Transient_Session::start( 'push_batch', array( 'items' => $items ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => Push_Page::PAGE_SLUG,
					'step'             => 'review',
					'push_batch_token' => $token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the manual-override search mini-form on the Push review step.
	 *
	 * @return void
	 */
	public function handle_push_manual_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_push_manual_search' );

		$token     = isset( $_POST['push_batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['push_batch_token'] ) ) : '';
		$origin_id = isset( $_POST['origin_id'] ) ? absint( $_POST['origin_id'] ) : 0;
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$batch = Transient_Session::get( 'push_batch', $token );

		if ( null === $batch || ! isset( $batch['items'][ $origin_id ] ) ) {
			$this->redirect_with_notice( 'push_batch_expired', Push_Page::PAGE_SLUG );
		}

		if ( '' !== $search ) {
			$search_result = Connection_Client::search_destination_content(
				Target_Store::get_target_url(),
				Target_Store::get_target_key(),
				$batch['items'][ $origin_id ]['post_type'],
				$search
			);

			if ( ! $search_result['success'] ) {
				$this->redirect_with_notice( 'push_search_failed', Push_Page::PAGE_SLUG );
			}

			$batch['items'][ $origin_id ]['manual_matches'] = array_merge(
				$batch['items'][ $origin_id ]['manual_matches'],
				$search_result['results'] ?? array()
			);

			Transient_Session::update( 'push_batch', $token, $batch );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => Push_Page::PAGE_SLUG,
					'step'             => 'review',
					'push_batch_token' => $token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the final confirmation on the Push review step: build the apply
	 * payload from the live local posts and push it to the target site.
	 *
	 * @return void
	 */
	public function handle_push_confirm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_push_confirm' );

		$token = isset( $_POST['push_batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['push_batch_token'] ) ) : '';
		$batch = Transient_Session::get( 'push_batch', $token );

		if ( null === $batch ) {
			$this->redirect_with_notice( 'push_batch_expired', Push_Page::PAGE_SLUG );
		}

		$submitted_actions = array();

		if ( isset( $_POST['action'] ) && is_array( $_POST['action'] ) ) {
			$submitted_actions = array_map( 'sanitize_text_field', wp_unslash( $_POST['action'] ) );
		}

		$submitted_slugs = array();

		if ( isset( $_POST['slug_strategy'] ) && is_array( $_POST['slug_strategy'] ) ) {
			$submitted_slugs = array_map( 'sanitize_text_field', wp_unslash( $_POST['slug_strategy'] ) );
		}

		$apply_items = array();

		foreach ( $batch['items'] as $origin_id => $item ) {
			$post = get_post( $origin_id );

			if ( ! $post ) {
				continue;
			}

			$submitted  = isset( $submitted_actions[ $origin_id ] ) ? $submitted_actions[ $origin_id ] : 'create_new';
			$candidates = $this->known_candidate_ids( $item );

			if ( preg_match( '/^overwrite:(\d+)$/', $submitted, $matches ) && in_array( (int) $matches[1], $candidates, true ) ) {
				$action         = 'update';
				$destination_id = (int) $matches[1];
			} else {
				// Not a known, previously-offered candidate -- don't trust it, fall back to creating a new post.
				$action         = 'create';
				$destination_id = null;
			}

			$slug_strategy = isset( $submitted_slugs[ $origin_id ] ) && 'destination' === $submitted_slugs[ $origin_id ] ? 'destination' : 'source';

			$author       = get_userdata( $post->post_author );
			$author_login = $author ? $author->user_login : '';

			$apply_items[] = array(
				'origin_id'      => $origin_id,
				'action'         => $action,
				'destination_id' => $destination_id,
				'slug_strategy'  => $slug_strategy,
				'post'           => array(
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
				),
			);
		}

		$apply_result = Connection_Client::push_posts(
			Target_Store::get_target_url(),
			Target_Store::get_target_key(),
			home_url(),
			$apply_items
		);

		Transient_Session::delete( 'push_batch', $token );

		if ( ! $apply_result['success'] ) {
			$this->redirect_with_notice( 'push_match_check_failed', Push_Page::PAGE_SLUG );
		}

		$has_errors   = false;
		$result_items = array();

		foreach ( $apply_result['results'] as $result ) {
			if ( ! empty( $result['error'] ) ) {
				$has_errors = true;
			}

			$origin_id      = (int) $result['origin_id'];
			$result_items[] = array(
				'title'        => $batch['items'][ $origin_id ]['title'] ?? '',
				'action_taken' => $result['action_taken'],
				'error'        => $result['error'],
			);
		}

		$result_token = Transient_Session::start( 'push_result', array( 'items' => $result_items ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => Push_Page::PAGE_SLUG,
					'step'              => 'results',
					'push_result_token' => $result_token,
					'pm_notice'         => $has_errors ? 'push_complete_with_errors' : 'push_complete',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle cancelling an in-progress push, discarding its batch session.
	 *
	 * @return void
	 */
	public function handle_push_cancel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_push_cancel' );

		$token = isset( $_GET['push_batch_token'] ) ? sanitize_text_field( wp_unslash( $_GET['push_batch_token'] ) ) : '';

		if ( '' !== $token ) {
			Transient_Session::delete( 'push_batch', $token );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . Migrate_Page::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Collect the destination post IDs already known to be legitimate candidates for an item.
	 *
	 * @param array $item The item's match data.
	 * @return array
	 */
	private function known_candidate_ids( array $item ): array {
		$ids = array();

		foreach ( array( 'origin_match', 'slug_match' ) as $key ) {
			if ( ! empty( $item[ $key ]['destination_id'] ) ) {
				$ids[] = (int) $item[ $key ]['destination_id'];
			}
		}

		foreach ( array( 'title_matches', 'manual_matches' ) as $key ) {
			foreach ( (array) ( $item[ $key ] ?? array() ) as $candidate ) {
				if ( ! empty( $candidate['destination_id'] ) ) {
					$ids[] = (int) $candidate['destination_id'];
				}
			}
		}

		return $ids;
	}

	/**
	 * Redirect back to an admin page with a notice code.
	 *
	 * @param string $notice_code The notice code to display.
	 * @param string $page_slug   The page slug to redirect back to.
	 * @return void
	 */
	private function redirect_with_notice( string $notice_code, string $page_slug ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => $page_slug,
					'pm_notice' => $notice_code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
