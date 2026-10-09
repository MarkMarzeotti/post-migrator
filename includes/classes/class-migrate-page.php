<?php
/**
 * Class Migrate Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers and renders the Post Migrator "Migrate" page under Tools.
 */
class Migrate_Page {
	/**
	 * This page's slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'post-migrator-migrate';

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
	 * Register the Migrate page under Tools, without a visible menu item.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			__( 'Migrate', 'post-migrator' ),
			__( 'Migrate', 'post-migrator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		remove_submenu_page( 'tools.php', self::PAGE_SLUG );
	}

	/**
	 * Render the Migrate page.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			Admin_Nav::render( self::PAGE_SLUG );
			Admin_Notices::render();
			$this->render_test_connection_section();
			?>
		</div>
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
				<input type="text" id="post-migrator-target-url" name="target_url" class="regular-text" value="<?php echo esc_attr( Target_Store::get_target_url() ); ?>" />
			</p>
			<p>
				<label for="post-migrator-target-key"><?php esc_html_e( 'Key', 'post-migrator' ); ?></label><br />
				<input type="text" id="post-migrator-target-key" name="target_key" class="regular-text code" value="<?php echo esc_attr( Target_Store::get_target_key() ); ?>" />
			</p>
			<?php submit_button( __( 'Test Connection', 'post-migrator' ) ); ?>
		</form>
		<?php
	}
}
