<?php
/**
 * Class Pull Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers and renders the Post Migrator "Pull" page under Tools.
 */
class Pull_Page extends Migration_Page {
	/**
	 * This page's slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'post-migrator-pull';

	/**
	 * Instance of self.
	 *
	 * @var self
	 */
	protected static $instance;

	/**
	 * Render the step where the user selects content on the source site to pull.
	 *
	 * @return void
	 */
	protected function render_select_step() {
		$post_type = $this->get_selected_post_type();
		$status    = $this->get_selected_status();
		$search    = $this->get_search_term();
		?>
		<h2><?php esc_html_e( 'Select Content to Pull', 'post-migrator' ); ?></h2>
		<?php $this->render_target_banner(); ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<p>
				<label for="post-migrator-pull-post-type"><?php esc_html_e( 'Post Type', 'post-migrator' ); ?></label><br />
				<select id="post-migrator-pull-post-type" name="pm_post_type">
					<?php foreach ( Content_Browser::get_browsable_post_types() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $post_type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="post-migrator-pull-status"><?php esc_html_e( 'Status', 'post-migrator' ); ?></label><br />
				<select id="post-migrator-pull-status" name="status">
					<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Any', 'post-migrator' ); ?></option>
					<?php foreach ( get_post_statuses() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="post-migrator-pull-search"><?php esc_html_e( 'Search', 'post-migrator' ); ?></label><br />
				<input type="search" id="post-migrator-pull-search" name="s" class="regular-text" value="<?php echo esc_attr( $search ); ?>" />
			</p>
			<?php submit_button( __( 'Filter', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
		$list_result = Connection_Client::list_source_content(
			Target_Store::get_target_url(),
			Target_Store::get_target_key(),
			$post_type,
			$status,
			$search
		);

		if ( ! $list_result['success'] ) {
			$this->render_connection_error( $list_result );
			return;
		}

		$results = $list_result['results'] ?? array();

		if ( empty( $results ) ) {
			?>
			<p><?php esc_html_e( 'No content matches this filter.', 'post-migrator' ); ?></p>
			<?php
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_pull_select" />
			<?php wp_nonce_field( 'post_migrator_pull_select' ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Title', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'post-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $result ) : ?>
						<tr>
							<td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( (string) $result['id'] ); ?>" /></td>
							<td><?php echo esc_html( $result['title'] ); ?></td>
							<td><?php echo esc_html( $result['status'] ); ?></td>
							<td><?php echo esc_html( $result['modified'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Continue', 'post-migrator' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render an inline error when browsing the source site's content fails.
	 *
	 * @param array $result A Connection_Client result array with 'success' => false.
	 * @return void
	 */
	private function render_connection_error( array $result ) {
		?>
		<div class="notice notice-error inline">
			<p>
				<?php esc_html_e( 'Could not retrieve content from the source site.', 'post-migrator' ); ?>
				<?php if ( ! empty( $result['message'] ) ) : ?>
					<?php echo esc_html( $result['message'] ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get this page's slug.
	 *
	 * @return string
	 */
	protected function page_slug(): string {
		return self::PAGE_SLUG;
	}

	/**
	 * Get this page's title.
	 *
	 * @return string
	 */
	protected function page_title(): string {
		return __( 'Pull Content', 'post-migrator' );
	}

	/**
	 * Get the Transient_Session namespace used for this flow's in-progress batch.
	 *
	 * @return string
	 */
	protected function batch_transient_prefix(): string {
		return 'pull_batch';
	}

	/**
	 * Get the Transient_Session namespace used for this flow's results.
	 *
	 * @return string
	 */
	protected function result_transient_prefix(): string {
		return 'pull_result';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for the confirm step.
	 *
	 * @return string
	 */
	protected function confirm_action(): string {
		return 'post_migrator_pull_confirm';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for the manual search step.
	 *
	 * @return string
	 */
	protected function manual_search_action(): string {
		return 'post_migrator_pull_manual_search';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for cancelling the flow.
	 *
	 * @return string
	 */
	protected function cancel_action(): string {
		return 'post_migrator_pull_cancel';
	}

	/**
	 * Get the confirm step's submit button label.
	 *
	 * @return string
	 */
	protected function confirm_button_label(): string {
		return __( 'Pull Selected Content', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for the review step's item-count summary.
	 *
	 * @return string
	 */
	protected function review_intro_format(): string {
		/* translators: 1: number of items, 2: the source site's URL */
		return esc_html__( 'You are about to pull %1$d item(s) from %2$s.', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for an origin-tracked match's radio label.
	 *
	 * @return string
	 */
	protected function origin_match_format(): string {
		/* translators: 1: matched post title, 2: matched post status */
		return esc_html__( 'Overwrite previously-pulled post "%1$s" (%2$s)', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for the site banner.
	 *
	 * @return string
	 */
	protected function site_banner_format(): string {
		/* translators: %s: the source site's URL */
		return esc_html__( 'Source site: %s', 'post-migrator' );
	}

	/**
	 * Get the currently selected post type filter, defaulting to the first available.
	 *
	 * See the identical note in Push_Page -- read from "pm_post_type" rather
	 * than the reserved "post_type" query var for the same reason.
	 *
	 * @return string
	 */
	private function get_selected_post_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no state change.
		$post_type = isset( $_GET['pm_post_type'] ) ? sanitize_key( wp_unslash( $_GET['pm_post_type'] ) ) : '';
		$available = Content_Browser::get_browsable_post_types();

		if ( isset( $available[ $post_type ] ) ) {
			return $post_type;
		}

		$first = array_key_first( $available );

		return null !== $first ? $first : 'post';
	}

	/**
	 * Get the currently selected status filter.
	 *
	 * @return string
	 */
	private function get_selected_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no state change.
		return isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	}

	/**
	 * Get the current search term filter.
	 *
	 * @return string
	 */
	private function get_search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no state change.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}
}
