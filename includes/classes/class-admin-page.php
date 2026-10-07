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
		</div>
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
