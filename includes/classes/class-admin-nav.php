<?php
/**
 * Class Admin Nav
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Renders the shared tab navigation between Post Migrator's admin pages.
 */
class Admin_Nav {
	/**
	 * Render the tab navigation, marking the given slug as the active tab.
	 *
	 * @param string $current_slug The current page's slug.
	 * @return void
	 */
	public static function render( string $current_slug ) {
		$tabs = array(
			Dashboard_Page::PAGE_SLUG => __( 'Dashboard', 'post-migrator' ),
			Migrate_Page::PAGE_SLUG   => __( 'Migrate', 'post-migrator' ),
		);
		?>
		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( admin_url( 'tools.php?page=' . $slug ) ); ?>"
					class="nav-tab<?php echo esc_attr( $slug === $current_slug ? ' nav-tab-active' : '' ); ?>"
				>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}
}
