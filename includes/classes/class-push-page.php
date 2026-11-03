<?php
/**
 * Class Push Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Registers and renders the Post Migrator "Push" page under Tools.
 */
class Push_Page extends Migration_Page {
	/**
	 * This page's slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'post-migrator-push';

	/**
	 * Instance of self.
	 *
	 * @var self
	 */
	protected static $instance;

	/**
	 * Render the step where the user selects local content to push.
	 *
	 * @return void
	 */
	protected function render_select_step() {
		$post_type = $this->get_selected_post_type();
		$status    = $this->get_selected_status();
		$search    = $this->get_search_term();
		?>
		<h2><?php esc_html_e( 'Select Content to Push', 'post-migrator' ); ?></h2>
		<?php $this->render_target_banner(); ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<p>
				<label for="post-migrator-push-post-type"><?php esc_html_e( 'Post Type', 'post-migrator' ); ?></label><br />
				<select id="post-migrator-push-post-type" name="pm_post_type">
					<?php foreach ( Content_Browser::get_browsable_post_types() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $post_type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="post-migrator-push-status"><?php esc_html_e( 'Status', 'post-migrator' ); ?></label><br />
				<select id="post-migrator-push-status" name="status">
					<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Any', 'post-migrator' ); ?></option>
					<?php foreach ( get_post_statuses() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="post-migrator-push-search"><?php esc_html_e( 'Search', 'post-migrator' ); ?></label><br />
				<input type="search" id="post-migrator-push-search" name="s" class="regular-text" value="<?php echo esc_attr( $search ); ?>" />
			</p>
			<?php submit_button( __( 'Filter', 'post-migrator' ), 'secondary' ); ?>
		</form>
		<?php
		$query = Content_Browser::query_posts( $post_type, $status, $search );

		if ( empty( $query->posts ) ) {
			?>
			<p><?php esc_html_e( 'No content matches this filter.', 'post-migrator' ); ?></p>
			<?php
			return;
		}

		$diff_states = $this->fetch_diff_states( $query->posts );

		if ( null === $diff_states ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Could not check which posts have changed on the target site. Showing all posts as selectable.', 'post-migrator' ); ?></p>
			</div>
			<?php
			$diff_states = array();
		}
		?>
		<?php
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'     => $post->ID,
				'parent' => (int) $post->post_parent,
				'post'   => $post,
			);
		}

		$rows = Content_Browser::sort_hierarchically( $items, $post_type );
		?>
		<?php $this->render_include_unchanged_toggle(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_push_select" />
			<?php wp_nonce_field( 'post_migrator_push_select' ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th class="check-column"></th>
						<th><?php esc_html_e( 'Title', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Changes', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'post-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$post         = $row['item']['post'];
						$depth        = $row['depth'];
						$state        = $diff_states[ $post->ID ] ?? null;
						$is_unchanged = Content_Hasher::STATE_UNCHANGED === $state;
						?>
						<tr>
							<td class="check-column">
								<input id="post-migrator-cb-select-<?php echo esc_attr( (string) $post->ID ); ?>" type="checkbox" name="post_ids[]" value="<?php echo esc_attr( (string) $post->ID ); ?>" class="<?php echo esc_attr( $is_unchanged ? 'post-migrator-cb-unchanged' : '' ); ?>" <?php disabled( $is_unchanged ); ?> />
								<label for="post-migrator-cb-select-<?php echo esc_attr( (string) $post->ID ); ?>">
									<span class="screen-reader-text">
										<?php
										/* translators: %s: post title */
										printf( esc_html__( 'Select %s', 'post-migrator' ), esc_html( get_the_title( $post ) ) );
										?>
									</span>
								</label>
							</td>
							<td>
								<?php if ( $depth > 0 ) : ?>
									<?php echo wp_kses( str_repeat( '<span aria-hidden="true">&#8212;</span> ', $depth ), array( 'span' => array( 'aria-hidden' => true ) ) ); ?>
								<?php endif; ?>
								<?php echo esc_html( get_the_title( $post ) ); ?>
							</td>
							<td><?php $this->render_diff_state_cell( $state ); ?></td>
							<td><?php echo esc_html( $post->post_status ); ?></td>
							<td><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Continue', 'post-migrator' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Ask the target site how this page's candidate posts compare to its
	 * current content, for the "Changes" column.
	 *
	 * @param \WP_Post[] $posts The candidate posts being listed.
	 * @return array|null Map of post ID to diff state, or null if the target couldn't be checked.
	 */
	private function fetch_diff_states( array $posts ): ?array {
		$hashes = Content_Hasher::hash_batch( $posts );
		$items  = array();

		foreach ( $posts as $post ) {
			$items[] = array(
				'origin_id'    => $post->ID,
				'post_type'    => $post->post_type,
				'slug'         => $post->post_name,
				'content_hash' => $hashes[ $post->ID ],
				'modified_gmt' => $post->post_modified_gmt,
			);
		}

		$result = Connection_Client::check_diff( Target_Store::get_target_url(), Target_Store::get_target_key(), home_url(), $items );

		if ( ! $result['success'] ) {
			return null;
		}

		$diffs  = (array) ( $result['diffs'] ?? array() );
		$states = array();

		foreach ( $posts as $post ) {
			$diff                = (array) ( $diffs[ (string) $post->ID ] ?? array() );
			$states[ $post->ID ] = $diff['state'] ?? null;
		}

		return $states;
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
		return __( 'Push Content', 'post-migrator' );
	}

	/**
	 * Get the Transient_Session namespace used for this flow's in-progress batch.
	 *
	 * @return string
	 */
	protected function batch_transient_prefix(): string {
		return 'push_batch';
	}

	/**
	 * Get the Transient_Session namespace used for this flow's results.
	 *
	 * @return string
	 */
	protected function result_transient_prefix(): string {
		return 'push_result';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for the confirm step.
	 *
	 * @return string
	 */
	protected function confirm_action(): string {
		return 'post_migrator_push_confirm';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for the manual search step.
	 *
	 * @return string
	 */
	protected function manual_search_action(): string {
		return 'post_migrator_push_manual_search';
	}

	/**
	 * Get the admin-post action (and matching nonce action) for cancelling the flow.
	 *
	 * @return string
	 */
	protected function cancel_action(): string {
		return 'post_migrator_push_cancel';
	}

	/**
	 * Get the confirm step's submit button label.
	 *
	 * @return string
	 */
	protected function confirm_button_label(): string {
		return __( 'Push Selected Content', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for the review step's item-count summary.
	 *
	 * @return string
	 */
	protected function review_intro_format(): string {
		/* translators: 1: number of items, 2: the target site's URL */
		return esc_html__( 'You are about to push %1$d item(s) to %2$s.', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for an origin-tracked match's radio label.
	 *
	 * @return string
	 */
	protected function origin_match_format(): string {
		/* translators: 1: matched post title, 2: matched post status */
		return esc_html__( 'Overwrite previously-pushed post "%1$s" (%2$s)', 'post-migrator' );
	}

	/**
	 * Get the escaped, translated printf format for the site banner.
	 *
	 * @return string
	 */
	protected function site_banner_format(): string {
		/* translators: %s: the target site's URL */
		return esc_html__( 'Target site: %s', 'post-migrator' );
	}

	/**
	 * Get the currently selected post type filter, defaulting to the first available.
	 *
	 * Read from "pm_post_type" rather than the reserved "post_type" query var:
	 * WordPress's own admin bootstrap treats a top-level $_GET['post_type'] as
	 * global state (populating $typenow) on every admin page load, not just
	 * post-type list screens, which breaks this hidden Tools page's menu
	 * parent resolution and causes a "Sorry, you are not allowed to access
	 * this page" 403 once this page's own submenu entry has been removed via
	 * remove_submenu_page().
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
