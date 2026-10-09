<?php
/**
 * Plugin Name:       Post Migrator
 * Description:       Tools for migrating posts.
 * Version:           0.1.0
 * Author:            Mark Marzeotti
 * Author URI:        https://markmarzeotti.com
 * Text Domain:       post-migrator
 *
 * @package PostMigrator
 */

defined( 'ABSPATH' ) || exit;

define( 'POST_MIGRATOR_VERSION', '0.1.0' );
define( 'POST_MIGRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register PSR-4 autoloader for plugin classes.
 */
spl_autoload_register(
	function ( $class_name ) {
		// Only handle classes in the PostMigrator namespace.
		if ( strpos( $class_name, 'PostMigrator\\' ) !== 0 ) {
			return;
		}

		// Remove the namespace prefix.
		$class_name = substr( $class_name, strlen( 'PostMigrator\\' ) );

		// Convert namespace separators to directory separators.
		$class_name = str_replace( '\\', '/', $class_name );

		// Convert class name from PascalCase to kebab-case for file naming.
		$class_parts = explode( '/', $class_name );
		$class_name  = array_pop( $class_parts );
		$class_name  = strtolower( str_replace( '_', '-', $class_name ) );

		// Build the file path.
		$base_path      = POST_MIGRATOR_DIR . 'includes/classes/';
		$namespace_path = ! empty( $class_parts ) ? implode( '/', array_map( 'strtolower', $class_parts ) ) . '/' : '';
		$file_path      = $base_path . $namespace_path . 'class-' . $class_name . '.php';

		// Include the file if it exists.
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

add_action( 'plugins_loaded', array( '\PostMigrator\Dashboard_Page', 'get_instance' ) );
add_action( 'plugins_loaded', array( '\PostMigrator\Migrate_Page', 'get_instance' ) );
add_action( 'plugins_loaded', array( '\PostMigrator\Push_Page', 'get_instance' ) );
add_action( 'plugins_loaded', array( '\PostMigrator\REST_Controller', 'get_instance' ) );
add_action( 'plugins_loaded', array( '\PostMigrator\Form_Handler', 'get_instance' ) );
