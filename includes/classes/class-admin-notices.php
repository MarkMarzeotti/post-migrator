<?php
/**
 * Class Admin Notices
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Renders a Post Migrator admin notice based on the current request's notice code.
 */
class Admin_Notices {
	/**
	 * Render an admin notice based on the current request's notice code, if any.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, no state change.
		$notice_code = isset( $_GET['pm_notice'] ) ? sanitize_key( wp_unslash( $_GET['pm_notice'] ) ) : '';

		if ( '' === $notice_code ) {
			return;
		}

		$notices = self::get_notices();

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
	 * Get the fixed map of notice codes to their type and message.
	 *
	 * @return array
	 */
	private static function get_notices(): array {
		return array(
			'key_regenerated'                => array( 'success', __( 'Key regenerated.', 'post-migrator' ) ),
			'permissions_saved'              => array( 'success', __( 'Permissions saved.', 'post-migrator' ) ),
			'connection_ok'                  => array( 'success', __( 'Connection successful.', 'post-migrator' ) ),
			'connection_unauthorized'        => array( 'error', __( 'The target site rejected the key.', 'post-migrator' ) ),
			'connection_unreachable'         => array( 'error', __( 'Could not reach the target site.', 'post-migrator' ) ),
			'connection_invalid_url'         => array( 'error', __( 'Please enter a valid site URL and key.', 'post-migrator' ) ),
			'connection_unexpected_response' => array( 'error', __( 'The target site returned an unexpected response.', 'post-migrator' ) ),
			'push_settings_saved'            => array( 'success', __( 'Push settings saved.', 'post-migrator' ) ),
			'push_connection_not_verified'   => array( 'error', __( 'Test the connection successfully before pushing content.', 'post-migrator' ) ),
			'push_no_posts_selected'         => array( 'error', __( 'Select at least one item to push.', 'post-migrator' ) ),
			'push_match_check_failed'        => array( 'error', __( 'Could not check for matching content on the other site.', 'post-migrator' ) ),
			'push_search_failed'             => array( 'error', __( 'The search on the other site failed.', 'post-migrator' ) ),
			'push_batch_expired'             => array( 'error', __( 'This push session expired. Please select content to push again.', 'post-migrator' ) ),
			'push_complete'                  => array( 'success', __( 'Push complete.', 'post-migrator' ) ),
			'push_complete_with_errors'      => array( 'warning', __( 'Push complete, but some items had errors.', 'post-migrator' ) ),
		);
	}
}
