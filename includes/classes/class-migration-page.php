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
			<?php foreach ( $batch['items'] as $origin_id => $item ) : ?>
				<?php $this->render_review_row( (int) $origin_id, $item ); ?>
			<?php endforeach; ?>
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
		// -- see the comment in render_review_row() for why. Each one carries
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
	 * Render a single selected item's row on the review step.
	 *
	 * @param int   $origin_id The item's origin post ID.
	 * @param array $item      The item's match data.
	 * @return void
	 */
	private function render_review_row( int $origin_id, array $item ) {
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
			<legend><strong><?php echo esc_html( $item['title'] ); ?></strong> <code><?php echo esc_html( $item['slug'] ); ?></code></legend>

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
			<details>
				<summary><?php esc_html_e( 'Search for a different post to overwrite', 'post-migrator' ); ?></summary>
				<input type="search" name="search" form="<?php echo esc_attr( $search_form_id ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Title, slug, or ID', 'post-migrator' ); ?>" />
				<?php
				submit_button(
					__( 'Search', 'post-migrator' ),
					'secondary',
					'submit',
					false,
					array( 'form' => $search_form_id )
				);
				?>
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
