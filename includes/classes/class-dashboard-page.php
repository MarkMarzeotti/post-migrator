<?php
/**
 * Class Dashboard Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers and renders the Post Migrator dashboard page under Tools.
 */
class Dashboard_Page {
	/**
	 * This page's slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'post-migrator';

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
	 * Register the Post Migrator dashboard page under Tools.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			__( 'Post Migrator', 'post-migrator' ),
			__( 'Post Migrator', 'post-migrator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Post Migrator dashboard page.
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
			$this->render_connection_info_section();
			$this->render_permissions_section();
			$this->render_push_settings_section();
			?>
		</div>
		<?php
	}

	/**
	 * Render this site's URL and key, with a button to regenerate the key.
	 *
	 * @return void
	 */
	private function render_connection_info_section() {
		?>
		<h2><?php esc_html_e( 'Your Connection Details', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( 'Share these with the other site to allow it to connect to this site.', 'post-migrator' ); ?></p>
		<p>
			<label for="post-migrator-site-url"><?php esc_html_e( 'Site URL', 'post-migrator' ); ?></label><br />
			<span class="post-migrator-field-row">
				<input type="text" id="post-migrator-site-url" readonly="readonly" class="regular-text code" value="<?php echo esc_url( home_url() ); ?>" />
				<button type="button" class="button post-migrator-copy" data-copy-target="post-migrator-site-url"><?php esc_html_e( 'Copy', 'post-migrator' ); ?></button>
			</span>
		</p>
		<p>
			<label for="post-migrator-key"><?php esc_html_e( 'Key', 'post-migrator' ); ?></label><br />
			<span class="post-migrator-field-row">
				<input type="text" id="post-migrator-key" readonly="readonly" class="regular-text code" value="<?php echo esc_attr( Key_Manager::get_key() ); ?>" />
				<button type="button" class="button post-migrator-copy" data-copy-target="post-migrator-key"><?php esc_html_e( 'Copy', 'post-migrator' ); ?></button>
			</span>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_regenerate_key" />
			<?php wp_nonce_field( 'post_migrator_regenerate_key' ); ?>
			<?php submit_button( __( 'Regenerate Key', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the form used to control whether other sites may pull or push content.
	 *
	 * @return void
	 */
	private function render_permissions_section() {
		?>
		<h2><?php esc_html_e( 'Permissions', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( 'Control whether other sites are allowed to pull content from this site or push content to it. These apply even once a connection has been tested successfully.', 'post-migrator' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_save_permissions" />
			<?php wp_nonce_field( 'post_migrator_save_permissions' ); ?>
			<p>
				<label>
					<input type="checkbox" name="allow_pull" value="1" <?php checked( Permissions::pull_allowed() ); ?> />
					<?php esc_html_e( 'Allow other sites to pull content from this site', 'post-migrator' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="allow_push" value="1" <?php checked( Permissions::push_allowed() ); ?> />
					<?php esc_html_e( 'Allow other sites to push content to this site', 'post-migrator' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Save Permissions', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the form used to set the default author for content pushed to this site.
	 *
	 * @return void
	 */
	private function render_push_settings_section() {
		?>
		<h2><?php esc_html_e( 'Push Settings', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( "Choose the author assigned to new posts created here by a push, when the pushed post's author doesn't have a matching account on this site.", 'post-migrator' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_save_push_settings" />
			<?php wp_nonce_field( 'post_migrator_save_push_settings' ); ?>
			<p>
				<label for="post-migrator-default-author"><?php esc_html_e( 'Default Author', 'post-migrator' ); ?></label><br />
				<?php
				wp_dropdown_users(
					array(
						'name'     => 'default_author',
						'id'       => 'post-migrator-default-author',
						'selected' => Push_Settings::get_default_author(),
					)
				);
				?>
			</p>
			<?php submit_button( __( 'Save Push Settings', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Enqueue the dashboard page assets.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/css/admin.css', array(), POST_MIGRATOR_VERSION );
		wp_enqueue_script( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/js/admin.js', array(), POST_MIGRATOR_VERSION, true );
		wp_localize_script(
			'post-migrator-admin',
			'postMigratorAdmin',
			array(
				'copiedLabel' => __( 'Copied!', 'post-migrator' ),
			)
		);
	}
}
