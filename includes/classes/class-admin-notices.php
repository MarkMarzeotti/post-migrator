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

		// An optional, short free-text detail -- e.g. the other site's own
		// explanation of a connection failure -- appended to the fixed
		// message above so the notice can be specific without needing a
		// dedicated static entry for every possible remote error string.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, no state change.
		$detail = isset( $_GET['pm_notice_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pm_notice_detail'] ) ) : '';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p>
				<?php echo esc_html( $message ); ?>
				<?php if ( '' !== $detail ) : ?>
					<?php echo esc_html( $detail ); ?>
				<?php endif; ?>
			</p>
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
			'key_regenerated'                  => array( 'success', __( 'Key regenerated.', 'post-migrator' ) ),
			'permissions_saved'                => array( 'success', __( 'Permissions saved.', 'post-migrator' ) ),
			'connection_ok'                    => array( 'success', __( 'Connection successful.', 'post-migrator' ) ),
			'connection_unauthorized'          => array( 'error', __( 'The target site rejected the key.', 'post-migrator' ) ),
			'connection_unreachable'           => array( 'error', __( 'Could not reach the target site.', 'post-migrator' ) ),
			'connection_invalid_url'           => array( 'error', __( 'Please enter a valid site URL and key.', 'post-migrator' ) ),
			'connection_unexpected_response'   => array( 'error', __( 'The target site returned an unexpected response.', 'post-migrator' ) ),
			'push_settings_saved'              => array( 'success', __( 'Push settings saved.', 'post-migrator' ) ),
			'push_connection_not_verified'     => array( 'error', __( 'Test the connection successfully before pushing content.', 'post-migrator' ) ),
			'push_no_posts_selected'           => array( 'error', __( 'Select at least one item to push.', 'post-migrator' ) ),
			'push_connection_invalid_key'      => array( 'error', __( 'The other site rejected the key for this request.', 'post-migrator' ) ),
			'push_connection_forbidden'        => array( 'error', __( 'The other site is not configured to accept this kind of connection.', 'post-migrator' ) ),
			'push_connection_version_mismatch' => array( 'error', __( "The other site's Post Migrator plugin does not recognize this request -- it may be a different version.", 'post-migrator' ) ),
			'push_connection_unreachable'      => array( 'error', __( 'Could not reach the other site.', 'post-migrator' ) ),
			'push_connection_unexpected'       => array( 'error', __( 'The other site returned an unexpected response.', 'post-migrator' ) ),
			'push_batch_expired'               => array( 'error', __( 'This push session expired. Please select content to push again.', 'post-migrator' ) ),
			'push_complete'                    => array( 'success', __( 'Push complete.', 'post-migrator' ) ),
			'push_complete_with_errors'        => array( 'warning', __( 'Push complete, but some items had errors.', 'post-migrator' ) ),
			'pull_connection_not_verified'     => array( 'error', __( 'Test the connection successfully before pulling content.', 'post-migrator' ) ),
			'pull_no_posts_selected'           => array( 'error', __( 'Select at least one item to pull.', 'post-migrator' ) ),
			'pull_connection_invalid_key'      => array( 'error', __( 'The other site rejected the key for this request.', 'post-migrator' ) ),
			'pull_connection_forbidden'        => array( 'error', __( 'The other site is not configured to accept this kind of connection.', 'post-migrator' ) ),
			'pull_connection_version_mismatch' => array( 'error', __( "The other site's Post Migrator plugin does not recognize this request -- it may be a different version.", 'post-migrator' ) ),
			'pull_connection_unreachable'      => array( 'error', __( 'Could not reach the other site.', 'post-migrator' ) ),
			'pull_connection_unexpected'       => array( 'error', __( 'The other site returned an unexpected response.', 'post-migrator' ) ),
			'pull_batch_expired'               => array( 'error', __( 'This pull session expired. Please select content to pull again.', 'post-migrator' ) ),
			'pull_complete'                    => array( 'success', __( 'Pull complete.', 'post-migrator' ) ),
			'pull_complete_with_errors'        => array( 'warning', __( 'Pull complete, but some items had errors.', 'post-migrator' ) ),
		);
	}
}
