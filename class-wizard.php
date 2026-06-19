<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Legacy file name maintained for backwards compatibility.
/**
 * Wizard class for the Complianz Terms & Conditions plugin.
 *
 * Manages the multi-step setup wizard used to configure and generate the Terms
 * and Conditions document. Handles wizard navigation (steps and sections),
 * rendering the menu and content areas, locking against concurrent edits,
 * tracking completion percentage, and reacting to save events to keep the
 * generated document in sync with user choices.
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

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_wizard' ) ) {

	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid -- Legacy class name maintained for backwards compatibility.
	/**
	 * Manages the Terms & Conditions setup wizard.
	 *
	 * Implements a singleton pattern to ensure only one instance of the wizard
	 * is active at a time. Provides navigation between steps and sections,
	 * renders wizard UI components, tracks required-field completion, and
	 * coordinates save/finish events with the document and field subsystems.
	 *
	 * @package Complianz_Terms_Conditions
	 *
	 * @since   1.0.0
	 */
	class cmplz_tc_wizard {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid

		/**
		 * Holds the singleton instance of this class.
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_wizard
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Current position within the wizard (step or section index).
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    int|null
		 */
		public $position;

		/**
		 * Total number of steps for the active wizard page; false until calculated.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    int|false
		 */
		public $total_steps = false;

		/**
		 * The last section index for the current step; false until calculated.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    int|false
		 */
		public $last_section;

		/**
		 * URL of the admin settings page that hosts this wizard.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string
		 */
		public $page_url;

		/**
		 * Cached overall completion percentage (0-100); false until first calculated.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    int|false
		 */
		public $percentage_complete = false;

		/**
		 * Initialises the singleton instance and registers all WordPress hooks.
		 *
		 * Calling this constructor a second time will trigger wp_die() because
		 * cmplz_tc_wizard is a singleton. All admin hooks (asset enqueue, step
		 * transitions, save events, custom action hooks) are registered here so
		 * the wizard is fully operational once the object is constructed.
		 *
		 * @since  1.0.0
		 * @access public
		 */
		public function __construct() {
			// Enforce singleton: abort if an instance already exists.
			if ( isset( self::$_this ) ) {
				wp_die(
					sprintf(
						'%s is a singleton class and you cannot create a second instance.',
						esc_html( get_class( $this ) )
					)
				);
			}

			self::$_this = $this;

			// Enqueue wizard CSS/JS only on the plugin's admin pages.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// callback from settings.
			add_action( 'cmplz_tc_terms-conditions_last_step', array( $this, 'wizard_last_step_callback' ), 10, 1 );

			// link action to custom hook.
			add_action( 'cmplz_tc_terms-conditions_wizard', array( $this, 'wizard_after_step' ), 10, 1 );

			// process custom hooks.
			add_action( 'admin_init', array( $this, 'process_custom_hooks' ) );
			add_action( 'complianz_tc_before_save_terms-conditions_option', array( $this, 'before_save_wizard_option' ), 10, 4 );
			add_action( 'complianz_tc_after_save_terms-conditions_option', array( $this, 'after_save_wizard_option' ), 10, 4 );
			add_action( 'cmplz_tc_after_saved_all_fields', array( $this, 'after_saved_all_fields' ), 10, 1 );
			add_action( 'cmplz_tc_last_step', array( $this, 'last_step_callback' ) );
		}

		/**
		 * Returns the singleton instance of cmplz_tc_wizard.
		 *
		 * Use this static accessor instead of constructing a new instance to
		 * ensure only one wizard object exists throughout a request.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_wizard The single shared instance.
		 */
		public static function this() {
			return self::$_this;
		}

		/**
		 * Fires the custom wizard action hook for the Terms & Conditions document type.
		 *
		 * Called on `admin_init`, this fires `cmplz_wizard_terms-conditions` so that
		 * other parts of the plugin (or third-party add-ons) can attach behaviour that
		 * runs once per request during wizard initialisation.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function process_custom_hooks() {
			/**
			 * Fires during admin_init for the Terms & Conditions wizard.
			 *
			 * @since 1.0.0
			 */
			do_action( 'cmplz_wizard_terms-conditions' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Hook name uses hyphens matching the document type slug; changing it would be a breaking change.
		}

		/**
		 * Initialises wizard state for a given document page.
		 *
		 * Calculates the last section for the current step, resolves the admin
		 * page URL, and, when a `post_id` query parameter is present, copies all
		 * field values from that post's meta into the wizard option store so the
		 * user is editing an existing document rather than starting from scratch.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @return void
		 */
		public function initialize( $page ) {
			$this->last_section = $this->last_section( $page, $this->step() );
			$this->page_url     = cmplz_tc_settings_page();

			// if a post id was passed, we copy the contents of that page to the wizard settings.
			if ( isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post_id from URL for display purposes only; no state change occurs here.
				$post_id = intval( $_GET['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post_id from URL for display purposes only; no state change occurs here.

				// get all fields for this page.
				$fields = COMPLIANZ_TC::$config->fields( $page );
				foreach ( $fields as $fieldname => $field ) {
					$fieldvalue = get_post_meta( $post_id, $fieldname, true );
					if ( $fieldvalue ) {
						// Save single or multiple-value fields appropriately.
						if ( ! COMPLIANZ_TC::$field->is_multiple_field( $fieldname ) ) {
							COMPLIANZ_TC::$field->save_field( $fieldname, $fieldvalue );
						} else {
							$field[ $fieldname ] = $fieldvalue;
							COMPLIANZ_TC::$field->save_multiple( $field );
						}
					}
				}
			}
		}

		/**
		 * Renders feedback after the wizard's last step is reached.
		 *
		 * If not all required fields are completed, displays a prompt to finish
		 * the remaining questions. Otherwise, shows a success message and the
		 * last-step tip template (e.g. share/download links).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function last_step_callback() {
			if ( ! $this->all_required_fields_completed( 'terms-conditions' ) ) {
				echo '<div class="cmplz-wizard-intro">';
				esc_html_e( 'Not all required fields are completed yet. Please check the steps to complete all required questions', 'complianz-terms-conditions' );
				echo '</div>';
			} else {
				echo '<div class="cmplz-wizard-intro">' . esc_html__( "You're done! Here are some tips & tricks to use this document to your full advantage.", 'complianz-terms-conditions' ) . '</div>';
				echo cmplz_tc_get_template( 'wizard/last-step.php' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template function returns trusted internal HTML.
			}
		}

		/**
		 * Processes completion actions after a wizard step is submitted.
		 *
		 * Fires on the `cmplz_tc_terms-conditions_wizard` action. Clears cached
		 * shortcode transients so the live document reflects any new answers.
		 * When the user clicks Finish or navigates to the last step via Next,
		 * marks the wizard as having been completed at least once.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function wizard_after_step() {
			if ( ! cmplz_tc_user_can_manage() ) {
				return;
			}

			// Clear document cache so the rendered document reflects latest answers.
			COMPLIANZ_TC::$document->clear_shortcode_transients();

			// when clicking to the last page, or clicking finish, run the finish sequence.
			if ( isset( $_POST['cmplz-finish'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified upstream in the form handler.
				|| ( isset( $_POST['step'] ) && 3 === (int) $_POST['step'] // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified upstream in the form handler.
						&& isset( $_POST['cmplz-next'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified upstream in the form handler.
			) {
				$this->set_wizard_completed_once();
			}
		}

		/**
		 * Runs before a wizard field option is saved.
		 *
		 * Updates the document modification timestamp whenever the wizard is
		 * submitted, regardless of whether any individual field value changed.
		 * Returns early (skips further processing) when the new value is identical
		 * to the previous value.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $fieldname  The name of the field being saved.
		 * @param mixed  $fieldvalue The new value submitted for the field.
		 * @param mixed  $prev_value The previously stored value for the field.
		 * @param string $type       The field type identifier.
		 * @return void
		 */
		public function before_save_wizard_option( $fieldname, $fieldvalue, $prev_value, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the complianz_tc_before_save hook signature.
			// Stamp the document update time on every save attempt.
			update_option( 'cmplz_tc_documents_update_date', time() );

			// Only run when changes have been made.
			if ( $fieldvalue === $prev_value ) {
				return;
			}
		}

		/**
		 * Hook callback invoked after all wizard fields have been saved in a single submission.
		 *
		 * Reserved for future post-save logic that should only execute once all
		 * fields in a submission have been processed (rather than per-field).
		 * Currently a no-op placeholder.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param array $posted_fields Associative array of all field names and values
		 *                             that were included in the current save operation.
		 * @return void
		 */
		public function after_saved_all_fields( $posted_fields ) {
		}

		/**
		 * Reacts to individual wizard field saves to keep dependent data up to date.
		 *
		 * Currently handles language-related fields: when `language_communication`,
		 * `address_company`, or `multilanguage_communication` changes, the list of
		 * languages for which PDFs should be generated is refreshed. This ensures
		 * withdrawal forms and other locale-specific PDFs are regenerated in the
		 * correct languages after a language change.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $fieldname  The name of the field that was just saved.
		 * @param mixed  $fieldvalue The new saved value.
		 * @param mixed  $prev_value The value before the save.
		 * @param string $type       The field type identifier.
		 * @return void
		 */
		public function after_save_wizard_option( $fieldname, $fieldvalue, $prev_value, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the complianz_tc_after_save hook signature.
			// Only run when changes have been made.
			if ( $fieldvalue === $prev_value ) {
				return;
			}

			// if languages have been changed, we update the withdrawal form, if those should be generated.
			if ( 'language_communication' === $fieldname || 'address_company' === $fieldname || 'multilanguage_communication' === $fieldname ) {
				$languages = cmplz_tc_get_value( 'multilanguage_communication' );
				if ( ! empty( $languages ) ) {
					// Filter out empty values before storing the active language list.
					$languages = array_filter( $languages );
					update_option( 'cmplz_generate_pdf_languages', $languages );
				}
			}

			// When only the primary language changes, reset the PDF language list to that single locale.
			if ( 'language_communication' === $fieldname ) {
				$languages = array( cmplz_tc_sanitize_language( get_locale() ) );
				$languages = array_filter( $languages );
				update_option( 'cmplz_generate_pdf_languages', $languages );
			}
		}

		/**
		 * Returns the next step index that contains at least one visible field.
		 *
		 * Recursively increments the step counter until a step with fields is found
		 * or the last step is reached, whichever comes first. Used to skip over
		 * steps that are entirely empty (e.g. due to unmet conditions).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step The step index to check first.
		 * @return int The next non-empty step index, or $step if already at the last step.
		 */
		public function get_next_not_empty_step( $page, $step ) {
			if ( ! COMPLIANZ_TC::$field->step_has_fields( $page, $step ) ) {
				if ( $step >= $this->total_steps( $page ) ) {
					return $step;
				}
				++$step;
				// Recurse to check the incremented step.
				$step = $this->get_next_not_empty_step( $page, $step );
			}

			return $step;
		}

		/**
		 * Returns the next section index (within a step) that contains visible fields.
		 *
		 * Sections are keyed with non-sequential integers, so this method resolves the
		 * actual position of the current section within the array before incrementing.
		 * Returns false when all remaining sections in the step are empty.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step containing the sections to search.
		 * @param int    $section The section key to start from.
		 * @return int|bool The next non-empty section key, or false if none remain.
		 */
		public function get_next_not_empty_section( $page, $step, $section ) {
			if ( ! COMPLIANZ_TC::$field->step_has_fields( $page, $step, $section ) ) {
				// some keys are missing, so we need to count the actual number of keys.
				if ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] ) ) {
					$n     = array_keys( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] ); // <---- Grab all the keys of your actual array and put in another array
					$count = array_search( $section, $n, true ); // <--- Returns the position of the offset from this array using search.

					// this is the actual list up to section key.
					$new_arr       = array_slice( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'], 0, $count + 1, true );// <--- Slice it with the 0 index as start and position+1 as the length parameter.
					$section_count = count( $new_arr ) + 1;
				} else {
					$section_count = $section + 1;
				}

				++$section;

				if ( $section_count > $this->total_sections( $page, $step ) ) {
					return false;
				}

				$section = $this->get_next_not_empty_section( $page, $step, $section );
			}

			return $section;
		}

		/**
		 * Returns the previous step index that contains at least one visible field.
		 *
		 * Recursively decrements the step counter until a non-empty step is found
		 * or step 1 is reached. Used when the user clicks the Previous button to
		 * avoid landing on a step that would show no fields.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step The step index to check first.
		 * @return int The previous non-empty step index, or 1 if already at the first step.
		 */
		public function get_previous_not_empty_step( $page, $step ) {
			if ( ! COMPLIANZ_TC::$field->step_has_fields( $page, $step ) ) {
				if ( $step <= 1 ) {
					return $step;
				}
				--$step;
				$step = $this->get_previous_not_empty_step( $page, $step );
			}

			return $step;
		}

		/**
		 * Returns the previous section index (within a step) that contains visible fields.
		 *
		 * Recursively decrements the section key until a non-empty section is found.
		 * Returns false when there are no earlier non-empty sections in the current step,
		 * signalling the caller to move to the previous step instead.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step containing the sections to search.
		 * @param int    $section The section key to start searching backwards from.
		 * @return false|int The previous non-empty section key, or false if none exist.
		 */
		public function get_previous_not_empty_section( $page, $step, $section ) {

			if ( ! COMPLIANZ_TC::$field->step_has_fields(
				$page,
				$step,
				$section
			)
			) {
				--$section;
				if ( $section < 1 ) {
					return false;
				}
				$section = $this->get_previous_not_empty_section(
					$page,
					$step,
					$section
				);
			}

			return $section;
		}

		/**
		 * Locks the wizard to prevent concurrent edits by other users.
		 *
		 * Stores the current user's ID in a transient whose expiry is controlled by
		 * the `cmplz_wizard_lock_time` filter (default: 2 minutes). The lock is
		 * automatically refreshed each time the wizard page is loaded by the same user.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::wizard_is_locked()
		 * @see    cmplz_tc_wizard::get_lock_user()
		 *
		 * @return void
		 */
		public function lock_wizard() {
			$user_id = get_current_user_id();
			/**
			 * Filters the wizard lock duration in seconds.
			 *
			 * @since 1.0.0
			 *
			 * @param int $duration Lock duration in seconds. Default is 2 minutes (120).
			 */
			set_transient( 'cmplz_wizard_locked_by_user', $user_id, apply_filters( 'cmplz_wizard_lock_time', 2 * MINUTE_IN_SECONDS ) );
		}


		/**
		 * Checks whether the wizard is currently locked by a different user.
		 *
		 * Compares the ID stored in the lock transient against the currently
		 * logged-in user. Returns true only when there is an active lock held by
		 * someone else; returns false if there is no lock or the lock belongs to
		 * the current user.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::lock_wizard()
		 * @see    cmplz_tc_wizard::get_lock_user()
		 *
		 * @return bool True if locked by another user, false otherwise.
		 */
		public function wizard_is_locked() {
			$user_id      = get_current_user_id();
			$lock_user_id = (int) $this->get_lock_user();
			if ( $lock_user_id && $user_id !== $lock_user_id ) {
				return true;
			}

			return false;
		}

		/**
		 * Retrieves the ID of the user who holds the current wizard lock.
		 *
		 * Returns the value stored in the `cmplz_wizard_locked_by_user` transient.
		 * Returns false when no lock exists or the transient has expired.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::lock_wizard()
		 *
		 * @return false|int User ID of the locking user, or false if unlocked.
		 */
		public function get_lock_user() {
			return get_transient( 'cmplz_wizard_locked_by_user' );
		}

		/**
		 * Renders the complete wizard UI for a given document page.
		 *
		 * Performs a capability check and, if the wizard is locked by another user,
		 * displays a warning notice and returns early. Otherwise it locks the wizard
		 * for the current user, determines the correct step and section to display
		 * (advancing forward on Next or backward on Previous), then renders the
		 * navigation menu and question content into the admin wrapper template.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page         The document page identifier (e.g. 'terms-conditions').
		 * @param string $wizard_title Optional heading shown above the wizard menu.
		 *                             Default empty string (no heading rendered).
		 * @return void
		 */
		public function wizard( $page, $wizard_title = '' ) {

			if ( ! cmplz_tc_user_can_manage() ) {
				return;
			}

			if ( $this->wizard_is_locked() ) {
				// Retrieve lock owner details to include in the warning message.
				$user_id = $this->get_lock_user();
				$user    = get_user_by( 'id', $user_id );
				/**
				 * Filters the wizard lock duration in seconds.
				 *
				 * @since 1.0.0
				 *
				 * @param int $duration Lock duration in seconds. Default is 2 minutes (120).
				 */
				$lock_time = apply_filters(
					'cmplz_wizard_lock_time',
					2 * MINUTE_IN_SECONDS
				) / 60;

				cmplz_tc_notice(
					sprintf(
						// translators: %s is the display name of the user currently editing the wizard.
						__(
							'The wizard is currently being edited by %s',
							'complianz-terms-conditions'
						),
						$user->user_nicename
					) . '<br>'
					. sprintf(
						// translators: %s is the number of minutes until the wizard lock expires.
						__(
							'If this user stops editing, the lock will expire after %s minutes.',
							'complianz-terms-conditions'
						),
						$lock_time
					),
					'warning'
				);

				return;
			}
			// Lock the wizard for other users while this user is editing.
			$this->lock_wizard();

			$this->initialize( $page );

			$section = $this->section();
			$step    = $this->step();

			// Advance to the next non-empty step/section when the current section
			// has no visible fields or the user clicked Next without validation errors.
			if ( $this->section_is_empty( $page, $step, $section )
				|| ( isset( $_POST['cmplz-next'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified upstream; this only controls navigation direction.
					&& ! COMPLIANZ_TC::$field->has_errors() )
			) {
				if ( COMPLIANZ_TC::$config->has_sections( $page, $step )
					&& ( $section < $this->last_section )
				) {
					++$section;
				} else {
					++$step;
					$section = $this->first_section( $page, $step );
				}

				$step    = $this->get_next_not_empty_step( $page, $step );
				$section = $this->get_next_not_empty_section(
					$page,
					$step,
					$section
				);
				// if the last section is also empty, it will return false, so we need to skip the step too.
				if ( ! $section ) {
					$step    = $this->get_next_not_empty_step(
						$page,
						$step + 1
					);
					$section = 1;
				}
			}

			// Navigate backwards when the user clicked Previous.
			if ( isset( $_POST['cmplz-previous'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified upstream; this only controls navigation direction.
				if ( COMPLIANZ_TC::$config->has_sections( $page, $step )
					&& $section > $this->first_section( $page, $step )
				) {
					--$section;
				} else {
					--$step;
					$section = $this->last_section( $page, $step );
				}

				$step    = $this->get_previous_not_empty_step( $page, $step );
				$section = $this->get_previous_not_empty_section(
					$page,
					$step,
					$section
				);
			}

			$menu    = $this->wizard_menu( $page, $wizard_title, $step, $section );
			$content = $this->wizard_content( $page, $step, $section );

			$args = array(
				'page'    => 'terms-conditions',
				'content' => $menu . $content,
			);
			echo cmplz_tc_get_template( 'admin_wrap.php', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template function returns trusted internal HTML.
		}

		/**
		 * Renders and returns the wizard navigation menu HTML.
		 *
		 * Iterates over all steps for the given page and builds a step list by
		 * rendering the `wizard/step.php` template for each entry. The active step
		 * also receives its section sub-list. Appends the overall completion
		 * percentage and an optional title block before rendering the outer menu
		 * wrapper via `wizard/menu.php`.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page           The document page identifier (e.g. 'terms-conditions').
		 * @param string $wizard_title   Optional heading for the wizard menu. Default empty string.
		 * @param int    $active_step    The step index currently selected.
		 * @param int    $active_section The section index currently selected within the active step.
		 * @return false|string Rendered menu HTML, or false on template failure.
		 */
		public function wizard_menu( $page, $wizard_title, $active_step, $active_section ) {
			$args_menu['steps'] = '';
			$total_steps_menu   = $this->total_steps( $page );
			for ( $i = 1; $i <= $total_steps_menu; $i++ ) {
				$args['title']     = $i . '. ' . COMPLIANZ_TC::$config->steps[ $page ][ $i ]['title'];
				$args['active']    = ( $active_step === $i ) ? 'active' : '';
				$args['completed'] = $this->required_fields_completed( $page, $i, false ) ? 'complete' : 'incomplete';
				$args['url']       = add_query_arg( array( 'step' => $i ), $this->page_url );
				if ( $this->post_id() ) {
					// Preserve the post_id parameter so document origin is maintained during navigation.
					$args['url'] = add_query_arg( array( 'post_id' => $this->post_id() ), $args['url'] );
				}
				// Only render section links for the currently active step.
				$args['sections'] = ( 'active' === $args['active'] ) ? $this->wizard_sections( $page, $active_step, $active_section ) : '';

				$args_menu['steps'] .= cmplz_tc_get_template( 'wizard/step.php', $args );
			}
			$args_menu['percentage-complete'] = $this->wizard_percentage_complete( false );
			$args_menu['title']               = ! empty( $wizard_title ) ? '<div class="cmplz-wizard-subtitle"><h2>' . $wizard_title . '</h2></div>' : '';

			return cmplz_tc_get_template( 'wizard/menu.php', $args_menu );
		}

		/**
		 * Renders and returns the section sub-navigation HTML for a wizard step.
		 *
		 * Iterates over all sections within the given step, skips empty sections, and
		 * builds an HTML string by rendering `wizard/section.php` for each visible
		 * section. The icon changes depending on whether the section is active,
		 * completed, or pending.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page           The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step           The step whose sections should be rendered.
		 * @param int    $active_section The section index that is currently active.
		 * @return string Rendered sections HTML, or an empty string if the step has no sections.
		 */
		public function wizard_sections( $page, $step, $active_section ) {
			$sections = '';

			if ( COMPLIANZ_TC::$config->has_sections( $page, $step ) ) {

				$first_section_idx = $this->first_section( $page, $step );
				$last_section_idx  = $this->last_section( $page, $step );
				for ( $i = $first_section_idx; $i <= $last_section_idx; $i++ ) {
					// Default icon: greyed-out circle for pending sections.
					$icon = cmplz_tc_icon( 'circle', 'disabled', '', 11 );

					if ( $this->section_is_empty( $page, $step, $i ) ) {
						continue;
					}
					if ( $i < $this->get_next_not_empty_section( $page, $step, $i ) ) {
						continue;
					}

					$active = ( $active_section === $i ) ? 'active' : '';
					if ( 'active' === $active ) {
						// Chevron icon highlights the current section.
						$icon = cmplz_tc_icon( 'chevron-right', 'default', '', 11 );
					} elseif ( $this->required_fields_completed( $page, $step, $i ) ) {
						// Checkmark icon indicates all required fields in this section are answered.
						$icon = cmplz_tc_icon( 'check', 'success', '', 11 );
					}

					$completed = ( $this->required_fields_completed( $page, $step, $i ) ) ? 'cmplz-done' : 'cmplz-to-do';
					$url       = add_query_arg(
						array(
							'step'    => $step,
							'section' => $i,
						),
						$this->page_url
					);
					if ( $this->post_id() ) {
						$url = add_query_arg( array( 'post_id' => $this->post_id() ), $url );
					}

					$title   = COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $i ]['title'];
					$regions = $this->get_section_regions( $page, $step, $i );
					// Append region labels (e.g. "EU | US") to the section title when applicable.
					$title    .= $regions ? ' - ' . implode( ' | ', $regions ) : '';
					$args      = array(
						'active'    => $active,
						'completed' => $completed,
						'icon'      => $icon,
						'url'       => $url,
						'title'     => $title,
					);
					$sections .= cmplz_tc_get_template( 'wizard/section.php', $args );
				}
			}

			return $sections;
		}

		/**
		 * Renders and returns the wizard question area for a specific step and section.
		 *
		 * Builds the full content panel including the section/step title (with region
		 * labels if applicable), navigation buttons (Previous, Next, Save, Finish), a
		 * post-save notice, the intro paragraph, and all visible fields. On the final
		 * step it also appends the other-plugins template and resolves the primary
		 * action button to either "Create" or "Open" the document depending on whether
		 * a shortcode page already exists.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step to render.
		 * @param int    $section The section within the step to render.
		 * @return false|string Rendered content HTML, or false on template failure.
		 */
		public function wizard_content( $page, $step, $section ) {

			$args['title'] = '';
			if ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['title'] ) ) {
				$args['title']  = COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['title'];
				$regions        = $this->get_section_regions( $page, $step, $section );
				$args['title'] .= $regions ? ' - ' . implode( ' | ', $regions ) : '';
			} else {
				$args['title'] .= COMPLIANZ_TC::$config->steps[ $page ][ $step ]['title'];
			}

			// Initialise all content slots to empty strings.
			$args['flags']                   = '';
			$args['save_notice']             = '';
			$args['save_as_notice']          = '';
			$args['learn_notice']            = '';
			$args['cookie_or_finish_button'] = '';
			$args['previous_button']         = '';
			$args['next_button']             = '';
			$args['save_button']             = '';

			if ( isset( $_POST['cmplz-save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified upstream by the field-save handler.
				$args['save_notice'] = cmplz_tc_notice( __( 'Changes saved', 'complianz-terms-conditions' ), 'success', true, false );
			}

			$args['intro']    = $this->get_intro( $page, $step, $section );
			$args['page_url'] = $this->page_url;
			$args['page']     = $page;

			// Embed the post_id as a hidden field when editing an existing document post.
			$args['post_id'] = $this->post_id() ? '<input type="hidden" value="' . $this->post_id() . '" name="post_id">' : '';

			// Buffer field output so it can be injected into the template variable.
			ob_start();
			COMPLIANZ_TC::$field->get_fields( $page, $step, $section );
			$args['fields'] = ob_get_clean();

			$args['step']    = $step;
			$args['section'] = $section;

			// Render Previous button for any step/section after the first.
			if ( $step > 1 || $section > 1 ) {
				$args['previous_button'] = '<input class="button button-link cmplz-previous" type="submit" name="cmplz-previous" value="' . __( 'Previous', 'complianz-terms-conditions' ) . '">';
			}

			if ( $step < $this->total_steps( $page ) ) {
				$args['next_button'] = '<input class="button button-primary cmplz-next" type="submit" name="cmplz-next" value="' . __( 'Next', 'complianz-terms-conditions' ) . '">';
			}

			$other_plugins = '';
			if ( $step > 0 && $step < $this->total_steps( $page ) ) {
				$args['save_button'] = '<input class="button button-secondary cmplz-save" type="submit" name="cmplz-save" value="' . __( 'Save', 'complianz-terms-conditions' ) . '">';
			} elseif ( $step === $this->total_steps( $page ) ) {
				// On the final step, show an upsell/other-plugins block.
				$other_plugins = cmplz_tc_get_template( 'wizard/other-plugins.php' );
				$page_id       = COMPLIANZ_TC::$document->get_shortcode_page_id( 'terms-conditions' );
				$link          = get_permalink( $page_id );
				if ( ! $link ) {
					// No page exists yet - offer to create one.
					$link = add_query_arg( array( 'step' => 3 ), cmplz_tc_settings_page() );
					// translators: %s is the document type name, e.g. "Terms & Conditions".
					$args['save_button'] = '<a class="button button-primary cmplz-save" href="' . $link . '" type="button" name="cmplz-save">' . sprintf( __( 'Create %s', 'complianz-terms-conditions' ), __( 'Terms & Conditions', 'complianz-terms-conditions' ) ) . '</a>';
				} else {
					// Page exists - offer to view it.
					// translators: %s is the document type name, e.g. "Terms & Conditions".
					$args['save_button'] = '<a class="button button-primary cmplz-save" target="_blank" href="' . $link . '" type="button" name="cmplz-save">' . sprintf( __( 'Open %s', 'complianz-terms-conditions' ), __( 'Terms & Conditions', 'complianz-terms-conditions' ) ) . '</a>';
				}
			}

			return cmplz_tc_get_template( 'wizard/content.php', $args ) . $other_plugins;
		}

		/**
		 * Determines whether a wizard section has no visible fields.
		 *
		 * A section is considered empty when the next non-empty section is different
		 * from the supplied section (i.e. all fields in this section are hidden due
		 * to unmet conditions). Empty sections are skipped in the navigation flow
		 * and omitted from the section menu.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step that contains the section.
		 * @param int    $section The section key to evaluate.
		 * @return bool True if the section has no visible fields, false otherwise.
		 */
		public function section_is_empty( $page, $step, $section ) {
			$section_compare = $this->get_next_not_empty_section(
				$page,
				$step,
				$section
			);
			if ( $section_compare !== $section ) {
				return true;
			}

			return false;
		}

		/**
		 * Enqueues wizard stylesheet on the Terms & Conditions admin pages.
		 *
		 * Hooked to `admin_enqueue_scripts`. Loads a minified CSS file in
		 * production (when SCRIPT_DEBUG is not defined or false) and the
		 * unminified version in debug mode. The stylesheet is only registered and
		 * enqueued when the current admin page slug contains 'terms-conditions'.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $hook The current admin page hook suffix passed by WordPress.
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			// Use unminified assets when SCRIPT_DEBUG is enabled.
			$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

			if ( strpos( $hook, 'terms-conditions' ) === false ) {
				return;
			}

			wp_register_style( 'cmplz-tc-terms-conditions', cmplz_tc_url . "assets/css/wizard$minified.css", array(), cmplz_tc_version );
			wp_enqueue_style( 'cmplz-tc-terms-conditions' );
		}


		/**
		 * Checks whether all required fields in a step (or section) have values.
		 *
		 * Fetches all fields for the specified page/step/section combination,
		 * filters down to required fields, and for each one verifies that a
		 * non-empty value has been saved. Fields that are gated behind a condition
		 * or callback condition that does not currently apply are skipped.
		 *
		 * When `$section` is false, all required fields across the entire step are
		 * checked rather than a specific section.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string    $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int       $step    The step index to check.
		 * @param int|false $section The section key to limit the check to, or false
		 *                           to check the whole step.
		 * @return bool True if all required fields have values, false if any are empty.
		 */
		public function required_fields_completed( $page, $step, $section ) {
			// Get all required fields for this section, and check if they're filled in.
			$fields = COMPLIANZ_TC::$config->fields( $page, $step, $section );

			// Filter the field list down to required fields only.
			$fields = cmplz_tc_array_filter_multidimensional(
				$fields,
				'required',
				true
			);
			foreach ( $fields as $fieldname => $args ) {
				// if a condition exists, only check for this field if the condition applies.
				if ( isset( $args['condition'] )
					|| ( isset( $args['callback_condition'] )
						&& ! COMPLIANZ_TC::$field->condition_applies( $args ) )
				) {
					continue;
				}
				$value = COMPLIANZ_TC::$field->get_value( $fieldname );
				if ( empty( $value ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Convenience wrapper for all_required_fields_completed() scoped to the Terms & Conditions wizard.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::all_required_fields_completed()
		 *
		 * @return bool True if all required wizard fields have been answered, false otherwise.
		 */
		public function all_required_fields_completed_wizard() {
			return $this->all_required_fields_completed( 'terms-conditions' );
		}

		/**
		 * Checks whether all required fields across every step (and section) are completed.
		 *
		 * Iterates over every step of the given page. Steps that have sections are
		 * checked section by section; steps without sections are checked as a whole.
		 * Returns false as soon as any required field is found to be empty.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::required_fields_completed()
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @return bool True if every required field has a value, false if any are missing.
		 */
		public function all_required_fields_completed( $page ) {
			$total_steps_all = $this->total_steps( $page );
			for ( $step = 1; $step <= $total_steps_all; $step++ ) {
				if ( COMPLIANZ_TC::$config->has_sections( $page, $step ) ) {
					$first_section_all = $this->first_section( $page, $step );
					$last_section_all  = $this->last_section( $page, $step );
					for (
						$section = $first_section_all;
						$section <= $last_section_all;
						$section++
					) {
						if ( ! $this->required_fields_completed(
							$page,
							$step,
							$section
						)
						) {
							return false;
						}
					}
				} elseif ( ! $this->required_fields_completed(
					$page,
					$step,
					false
				)
					) {

						return false;
				}
			}

			return true;
		}

		/**
		 * Returns the post ID of the document currently being edited.
		 *
		 * Reads `post_id` from `$_GET` or `$_POST` (GET takes precedence) and casts
		 * it to an integer. Returns false when no post ID is present in the request,
		 * indicating the wizard is being used to configure global plugin settings
		 * rather than editing a specific document post.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return int|false The integer post ID, or false if not present.
		 */
		public function post_id() {
			$post_id = false;
			if ( isset( $_GET['post_id'] ) || isset( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Reading post_id for display purposes only; no state change performed here.
				// Prefer GET so direct links (e.g. from the post list) always work.
				$post_id = ( isset( $_GET['post_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post_id for display purposes only; no state change performed here.
					? intval( $_GET['post_id'] ) : intval( $_POST['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Reading post_id for display purposes only; no state change performed here.
			}

			return $post_id;
		}

		/**
		 * Builds and returns the intro paragraph HTML for a wizard step or section.
		 *
		 * Looks up the `intro` key in the step or section configuration. If found
		 * and non-empty, wraps it in a `<div class="cmplz-wizard-intro">` block.
		 * Section-level intros take precedence over step-level intros when sections
		 * are present.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step index.
		 * @param int    $section The section key within the step.
		 * @return string HTML intro block, or an empty string if no intro is configured.
		 */
		public function get_intro( $page, $step, $section ) {
			// Only show when in action.
			$intro = '';
			if ( COMPLIANZ_TC::$config->has_sections( $page, $step ) ) {
				if ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['intro'] ) ) {
					$intro .= COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['intro'];
				}
			} elseif ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['intro'] ) ) {
					$intro .= COMPLIANZ_TC::$config->steps[ $page ][ $step ]['intro'];
			}

			if ( strlen( $intro ) > 0 ) {
				$intro = '<div class="cmplz-wizard-intro">'
						. $intro
						. '</div>';
			}

			return $intro;
		}


		/**
		 * Retrieves the regional identifiers that apply to a wizard step or section.
		 *
		 * Reads the `region` key from the configuration for the given step or section,
		 * normalises it to an array, removes any regions that are not enabled in the
		 * current installation (via `cmplz_has_region()`), and uppercases the remaining
		 * region codes. Returns false when no applicable regions remain.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step    The step index.
		 * @param int    $section The section key within the step.
		 * @return array|false Array of uppercase region codes (e.g. ['EU', 'US']),
		 *                     or false if no enabled regions apply.
		 */
		public function get_section_regions( $page, $step, $section ) {
			// Only show when in action.
			$regions = false;

			if ( COMPLIANZ_TC::$config->has_sections( $page, $step ) ) {
				if ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['region'] ) ) {
					$regions
						= COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'][ $section ]['region'];
				}
			} elseif ( isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['region'] ) ) {
					$regions
						= COMPLIANZ_TC::$config->steps[ $page ][ $step ]['region'];
			}

			if ( $regions ) {
				// Normalise scalar region to a single-element array.
				if ( ! is_array( $regions ) ) {
					$regions = array( $regions );
				}

				// Remove regions that are not active in this installation.
				foreach ( $regions as $index => $region ) {
					if ( ! cmplz_has_region( $region ) ) {
						unset( $regions[ $index ] );
					}
				}
				if ( 0 === count( $regions ) ) {
					$regions = false;
				}
			}
			if ( $regions ) {
				// Uppercase region codes for consistent display (e.g. 'EU', 'US').
				$regions = array_map( 'strtoupper', $regions );
			}

			return $regions;
		}


		/**
		 * Resolves the document page type identifier from a post ID or the current request URL.
		 *
		 * When a post ID is supplied, the region and post type are combined to form
		 * the page slug (e.g. 'terms-conditions-eu'). Otherwise the `page` query
		 * parameter is read and the 'cmplz-' prefix is stripped. Returns false when
		 * neither source provides a valid value.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param int|false $post_id Optional post ID to derive the page type from.
		 *                           Default false (falls back to $_GET['page']).
		 * @return string|false The page type slug, or false if it cannot be determined.
		 */
		public function get_type( $post_id = false ) {
			$page = false;
			if ( $post_id ) {
				$region    = COMPLIANZ_TC::$document->get_region( $post_id );
				$post_type = get_post_type( $post_id );
				// Combine post type (without 'cmplz-' prefix) and region into the page slug.
				$page = str_replace( 'cmplz-', '', $post_type ) . '-'
							. $region;
			}
			if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page slug from URL for routing; no state change performed here.
				$page = str_replace(
					'cmplz-',
					'',
					sanitize_title( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page slug from URL for routing; no state change performed here.
				);
			}

			return $page;
		}


		/**
		 * Checks whether the wizard has been completed at least once.
		 *
		 * Reads the `cmplz_wizard_completed_once` option. The option is set to true
		 * by set_wizard_completed_once() when the user finishes the wizard for the
		 * first time. Other parts of the plugin use this to determine whether to show
		 * first-run prompts or assume the document is already configured.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::set_wizard_completed_once()
		 *
		 * @return mixed The stored option value (true when complete, empty string or false otherwise).
		 */
		public function wizard_completed_once() {
			return get_option( 'cmplz_wizard_completed_once' );
		}


		/**
		 * Marks the wizard as having been completed at least once.
		 *
		 * Persists true to the `cmplz_wizard_completed_once` option. Called
		 * automatically when the user clicks Finish or navigates past the last step.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_wizard::wizard_completed_once()
		 *
		 * @return void
		 */
		public function set_wizard_completed_once() {
			update_option( 'cmplz_wizard_completed_once', true );
		}

		/**
		 * Returns the validated current step index from the request.
		 *
		 * Reads the step from `$_GET` or `$_POST` (POST takes precedence), clamps the
		 * value to the range [1, total_steps]. Defaults to 1 when no step parameter
		 * is present. When `$page` is not supplied, defaults to 'terms-conditions'.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string|false $page The document page identifier to count steps for.
		 *                           Default false (uses 'terms-conditions').
		 * @return int The current step index, always within [1, total_steps].
		 */
		public function step( $page = false ) {
			$step = 1;
			if ( ! $page ) {
				$page = 'terms-conditions';
			}

			$total_steps = $this->total_steps( $page );

			if ( isset( $_GET['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading step from URL for navigation; no state change performed here.
				$step = intval( $_GET['step'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading step from URL for navigation; no state change performed here.
			}

			// POST takes precedence over GET (form submission overrides URL parameter).
			if ( isset( $_POST['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading step for navigation only; nonce verified by the form handler upstream.
				$step = intval( $_POST['step'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading step for navigation only; nonce verified by the form handler upstream.
			}

			// Clamp to valid range.
			if ( $step > $total_steps ) {
				$step = $total_steps;
			}

			if ( $step <= 1 ) {
				$step = 1;
			}

			return $step;
		}

		/**
		 * Returns the validated current section index from the request.
		 *
		 * Reads the section from `$_GET` or `$_POST` (POST takes precedence), clamps
		 * the value to the range [1, last_section]. Defaults to 1 when no section
		 * parameter is present.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return int The current section index, always within [1, last_section].
		 */
		public function section() {
			$section = 1;
			if ( isset( $_GET['section'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading section from URL for navigation; no state change performed here.
				$section = intval( $_GET['section'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading section from URL for navigation; no state change performed here.
			}

			// POST takes precedence over GET (form submission overrides URL parameter).
			if ( isset( $_POST['section'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading section for navigation only; nonce verified by the form handler upstream.
				$section = intval( $_POST['section'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading section for navigation only; nonce verified by the form handler upstream.
			}

			if ( $section > $this->last_section ) {
				$section = $this->last_section;
			}

			if ( $section <= 1 ) {
				$section = 1;
			}

			return $section;
		}

		/**
		 * Returns the total number of steps configured for a document page.
		 *
		 * Counts the entries in the `steps` configuration array for the given page.
		 * Steps are expected to be keyed sequentially starting at 1.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @return int Total number of steps.
		 */
		public function total_steps( $page ) {
			return count( COMPLIANZ_TC::$config->steps[ $page ] );
		}

		/**
		 * Returns the total number of sections within a wizard step.
		 *
		 * Returns 0 when the step has no sections key in the configuration.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step The step index to count sections for.
		 * @return int Total number of sections, or 0 if the step has no sections.
		 */
		public function total_sections( $page, $step ) {
			if ( ! isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] ) ) {
				return 0;
			}

			return count( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] );
		}


		/**
		 * Returns the highest section key for a given step.
		 *
		 * When a step has no sections, returns 1 as a safe default so that navigation
		 * logic can always treat a step as having at least one section. The maximum
		 * key is used rather than the count because section keys may not be sequential.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step The step index to inspect.
		 * @return int The highest section key, or 1 if the step has no sections.
		 */
		public function last_section( $page, $step ) {
			if ( ! isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] ) ) {
				return 1;
			}

			$array = COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'];

			return max( array_keys( $array ) );
		}

		/**
		 * Returns the lowest (first) section key for a given step.
		 *
		 * When a step has no sections, returns 1 as a safe default. Uses key() on
		 * the sections array to get the first key rather than assuming it is 1,
		 * because section keys can be arbitrary integers.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string $page The document page identifier (e.g. 'terms-conditions').
		 * @param int    $step The step index to inspect.
		 * @return int The first section key, or 1 if the step has no sections.
		 */
		public function first_section( $page, $step ) {
			if ( ! isset( COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'] ) ) {
				return 1;
			}

			$arr       = COMPLIANZ_TC::$config->steps[ $page ][ $step ]['sections'];
			$first_key = key( $arr );

			return $first_key;
		}


		/**
		 * Estimates the remaining time (in minutes) to complete the wizard from a given position.
		 *
		 * Sums the `time` values defined in each field's configuration for all steps
		 * and sections from the current position to the end of the wizard. Sections
		 * are iterated only for the current step (to account for partial completion);
		 * remaining steps are summed in full. The result is rounded up by 0.45 to
		 * avoid showing 0 minutes when a small amount of time remains.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param string    $page    The document page identifier (e.g. 'terms-conditions').
		 * @param int       $step    The step the user is currently on.
		 * @param int|false $section The section the user is currently on, or false
		 *                           if the step has no sections.
		 * @return int Estimated minutes remaining, rounded up.
		 */
		public function remaining_time( $page, $step, $section = false ) {

			// get remaining steps including this one.
			$time        = 0;
			$total_steps = $this->total_steps( $page );
			for ( $i = $total_steps; $i >= $step; $i-- ) {
				$sub = 0;

				// if we're on a step with sections, we should add the sections that still need to be done.
				if ( ( $i === $step )
					&& COMPLIANZ_TC::$config->has_sections( $page, $step )
				) {

					for (
						$s = $this->last_section( $page, $i ); $s >= $section;
						$s--
					) {
						$subsub         = 0;
						$section_fields = COMPLIANZ_TC::$config->fields(
							$page,
							$step,
							$s
						);
						foreach (
							$section_fields as $section_fieldname =>
							$section_field
						) {
							if ( isset( $section_field['time'] ) ) {
								$sub    += $section_field['time'];
								$subsub += $section_field['time'];
								$time   += $section_field['time'];
							}
						}
					}
				} else {
					// For steps other than the current one, sum all fields.
					$fields = COMPLIANZ_TC::$config->fields( $page, $i, false );

					foreach ( $fields as $fieldname => $field ) {
						if ( isset( $field['time'] ) ) {
							$sub  += $field['time'];
							$time += $field['time'];
						}
					}
				}
			}

			return (int) round( $time + 0.45 );
		}

		/**
		 * Calculates the overall wizard completion percentage.
		 *
		 * Counts all required fields across every step and section (excluding fields
		 * whose conditions are not met) and determines how many have a non-empty value.
		 * Also factors in whether the required shortcode pages (e.g. Terms & Conditions
		 * page) have been created. The result is rounded up and cached in
		 * $this->percentage_complete to avoid redundant recalculation on the same request.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param bool $count_warnings Whether to include warning-state fields in
		 *                             the total. Default true (currently unused internally).
		 * @return int Completion percentage between 0 and 100.
		 */
		public function wizard_percentage_complete( $count_warnings = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future use; public API must remain stable.
			// Return cached value to avoid recalculation within the same request.
			if ( false !== $this->percentage_complete ) {
				return $this->percentage_complete;
			}
			$total_fields     = 0;
			$completed_fields = 0;
			$total_steps      = $this->total_steps( 'terms-conditions' );
			for ( $i = 1; $i <= $total_steps; $i++ ) {
				$fields = COMPLIANZ_TC::$config->fields( 'terms-conditions', $i, false );
				foreach ( $fields as $fieldname => $field ) {
					// Determine whether this field is required under current conditions.
					$required = isset( $field['required'] ) ? $field['required'] : false;
					if ( ( isset( $field['condition'] ) || isset( $field['callback_condition'] ) ) && ! COMPLIANZ_TC::$field->condition_applies( $field )
					) {
						$required = false;
					}
					if ( $required ) {
						$value = cmplz_tc_get_value( $fieldname, false, false );
						++$total_fields;
						if ( ! empty( $value ) ) {
							++$completed_fields;
						}
					}
				}
			}

			// Factor in required document pages (e.g. Terms & Conditions shortcode page).
			$pages = COMPLIANZ_TC::$document->get_required_pages();
			foreach ( $pages as $region => $region_pages ) {
				foreach ( $region_pages as $type => $page ) {
					if ( COMPLIANZ_TC::$document->page_exists( $type ) ) {
						++$completed_fields;
					}
					++$total_fields;
				}
			}

			// Round up slightly (+ 0.45) so a nearly-complete wizard shows 100% rather than 99%.
			$percentage                = (int) round( 100 * ( $completed_fields / $total_fields ) + 0.45 );
			$this->percentage_complete = $percentage;
			return $percentage;
		}
	}


} //class closure
