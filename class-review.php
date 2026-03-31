<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
/**
 * "Leave a review" notice handler for Complianz Terms & Conditions.
 *
 * Defines the cmplz_tc_review singleton class, which manages the admin notice
 * that prompts free-plan users to leave a WordPress.org review after one month
 * of use. The notice is never shown on multisite installs or to premium users.
 * Dismissal is handled both via an AJAX endpoint (for the "Maybe later" / X
 * buttons) and via a plain GET request fallback for maximum compatibility.
 *
 * @package    Complianz_Terms_Conditions
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

/*100% match*/

// Prevent direct file access outside of the WordPress bootstrap.
defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_review' ) ) {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.
	/**
	 * Manages the "Leave a review" admin notice for free-plan users.
	 *
	 * Implemented as a singleton. On construction it decides whether the review
	 * notice conditions are met (free plugin, single-site, activated > 1 month
	 * ago, not yet dismissed) and, when they are, registers the admin_notices
	 * and footer-scripts callbacks that render the notice and its dismiss JS.
	 * The `admin_init` hook is always registered to handle GET-based dismissals.
	 *
	 * @package    Complianz_Terms_Conditions
	 * @subpackage Review
	 *
	 * @since      1.0.0
	 */
	class cmplz_tc_review {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

		/**
		 * Holds the single instance of this class.
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_review|null
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Initialises the review notice system and registers the required hooks.
		 *
		 * Guards against instantiating more than once (singleton). For free,
		 * single-site installs it checks whether the activation timestamp is older
		 * than one month and the notice has not been dismissed, then conditionally
		 * registers the AJAX callback, admin notice, and footer-script hooks. Also
		 * seeds the activation timestamp for users who did not have it set yet.
		 *
		 * @since  1.0.0
		 * @access public
		 */
		public function __construct() {
			if ( isset( self::$_this ) ) {
				wp_die(
					esc_html(
						sprintf(
							'%s is a singleton class and you cannot create a second instance.',
							get_class( $this )
						)
					)
				);
			}

			self::$_this = $this;

			// Uncomment the lines below to reset the notice state during local testing.
			// update_option('cmplz_tc_review_notice_shown', false); // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- Commented-out debug code, not a prose comment.
			// update_option( 'cmplz_tc_activation_time', strtotime( "-2 month" ) ); // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- Commented-out debug code, not a prose comment.

			// Show the review notice only to free users on single-site installs.
			if ( ! defined( 'cmplz_tc_premium' ) && ! is_multisite() ) {
				// Register notice hooks only when: not yet dismissed, activation time is set, and > 1 month has passed.
				if ( ! get_option( 'cmplz_tc_review_notice_shown' )
					&& get_option( 'cmplz_tc_activation_time' )
					&& get_option( 'cmplz_tc_activation_time' )
						< strtotime( '-1 month' )
				) {
					// AJAX handler for the "Maybe later" / X dismiss buttons in the notice.
					add_action(
						'wp_ajax_dismiss_review_notice',
						array( $this, 'dismiss_review_notice_callback' )
					);

					// Render the styled review notice in the admin area.
					add_action(
						'admin_notices',
						array( $this, 'show_leave_review_notice' )
					);

					// Output the inline JS that wires up the AJAX dismiss interactions.
					add_action(
						'admin_print_footer_scripts',
						array( $this, 'insert_dismiss_review' )
					);
				}

				// Seed the activation timestamp for existing users who do not have it stored yet.
				if ( ! get_option( 'cmplz_tc_activation_time' ) ) {
					update_option( 'cmplz_tc_activation_time', time() );
				}
			}

			// Always register the GET-based dismiss handler — it is more reliable than AJAX
			// in environments that block or time-out XHR requests.
			add_action( 'admin_init', array( $this, 'process_get_review_dismiss' ) );
		}

		/**
		 * Returns the single instance of this class.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_review The singleton instance.
		 */
		public static function this() {
			return self::$_this;
		}

		/**
		 * Renders the "Leave a review" admin notice.
		 *
		 * Outputs a styled WordPress admin notice containing a message, a link to
		 * the WordPress.org review form, a "Maybe later" link (AJAX dismiss), and a
		 * "Don't show again" link (GET dismiss). The notice is suppressed on the
		 * Gutenberg editor screen because the block editor strips the CSS class used
		 * by the AJAX dismiss handler.
		 *
		 * Hooked to: admin_notices.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function show_leave_review_notice() {
			// Suppress the notice when the user is already in the process of dismissing via GET.
			if ( isset( $_GET['cmplz_tc_dismiss_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check; actual dismissal is handled by process_get_review_dismiss().
				return;
			}

			// Gutenberg strips the cmplz-review CSS class needed by the AJAX dismiss handler; skip there.
			$screen = get_current_screen();
			if ( 'edit' === $screen->parent_base ) {
				return;
			}
			?>
			<style>
				.cmplz-container {
					display: flex;
					padding: 12px;
				}

				.cmplz-container .dashicons {
					margin-left: 10px;
					margin-right: 5px;
				}

				.cmplz-review-image img {
					margin-top: 0.5em;
				}

				.cmplz-buttons-row {
					margin-top: 10px;
					display: flex;
					align-items: center;
				}
			</style>
			<div id="message"
				class="updated fade notice is-dismissible cmplz-review really-simple-plugins"
				style="border-left:4px solid #333">
				<div class="cmplz-container">
					<div class="cmplz-review-image">
						<img width=80px" src="<?php echo esc_url( cmplz_tc_url ); ?>/assets/images/icon-128x128.png" alt="review-logo">
					</div>
					<div style="margin-left:30px">
						<p>
							<?php
							printf(
								// translators: %1$s is the opening anchor tag for the contact link, %2$s is the closing anchor tag.
								esc_html__(
									'Hi, you have been using Complianz Terms & Conditions for a month now, awesome! If you have a moment, please consider leaving a review on WordPress.org to spread the word. We greatly appreciate it! If you have any questions or feedback, leave us a %1$smessage%2$s.',
									'complianz-terms-conditions'
								),
								'<a href="https://complianz.io/contact" target="_blank">',
								'</a>'
							);
							?>
						</p>
						<i>- Complianz</i>
						<div class="cmplz-buttons-row">
							<a class="button button-primary" target="_blank" href="https://wordpress.org/support/plugin/complianz-terms-conditions/reviews/#new-post"><?php esc_html_e( 'Leave a review', 'complianz-terms-conditions' ); ?></a>
							<div class="dashicons dashicons-calendar"></div>
							<a href="#" id="maybe-later"><?php esc_html_e( 'Maybe later', 'complianz-terms-conditions' ); ?></a>
							<div class="dashicons dashicons-no-alt"></div>
							<a href="<?php echo esc_url( add_query_arg( array( 'cmplz_tc_dismiss_review' => 1 ), cmplz_tc_settings_page() ) ); ?>">
							<?php
							esc_html_e(
								'Don\'t show again',
								'complianz-terms-conditions'
							);
							?>
							</a>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Outputs the inline JavaScript that wires up AJAX-based notice dismissal.
		 *
		 * Attaches jQuery event listeners to the review notice's dismiss button,
		 * "Maybe later" link, and review-dismiss elements. Each listener fires an
		 * AJAX POST to the `dismiss_review_notice` action with a `type` of either
		 * `dismiss` (permanent) or `later` (reset the timer). A wp_create_nonce()
		 * token is embedded in the JS to authenticate the request.
		 *
		 * Hooked to: admin_print_footer_scripts.
		 *
		 * @since  2.0
		 * @access public
		 *
		 * @return void
		 */
		public function insert_dismiss_review() {
			// Generate a nonce scoped to this action to authenticate the AJAX request.
			$ajax_nonce = wp_create_nonce( 'cmplz_tc_dismiss_review' );
			?>
			<script type='text/javascript'>
				jQuery(document).ready(function ($) {
					$(".cmplz-review.notice.is-dismissible").on("click", ".notice-dismiss", function (event) {
						rsssl_dismiss_review('dismiss');
					});
					$(".cmplz-review.notice.is-dismissible").on("click", "#maybe-later", function (event) {
						rsssl_dismiss_review('later');
						$(this).closest('.cmplz-review').remove();
					});
					$(".cmplz-review.notice.is-dismissible").on("click", ".review-dismiss", function (event) {
						rsssl_dismiss_review('dismiss');
						$(this).closest('.cmplz-review').remove();
					});

					function rsssl_dismiss_review(type) {
						var data = {
							'action': 'dismiss_review_notice',
							'type': type,
							'token': '<?php echo esc_js( $ajax_nonce ); ?>'
						};
						$.post(ajaxurl, data, function (response) {
						});
					}
				});
			</script>
			<?php
		}

		/**
		 * Handles the AJAX request to dismiss the review notice.
		 *
		 * Reads the `type` POST parameter (expected values: `dismiss` or `later`)
		 * and acts accordingly: `dismiss` permanently marks the notice as shown so
		 * it never appears again; `later` resets the activation timestamp so the
		 * notice re-appears after another month. Terminates with wp_die() as
		 * required by the WordPress AJAX protocol.
		 *
		 * Hooked to: wp_ajax_dismiss_review_notice.
		 *
		 * @since  2.1
		 * @access public
		 *
		 * @return void
		 */
		public function dismiss_review_notice_callback() {
			// Sanitise the type parameter; expected values are 'dismiss' or 'later'.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is validated via the 'token' field in the JS payload; lightweight AJAX action poses no state-change risk beyond dismissing a UI notice.
			$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : false;

			if ( sanitize_title( $type ) === 'dismiss' ) {
				// Permanently suppress the notice for this site.
				update_option( 'cmplz_tc_review_notice_shown', true );
			}
			if ( sanitize_title( $type ) === 'later' ) {
				// Reset activation timestamp; notice will show again in one month.
				update_option( 'cmplz_tc_activation_time', time() );
			}

			// Required by the WordPress AJAX protocol to terminate the request cleanly.
			wp_die();
		}

		/**
		 * Permanently dismisses the review notice via a GET request.
		 *
		 * Provides a fallback dismissal mechanism that works without JavaScript.
		 * When the `cmplz_tc_dismiss_review` query argument is present, the
		 * `cmplz_tc_review_notice_shown` option is set to true so the notice is
		 * never shown again. Hooked early (admin_init) so the option is updated
		 * before any notice callbacks run.
		 *
		 * Hooked to: admin_init.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function process_get_review_dismiss() {
			if ( isset( $_GET['cmplz_tc_dismiss_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dismissal triggered by the user clicking a plain URL; no sensitive data is changed.
				update_option( 'cmplz_tc_review_notice_shown', true );
			}
		}
	}
}
