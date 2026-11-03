<?php
/**
 * Class Migration Page
 *
 * @package PostMigrator
 */

declare( strict_types = 1 );

namespace PostMigrator;

/**
 * Shared rendering for the Push and Pull admin pages' select/review/results
 * steps. The two directions differ only in how content is selected (local
 * WP_Query for push, a REST call to the other site for pull) and in a
 * handful of labels; everything else -- the review step's match radios,
 * manual-search mini-forms, and the results table -- is identical.
 *
 * Each concrete subclass is a sub-flow of the Migrate page (reached only via
 * a link there once a connection has been verified), not a top-level
 * section, so it is not added to Admin_Nav's tabs.
 */
abstract class Migration_Page {
	/**
	 * Instance of self.
	 *
	 * Redeclared by each concrete subclass -- a static property declared
	 * only here would otherwise be shared across every subclass.
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
	 * Enqueue the shared admin assets -- used by the review step's Quick
	 * Edit-style toggle (the same script/stylesheet Dashboard_Page already
	 * enqueues for its own page). Loaded on every step of this page, not
	 * just "review", since $hook_suffix can't distinguish between them; the
	 * script itself no-ops when its target markup isn't present.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . $this->page_slug() !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/css/admin.css', array(), POST_MIGRATOR_VERSION );
		wp_enqueue_script( 'post-migrator-admin', POST_MIGRATOR_URL . 'dist/js/admin.js', array(), POST_MIGRATOR_VERSION, true );
	}

	/**
	 * Get Singleton Instance.
	 *
	 * @param mixed ...$args Args assigned to instance in constructor.
	 * @return self
	 */
	public static function get_instance( ...$args ) {
		if ( is_null( static::$instance ) ) {
			static::$instance = new static( ...$args );
		}

		return static::$instance;
	}

