<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
/**
 * Admin controller for the Complianz Terms & Conditions plugin.
 *
 * Defines the cmplz_tc_admin singleton class which manages all WordPress
 * admin integration: asset enqueuing, menu registration (standalone or nested
 * under the Complianz GDPR plugin), plugin action links, activation redirect,
 * companion-plugin status links, and the upgrade routine. All hooks are
 * registered in the constructor so the class is self-contained.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Admin
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Prevent direct file access outside of the WordPress bootstrap.
defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_admin' ) ) {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.
	/**
	 * Admin controller singleton for the Complianz T&C plugin.
	 *
	 * Registers all admin-area hooks and provides helper methods used by
	 * templates and other classes. Only one instance is ever created; access
	 * it through COMPLIANZ_TC::$admin.
	 *
	 * @package    Complianz_Terms_Conditions
	 * @subpackage Admin
	 *
	 * @since      1.0.0
	 */
	class cmplz_tc_admin {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

		/**
		 * Holds the single instance of this class (singleton pattern).
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_admin|null
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Error message to display in the admin area after a failed operation.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string
		 */
		public $error_message = '';

		/**
		 * Success message to display in the admin area after a successful operation.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string
		 */
		public $success_message = '';

		/**
		 * Initialises the admin controller and registers all WordPress hooks.
		 *
		 * Guards against a second instantiation (singleton). When the Complianz
		 * GDPR/CCPA plugin is active (`cmplz_version` defined) the T&C menu
		 * page is nested under the Complianz top-level menu via the
		 * `cmplz_admin_menu` action; otherwise a standalone "Tools > Terms &
		 * Conditions" submenu is registered. Plugin action links are added for
		 * both the standard and network admin plugins screens.
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

			// Enqueue admin styles and scripts on T&C plugin screens.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// Register the admin menu: nest under Complianz when available, otherwise use Tools.
			if ( ! defined( 'cmplz_version' ) ) {
				add_action( 'admin_menu', array( $this, 'register_main_menu' ), 20 );
			} else {
				add_action( 'cmplz_admin_menu', array( $this, 'register_admin_page' ), 20 );
			}

			// Add Settings and Support links to the Plugins list table.
			$plugin = cmplz_tc_plugin;
			add_filter( "plugin_action_links_$plugin", array( $this, 'plugin_settings_link' ) );
			add_filter( "network_admin_plugin_action_links_$plugin", array( $this, 'plugin_settings_link' ) );

			// Run the upgrade check and activation redirect on every admin_init.
			add_action( 'admin_init', array( $this, 'check_upgrade' ), 10, 2 );
			add_action( 'admin_init', array( $this, 'maybe_redirect_to_settings' ), 10, 2 );
		}

		/**
		 * Returns the single instance of this class.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_admin The singleton instance.
		 */
		public static function this() {
			return self::$_this;
		}

		/**
		 * Redirects the user to the plugin settings page after first activation.
		 *
		 * Consumes the `cmplz_tc_redirect_to_settings` transient set by
		 * `cmplz_tc_activation()`. The transient is deleted before the redirect
		 * to ensure it fires only once and is not triggered by subsequent page
		 * loads. Hooked to `admin_init`.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_activation()  Sets the transient on plugin activation.
		 *
		 * @return void
		 */
		public function maybe_redirect_to_settings() {
			if ( get_transient( 'cmplz_tc_redirect_to_settings' ) ) {
				// Delete the transient first so the redirect only happens once.
				delete_transient( 'cmplz_tc_redirect_to_settings' );
				wp_safe_redirect( add_query_arg( array( 'page' => 'terms-conditions' ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		/**
		 * Returns the install, upgrade, or "Installed" status link for a companion plugin.
		 *
		 * Determines the current state of a companion plugin by checking whether
		 * its free or premium PHP constants are defined, then returns the
		 * appropriate HTML anchor or plain-text label:
		 *
		 * - Neither free nor premium active → "Install" link to wordpress.org search.
		 * - Free active but no premium      → "Upgrade to pro" link to the plugin website.
		 * - Premium (or wpsi_plugin) active  → Plain "Installed" text (no action needed).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    templates/wizard/other-plugins.php  Calls this method for each plugin card.
		 *
		 * @param  array $item  Plugin descriptor array with keys:
		 *                      - `constant_free`    (string) PHP constant for the free edition.
		 *                      - `constant_premium` (string) PHP constant for the premium edition.
		 *                      - `search`           (string) WordPress.org search query string.
		 *                      - `website`          (string) URL to the premium/pricing page.
		 * @return string       HTML anchor tag or plain-text status label.
		 */
		public function get_status_link( $item ) {
			$status = '';
			if ( ! defined( $item['constant_free'] ) && ! defined( $item['constant_premium'] ) ) {
				// Plugin is not installed: link to the wordpress.org search results.
				$link   = admin_url() . 'plugin-install.php?s=' . $item['search'] . '&tab=search&type=term';
				$text   = __( 'Install', 'complianz-terms-conditions' );
				$status = '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
			} elseif ( 'wpsi_plugin' === $item['constant_free'] || defined( $item['constant_premium'] ) ) {
				// Premium (or a special-cased plugin) is active: no further action required.
				$status = esc_html__( 'Installed', 'complianz-terms-conditions' );
			} elseif ( defined( $item['constant_free'] ) && ! defined( $item['constant_premium'] ) ) {
				// Free edition is active but premium is not: offer an upgrade link.
				$link   = $item['website'];
				$text   = __( 'Upgrade to pro', 'complianz-terms-conditions' );
				$status = '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
			}

			return $status;
		}

		/**
		 * Runs version-specific upgrade routines and updates the stored version number.
		 *
		 * Compares the previously stored plugin version against known migration
		 * thresholds and applies any required data changes. After all migrations
		 * are complete it fires the `cmplz_tc_upgrade` action (used by add-ons)
		 * and writes the current version to the database.
		 *
		 * Note: when SCRIPT_DEBUG is enabled, a timestamp is appended to
		 * `cmplz_tc_version`; the stored option value is compared without the
		 * timestamp by reading it directly from the database.
		 *
		 * Hooked to: admin_init (priority 10).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function check_upgrade() {
			// Read the previously stored version; false when the plugin has never been upgraded.
			$prev_version = get_option( 'cmplz-tc-current-version', false );

			if ( $prev_version
				&& version_compare( $prev_version, '1.0.4', '<' )
			) {
				// Migration for < 1.0.4: re-save the documents update date to trigger a regeneration.
				update_option( 'cmplz_tc_documents_update_date', get_option( 'cmplz_tc_documents_update_date' ) );
			}

			/**
			 * Fires after version-specific upgrade routines have been applied.
			 *
			 * Add-ons can hook here to run their own migration logic.
			 *
			 * @since 1.0.0
			 *
			 * @param string|false $prev_version The version string stored before this upgrade, or false on first run.
			 */
			do_action( 'cmplz_tc_upgrade', $prev_version );

			// Persist the current version so future requests can detect upgrades.
			update_option( 'cmplz-tc-current-version', cmplz_tc_version );
		}

		/**
		 * Enqueues admin stylesheet and script on T&C plugin screens.
		 *
		 * Runs on `admin_enqueue_scripts` and bails immediately for any admin
		 * page that is not part of the T&C plugin (detected by checking whether
		 * `'terms-conditions'` appears in the hook suffix). In non-debug mode
		 * the `.min` variants of all assets are loaded; in SCRIPT_DEBUG mode
		 * the un-minified sources are used. The admin AJAX URL and a nonce are
		 * passed to the script via `wp_localize_script()`.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $hook  The current admin page hook suffix
		 *                       (e.g. `'tools_page_terms-conditions'`).
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			// Only enqueue assets on screens that belong to this plugin.
			if ( false === strpos( $hook, 'terms-conditions' ) ) {
				return;
			}

			// Remove any conflicting Complianz GDPR wizard stylesheet.
			wp_dequeue_style( 'cmplz-wizard' );

			// Use un-minified assets when SCRIPT_DEBUG is active.
			$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

			wp_register_style( 'cmplz-tc', trailingslashit( cmplz_tc_url ) . "assets/css/admin$minified.css", array(), cmplz_tc_version );
			wp_enqueue_style( 'cmplz-tc' );
			wp_register_style( 'cmplz-tc-tips-tricks', trailingslashit( cmplz_tc_url ) . "assets/css/tips-tricks$minified.css", array(), cmplz_tc_version );
			wp_enqueue_style( 'cmplz-tc-tips-tricks' );
			wp_enqueue_script( 'cmplz-tc-admin', cmplz_tc_url . "assets/js/admin$minified.js", array( 'jquery' ), cmplz_tc_version, true );

			// Pass the AJAX endpoint URL and a save nonce to the admin JavaScript.
			wp_localize_script(
				'cmplz-tc-admin',
				'complianz_tc_admin',
				array(
					'admin_url' => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'complianz_tc_save' ),
				)
			);
		}

		/**
		 * Prepends Settings and Support links to the plugin's action links row.
		 *
		 * Hooked to both `plugin_action_links_{plugin}` (single-site) and
		 * `network_admin_plugin_action_links_{plugin}` (multisite). The Support
		 * link points to WordPress.org for the free edition and to
		 * complianz.io/support for the premium edition.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $links  Existing action links for the plugin row.
		 * @return array         Modified links array with Settings and Support prepended.
		 */
		public function plugin_settings_link( $links ) {
			// Prepend the Settings link; placed after Support due to unshift order.
			$settings_link = '<a href="'
							. esc_url( cmplz_tc_settings_page() )
							. '" class="cmplz-tc-settings-link">'
							. esc_html__( 'Settings', 'complianz-terms-conditions' ) . '</a>';
			array_unshift( $links, $settings_link );

			// Link to WP.org support for free users; to complianz.io for premium.
			$support_link = defined( 'cmplz_free' )
				? 'https://wordpress.org/support/plugin/complianz-terms-conditions'
				: 'https://complianz.io/support';
			$faq_link     = '<a target="_blank" href="' . esc_url( $support_link ) . '">'
							. esc_html__( 'Support', 'complianz-terms-conditions' ) . '</a>';
			array_unshift( $links, $faq_link );

			return $links;
		}

		/**
		 * Registers a standalone "Terms & Conditions" submenu page under Tools.
		 *
		 * Used when the Complianz GDPR/CCPA plugin is not active. Capability-
		 * gated via `cmplz_tc_user_can_manage()`. After adding the submenu the
		 * `cmplz_admin_menu` action is fired so that add-ons can register their
		 * own sub-items under this menu.
		 *
		 * Hooked to: admin_menu (priority 20).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_user_can_manage()  Capability check.
		 * @see    register_admin_page()        Alternative used when Complianz GDPR is active.
		 *
		 * @return void
		 */
		public function register_main_menu() {
			if ( ! cmplz_tc_user_can_manage() ) {
				return;
			}

			global $cmplz_admin_page;
			$cmplz_admin_page = add_submenu_page(
				'tools.php',                                         // Parent menu slug.
				__( 'Terms & Conditions', 'complianz-terms-conditions' ), // Page title.
				__( 'Terms & Conditions', 'complianz-terms-conditions' ), // Menu label.
				'manage_options',                                    // Required capability.
				'terms-conditions',                                  // Menu slug.
				array( $this, 'wizard_page' ),                       // Page render callback.
				40                                                   // Menu position.
			);

			/**
			 * Fires after the standalone T&C admin menu has been registered.
			 *
			 * Add-ons hook here to append sub-items under the T&C menu entry.
			 *
			 * @since 1.0.0
			 */
			do_action( 'cmplz_admin_menu' );
		}

		/**
		 * Registers a "Terms & Conditions" submenu page nested under the Complianz GDPR menu.
		 *
		 * Used when the Complianz GDPR/CCPA plugin is active and has already
		 * registered its top-level `complianz` menu. Capability-gated via
		 * `cmplz_tc_user_can_manage()`.
		 *
		 * Hooked to: cmplz_admin_menu (priority 20).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_user_can_manage()  Capability check.
		 * @see    register_main_menu()         Alternative used when Complianz GDPR is absent.
		 *
		 * @return void
		 */
		public function register_admin_page() {
			if ( ! cmplz_tc_user_can_manage() ) {
				return;
			}
			add_submenu_page(
				'complianz',                                              // Parent menu slug (Complianz GDPR).
				__( 'Terms & Conditions', 'complianz-terms-conditions' ), // Page title.
				__( 'Terms & Conditions', 'complianz-terms-conditions' ), // Menu label.
				'manage_options',                                         // Required capability.
				'terms-conditions',                                       // Menu slug.
				array( $this, 'wizard_page' )                            // Page render callback.
			);
		}

		/**
		 * Renders the wizard admin page for the Terms & Conditions document type.
		 *
		 * Delegates entirely to `cmplz_tc_wizard::wizard()`, which builds and
		 * outputs the full wizard UI including the admin wrap, sidebar menu,
		 * and step content. Registered as the `page_callback` for both
		 * `register_main_menu()` and `register_admin_page()`.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::wizard()  Outputs the full wizard HTML.
		 *
		 * @return void
		 */
		public function wizard_page() {
			COMPLIANZ_TC::$wizard->wizard( 'terms-conditions' );
		}

		/**
		 * Outputs a tooltip icon with the provided help text as a data attribute.
		 *
		 * Renders a dashicon question-mark element whose tooltip content is set
		 * via the `data-cmplz-tooltip` attribute and revealed by CSS/JS on hover.
		 * The caller is responsible for escaping `$str` before passing it in.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $str  Pre-escaped help text to display in the tooltip.
		 * @return void
		 */
		public function get_help_tip( $str ) {
			?>
			<span class="cmplz-tooltip-right tooltip-right"
					data-cmplz-tooltip="<?php echo esc_attr( $str ); ?>">
				<span class="dashicons dashicons-editor-help"></span>
			</span>
			<?php
		}
	}
} // class closure
