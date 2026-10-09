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

		$this->redirect_with_notice( 'key_regenerated' );
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

		if ( '' === $target_url || '' === $target_key || ! Connection_Client::validate_target_url( $target_url ) ) {
			$this->redirect_with_notice( 'connection_invalid_url' );
		}

		$result = Connection_Client::test_connection( $target_url, $target_key );

		$this->redirect_with_notice( $result['code'] );
	}

	/**
	 * Redirect back to the admin page with a notice code.
	 *
	 * @param string $notice_code The notice code to display.
	 * @return void
	 */
	private function redirect_with_notice( string $notice_code ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'post-migrator',
					'pm_notice' => $notice_code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
