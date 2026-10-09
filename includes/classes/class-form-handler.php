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
		add_action( 'admin_post_post_migrator_pull_select', array( $this, 'handle_pull_select' ) );
		add_action( 'admin_post_post_migrator_pull_manual_search', array( $this, 'handle_pull_manual_search' ) );
		add_action( 'admin_post_post_migrator_pull_confirm', array( $this, 'handle_pull_confirm' ) );
		add_action( 'admin_post_post_migrator_pull_cancel', array( $this, 'handle_pull_cancel' ) );
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
			$this->redirect_with_notice(
				$this->connection_failure_notice( $match_result, 'push' ),
				Push_Page::PAGE_SLUG,
				$match_result['message'] ?? ''
			);
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
					'page'        => Push_Page::PAGE_SLUG,
					'step'        => 'review',
					'batch_token' => $token,
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

		$token     = isset( $_POST['batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_token'] ) ) : '';
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
				$this->redirect_with_notice(
					$this->connection_failure_notice( $search_result, 'push' ),
					Push_Page::PAGE_SLUG,
					$search_result['message'] ?? ''
				);
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
					'page'        => Push_Page::PAGE_SLUG,
					'step'        => 'review',
					'batch_token' => $token,
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

		$token = isset( $_POST['batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_token'] ) ) : '';
		$batch = Transient_Session::get( 'push_batch', $token );

		if ( null === $batch ) {
			$this->redirect_with_notice( 'push_batch_expired', Push_Page::PAGE_SLUG );
		}

		// Read from "item_action", not "action": the latter is WordPress's own
		// admin-post.php routing field (see the comment in
		// Migration_Page::render_review_row() for why they must never collide).
		$submitted_actions = array();

		if ( isset( $_POST['item_action'] ) && is_array( $_POST['item_action'] ) ) {
			$submitted_actions = array_map( 'sanitize_text_field', wp_unslash( $_POST['item_action'] ) );
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

			$apply_items[] = array(
				'origin_id'      => $origin_id,
				'action'         => $action,
				'destination_id' => $destination_id,
				'slug_strategy'  => $slug_strategy,
				'post'           => Post_Packager::package( $post ),
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
			$this->redirect_with_notice(
				$this->connection_failure_notice( $apply_result, 'push' ),
				Push_Page::PAGE_SLUG,
				$apply_result['message'] ?? ''
			);
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
					'page'         => Push_Page::PAGE_SLUG,
					'step'         => 'results',
					'result_token' => $result_token,
					'pm_notice'    => $has_errors ? 'push_complete_with_errors' : 'push_complete',
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

		$token = isset( $_GET['batch_token'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_token'] ) ) : '';

		if ( '' !== $token ) {
			Transient_Session::delete( 'push_batch', $token );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . Migrate_Page::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the "Continue" form submission on the Pull selection step: fetch
	 * the selected posts from the source site and check them against local
	 * content to move to the review step.
	 *
	 * @return void
	 */
	public function handle_pull_select() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_pull_select' );

		if ( ! Target_Store::is_verified() ) {
			$this->redirect_with_notice( 'pull_connection_not_verified', Migrate_Page::PAGE_SLUG );
		}

		$post_ids = array();

		if ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
			$post_ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['post_ids'] ) ) ) );
		}

		if ( empty( $post_ids ) ) {
			$this->redirect_with_notice( 'pull_no_posts_selected', Pull_Page::PAGE_SLUG );
		}

		$target_url = Target_Store::get_target_url();

		$fetch_result = Connection_Client::fetch_source_posts( $target_url, Target_Store::get_target_key(), $post_ids );

		if ( ! $fetch_result['success'] ) {
			$this->redirect_with_notice(
				$this->connection_failure_notice( $fetch_result, 'pull' ),
				Pull_Page::PAGE_SLUG,
				$fetch_result['message'] ?? ''
			);
		}

		$fetched = $fetch_result['items'] ?? array();
		$items   = array();

		foreach ( $post_ids as $origin_id ) {
			$fetched_item = $fetched[ (string) $origin_id ] ?? null;

			if ( null === $fetched_item ) {
				continue;
			}

			$items[ $origin_id ] = array(
				'post_type'      => $fetched_item['post_type'],
				'slug'           => $fetched_item['slug'],
				'title'          => $fetched_item['title'],
				'origin_match'   => null,
				'slug_match'     => null,
				'title_matches'  => array(),
				'manual_matches' => array(),
			);
		}

		if ( empty( $items ) ) {
			$this->redirect_with_notice( 'pull_no_posts_selected', Pull_Page::PAGE_SLUG );
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

		// Destination is local for a pull, so matching runs as a direct call
		// rather than over REST -- see the direction-reversal note in
		// Content_Matcher's class doc.
		$matches = Content_Matcher::match_batch( $target_url, $match_request_items );

		foreach ( $items as $origin_id => &$item ) {
			$match                 = $matches[ $origin_id ] ?? array();
			$item['origin_match']  = $match['origin_match'] ?? null;
			$item['slug_match']    = $match['slug_match'] ?? null;
			$item['title_matches'] = $match['title_matches'] ?? array();
		}
		unset( $item );

		$token = Transient_Session::start( 'pull_batch', array( 'items' => $items ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Pull_Page::PAGE_SLUG,
					'step'        => 'review',
					'batch_token' => $token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the manual-override search mini-form on the Pull review step.
	 *
	 * @return void
	 */
	public function handle_pull_manual_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_pull_manual_search' );

		$token     = isset( $_POST['batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_token'] ) ) : '';
		$origin_id = isset( $_POST['origin_id'] ) ? absint( $_POST['origin_id'] ) : 0;
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$batch = Transient_Session::get( 'pull_batch', $token );

		if ( null === $batch || ! isset( $batch['items'][ $origin_id ] ) ) {
			$this->redirect_with_notice( 'pull_batch_expired', Pull_Page::PAGE_SLUG );
		}

		if ( '' !== $search ) {
			// Destination is local for a pull, so the search runs as a direct
			// call rather than over REST.
			$batch['items'][ $origin_id ]['manual_matches'] = array_merge(
				$batch['items'][ $origin_id ]['manual_matches'],
				Content_Matcher::search( $batch['items'][ $origin_id ]['post_type'], $search )
			);

			Transient_Session::update( 'pull_batch', $token, $batch );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Pull_Page::PAGE_SLUG,
					'step'        => 'review',
					'batch_token' => $token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the final confirmation on the Pull review step: fetch the live
	 * source content fresh and apply it locally.
	 *
	 * @return void
	 */
	public function handle_pull_confirm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_pull_confirm' );

		$token = isset( $_POST['batch_token'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_token'] ) ) : '';
		$batch = Transient_Session::get( 'pull_batch', $token );

		if ( null === $batch ) {
			$this->redirect_with_notice( 'pull_batch_expired', Pull_Page::PAGE_SLUG );
		}

		$submitted_actions = array();

		if ( isset( $_POST['item_action'] ) && is_array( $_POST['item_action'] ) ) {
			$submitted_actions = array_map( 'sanitize_text_field', wp_unslash( $_POST['item_action'] ) );
		}

		$submitted_slugs = array();

		if ( isset( $_POST['slug_strategy'] ) && is_array( $_POST['slug_strategy'] ) ) {
			$submitted_slugs = array_map( 'sanitize_text_field', wp_unslash( $_POST['slug_strategy'] ) );
		}

		$target_url = Target_Store::get_target_url();

		// Re-fetch fresh content now, rather than trusting the stashed batch,
		// for the same reason handle_push_confirm() re-reads local posts
		// fresh: time may have passed since the select step.
		$fetch_result = Connection_Client::fetch_source_posts( $target_url, Target_Store::get_target_key(), array_keys( $batch['items'] ) );

		if ( ! $fetch_result['success'] ) {
			Transient_Session::delete( 'pull_batch', $token );

			$this->redirect_with_notice(
				$this->connection_failure_notice( $fetch_result, 'pull' ),
				Pull_Page::PAGE_SLUG,
				$fetch_result['message'] ?? ''
			);
		}

		$fetched     = $fetch_result['items'] ?? array();
		$apply_items = array();

		foreach ( $batch['items'] as $origin_id => $item ) {
			$fetched_item = $fetched[ (string) $origin_id ] ?? null;

			if ( null === $fetched_item ) {
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

			$apply_items[] = array(
				'origin_id'      => $origin_id,
				'action'         => $action,
				'destination_id' => $destination_id,
				'slug_strategy'  => $slug_strategy,
				'post'           => $fetched_item['post'],
			);
		}

		// Destination is local for a pull, so writing runs as a direct call
		// rather than over REST.
		$results = Post_Writer::apply_batch( $target_url, $apply_items );

		Transient_Session::delete( 'pull_batch', $token );

		$has_errors   = false;
		$result_items = array();

		foreach ( $results as $result ) {
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

		$result_token = Transient_Session::start( 'pull_result', array( 'items' => $result_items ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => Pull_Page::PAGE_SLUG,
					'step'         => 'results',
					'result_token' => $result_token,
					'pm_notice'    => $has_errors ? 'pull_complete_with_errors' : 'pull_complete',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle cancelling an in-progress pull, discarding its batch session.
	 *
	 * @return void
	 */
	public function handle_pull_cancel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'post-migrator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'post_migrator_pull_cancel' );

		$token = isset( $_GET['batch_token'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_token'] ) ) : '';

		if ( '' !== $token ) {
			Transient_Session::delete( 'pull_batch', $token );
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
	 * @param string $detail      Optional short free-text detail to append to the notice.
	 * @return void
	 */
	private function redirect_with_notice( string $notice_code, string $page_slug, string $detail = '' ) {
		$args = array(
			'page'      => $page_slug,
			'pm_notice' => $notice_code,
		);

		if ( '' !== $detail ) {
			$args['pm_notice_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Map a failed Connection_Client result to the notice code that accurately
	 * describes why the request to the other site failed -- an invalid key, the
	 * other site being configured to reject this kind of connection, a likely
	 * plugin version mismatch, an unreachable site, or anything else unexpected.
	 *
	 * @param array  $result A Connection_Client result array with 'success' => false.
	 * @param string $prefix The notice code prefix for the current flow ('push' or 'pull').
	 * @return string
	 */
	private function connection_failure_notice( array $result, string $prefix ): string {
		$map = array(
			'connection_invalid_key'      => $prefix . '_connection_invalid_key',
			'connection_forbidden'        => $prefix . '_connection_forbidden',
			'connection_version_mismatch' => $prefix . '_connection_version_mismatch',
			'connection_unreachable'      => $prefix . '_connection_unreachable',
		);

		return $map[ $result['code'] ?? '' ] ?? $prefix . '_connection_unexpected';
	}
}