	/**
	 * Register this page under Tools, without a visible menu item.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			$this->page_title(),
			$this->page_title(),
			'manage_options',
			$this->page_slug(),
			array( $this, 'render_page' )
		);

		remove_submenu_page( 'tools.php', $this->page_slug() );
	}

	/**
	 * Render the page, dispatching on the current step.
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
	 * Render the step where the user reviews auto-matches and confirms what to apply.
	 *
	 * @return void
	 */
	protected function render_review_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only token lookup, no state change.
		$token = isset( $_GET['batch_token'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_token'] ) ) : '';
		$batch = Transient_Session::get( $this->batch_transient_prefix(), $token );

		if ( null === $batch ) {
			?>
			<p><?php esc_html_e( 'This review session has expired or could not be found. Please select content again.', 'post-migrator' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . $this->page_slug() ) ); ?>"><?php esc_html_e( 'Start Over', 'post-migrator' ); ?></a></p>
			<?php
			return;
		}
		?>
		<h2><?php esc_html_e( 'Review and Confirm', 'post-migrator' ); ?></h2>
		<?php $this->render_target_banner(); ?>
		<p><?php esc_html_e( 'Choose what each selected item should do.', 'post-migrator' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $this->confirm_action() ); ?>" />
			<input type="hidden" name="batch_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( $this->confirm_action() ); ?>
			<table class="wp-list-table widefat fixed striped post-migrator-review-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'post-migrator' ); ?></th>
						<th><?php esc_html_e( 'Action', 'post-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$index = 0;

					foreach ( $batch['items'] as $origin_id => $item ) {
						$this->render_review_item( (int) $origin_id, $item, 0 === $index % 2 );
						++$index;
					}
					?>
				</tbody>
			</table>
			<p>
				<strong>
					<?php
					printf(
						$this->review_intro_format(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped by the subclass via esc_html__().
						count( $batch['items'] ),
						esc_html( Target_Store::get_target_url() )
					);
					?>
				</strong>
			</p>
			<?php submit_button( $this->confirm_button_label() ); ?>
		</form>
		<?php
		// Rendered as siblings of the main form above, never nested inside it
		// -- see the comment in render_review_controls() for why. Each one carries
		// no visible content of its own; the matching row's search input and
		// button (inside the main form) target it via their "form" attribute.
		foreach ( array_keys( $batch['items'] ) as $origin_id ) :
			?>
			<form id="post-migrator-search-<?php echo esc_attr( (string) $origin_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $this->manual_search_action() ); ?>" />
				<input type="hidden" name="batch_token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="origin_id" value="<?php echo esc_attr( (string) $origin_id ); ?>" />
				<?php wp_nonce_field( $this->manual_search_action() ); ?>
			</form>
			<?php
		endforeach;
		?>
		<p>
			<a href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'      => $this->cancel_action(),
							'batch_token' => $token,
						),
						admin_url( 'admin-post.php' )
					),
					$this->cancel_action()
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
	 * Render a single selected item as a view row (title, slug, a Quick
	 * Edit toggle, and a read-only summary of its currently chosen action)
	 * plus a hidden inline-edit row beneath it holding the actual controls
	 * -- the same view/edit row pair convention WordPress core's own post
	 * list tables use for Quick Edit, though purely client-side here (no
	 * AJAX save): everything still submits together via the confirm button
	 * at the bottom of the page, same as before this was a table.
	 *
	 * @param int   $origin_id The item's origin post ID.
	 * @param array $item      The item's match data.
	 * @param bool  $alternate Whether this item should get the "alternate" (striped) background.
	 * @return void
	 */
	private function render_review_item( int $origin_id, array $item, bool $alternate ) {
		// Open Quick Edit by default right after a manual search just added
		// fresh candidates for this item -- the user came from that search
		// expecting to see (and pick) the result, not a freshly-collapsed
		// row they'd have to reopen.
		$start_open = ! empty( $item['manual_matches'] );

		// core's CSS .striped rule stripes alternate raw <tr>s via
		// :nth-child(odd); with two <tr>s per item (a view row and a
		// hidden edit row), that would always land on the same row, not
		// alternate per item. Using core's own "alternate" class instead
		// (the pre-:nth-child convention WP_List_Table still falls back to
		// for exactly this kind of non-uniform row count) stripes correctly.
		$row_classes = array_filter(
			array(
				$start_open ? 'hidden' : '',
				$alternate ? 'alternate' : '',
			)
		);
		?>
		<tr id="post-migrator-row-<?php echo esc_attr( (string) $origin_id ); ?>" class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>">
			<td>
				<strong><?php echo esc_html( $item['title'] ); ?></strong>
				<div class="row-actions">
					<span class="edit">
						<a href="#" class="editinline post-migrator-quick-edit" data-origin-id="<?php echo esc_attr( (string) $origin_id ); ?>">
							<?php esc_html_e( 'Options', 'post-migrator' ); ?>
						</a>
					</span>
				</div>
			</td>
			<td><code><?php echo esc_html( $item['slug'] ); ?></code></td>
			<td class="post-migrator-summary" data-origin-id="<?php echo esc_attr( (string) $origin_id ); ?>">
				<?php echo esc_html( $this->default_item_action_label( $item ) ); ?>
			</td>
		</tr>
		<?php
		$edit_row_classes = array_filter(
			array(
				'post-migrator-inline-edit',
				$start_open ? '' : 'hidden',
				$alternate ? 'alternate' : '',
			)
		);
		?>
		<tr id="post-migrator-edit-<?php echo esc_attr( (string) $origin_id ); ?>" class="<?php echo esc_attr( implode( ' ', $edit_row_classes ) ); ?>">
			<td colspan="3">
				<?php $this->render_review_controls( $origin_id, $item ); ?>
				<p class="post-migrator-inline-edit-actions">
					<button type="button" class="button button-primary post-migrator-quick-edit-save" data-origin-id="<?php echo esc_attr( (string) $origin_id ); ?>"><?php esc_html_e( 'Update', 'post-migrator' ); ?></button>
					<button type="button" class="button post-migrator-quick-edit-cancel" data-origin-id="<?php echo esc_attr( (string) $origin_id ); ?>"><?php esc_html_e( 'Cancel', 'post-migrator' ); ?></button>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Get the label text for whichever item_action option is selected by
	 * default -- the same option Form_Handler falls back to when no radio
	 * is submitted at all ('create_new') -- for the view row's initial
	 * summary, before any Quick Edit interaction.
	 *
	 * @param array $item The item's match data.
	 * @return string
	 */
	private function default_item_action_label( array $item ): string {
		if ( null !== $item['origin_match'] ) {
			return sprintf(
				$this->origin_match_format(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain sprintf, not output; the caller escapes the result.
				$item['origin_match']['title'],
				$item['origin_match']['status']
			);
		}

		if ( null !== $item['slug_match'] ) {
			return sprintf(
				/* translators: 1: matched post title, 2: matched post status */
				__( 'Overwrite matching post "%1$s" (%2$s)', 'post-migrator' ),
				$item['slug_match']['title'],
				$item['slug_match']['status']
			);
		}

		return __( 'Create as a new post', 'post-migrator' );
	}

	/**
	 * Render a single selected item's Quick Edit controls.
	 *
	 * @param int   $origin_id The item's origin post ID.
	 * @param array $item      The item's match data.
	 * @return void
	 */
	private function render_review_controls( int $origin_id, array $item ) {
		// Deliberately not named "action[...]": the main form already has a
		// hidden input named "action" (WordPress's own admin-post.php routing
		// field). Both would normalize to the same top-level POST key, and
		// PHP would silently convert that field from a string to an array --
		// which sanitize_text_field() then reduces to '', making
		// admin-post.php treat the request as having no action at all and
		// silently do nothing.
		$field_name           = 'item_action[' . $origin_id . ']';
		$has_confident        = null !== $item['origin_match'] || null !== $item['slug_match'];
		$has_overwrite_option = $has_confident || ! empty( $item['manual_matches'] );
		?>
		<fieldset class="post-migrator-review-row">
			<?php if ( null !== $item['origin_match'] ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="overwrite:<?php echo esc_attr( (string) $item['origin_match']['destination_id'] ); ?>" checked="checked" />
					<?php
					printf(
						$this->origin_match_format(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped by the subclass via esc_html__().
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
				<p class="post-migrator-equal-options">
					<label>
						<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="create_new" checked="checked" />
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

			<?php
			// The search controls below are visually inside this row but are
			// bound, via the "form" attribute, to a standalone <form> rendered
			// after the main confirm form closes (see render_review_step()).
			// A <form> cannot be nested inside another <form> -- browsers merge
			// the two into one, stranding whichever submit button comes later
			// in the markup outside of any form at all, so it silently stops
			// being submittable. This keeps every <form> on the page a sibling,
			// never a descendant, of another.
			$search_form_id = 'post-migrator-search-' . $origin_id;
			?>
			<p>
				<label for="post-migrator-search-input-<?php echo esc_attr( (string) $origin_id ); ?>"><?php esc_html_e( 'Search for a different post to overwrite', 'post-migrator' ); ?></label><br />
				<input type="search" id="post-migrator-search-input-<?php echo esc_attr( (string) $origin_id ); ?>" name="search" form="<?php echo esc_attr( $search_form_id ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Title, slug, or ID', 'post-migrator' ); ?>" />
				<?php
				submit_button(
					__( 'Search', 'post-migrator' ),
					'secondary',
					'submit',
					false,
					array( 'form' => $search_form_id )
				);
				?>
			</p>

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
	 * Render the final step, showing the outcome of the apply.
	 *
	 * @return void
	 */
	protected function render_results_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only token lookup, no state change.
		$token   = isset( $_GET['result_token'] ) ? sanitize_text_field( wp_unslash( $_GET['result_token'] ) ) : '';
		$results = Transient_Session::get( $this->result_transient_prefix(), $token );

		if ( null === $results ) {
			?>
			<p><?php esc_html_e( 'No results to show.', 'post-migrator' ); ?></p>
			<?php
			return;
		}
		?>
		<h2><?php esc_html_e( 'Results', 'post-migrator' ); ?></h2>
		<?php $this->render_target_banner(); ?>
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
								<span class="post-migrator-error"><?php echo esc_html( $result['error'] ); ?></span>
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
	 * Render a prominent, repeated reminder of which site this flow involves,
	 * so the user can always confirm they're acting on the right site.
	 *
	 * @return void
	 */
	protected function render_target_banner() {
		?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					$this->site_banner_format(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped by the subclass via esc_html__().
					'<strong>' . esc_html( Target_Store::get_target_url() ) . '</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the step where the user selects content.
	 *
	 * @return void
	 */
	abstract protected function render_select_step();

	/**
	 * Get this page's slug.
	 *
	 * @return string
	 */
	abstract protected function page_slug(): string;

	/**
	 * Get this page's title, used for both its page heading and menu entry.
	 *
	 * @return string
	 */
	abstract protected function page_title(): string;

	/**
	 * Get the Transient_Session namespace used for this flow's in-progress batch.
	 *
	 * @return string
	 */
	abstract protected function batch_transient_prefix(): string;

	/**
	 * Get the Transient_Session namespace used for this flow's results.
	 *
	 * @return string
	 */
	abstract protected function result_transient_prefix(): string;

	/**
	 * Get the admin-post action (and matching nonce action) for the confirm step.
	 *
	 * @return string
	 */
	abstract protected function confirm_action(): string;

	/**
	 * Get the admin-post action (and matching nonce action) for the manual search step.
	 *
	 * @return string
	 */
	abstract protected function manual_search_action(): string;

	/**
	 * Get the admin-post action (and matching nonce action) for cancelling the flow.
	 *
	 * @return string
	 */
	abstract protected function cancel_action(): string;

	/**
	 * Get the confirm step's submit button label.
	 *
	 * @return string
	 */
	abstract protected function confirm_button_label(): string;

	/**
	 * Get the escaped, translated printf format for the review step's item-count summary.
	 *
	 * @return string
	 */
	abstract protected function review_intro_format(): string;

	/**
	 * Get the escaped, translated printf format for an origin-tracked match's radio label.
	 *
	 * @return string
	 */
	abstract protected function origin_match_format(): string;

	/**
	 * Get the escaped, translated printf format for the site banner.
	 *
	 * @return string
	 */
	abstract protected function site_banner_format(): string;
}
