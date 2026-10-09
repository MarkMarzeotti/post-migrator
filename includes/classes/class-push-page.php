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
 *
 * This is a sub-flow of the Migrate page (reached only via a link there once
 * a connection has been verified), not a top-level section, so it is not
 * added to Admin_Nav's tabs.
 */
class Push_Page {
	/**
	 * This page's slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'post-migrator-push';

	/**
	 * Number of posts shown per page on the selection step.
	 *
	 * @var int
	 */
	private const POSTS_PER_PAGE = 50;

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
	 * Register the Push page under Tools, without a visible menu item.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			__( 'Push Content', 'post-migrator' ),
			__( 'Push Content', 'post-migrator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		remove_submenu_page( 'tools.php', self::PAGE_SLUG );
	}

	/**
	 * Render the Push page, dispatching on the current step.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			Admin_Nav::render( Migrate_Page::PAGE_SLUG );
			Admin_Notices::render();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step dispatch, no state change.
			$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'select';

			switch ( $step ) {
				case 'review':
					$this->render_review_step();
					break;

				case 'results':
					$this->render_results_step();
					break;

				default:
					$this->render_select_step();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the step where the user selects local content to push.
	 *
	 * @return void
	 */
	private function render_select_step() {
		$post_type = $this->get_selected_post_type();
		$status    = $this->get_selected_status();
		$search    = $this->get_search_term();
		?>
		<h2><?php esc_html_e( 'Select Content to Push', 'post-migrator' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<p>
				<label for="post-migrator-push-post-type"><?php esc_html_e( 'Post Type', 'post-migrator' ); ?></label><br />
				<select id="post-migrator-push-post-type" name="pm_post_type">
					<?php foreach ( $this->get_pushable_post_types() as $slug => $label ) : ?>
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
		$query = $this->query_own_posts( $post_type, $status, $search );

		if ( empty( $query->posts ) ) {
			?>
			<p><?php esc_html_e( 'No content matches this filter.', 'post-migrator' ); ?></p>
			<?php
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_push_select" />
			<?php wp_nonce_field( 'post_migrator_push_select' ); ?>
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
					<?php foreach ( $query->posts as $post ) : ?>
						<tr>
							<td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( (string) $post->ID ); ?>" /></td>
							<td><?php echo esc_html( get_the_title( $post ) ); ?></td>
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
	 * Render the step where the user reviews auto-matches and confirms what to push.
	 *
	 * @return void
	 */
	private function render_review_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only token lookup, no state change.
		$token = isset( $_GET['push_batch_token'] ) ? sanitize_text_field( wp_unslash( $_GET['push_batch_token'] ) ) : '';
		$batch = Transient_Session::get( 'push_batch', $token );

		if ( null === $batch ) {
			?>
			<p><?php esc_html_e( 'This review session has expired or could not be found. Please select content to push again.', 'post-migrator' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Start Over', 'post-migrator' ); ?></a></p>
			<?php
			return;
		}
		?>
		<h2><?php esc_html_e( 'Review and Confirm', 'post-migrator' ); ?></h2>
		<p><?php esc_html_e( 'Choose what each selected item should do on the other site.', 'post-migrator' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="post_migrator_push_confirm" />
			<input type="hidden" name="push_batch_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'post_migrator_push_confirm' ); ?>
			<?php foreach ( $batch['items'] as $origin_id => $item ) : ?>
				<?php $this->render_review_row( (int) $origin_id, $item, $token ); ?>
			<?php endforeach; ?>
			<?php submit_button( __( 'Push Selected Content', 'post-migrator' ) ); ?>
		</form>
		<p>
			<a href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'           => 'post_migrator_push_cancel',
							'push_batch_token' => $token,
						),
						admin_url( 'admin-post.php' )
					),
					'post_migrator_push_cancel'
				)
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned 
			?>
						">
				<?php esc_html_e( 'Cancel', 'post-migrator' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render a single selected item's row on the review step.
	 *
	 * @param int    $origin_id The item's origin post ID.
	 * @param array  $item      The item's match data.
	 * @param string $token     The current batch's transient token.
	 * @return void
	 */
	private function render_review_row( int $origin_id, array $item, string $token ) {
		$field_name           = 'action[' . $origin_id . ']';
		$has_confident        = null !== $item['origin_match'] || null !== $item['slug_match'];
		$has_overwrite_option = $has_confident || ! empty( $item['manual_matches'] );
		?>
		<fieldset class="post-migrator-push-row">
			<legend><strong><?php echo esc_html( $item['title'] ); ?></strong> <code><?php echo esc_html( $item['slug'] ); ?></code></legend>

			<?php if ( null !== $item['origin_match'] ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="overwrite:<?php echo esc_attr( (string) $item['origin_match']['destination_id'] ); ?>" checked="checked" />
					<?php
					printf(
						/* translators: 1: matched post title, 2: matched post status */
						esc_html__( 'Overwrite previously-pushed post "%1$s" (%2$s)', 'post-migrator' ),
						esc_html( $item['origin_match']['title'] ),
						esc_html( $item['origin_match']['status'] )
					);
					?>
				</label><br />
			<?php elseif ( null !== $item['slug_match'] ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="overwrite:<?php echo esc_attr( (string) $item['slug_match']['destination_id'] ); ?>" checked="checked" />
					<?php
					printf(
						/* translators: 1: matched post title, 2: matched post status */
						esc_html__( 'Overwrite matching post "%1$s" (%2$s)', 'post-migrator' ),
						esc_html( $item['slug_match']['title'] ),
						esc_html( $item['slug_match']['status'] )
					);
					?>
				</label><br />
			<?php endif; ?>

			<?php if ( $has_confident ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="create_new" />
					<?php esc_html_e( 'Create as a new post instead', 'post-migrator' ); ?>
				</label><br />
			<?php else : ?>
				<p class="post-migrator-push-equal-options">
					<label>
						<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="create_new" />
						<?php esc_html_e( 'Create as a new post', 'post-migrator' ); ?>
					</label>
					<?php foreach ( $item['title_matches'] as $suggestion ) : ?>
						<br /><label>
							<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="overwrite:<?php echo esc_attr( (string) $suggestion['destination_id'] ); ?>" />
							<?php
							printf(
								/* translators: 1: suggested post title, 2: suggested post status */
								esc_html__( 'Possible match: overwrite "%1$s" (%2$s)', 'post-migrator' ),
								esc_html( $suggestion['title'] ),
								esc_html( $suggestion['status'] )
							);
							?>
						</label>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php foreach ( $item['manual_matches'] as $manual ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="overwrite:<?php echo esc_attr( (string) $manual['destination_id'] ); ?>" />
					<?php
					printf(
						/* translators: 1: found post title, 2: found post status */
						esc_html__( 'Found: overwrite "%1$s" (%2$s)', 'post-migrator' ),
						esc_html( $manual['title'] ),
						esc_html( $manual['status'] )
					);
					?>
				</label><br />
			<?php endforeach; ?>

			<details>
				<summary><?php esc_html_e( 'Search for a different post to overwrite', 'post-migrator' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="post_migrator_push_manual_search" />
					<input type="hidden" name="push_batch_token" value="<?php echo esc_attr( $token ); ?>" />
					<input type="hidden" name="origin_id" value="<?php echo esc_attr( (string) $origin_id ); ?>" />
					<?php wp_nonce_field( 'post_migrator_push_manual_search' ); ?>
					<input type="search" name="search" class="regular-text" placeholder="<?php esc_attr_e( 'Title, slug, or ID', 'post-migrator' ); ?>" />
					<?php submit_button( __( 'Search', 'post-migrator' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>

			<?php if ( $has_overwrite_option ) : ?>
				<p>
					<?php esc_html_e( 'Slug for overwritten post:', 'post-migrator' ); ?>
					<label><input type="radio" name="slug_strategy[<?php echo esc_attr( (string) $origin_id ); ?>]" value="source" checked="checked" /> <?php esc_html_e( 'Use source slug', 'post-migrator' ); ?></label>
					<label><input type="radio" name="slug_strategy[<?php echo esc_attr( (string) $origin_id ); ?>]" value="destination" /> <?php esc_html_e( "Keep destination's current slug", 'post-migrator' ); ?></label>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Render the final step, showing the outcome of the push.
	 *
	 * @return void
	 */
	private function render_results_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only token lookup, no state change.
		$token   = isset( $_GET['push_result_token'] ) ? sanitize_text_field( wp_unslash( $_GET['push_result_token'] ) ) : '';
		$results = Transient_Session::get( 'push_result', $token );

		if ( null === $results ) {
			?>
			<p><?php esc_html_e( 'No push results to show.', 'post-migrator' ); ?></p>
			<?php
			return;
		}
		?>
		<h2><?php esc_html_e( 'Push Results', 'post-migrator' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'post-migrator' ); ?></th>
					<th><?php esc_html_e( 'Result', 'post-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['items'] as $result ) : ?>
					<tr>
						<td><?php echo esc_html( $result['title'] ); ?></td>
						<td>
							<?php if ( $result['error'] ) : ?>
								<span class="post-migrator-push-error"><?php echo esc_html( $result['error'] ); ?></span>
							<?php else : ?>
								<?php
								printf(
									/* translators: %s: action taken, e.g. "created" or "updated" */
									esc_html__( 'Success: %s', 'post-migrator' ),
									esc_html( $result['action_taken'] )
								);
								?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=' . Migrate_Page::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Back to Migrate', 'post-migrator' ); ?></a></p>
		<?php
	}

	/**
	 * Get the public, pushable post types, keyed by slug.
	 *
	 * @return array
	 */
	private function get_pushable_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		$options = array();

		foreach ( $post_types as $slug => $object ) {
			$options[ $slug ] = $object->labels->name;
		}

		return $options;
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
		$available = $this->get_pushable_post_types();

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

	/**
	 * Query this site's own posts for the selection step.
	 *
	 * @param string $post_type The post type to query.
	 * @param string $status    The status to filter by, or '' for any.
	 * @param string $search    The search term, or '' for none.
	 * @return \WP_Query
	 */
	private function query_own_posts( string $post_type, string $status, string $search ): \WP_Query {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => '' !== $status ? $status : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => self::POSTS_PER_PAGE,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return new \WP_Query( $args );
	}
}
