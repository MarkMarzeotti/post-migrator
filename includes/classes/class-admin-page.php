<?php
/**
 * Class Admin Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers and renders the Post Migrator admin page under Tools.
 */
class Admin_Page {
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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
	 * Register the Post Migrator admin page under Tools.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			__( 'Post Migrator', 'post-migrator' ),
			__( 'Post Migrator', 'post-migrator' ),
			'manage_options',
			'post-migrator',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Post Migrator admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			$this->render_notices();
			$this->render_key_section();
			$this->render_test_connection_section();
			?>
		</div>
		<?php
	}

	/**
	 * Render an admin notice based on the current request's notice code, if any.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, no state change.
		$notice_code = isset( $_GET['pm_notice'] ) ? sanitize_key( wp_unslash( $_GET['pm_notice'] ) ) : '';

		if ( '' === $notice_code ) {
			return;
		}

		$notices = array(
			'key_regenerated'                => array( 'success', __( 'Key regenerated.', 'post-migrator' ) ),
			'connection_ok'                  => array( 'success', __( 'Connection successful.', 'post-migrator' ) ),
			'connection_unauthorized'        => array( 'error', __( 'The target site rejected the key.', 'post-migrator' ) ),
			'connection_unreachable'         => array( 'error', __( 'Could not reach the target site.', 'post-migrator' ) ),
			'connection_invalid_url'         => array( 'error', __( 'Please enter a valid site URL and key.', 'post-migrator' ) ),
			'connection_unexpected_response' => array( 'error', __( 'The target site returned an unexpected response.', 'post-migrator' ) ),
		);

		if ( ! isset( $notices[ $notice_code ] ) ) {
			return;
		}

		$type    = $notices[ $notice_code ][0];
		$message = $notices[ $notice_code ][1];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render this site's key, with a button to regenerate it.
	 *
	 * @return void
	 */
	private function render_key_section() {
		?>
		<h2><?php esc_html_e( 'Your Key', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( 'Share this key with the other site to allow it to connect to this site.', 'post-migrator' ); ?></p>
		<p>
			<input type="text" readonly="readonly" class="regular-text code" value="<?php echo esc_attr( Key_Manager::get_key() ); ?>" />
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_regenerate_key" />
			<?php wp_nonce_field( 'post_migrator_regenerate_key' ); ?>
			<?php submit_button( __( 'Regenerate Key', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the form used to test a connection to another site.
	 *
	 * @return void
	 */
	private function render_test_connection_section() {
		?>
		<h2><?php esc_html_e( 'Test Connection', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( "Enter the other site's URL and key to test a connection.", 'post-migrator' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_test_connection" />
			<?php wp_nonce_field( 'post_migrator_test_connection' ); ?>
			<p>
				<label for="post-migrator-target-url"><?php esc_html_e( 'Site URL', 'post-migrator' ); ?></label><br />
				<input type="text" id="post-migrator-target-url" name="target_url" class="regular-text" />
			</p>
			<p>
				<label for="post-migrator-target-key"><?php esc_html_e( 'Key', 'post-migrator' ); ?></label><br />
				<input type="text" id="post-migrator-target-key" name="target_key" class="regular-text code" />
			</p>
			<?php submit_button( __( 'Test Connection', 'post-migrator' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Enqueue the Post Migrator admin page assets.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_post-migrator' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/css/admin.css', array(), POST_MIGRATOR_VERSION );
		wp_enqueue_script( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/js/admin.js', array(), POST_MIGRATOR_VERSION, true );
	}
}
