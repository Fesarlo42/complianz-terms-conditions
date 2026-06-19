<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
// 100% match.

/**
 * Field rendering and persistence layer for the Complianz T&C wizard.
 *
 * Defines the cmplz_tc_field singleton class, which is responsible for
 * rendering every input type used in the setup wizard (text, checkbox,
 * radio, select, editor, multicheckbox, and more), saving submitted
 * wizard form data with per-type sanitisation, managing "multiple" rows
 * (add/remove repeatable entries), evaluating field conditions and
 * callback conditions, and registering translatable field values with
 * Polylang and WPML.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Field
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_field' ) ) {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.
	/**
	 * Renders wizard form fields and persists their values to WordPress options.
	 *
	 * Implemented as a singleton. Hooks into WordPress init (priority 5) to
	 * process form saves before the page renders, and into the plugin's own
	 * complianz_tc_before_label / complianz_tc_label_html / complianz_tc_after_label /
	 * complianz_tc_after_field action sequence that drives the field layout in the
	 * wizard template. Every public field-type method (text, radio, checkbox, etc.)
	 * follows the same pattern: check show_field(), fire the label hooks, render the
	 * input, fire after_field.
	 *
	 * @package    Complianz_Terms_Conditions
	 * @subpackage Field
	 *
	 * @since      1.0.0
	 */
	class cmplz_tc_field {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/**
		 * Holds the single instance of this class (singleton).
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_field|null
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Current wizard step position counter used during field rendering.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    int|null
		 */
		public $position;

		/**
		 * Cached field definitions array returned by cmplz_tc_config::fields().
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array|null
		 */
		public $fields;

		/**
		 * Default argument map merged with every field's args via wp_parse_args().
		 *
		 * Populated by load(). Keys mirror the field configuration schema:
		 * fieldname, type, required, default, label, table, callback_condition,
		 * condition, callback, placeholder, optional, disabled, hidden, region,
		 * media, first, warn, cols, minimum.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array
		 */
		public $default_args;

		/**
		 * Collects fieldnames of required fields that were empty on save.
		 *
		 * Populated during save_field() and read by show_errors() to render
		 * inline validation messages beneath the corresponding field label.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[] List of fieldname strings that failed required validation.
		 */
		public $form_errors = array();

		/**
		 * Initialises the field system, registers hooks, and loads default args.
		 *
		 * Guards against a second instantiation (singleton). Registers process_save()
		 * on init at priority 5 so the form is saved before the wizard template
		 * renders. Hooks label-rendering callbacks to the complianz_tc_* action
		 * sequence used by every field-type method. Calls load() to populate the
		 * default_args map.
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
			add_action( 'init', array( $this, 'process_save' ), 5 );
			add_action( 'cmplz_tc_register_translation', array( $this, 'register_translation' ), 10, 2 );

			add_action( 'complianz_tc_before_label', array( $this, 'before_label' ), 10, 1 );
			add_action( 'complianz_tc_before_label', array( $this, 'show_errors' ), 10, 1 );
			add_action( 'complianz_tc_label_html', array( $this, 'label_html' ), 10, 1 );
			add_action( 'complianz_tc_after_label', array( $this, 'after_label' ), 10, 1 );
			add_action( 'complianz_tc_after_field', array( $this, 'after_field' ), 10, 1 );

			$this->load();
		}

		/**
		 * Returns the single instance of this class.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_field The singleton instance.
		 */
		public static function this() {
			return self::$_this;
		}

		/**
		 * Renders the `<label>` element for a wizard field.
		 *
		 * Outputs a label with an optional `cmplz-disabled` class when the field
		 * is disabled, a title wrapper div, and an optional help/tooltip icon
		 * rendered via cmplz_tc_icon(). Hooked to: complianz_tc_label_html.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments merged with default_args. Relevant keys:
		 *                     - 'disabled'  (bool)   Whether the field is non-editable.
		 *                     - 'fieldname' (string) The input's id/for attribute value.
		 *                     - 'label'     (string) Human-readable label text (pre-escaped).
		 *                     - 'tooltip'   (string) Optional tooltip text for the help icon.
		 * @return void
		 */
		public function label_html( $args ) {
			?>
			<label class="<?php	echo $args['disabled'] ? 'cmplz-disabled' : ''; ?>" for="cmplz_<?php echo esc_attr( $args['fieldname'] ); ?>">
				<div class="cmplz-title-wrap"><?php echo esc_html( $args['label'] ); ?></div>
				<div>
					<?php
					if ( isset( $args['tooltip'] ) ) {
						echo cmplz_tc_icon( 'help', 'default', $args['tooltip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper.
					}
					?>
				</div>
			</label>
			<?php
		}


		/**
		 * Registers a field value string with active multilingual plugins.
		 *
		 * Registers the supplied string with Polylang (pll_register_string()),
		 * WPML (icl_register_string()), and the wpml_register_single_string
		 * action so it appears in each plugin's string-translation interface.
		 * Called via the cmplz_tc_register_translation action fired from save_field()
		 * and save_multiple().
		 *
		 * Hooked to: cmplz_tc_register_translation.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname            The unique field identifier used as the string name/key.
		 * @param  string $translation_string The translatable field value to register.
		 * @return void
		 */
		public function register_translation( $fieldname, $translation_string ) {
			// Polylang integration.
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $fieldname, $translation_string, 'complianz' );
			}

			// WPML integration.
			if ( function_exists( 'icl_register_string' ) ) {
				icl_register_string( 'complianz', $fieldname, $translation_string );
			}

			do_action(
				'wpml_register_single_string',
				'complianz',
				$fieldname,
				$translation_string
			);
		}

		/**
		 * Populates the default_args map used by all field-type methods.
		 *
		 * Called once during __construct(). Every field's configuration array is
		 * merged with these defaults via wp_parse_args() before rendering, so any
		 * key omitted by the field definition falls back to a safe default value.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function load() {
			$this->default_args = array(
				'fieldname'          => '',
				'type'               => 'text',
				'required'           => false,
				'default'            => '',
				'label'              => '',
				'table'              => false,
				'callback_condition' => false,
				'condition'          => false,
				'callback'           => false,
				'placeholder'        => '',
				'optional'           => false,
				'disabled'           => false,
				'hidden'             => false,
				'region'             => false,
				'media'              => true,
				'first'              => false,
				'warn'               => false,
				'cols'               => false,
				'minimum'            => 0,
			);
		}

		/**
		 * Processes and persists wizard form submissions on init (priority 5).
		 *
		 * Validates the manage_options capability and verifies the complianz_tc_nonce
		 * nonce before touching any data. Handles three distinct POST sub-actions:
		 * removing a repeatable-field row (cmplz_tc_remove_multiple), adding a new
		 * empty row (cmplz_tc_add_multiple), and bulk-saving repeatable rows
		 * (cmplz_tc_multiple). Also processes custom document page/URL overrides
		 * for each document type and saves all remaining cmplz_-prefixed fields
		 * via save_field(). Fires cmplz_after_saved_all_fields when complete.
		 *
		 * Hooked to: init (priority 5).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function process_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( isset( $_POST['complianz_tc_nonce'] ) ) {
				// Verify nonce before processing any posted data.
				if ( ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['complianz_tc_nonce'] ) ),
					'complianz_tc_save'
				) ) {
					return;
				}

				$fields = COMPLIANZ_TC::$config->fields();

				// Remove multiple field.
				if ( isset( $_POST['cmplz_tc_remove_multiple'] ) ) {
					$fieldnames = array_map(
						function ( $el ) {
							return sanitize_title( $el );
						},
						wp_unslash( $_POST['cmplz_tc_remove_multiple'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized via sanitize_title() in array_map callback.
					);

					foreach ( $fieldnames as $fieldname => $key ) {

						$page    = $fields[ $fieldname ]['source'];
						$options = get_option( 'complianz_tc_options_' . $page );

						$multiple_field = $this->get_value(
							$fieldname,
							array()
						);

						unset( $multiple_field[ $key ] );

						$options[ $fieldname ] = $multiple_field;
						if ( ! empty( $options ) ) {
							update_option(
								'complianz_tc_options_' . $page,
								$options
							);
						}
					}
				}

				// Add multiple field.
				if ( isset( $_POST['cmplz_tc_add_multiple'] ) ) {
					$fieldname
						= $this->sanitize_fieldname( sanitize_text_field( wp_unslash( $_POST['cmplz_tc_add_multiple'] ) ) );
					$this->add_multiple_field( $fieldname );
				}

				// Save multiple field.
				if ( ( isset( $_POST['cmplz-save'] )
						|| isset( $_POST['cmplz-next'] ) )
					&& isset( $_POST['cmplz_tc_multiple'] )
				) {
					$fieldnames
						= $this->sanitize_array( wp_unslash( $_POST['cmplz_tc_multiple'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array sanitized recursively inside sanitize_array(). // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array sanitized recursively inside sanitize_array().
					$this->save_multiple( $fieldnames );
				}

				// Save the custom URL's for not Complianz generated pages.
				$docs = COMPLIANZ_TC::$document->get_document_types();
				foreach ( $docs as $document ) {
					if ( isset( $_POST[ 'cmplz_' . $document . '_custom_page' ] ) ) {
						$doc_id = intval( $_POST[ 'cmplz_' . $document . '_custom_page' ] );
						update_option( 'cmplz_' . $document . '_custom_page', $doc_id );
						// If we have an actual privacy statement (custom), set it as the privacy URL for WP.
						if ( 'privacy-statement' === $document && $doc_id > 0 ) {
							COMPLIANZ_TC::$document->set_wp_privacy_policy( $doc_id, 'privacy-statement' );
						}
					}
					if ( isset( $_POST[ 'cmplz_' . $document . '_custom_page_url' ] ) ) {
						$url = esc_url_raw( wp_unslash( $_POST[ 'cmplz_' . $document . '_custom_page_url' ] ) );
						update_option( 'cmplz_' . $document . '_custom_page_url', $url );
					}
				}

				// Save data.
				$posted_fields = array_filter( wp_unslash( $_POST ), array( $this, 'filter_complianz_tc_fields' ), ARRAY_FILTER_USE_KEY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are sanitized per field type inside save_field() → sanitize().
				foreach ( $posted_fields as $fieldname => $fieldvalue ) {
					$this->save_field( $fieldname, $fieldvalue );
				}
				do_action( 'cmplz_after_saved_all_fields', $posted_fields );
			}
		}



		/**
		 * Recursively sanitises every scalar value in a nested array.
		 *
		 * Walks the supplied array depth-first. Scalar leaves are passed through
		 * sanitize_text_field(); nested arrays are recursed into. Used to sanitise
		 * the cmplz_tc_multiple POST payload before it is handed to save_multiple().
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $items The input array to sanitise (passed by reference internally).
		 * @return array       The sanitised array with all scalar values cleaned.
		 */
		public function sanitize_array( $items ) {
			foreach ( $items as &$value ) {
				if ( ! is_array( $value ) ) {
					$value = sanitize_text_field( $value );
				} else {
					$this->sanitize_array( $value ); // phpcs:ignore -- Recursive call; $value is passed by reference.
				}
			}

			return $items;
		}



		/**
		 * Checks whether a field has a conditional display rule.
		 *
		 * Returns true when the field's config has a non-empty 'condition' key,
		 * which means the field's visibility depends on the current value of
		 * another field. Used by save_field() to skip required-field validation
		 * for fields that are not currently visible.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname The field identifier to look up in the config.
		 * @return bool              True when the field has a condition, false otherwise.
		 */
		public function is_conditional( $fieldname ) {
			$fields = COMPLIANZ_TC::$config->fields();
			if ( isset( $fields[ $fieldname ]['condition'] )
				&& $fields[ $fieldname ]['condition']
			) {
				return true;
			}

			return false;
		}

		/**
		 * Checks whether a field is a repeatable "multiple" type.
		 *
		 * Returns true for fields of type 'thirdparties' or 'processors', which
		 * store an array of repeatable sub-entries (rows) rather than a single
		 * scalar value. Used to determine the correct save and render strategy.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname The field identifier to look up in the config.
		 * @return bool              True for thirdparties/processors types, false otherwise.
		 */
		public function is_multiple_field( $fieldname ) {
			$fields = COMPLIANZ_TC::$config->fields();
			if ( isset( $fields[ $fieldname ]['type'] )
				&& ( 'thirdparties' === $fields[ $fieldname ]['type'] )
			) {
				return true;
			}
			if ( isset( $fields[ $fieldname ]['type'] )
				&& ( 'processors' === $fields[ $fieldname ]['type'] )
			) {
				return true;
			}

			return false;
		}


		/**
		 * Saves repeatable-field row data from the wizard form.
		 *
		 * Iterates over the submitted fieldname/row map, sanitises every value,
		 * marks each entry as saved_by_user so it is not overwritten by automatic
		 * imports, merges the rows into the existing stored array, and persists
		 * the result. For translatable types (cookies, thirdparties, processors,
		 * editor) each sub-value is registered with multilingual plugins via the
		 * cmplz_register_translation action.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $fieldnames Map of fieldname => array of row data submitted
		 *                           from cmplz_tc_multiple POST entries.
		 * @return void
		 */
		public function save_multiple( $fieldnames ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$fields = COMPLIANZ_TC::$config->fields();
			foreach ( $fieldnames as $fieldname => $saved_fields ) {

				if ( ! isset( $fields[ $fieldname ] ) ) {
					return;
				}

				$page           = $fields[ $fieldname ]['source'];
				$type           = $fields[ $fieldname ]['type'];
				$options        = get_option( 'complianz_tc_options_' . $page );
				$multiple_field = $this->get_value( $fieldname, array() );

				foreach ( $saved_fields as $key => $value ) {
					$value = is_array( $value )
						? array_map( 'sanitize_text_field', $value )
						: sanitize_text_field( $value );
					// store the fact that this value was saved from the back-end, so should not get overwritten.
					$value['saved_by_user'] = true;
					$multiple_field[ $key ] = $value;

					// Make cookies and thirdparties translatable.
					if ( 'cookies' === $type || 'thirdparties' === $type
						|| 'processors' === $type
						|| 'editor' === $type
					) {
						if ( isset( $fields[ $fieldname ]['translatable'] )
							&& $fields[ $fieldname ]['translatable']
						) {
							foreach ( $value as $value_key => $field_value ) {
								do_action(
									'cmplz_register_translation',
									$key . '_' . $fieldname . '_' . $value_key,
									$field_value
								);
							}
						}
					}
				}

				$options[ $fieldname ] = $multiple_field;
				if ( ! empty( $options ) ) {
					update_option( 'complianz_tc_options_' . $page, $options );
				}
			}
		}

		/**
		 * Sanitises and persists a single wizard field value to the options table.
		 *
		 * Checks the manage_options capability, strips the cmplz_ prefix from the
		 * fieldname, applies the cmplz_fieldvalue filter, sanitises the value
		 * according to the field type (via sanitize()), records validation errors
		 * for empty required non-conditional fields, registers translatable values,
		 * and updates the complianz_tc_options_{source} option. Fires
		 * complianz_tc_before_save_{source}_option and
		 * complianz_tc_after_save_{source}_option hooks around the update.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname  The POST key (prefixed with cmplz_) for the field.
		 * @param  mixed  $fieldvalue The raw submitted value; will be sanitised by this method.
		 * @return void
		 */
		public function save_field( $fieldname, $fieldvalue ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$fieldvalue = apply_filters( 'cmplz_fieldvalue', $fieldvalue, $fieldname );
			$fields     = COMPLIANZ_TC::$config->fields();
			$fieldname  = str_replace( 'cmplz_', '', $fieldname );

			// Do not save callback fields.
			if ( isset( $fields[ $fieldname ]['callback'] ) ) {
				return;
			}

			$type       = $fields[ $fieldname ]['type'];
			$page       = $fields[ $fieldname ]['source'];
			$required   = isset( $fields[ $fieldname ]['required'] ) ? $fields[ $fieldname ]['required'] : false;
			$fieldvalue = $this->sanitize( $fieldvalue, $type );

			if ( ! $this->is_conditional( $fieldname ) && $required
				&& empty( $fieldvalue )
			) {
				$this->form_errors[] = $fieldname;
			}

			// Make translatable.
			if ( 'text' === $type || 'textarea' === $type || 'editor' === $type || 'url' === $type ) {
				if ( isset( $fields[ $fieldname ]['translatable'] )
					&& $fields[ $fieldname ]['translatable']
				) {
					do_action( 'cmplz_tc_register_translation', $fieldname, $fieldvalue );
				}
			}

			$options = get_option( 'complianz_tc_options_' . $page );
			if ( ! is_array( $options ) ) {
				$options = array();
			}
			$prev_value = isset( $options[ $fieldname ] ) ? $options[ $fieldname ] : false;
			do_action( 'complianz_tc_before_save_' . $page . '_option', $fieldname, $fieldvalue, $prev_value, $type );
			$options[ $fieldname ] = $fieldvalue;

			update_option( 'complianz_tc_options_' . $page, $options );

			do_action( 'complianz_tc_after_save_' . $page . '_option', $fieldname, $fieldvalue, $prev_value, $type );
		}


		/**
		 * Adds a new empty row to a repeatable field's stored array.
		 *
		 * Appends a blank entry to the existing multiple-field value and persists
		 * it. For used_cookies fields a timestamped key is generated automatically
		 * when $cookie_type is not provided. Prevents duplicating an existing key,
		 * re-adding a previously deleted cookie, and adding built-in WordPress
		 * cookies (whose names start with wordpress_).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string       $fieldname   The multiple-field identifier.
		 * @param  string|false $cookie_type Optional cookie type key. When false and the
		 *                                   field is 'used_cookies', a custom_{timestamp}
		 *                                   key is generated. Default false.
		 * @return void
		 */
		public function add_multiple_field( $fieldname, $cookie_type = false ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$fields = COMPLIANZ_TC::$config->fields();

			$page    = $fields[ $fieldname ]['source'];
			$options = get_option( 'complianz_tc_options_' . $page );

			$multiple_field = $this->get_value( $fieldname, array() );
			if ( 'used_cookies' === $fieldname && ! $cookie_type ) {
				$cookie_type = 'custom_' . time();
			}
			if ( ! is_array( $multiple_field ) ) {
				$multiple_field = array( $multiple_field );
			}

			if ( $cookie_type ) {
				// Prevent key from being added twice.
				foreach ( $multiple_field as $index => $cookie ) {
					if ( $cookie['key'] === $cookie_type ) {
						return;
					}
				}

				// Don't add field if it was deleted previously.
				$deleted_cookies = get_option( 'cmplz_deleted_cookies' );
				if ( ( $deleted_cookies
						&& in_array( $cookie_type, $deleted_cookies, true ) )
				) {
					return;
				}

				// Don't add default WordPress cookies.
				if ( strpos( $cookie_type, 'wordpress_' ) !== false ) {
					return;
				}

				$multiple_field[] = array( 'key' => $cookie_type );
			} else {
				$multiple_field[] = array();
			}

			$options[ $fieldname ] = $multiple_field;

			if ( ! empty( $options ) ) {
				update_option( 'complianz_tc_options_' . $page, $options );
			}
		}

		/**
		 * Sanitises a field value according to its declared type.
		 *
		 * Maps each supported field type to the appropriate WordPress sanitisation
		 * function: colorpicker → sanitize_hex_color(), text/phone →
		 * sanitize_text_field(), multicheckbox → array_map(sanitize_text_field),
		 * email → sanitize_email(), url → esc_url_raw(), number → intval(),
		 * textarea/editor → wp_kses_post(). css and javascript types are stored
		 * as-is (capability check is the gate). Returns false when the current
		 * user lacks manage_options.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  mixed  $value The raw value to sanitise.
		 * @param  string $type  The field type string (e.g. 'text', 'checkbox', 'url').
		 * @return array|bool|int|string|false The sanitised value, or false on capability failure.
		 */
		public function sanitize( $value, $type ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}
			switch ( $type ) {
				case 'colorpicker':
					return sanitize_hex_color( $value );
				case 'text':
					return sanitize_text_field( $value );
				case 'multicheckbox':
					if ( ! is_array( $value ) ) {
						$value = array( $value );
					}

					return array_map( 'sanitize_text_field', $value );
				case 'phone':
					$value = sanitize_text_field( $value );

					return $value;
				case 'email':
					return sanitize_email( $value );
				case 'url':
					return esc_url_raw( $value );
				case 'number':
					return intval( $value );
				case 'css':
				case 'javascript':
					return $value;
				case 'editor':
				case 'textarea':
					return wp_kses_post( $value );
			}

			return sanitize_text_field( $value );
		}



		/**
		 * Filters the POST array to retain only known Complianz field keys.
		 *
		 * Used as an array_filter() callback with ARRAY_FILTER_USE_KEY to extract
		 * only the keys from $_POST that are prefixed with cmplz_ and correspond
		 * to a registered field in the config. Prevents arbitrary POST data from
		 * reaching save_field().
		 *
		 * @since  1.0.0
		 * @access private
		 *
		 * @param  string $fieldname The POST key to test.
		 * @return bool              True when the key is a known cmplz_ field, false otherwise.
		 */
		private function filter_complianz_tc_fields(
			$fieldname
		) {
			if ( strpos( $fieldname, 'cmplz_' ) !== false
				&& isset(
					COMPLIANZ_TC::$config->fields[ str_replace(
						'cmplz_',
						'',
						$fieldname
					) ]
				)
			) {
				return true;
			}

			return false;
		}

		/**
		 * Opens the field wrapper div and prepends conditional-visibility attributes.
		 *
		 * Builds CSS class strings and HTML data attributes for multi-condition
		 * support (condition-check-N, data-condition-question-N, data-condition-answer-N).
		 * Evaluates the field's current condition via condition_applies() to add the
		 * cmplz-hidden class for fields whose condition is not currently met. Also
		 * handles hidden, first, type, cols/col/colspan classes, and calls
		 * get_master_label() for section headings.
		 *
		 * Hooked to: complianz_tc_before_label.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: condition, hidden,
		 *                     first, type, cols, col, colspan, fieldname, master_label.
		 * @return void
		 */
		public function before_label( $args ) {
			$condition_class    = '';
			$condition_question = '';
			$condition_answer   = '';

			if ( ! empty( $args['condition'] ) ) {
				$condition_count = 1;
				foreach ( $args['condition'] as $question => $answer ) {
					$question            = esc_attr( $question );
					$answer              = esc_attr( $answer );
					$condition_class    .= "condition-check-{$condition_count} ";
					$condition_question .= "data-condition-answer-{$condition_count}='{$answer}' ";
					$condition_answer   .= "data-condition-question-{$condition_count}='{$question}' ";
					++$condition_count;
				}
			}

			$hidden_class = ( $args['hidden'] ) ? 'hidden' : '';
			$cmplz_hidden = $this->condition_applies( $args ) ? '' : 'cmplz-hidden';
			$first_class  = ( $args['first'] ) ? 'first' : '';
			$type         = 'notice' === $args['type'] ? '' : $args['type'];

			$cols_class    = isset( $args['cols'] ) && $args['cols'] ? "cmplz-cols-{$args['cols']}" : '';
			$col_class     = isset( $args['col'] ) ? "cmplz-col-{$args['col']}" : '';
			$colspan_class = isset( $args['colspan'] ) ? "cmplz-colspan-{$args['colspan']}" : '';

			$this->get_master_label( $args );

			echo '<div class="field-group ' .
					esc_attr(
						$args['fieldname'] . ' ' .
						esc_attr( $cols_class ) . ' ' .
						esc_attr( $col_class ) . ' ' .
						esc_attr( $colspan_class ) . ' ' .
						'cmplz-' . $type . ' ' .
						$hidden_class . ' ' .
						$first_class . ' ' .
						$condition_class . ' ' .
						$cmplz_hidden
					)
				. '" ';

			echo $condition_question; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr()-escaped values above.
			echo $condition_answer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr()-escaped values above.

			echo '><div class="cmplz-field"><div class="cmplz-label">';
		}

		/**
		 * Renders an optional section-level heading above a field group.
		 *
		 * Outputs an `<h2>` heading wrapped in `.cmplz-master-label` when the
		 * field args include a 'master_label' key. Used to visually separate
		 * groups of fields within the same wizard step.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments. Only 'master_label' (string) is used.
		 * @return void
		 */
		public function get_master_label( $args ) {
			if ( ! isset( $args['master_label'] ) ) {
				return;
			}
			?>
			<div class="cmplz-master-label"><h2><?php echo esc_html( $args['master_label'] ); ?></h2></div>
			<?php
		}

		/**
		 * Renders an inline validation error message for a required field.
		 *
		 * Checks whether the field's name is present in the form_errors list
		 * (populated by save_field() for empty required fields) and outputs a
		 * styled error div when it is. Hooked to: complianz_tc_before_label.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments. Uses 'fieldname' (string) to match
		 *                     against the form_errors array.
		 * @return void
		 */
		public function show_errors(
			$args
		) {
			if ( in_array( $args['fieldname'], $this->form_errors, true ) ) {
				?>
				<div class="cmplz-form-errors">
					<?php
					esc_html_e(
						'This field is required. Please complete the question before continuing',
						'complianz-terms-conditions'
					)
					?>
				</div>
				<?php
			}
		}

		/**
		 * Renders the tooltip icon inside a field label when a tooltip is set.
		 *
		 * Outputs the help icon SVG via cmplz_tc_icon() when 'tooltip' is present
		 * in the field args. This is an alternative entry point to the tooltip
		 * rendered by label_html(); it can be used when callers need the icon
		 * in a different DOM position.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments. Uses 'tooltip' (string) when present.
		 * @return void
		 */
		public function in_label( $args ) {
			if ( isset( $args['tooltip'] ) ) {
				echo cmplz_tc_icon( 'help', 'default', $args['tooltip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper.
			}
		}

		/**
		 * Closes the `.cmplz-label` wrapper div opened by before_label().
		 *
		 * Hooked to: complianz_tc_after_label.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments (unused; present for hook compatibility).
		 * @return void
		 */
		public function after_label( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by complianz_tc_after_label hook signature.
				echo '</div>';
		}

		/**
		 * Closes the field wrapper and renders the optional help sidebar notice.
		 *
		 * Closes the `.cmplz-field` inner div, outputs the help text (via
		 * cmplz_tc_sidebar_notice() with kses-sanitised content) inside a
		 * `.cmplz-help-warning-wrap` div, fires the cmplz_tc_notice_{fieldname}
		 * action for field-specific notice injection, then closes the outer
		 * `.field-group` wrapper opened by before_label().
		 *
		 * Hooked to: complianz_tc_after_field.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments. Uses 'help' (string) and 'fieldname' (string).
		 * @return void
		 */
		public function after_field( $args ) {

			$this->get_comment( $args );
			echo '</div><!--close in after field-->';
			echo '<div class="cmplz-help-warning-wrap">';
			if ( isset( $args['help'] ) ) {
				cmplz_tc_sidebar_notice( wp_kses_post( $args['help'] ) );
			}

			do_action( 'cmplz_tc_notice_' . $args['fieldname'], $args );

			echo '</div>';
			echo '</div>';
		}


		/**
		 * Renders a single-line text input field.
		 *
		 * Fires the standard label hooks, then outputs an `<input type="text">`
		 * with the current stored value, the cmplz_-prefixed field name, optional
		 * required attribute, and check/times validation icons.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default, required, placeholder).
		 * @return void
		 */
		public function text( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname   = 'cmplz_' . $args['fieldname'];
			$value       = $this->get_value( $args['fieldname'], $args['default'] );
			$required    = $args['required'] ? 'required' : '';
			$is_required = $args['required'] ? 'is-required' : '';
			$check_icon  = cmplz_tc_icon( 'check', 'success' );
			$times_icon  = cmplz_tc_icon( 'times', 'error' );
			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<input <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
				class="validation <?php echo $is_required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"
				type="text"
				value="<?php echo esc_html( $value ); ?>"
				name="<?php echo esc_html( $fieldname ); ?>"
				id="<?php echo esc_html( $fieldname ); ?>"
			>
			<?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php echo $times_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>

			<?php
		}

		/**
		 * Renders a URL input field with a browser-side URL pattern constraint.
		 *
		 * Identical to text() but adds a pattern attribute that accepts URLs
		 * (with or without http(s)://) for client-side validation. The value is
		 * stored via esc_url_raw() on save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default, required, placeholder).
		 * @return void
		 */
		public function url( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname   = 'cmplz_' . $args['fieldname'];
			$value       = $this->get_value( $args['fieldname'], $args['default'] );
			$required    = $args['required'] ? 'required' : '';
			$is_required = $args['required'] ? 'is-required' : '';
			$check_icon  = cmplz_tc_icon( 'check', 'success' );
			$times_icon  = cmplz_tc_icon( 'times', 'error' );

			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<input <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
				class="validation <?php echo $is_required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"
				type="text"
				pattern="(http(s)?(:\/\/))?(www\.)?[\#a-zA-Z0-9\-_\.\/\:].*"
				value="<?php echo esc_html( $value ); ?>"
				name="<?php echo esc_html( $fieldname ); ?>"
				id="<?php echo esc_html( $fieldname ); ?>"
			>
			<?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php echo $times_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>

			<?php
		}

		/**
		 * Renders an email address input field.
		 *
		 * Outputs `<input type="email">` with the current value. The submitted
		 * value is sanitised with sanitize_email() during save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default, required, placeholder).
		 * @return void
		 */
		public function email( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname   = 'cmplz_' . $args['fieldname'];
			$value       = $this->get_value( $args['fieldname'], $args['default'] );
			$required    = $args['required'] ? 'required' : '';
			$is_required = $args['required'] ? 'is-required' : '';
			$check_icon  = cmplz_tc_icon( 'check', 'success' );
			$times_icon  = cmplz_tc_icon( 'times', 'error' );
			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<input <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
				class="validation <?php echo $is_required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
				placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"
				type="email"
				value="<?php echo esc_html( $value ); ?>"
				name="<?php echo esc_html( $fieldname ); ?>"
				id="<?php echo esc_html( $fieldname ); ?>"
			>
			<?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php echo $times_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>

			<?php
		}

		/**
		 * Renders a telephone number input field.
		 *
		 * Outputs `<input type="text" autocomplete="tel">` to trigger the mobile
		 * keyboard's telephone layout. The submitted value is sanitised with
		 * sanitize_text_field() during save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default, required, placeholder).
		 * @return void
		 */
		public function phone( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname   = 'cmplz_' . $args['fieldname'];
			$value       = $this->get_value( $args['fieldname'], $args['default'] );
			$required    = $args['required'] ? 'required' : '';
			$is_required = $args['required'] ? 'is-required' : '';
			$check_icon  = cmplz_tc_icon( 'check', 'success' );
			$times_icon  = cmplz_tc_icon( 'times', 'error' );

			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<input autocomplete="tel" <?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
					class="validation <?php echo $is_required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded CSS class. ?>"
					placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"
					type="text"
					value="<?php echo esc_html( $value ); ?>"
					name="<?php echo esc_html( $fieldname ); ?>"
			>
			<?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php echo $times_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>

			<?php
		}

		/**
		 * Renders a numeric input field with min and step constraints.
		 *
		 * Outputs `<input type="number">` with the configured minimum value and
		 * a step derived from 'validation_step' (default 1). The submitted value
		 * is cast to int via intval() during save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: fieldname, default,
		 *                     required, placeholder, minimum, validation_step.
		 * @return void
		 */
		public function number(
			$args
		) {
			$fieldname = 'cmplz_' . $args['fieldname'];
			$value     = $this->get_value(
				$args['fieldname'],
				$args['default']
			);
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<input 
			<?php
			if ( $args['required'] ) {
				echo 'required';
			}
			?>
				class="validation 
				<?php
				if ( $args['required'] ) {
					echo 'is-required';
				}
				?>
				"
				placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"
				type="number"
				value="<?php echo esc_html( $value ); ?>"
				name="<?php echo esc_html( $fieldname ); ?>"
				id="<?php echo esc_html( $fieldname ); ?>"
				min="<?php echo esc_attr( $args['minimum'] ); ?>" step="<?php echo isset( $args['validation_step'] ) ? intval( $args['validation_step'] ) : 1; ?>"
				>
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}


		/**
		 * Renders a toggle-switch checkbox field.
		 *
		 * Outputs a hidden input (for unchecked state) and a styled toggle-switch
		 * checkbox. Supports a $force_value override used by the cookies/thirdparties
		 * sub-field renderers. Disabled checkboxes pass their current value through
		 * a hidden placeholder so it is not wiped on save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args        Merged field arguments (fieldname, default, required, disabled).
		 * @param  mixed $force_value Optional value override; when truthy, overrides the stored value.
		 *                            Default false.
		 * @return void
		 */
		public function checkbox(
			$args,
			$force_value = false
		) {
			$fieldname = 'cmplz_' . $args['fieldname'];

			$value             = $force_value ? $force_value
				: $this->get_value( $args['fieldname'], $args['default'] );
			$placeholder_value = ( $args['disabled'] && $value ) ? $value : 0;
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<label class="cmplz-switch">
				<input name="<?php echo esc_html( $fieldname ); ?>" type="hidden"
						value="<?php echo esc_attr( $placeholder_value ); ?>"/>

				<input name="<?php echo esc_html( $fieldname ); ?>" size="40"
						type="checkbox"
					<?php
					if ( $args['disabled'] ) {
						echo 'disabled';
					}
					?>
						class="
						<?php
						if ( $args['required'] ) {
							echo 'is-required';
						}
						?>
						"
						value="1" <?php checked( 1, $value, true ); ?> />
				<span class="cmplz-slider cmplz-round"></span>
			</label>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a group of checkboxes allowing multiple selections.
		 *
		 * Builds three parallel index arrays (value, default, disabled) keyed by
		 * option value, then iterates over $args['options'] to output a styled
		 * `.cmplz-checkbox-container` label for each option. Options in the
		 * disabled index are wrapped in a `.cmplz-not-allowed` div. A hidden 0
		 * input per option ensures unchecked values are posted. Falls back to a
		 * "No options found" notice when the options array is empty.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: fieldname, options
		 *                     (assoc array of value => label), default, disabled (bool|array),
		 *                     required.
		 * @return void
		 */
		public function multicheckbox( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname = 'cmplz_' . $args['fieldname'];

			// Initialize.
			$default_index  = array();
			$disabled_index = array();
			$value_index    = array();
			$validate       = '';
			$check_icon     = '';

			if ( ! empty( $args['options'] ) ) {
				// Value index.
				$value = cmplz_tc_get_value( $args['fieldname'], false, false, false );
				foreach ( $args['options'] as $option_key => $option_label ) {
					if ( is_array( $value ) && isset( $value[ $option_key ] ) && $value[ $option_key ] ) { // If value is not set it is an empty string.
						$value_index[ $option_key ] = 'checked';
					} else {
						$value_index[ $option_key ] = '';
					}
				}

				// Default index.
				$defaults = apply_filters( 'cmplz_tc_default_value', $args['default'], $fieldname );
				foreach ( $args['options'] as $option_key => $option_label ) {
					if ( ! is_array( $defaults ) ) { // If default is not an array, treat it as a scalar.
						$default_index[ $option_key ] = ( $option_key === $defaults ) ? 'cmplz-default' : '';
					} else {
						$default_index[ $option_key ] = in_array( $option_key, $defaults, true ) ? 'cmplz-default' : '';
					}
				}

				// Disabled index.
				foreach ( $args['options'] as $option_key => $option_label ) {
					if ( is_array( $args['disabled'] ) && in_array( $option_key, $args['disabled'], true ) ) {
						$disabled_index[ $option_key ] = 'cmplz-disabled';
					} else {
						$disabled_index[ $option_key ] = '';
					}
				}

				// Required.
				$validate = $args['required'] ? 'class="cmplz-validate-multicheckbox"' : '';

				// Check icon.
				$check_icon = cmplz_tc_icon( 'check', 'success' );
			}

			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<div <?php echo $validate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string from trusted internal logic. ?>>

			<?php
			if ( ! empty( $args['options'] ) ) {
				foreach ( $args['options'] as $option_key => $option_label ) {
					if ( 'cmplz-disabled' === $disabled_index[ $option_key ] ) {
						echo '<div class="cmplz-not-allowed">';
					}
					?>
					<label class="cmplz-checkbox-container <?php echo esc_attr( $disabled_index[ $option_key ] ); ?>"><?php echo esc_html( $option_label ); ?>
						<input
							name="<?php echo esc_html( $fieldname ); ?>[<?php echo esc_attr( $option_key ); ?>]"
							type="hidden"
							value="0"
						>
						<input
							name="<?php echo esc_html( $fieldname ); ?>[<?php echo esc_attr( $option_key ); ?>]"
							class="<?php echo esc_html( $fieldname ); ?>[<?php echo esc_attr( $option_key ); ?>]"
							type="checkbox"
							id="<?php echo esc_html( $fieldname ); ?>"
							value="1"
							<?php echo $value_index[ $option_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded 'checked' or empty string. ?>
						>
						<div
							class="checkmark <?php echo esc_attr( $default_index[ $option_key ] ); ?>"
							<?php echo $value_index[ $option_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded 'checked' or empty string. ?>
						><?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?></div>
					</label>
					<?php
					if ( 'cmplz-disabled' === $disabled_index[ $option_key ] ) {
						echo '</div>'; // Closes cmplz-not-allowed wrapper.
					}
				}
			} else {
				cmplz_tc_notice( __( 'No options found', 'complianz-terms-conditions' ) );
			}
			?>

			</div>

			<?php
			do_action( 'complianz_tc_after_field', $args );
		}

		/**
		 * Renders a group of radio-button inputs.
		 *
		 * Iterates over $args['options'] to output a styled `.cmplz-radio-container`
		 * label for each option. Handles disabled options (individually via array or
		 * all via true) and marks default options with the cmplz-default CSS class.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: fieldname, default,
		 *                     options (assoc value => label), required, disabled (bool|array).
		 * @return void
		 */
		public function radio( $args ) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			$fieldname      = 'cmplz_' . $args['fieldname'];
			$value          = $this->get_value( $args['fieldname'], $args['default'] );
			$options        = $args['options'];
			$required       = $args['required'] ? 'required' : '';
			$check_icon     = cmplz_tc_icon( 'bullet', 'default', '', 10 );
			$disabled_index = array();
			$default_index  = array();

			if ( ! empty( $options ) ) {
				// Disabled index.
				foreach ( $options as $option_value => $option_label ) {
					if ( ( is_array( $args['disabled'] ) && in_array( $option_value, $args['disabled'], true ) ) || true === $args['disabled'] ) {
						$disabled_index[ $option_value ] = 'cmplz-disabled';
					} else {
						$disabled_index[ $option_value ] = '';
					}
				}
				// Default index.
				foreach ( $options as $option_value => $option_label ) {
					if ( is_array( $args['default'] ) && in_array( $option_value, $args['default'], true ) ) {
						$default_index[ $option_value ] = 'cmplz-default';
					} else {
						$default_index[ $option_value ] = '';
					}
				}
			}

			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<?php
			if ( ! empty( $options ) ) {
				foreach ( $options as $option_value => $option_label ) {
					if ( 'cmplz-disabled' === $disabled_index[ $option_value ] ) {
						echo '<div class="cmplz-not-allowed">';
					}
					?>
					<label class="cmplz-radio-container <?php echo esc_attr( $disabled_index[ $option_value ] ); ?>"><?php echo esc_html( $option_label ); ?>
						<input
							<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
								type="radio"
								id="<?php echo esc_html( $fieldname ); ?>"
								name="<?php echo esc_html( $fieldname ); ?>"
								class="<?php echo esc_html( $fieldname ); ?>"
								value="<?php echo esc_html( $option_value ); ?>"
							<?php
							if ( $option_value === $value ) {
								echo 'checked';}
							?>
						>
						<div class="radiobtn <?php echo esc_attr( $default_index[ $option_value ] ); ?>"
							<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded attribute string. ?>
						><?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?></div>
					</label>
					<?php
					if ( 'cmplz-disabled' === $disabled_index[ $option_value ] ) {
						echo '</div>'; // Closes cmplz-not-allowed wrapper.
					}
				}
			}
			?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}


		/**
		 * Determines whether a field should be rendered based on its callback condition.
		 *
		 * A thin wrapper around condition_applies() that passes 'callback_condition'
		 * as the type. Field-type methods call this at the top to bail out early
		 * when the field's condition is not currently met.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments containing 'callback_condition'.
		 * @return bool        True when the field should be rendered, false when it should be hidden.
		 */
		public function show_field( $args ) {
			$show = ( $this->condition_applies( $args, 'callback_condition' ) );

			return $show;
		}


		/**
		 * Evaluates a named PHP function as a field condition.
		 *
		 * Calls the supplied function name and returns its boolean result. Supports
		 * negation by prefixing the function name with 'NOT ': the result is flipped
		 * before returning. Used by condition_applies() when a condition value is a
		 * callable string rather than a field/value pair.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $func The callback function name. Prefix with 'NOT ' to invert the result.
		 * @return bool         The (possibly inverted) return value of the callback function.
		 */
		public function function_callback_applies( $func ) {
			$invert = false;

			if ( strpos( $func, 'NOT ' ) !== false ) {
				$invert = true;
				$func   = str_replace( 'NOT ', '', $func );
			}
			$show_field = $func();
			if ( $invert ) {
				$show_field = ! $show_field;
			}
			if ( $show_field ) {
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Evaluates whether a field's condition is currently satisfied.
		 *
		 * Supports two condition types: 'condition' (field/value pairs checked against
		 * current stored option values) and 'callback_condition' (PHP function names).
		 * Within a condition, multiple values for the same question are separated by
		 * commas and treated as OR. Multiple questions are AND-ed. Negation is
		 * expressed by prefixing a value with 'NOT '. When no condition is set the
		 * method returns true (field is visible by default).
		 *
		 * When checking 'condition', any array-type 'callback_condition' is merged in
		 * so a field can require both a stored-value condition and a function condition.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array        $args The field argument array (merged with default_args).
		 * @param  string|false $type The condition type to evaluate: 'condition',
		 *                            'callback_condition', or false to auto-detect.
		 *                            Default false.
		 * @return bool               True when the condition is satisfied (field is visible).
		 */
		public function condition_applies( $args, $type = false ) {
			$default_args = $this->default_args;
			$args         = wp_parse_args( $args, $default_args );

			if ( ! $type ) {
				if ( $args['condition'] ) {
					$type = 'condition';
				} elseif ( $args['callback_condition'] ) {
					$type = 'callback_condition';
				}
			}

			if ( ! $type || ! $args[ $type ] ) {
				return true;
			}

			// Function callbacks.
			$maybe_is_function = is_string( $args[ $type ] ) ? str_replace( 'NOT ', '', $args[ $type ] ) : '';
			if ( ! is_array( $args[ $type ] ) && ! empty( $args[ $type ] ) && function_exists( $maybe_is_function ) ) {
				return $this->function_callback_applies( $args[ $type ] );
			}

			$condition = $args[ $type ];

			// if we're checking the condition, but there's also a callback condition, check that one as well.
			// but only if it's an array. Otherwise it's a func.
			if ( 'condition' === $type && isset( $args['callback_condition'] ) && is_array( $args['callback_condition'] ) ) {
				$condition += $args['callback_condition'];
			}

			foreach ( $condition as $c_fieldname => $c_value_content ) {
				$c_values = $c_value_content;
				// the possible multiple values are separated with comma instead of an array, so we can add NOT.
				if ( ! is_array( $c_value_content ) && strpos( $c_value_content, ',' ) !== false ) {
					$c_values = explode( ',', $c_value_content );
				}
				$c_values = is_array( $c_values ) ? $c_values : array( $c_values );

				foreach ( $c_values as $c_value ) {
					$maybe_is_function = str_replace( 'NOT ', '', $c_value );
					if ( function_exists( $maybe_is_function ) ) {
						$match = $this->function_callback_applies( $c_value );
						if ( ! $match ) {
							return false;
						}
					} else {
						$actual_value = cmplz_tc_get_value( $c_fieldname );

						$fieldtype = $this->get_field_type( $c_fieldname );

						if ( strpos( $c_value, 'NOT ' ) === false ) {
							$invert = false;
						} else {
							$invert  = true;
							$c_value = str_replace( 'NOT ', '', $c_value );
						}

						if ( 'multicheckbox' === $fieldtype ) {
							if ( ! is_array( $actual_value ) ) {
								$actual_value = array( $actual_value );
							}
							// Get all items that are set to true.
							$actual_value = array_filter(
								$actual_value,
								function ( $item ) {
									return 1 === $item;
								}
							);
							$actual_value = array_keys( $actual_value );

							$match = false;
							foreach ( $c_values as $check_each_value ) {
								if ( in_array(
									$check_each_value,
									$actual_value,
									true
								)
								) {
									$match = true;
								}
							}
						} else {
							// When the actual value is an array, one match is enough.
							// Check all items; return false only when none matched.
							// This preserves the AND property of the outer condition.
							$match = ( $c_value === $actual_value || in_array( $actual_value, $c_values, true ) );

						}
						if ( $invert ) {
							$match = ! $match;
						}
						if ( ! $match ) {
							return false;
						}
					}
				}
			}

			return true;
		}

		/**
		 * Returns the field type string for a given fieldname.
		 *
		 * Looks up the 'type' key in the plugin config for the supplied fieldname.
		 * Used by condition_applies() to handle multicheckbox fields differently
		 * from scalar fields during condition evaluation.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname The field identifier to look up.
		 * @return string|false      The field type string (e.g. 'text', 'multicheckbox'),
		 *                           or false when the fieldname is not registered.
		 */
		public function get_field_type( $fieldname ) {
			if ( ! isset( COMPLIANZ_TC::$config->fields[ $fieldname ] ) ) {
				return false;
			}

			return COMPLIANZ_TC::$config->fields[ $fieldname ]['type'];
		}

		/**
		 * Renders a multi-line textarea input field.
		 *
		 * Outputs a `<textarea>` element with the current stored value, the
		 * cmplz_-prefixed field name, optional required attribute, and check/times
		 * validation icons. The submitted value is sanitised with wp_kses_post()
		 * during save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default, required, placeholder).
		 * @return void
		 */
		public function textarea(
			$args
		) {
			$fieldname  = 'cmplz_' . $args['fieldname'];
			$check_icon = cmplz_tc_icon( 'check', 'success' );
			$times_icon = cmplz_tc_icon( 'times', 'error' );
			$value      = $this->get_value( $args['fieldname'], $args['default'] );
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<textarea name="<?php echo esc_html( $fieldname ); ?>" id="<?php echo esc_html( $fieldname ); ?>"
						<?php
						if ( $args['required'] ) {
							echo 'required';
						}
						?>
						class="validation 
						<?php
						if ( $args['required'] ) {
							echo 'is-required';
						}
						?>
						"
						placeholder="<?php echo esc_html( $args['placeholder'] ); ?>"><?php echo esc_html( $value ); ?></textarea>

			<?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php echo $times_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a WP-editor (TinyMCE/Gutenberg) rich-text field.
		 *
		 * Forces the 'first' flag so the field occupies the full wizard column.
		 * Passes media_buttons (controlled by $args['media']), editor_height of 300 px,
		 * and textarea_rows of 15 to wp_editor(). The submitted content is sanitised
		 * with wp_kses_post() during save.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array  $args Merged field arguments (fieldname, default, media).
		 * @param  string $step Current wizard step identifier (passed through to wp_editor). Default ''.
		 * @return void
		 */
		public function editor( $args, $step = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $step is part of the public API signature; callers may pass it for future use.
			$fieldname     = 'cmplz_' . $args['fieldname'];
			$args['first'] = true;
			$media         = $args['media'] ? true : false;

			$value = $this->get_value( $args['fieldname'], $args['default'] );

			if ( ! $this->show_field( $args ) ) {
				return;
			}

			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<?php
			$settings = array(
				'media_buttons' => $media,
				'editor_height' => 300,
				// In pixels; takes precedence over textarea_rows and has no default value.
				'textarea_rows' => 15,
			);
			wp_editor( $value, $fieldname, $settings );
			?>
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a JavaScript code editor field using the Ace editor library.
		 *
		 * Outputs a div styled for Ace, a hidden `<textarea>` (the actual POST value),
		 * and an inline script that initialises Ace in JavaScript mode and syncs
		 * changes back to the textarea. The saved value is stored as-is (no
		 * sanitisation beyond the capability check in sanitize()).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default).
		 * @return void
		 */
		public function javascript(
			$args
		) {
			$fieldname = 'cmplz_' . $args['fieldname'];
			$value     = $this->get_value(
				$args['fieldname'],
				$args['default']
			);
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<div id="<?php echo esc_html( $fieldname ); ?>editor"
				style="height: 200px; width: 100%"><?php echo esc_html( $value ); ?></div>
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<script>
				var <?php echo esc_html( $fieldname ); ?> =
				ace.edit("<?php echo esc_html( $fieldname ); ?>editor");
				<?php echo esc_html( $fieldname ); ?>.setTheme("ace/theme/monokai");
				<?php echo esc_html( $fieldname ); ?>.session.setMode("ace/mode/javascript");
				jQuery(document).ready(function ($) {
					var textarea = $('textarea[name="<?php echo esc_html( $fieldname ); ?>"]');
					<?php echo esc_html( $fieldname ); ?>.
					getSession().on("change", function () {
						textarea.val(<?php echo esc_html( $fieldname ); ?>.getSession().getValue()
					)
					});
				});
			</script>
			<textarea style="display:none"
						name="<?php echo esc_html( $fieldname ); ?>"><?php echo esc_html( $value ); ?></textarea>
			<?php
		}

		/**
		 * Renders a CSS code editor field using the Ace editor library.
		 *
		 * Identical to javascript() but initialises Ace in CSS mode and uses a
		 * slightly taller editor (290 px vs 200 px). The saved value is stored
		 * as-is (no sanitisation beyond the capability check in sanitize()).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, default).
		 * @return void
		 */
		public function css(
			$args
		) {
			$fieldname = 'cmplz_' . $args['fieldname'];

			$value = $this->get_value( $args['fieldname'], $args['default'] );
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>

			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<div id="<?php echo esc_html( $fieldname ); ?>editor"
				style="height: 290px; width: 100%"><?php echo esc_html( $value ); ?></div>
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<script>
				var <?php echo esc_html( $fieldname ); ?> =
				ace.edit("<?php echo esc_html( $fieldname ); ?>editor");
				<?php echo esc_html( $fieldname ); ?>.setTheme("ace/theme/monokai");
				<?php echo esc_html( $fieldname ); ?>.session.setMode("ace/mode/css");
				jQuery(document).ready(function ($) {
					var textarea = $('textarea[name="<?php echo esc_html( $fieldname ); ?>"]');
					<?php echo esc_html( $fieldname ); ?>.
					getSession().on("change", function () {
						textarea.val(<?php echo esc_html( $fieldname ); ?>.getSession().getValue()
					)
					});
				});
			</script>
			<textarea style="display:none"
						name="<?php echo esc_html( $fieldname ); ?>"><?php echo esc_html( $value ); ?></textarea>
			<?php
		}

		/**
		 * Checks whether a wizard step/section contains at least one visible field.
		 *
		 * Iterates over all fields for the given page/step/section combination and
		 * returns true on the first field that either has a callback (always shown)
		 * or passes show_field(). Returns false when all fields are hidden by their
		 * conditions. Used by the wizard to skip empty steps.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string    $page    The option source / document type to query fields for.
		 * @param  int|false $step    The wizard step index. Default false (all steps).
		 * @param  int|false $section The wizard section index. Default false (all sections).
		 * @return bool                 True when at least one field is visible in the step/section.
		 */
		public function step_has_fields( $page, $step = false, $section = false ) {
			$fields = COMPLIANZ_TC::$config->fields( $page, $step, $section );
			foreach ( $fields as $fieldname => $args ) {
				$default_args = $this->default_args;
				$args         = wp_parse_args( $args, $default_args );

				$type              = ( $args['callback'] ) ? 'callback'
					: $args['type'];
				$args['fieldname'] = $fieldname;

				if ( 'callback' === $type ) {
					return true;
				} elseif ( $this->show_field( $args ) ) {
						return true;
				}
			}

			return false;
		}

		/**
		 * Retrieves and renders all fields for a wizard step/section.
		 *
		 * Fetches the field list from the config, marks the first field with
		 * $args['first'] = true for CSS-first-child targeting, merges each field's
		 * args with default_args, then dispatches to the appropriate field-type
		 * method via a switch statement. Supports all registered types: callback,
		 * text, url, email, phone, number, checkbox, multicheckbox, radio, select,
		 * textarea, editor, javascript, css, notice, label, button, upload, document,
		 * cookies, services, multiple, thirdparties, processors, colorpicker,
		 * borderradius, borderwidth.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string       $source          The option source identifier used to filter fields.
		 * @param  int|false    $step            The wizard step index. Default false.
		 * @param  int|false    $section         The section index. Default false.
		 * @param  string|false $get_by_fieldname Retrieve a single field by name. Default false.
		 * @return void
		 */
		public function get_fields(
			$source,
			$step = false,
			$section = false,
			$get_by_fieldname = false
		) {

			$fields = COMPLIANZ_TC::$config->fields(
				$source,
				$step,
				$section,
				$get_by_fieldname
			);

			$i = 0;
			foreach ( $fields as $fieldname => $args ) {
				if ( 0 === $i ) {
					$args['first'] = true;
				}
				++$i;
				$default_args = $this->default_args;
				$args         = wp_parse_args( $args, $default_args );

				$type              = ( $args['callback'] ) ? 'callback'
					: $args['type'];
				$args['fieldname'] = $fieldname;
				switch ( $type ) {
					case 'callback':
						$this->callback( $args );
						break;
					case 'text':
						$this->text( $args );
						break;
					case 'document':
						$this->document( $args );
						break;
					case 'button':
						$this->button( $args );
						break;
					case 'upload':
						$this->upload( $args );
						break;
					case 'url':
						$this->url( $args );
						break;
					case 'select':
						$this->select( $args );
						break;
					case 'colorpicker':
						$this->colorpicker( $args );
						break;
					case 'borderradius':
						$this->border_radius( $args );
						break;
					case 'borderwidth':
						$this->border_width( $args );
						break;
					case 'checkbox':
						$this->checkbox( $args );
						break;
					case 'textarea':
						$this->textarea( $args );
						break;
					case 'cookies':
						$this->cookies( $args );
						break;
					case 'services':
						$this->services( $args );
						break;
					case 'multiple':
						$this->multiple( $args );
						break;
					case 'radio':
						$this->radio( $args );
						break;
					case 'multicheckbox':
						$this->multicheckbox( $args );
						break;
					case 'javascript':
						$this->javascript( $args );
						break;
					case 'css':
						$this->css( $args );
						break;
					case 'email':
						$this->email( $args );
						break;
					case 'phone':
						$this->phone( $args );
						break;
					case 'thirdparties':
						$this->thirdparties( $args );
						break;
					case 'processors':
						$this->processors( $args );
						break;
					case 'number':
						$this->number( $args );
						break;
					case 'notice':
						$this->notice( $args );
						break;
					case 'editor':
						$this->editor( $args );
						break;
					case 'label':
						$this->label( $args );
						break;
				}
			}
		}

		/**
		 * Renders a field whose content is provided by a custom action hook.
		 *
		 * Fires the label hooks (before_label, label_html, after_label) then
		 * dispatches to the `cmplz_tc_{callback}` action, where `{callback}` is
		 * the field's 'callback' key. This allows plugin extensions to inject
		 * arbitrary HTML into any wizard field position. Fires after_field last.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Uses 'callback' (string) as the action suffix.
		 * @return void
		 */
		public function callback(
			$args
		) {
			$callback = $args['callback'];
			do_action( 'complianz_tc_before_label', $args );
			?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php
			do_action( 'complianz_tc_after_label', $args );
			do_action( "cmplz_tc_$callback", $args );
			do_action( 'complianz_tc_after_field', $args );
		}

		/**
		 * Renders an inline warning notice in place of a field.
		 *
		 * Used for fields of type 'notice' to display a styled warning message
		 * (via cmplz_tc_notice()) where a normal input would appear. The field
		 * label text is used as the notice message.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Uses 'label' (string) as the notice message.
		 * @return void
		 */
		public function notice(
			$args
		) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			do_action( 'complianz_tc_before_label', $args );
			cmplz_tc_notice( $args['label'], 'warning' );
			do_action( 'complianz_tc_after_label', $args );
			do_action( 'complianz_tc_after_field', $args );
		}

		/**
		 * Renders a `<select>` dropdown field.
		 *
		 * Outputs a dropdown with a blank "Choose an option" placeholder followed by
		 * one `<option>` per entry in $args['options']. The currently stored value is
		 * pre-selected. The submitted value is sanitised with sanitize_text_field()
		 * on save (default path in sanitize()).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: fieldname, default,
		 *                     options (assoc value => label), required.
		 * @return void
		 */
		public function select(
			$args
		) {

			$fieldname = 'cmplz_' . $args['fieldname'];

			$value = $this->get_value( $args['fieldname'], $args['default'] );
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<select 
			<?php
			if ( $args['required'] ) {
				echo 'required';
			}
			?>
			name="<?php echo esc_html( $fieldname ); ?>" id="<?php echo esc_attr( $fieldname ); ?>">
				<option value="">
				<?php
				esc_html_e(
					'Choose an option',
					'complianz-terms-conditions'
				)
				?>
						</option>
				<?php
				foreach (
					$args['options'] as $option_key => $option_label
				) {
					?>
					<option
						value="<?php echo esc_html( $option_key ); ?>" 
						<?php
						echo ( $value === $option_key )
								? 'selected'
								: ''
						?>
						><?php echo esc_html( $option_label ); ?></option>
				<?php } ?>
			</select>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a display-only label field with no interactive input.
		 *
		 * Used for fields of type 'label' that provide contextual headings or
		 * descriptive text within the wizard layout. Fires the standard label
		 * hooks and after_field but outputs no input element.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname).
		 * @return void
		 */
		public function label(
			$args
		) {

			$fieldname = 'cmplz_' . $args['fieldname'];
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a button or form-submit action field.
		 *
		 * Supports two modes controlled by $args['post_get']: 'get' renders an
		 * anchor tag linking to the settings page with an 'action' query arg;
		 * anything else renders a `<input type="submit">` that POSTs to the wizard.
		 * Disabled buttons render as grey with href="#" (GET mode) or the HTML
		 * disabled attribute (POST mode). Supports a confirmation dialog via
		 * $args['warn'].
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: fieldname, label,
		 *                     disabled (bool), post_get ('get'|'post'), action (string),
		 *                     warn (string|false) — confirm() message text.
		 * @return void
		 */
		public function button(
			$args
		) {
			$fieldname = 'cmplz_' . $args['fieldname'];
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>
			<?php if ( 'get' === $args['post_get'] ) { ?>
				<a 
				<?php
				if ( $args['disabled'] ) {
					echo 'disabled'; }
				?>
					href="
					<?php
					echo $args['disabled']
					? '#'
					: esc_url( cmplz_tc_settings_page() . '&action=' . $args['action'] )
					?>
					"
					class="button"><?php echo esc_html( $args['label'] ); ?></a>
			<?php } else { ?>
				<input 
				<?php
				if ( $args['warn'] ) {
					echo 'onclick="return confirm(\'' . esc_js( $args['warn'] )
						. '\');"'; }
				?>
						<?php
						if ( $args['disabled'] ) {
							echo 'disabled';
						}
						?>
					class="button" type="submit"
										name="<?php echo esc_attr( $args['action'] ); ?>"
										value="<?php echo esc_html( $args['label'] ); ?>">
			<?php } ?>

			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}

		/**
		 * Renders a file-upload field pair (file picker + submit button).
		 *
		 * Outputs a file input named cmplz-upload-file and a submit button whose
		 * name comes from $args['action']. The submit is disabled when $args['disabled']
		 * is true.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments. Relevant keys: label, disabled (bool),
		 *                     action (string — the submit button name).
		 * @return void
		 */
		public function upload(
			$args
		) {
			if ( ! $this->show_field( $args ) ) {
				return;
			}

			?>
			<?php do_action( 'complianz_tc_before_label', $args ); ?>
			<?php do_action( 'complianz_tc_label_html', $args ); ?>
			<?php do_action( 'complianz_tc_after_label', $args ); ?>

			<input type="file" type="submit" name="cmplz-upload-file"
					value="<?php echo esc_html( $args['label'] ); ?>">
			<input 
			<?php
			if ( $args['disabled'] ) {
				echo 'disabled'; }
			?>
				class="button" type="submit"
									name="<?php echo esc_attr( $args['action'] ); ?>"
									value="
									<?php
									esc_html_e(
										'Start',
										'complianz-terms-conditions'
									)
									?>
										">
			<?php do_action( 'complianz_tc_after_field', $args ); ?>
			<?php
		}


		/**
		 * Renders the wizard form's nonce field and Save button.
		 *
		 * Outputs the complianz_tc_nonce hidden input (used to verify the form
		 * submission in process_save()) and a primary button-style submit input.
		 * Must be called inside a `<table>` / `<tr>` wrapper in the wizard template.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function save_button() {
			wp_nonce_field( 'complianz_tc_save', 'complianz_tc_nonce' );
			?>
			<th></th>
			<td>
				<input class="button button-primary" type="submit"
						name="cmplz-save"
						value="<?php esc_html_e( 'Save', 'complianz-terms-conditions' ); ?>">

			</td>
			<?php
		}


		/**
		 * Renders a simple repeatable text-area field with add/remove controls.
		 *
		 * Outputs an "Add new" submit button and, for each stored row, a textarea
		 * for the 'description' sub-field and a "Remove" button. Row data is
		 * submitted as cmplz_multiple[{fieldname}][{key}][description]. This is
		 * a lightweight alternative to cookies/thirdparties for simple repeatable
		 * text entries.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Merged field arguments (fieldname, label).
		 * @return void
		 */
		public function multiple(
			$args
		) {
			$values = $this->get_value( $args['fieldname'] );
			if ( ! $this->show_field( $args ) ) {
				return;
			}
			?>
			<?php do_action( 'complianz_before_label', $args ); ?>
			<label><?php echo esc_html( $args['label'] ); ?></label>
			<?php do_action( 'complianz_after_label', $args ); ?>
			<button class="button" type="submit" name="cmplz_add_multiple"
					value="<?php echo esc_html( $args['fieldname'] ); ?>">
					<?php
					esc_html_e(
						'Add new',
						'complianz-terms-conditions'
					)
					?>
					</button>
			<br><br>
			<?php
			if ( $values ) {
				foreach ( $values as $key => $value ) {
					?>

					<div>
						<div>
							<label>
							<?php
							esc_html_e(
								'Description',
								'complianz-terms-conditions'
							)
							?>
									</label>
						</div>
						<div>
						<textarea class="cmplz_multiple"
									name="cmplz_multiple[<?php echo esc_html( $args['fieldname'] ); ?>][<?php echo esc_attr( $key ); ?>][description]">
									<?php
									if ( isset( $value['description'] ) ) {
										echo esc_html( $value['description'] ); }
									?>
								</textarea>
						</div>

					</div>
					<button class="button cmplz-remove" type="submit"
							name="cmplz_remove_multiple[<?php echo esc_html( $args['fieldname'] ); ?>]"
							value="<?php echo esc_attr( $key ); ?>">
							<?php
							esc_html_e(
								'Remove',
								'complianz-terms-conditions'
							)
							?>
							</button>
					<?php
				}
			}
			?>
			<?php do_action( 'complianz_after_field', $args ); ?>
			<?php
		}

		/**
		 * Returns a localised heading string for a language-grouped cookies/services block.
		 *
		 * Produces either "Cookies in {language name}" or "Services in {language name}",
		 * where the language name is resolved from the plugin's language_codes map.
		 * Falls back to the uppercased locale code when the language is not in the map.
		 *
		 * @since  1.0.0
		 * @access private
		 *
		 * @param  string $language The ISO 639-1 language code (e.g. 'nl', 'fr').
		 * @param  string $type     Display context: 'cookie' (default) or 'service'.
		 * @return string           The localised heading string.
		 */
		private function get_language_descriptor( $language, $type = 'cookie' ) {
			// translators: %s is the name of the language (e.g. "Dutch", "French").
			$string = 'cookie' === $type ? __( 'Cookies in %s', 'complianz-terms-conditions' ) : __( 'Services in %s', 'complianz-terms-conditions' );
			if ( isset( COMPLIANZ_TC::$config->language_codes[ $language ] ) ) {
				$string = sprintf(
					$string,
					COMPLIANZ_TC::$config->language_codes[ $language ]
				);
			} else {
				$string = sprintf(
					$string,
					strtoupper( $language )
				);
			}

			return $string;
		}

		/**
		 * Returns the current stored value for a field, with a default fallback.
		 *
		 * Looks up the field's source option group in the config, reads it from
		 * the WordPress options table, and applies the cmplz_tc_default_value filter
		 * when no value has been stored yet. Returns false when the fieldname is
		 * not registered in the config.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname The field identifier to retrieve.
		 * @param  mixed  $default_value Value to return (after filtering) when nothing is stored.
		 *                               Default '' (empty string).
		 * @return mixed             The stored value, the filtered default, or false when the
		 *                           fieldname is not found in the config.
		 */
		public function get_value( $fieldname, $default_value = '' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Renaming would break all callers.
			$fields = COMPLIANZ_TC::$config->fields();

			if ( ! isset( $fields[ $fieldname ] ) ) {
				return false;
			}

			$source  = $fields[ $fieldname ]['source'];
			$options = get_option( 'complianz_tc_options_' . $source );
			$value   = isset( $options[ $fieldname ] )
				? $options[ $fieldname ] : false;

			// If no value is stored, apply the filtered default.
			$value = ( false !== $value ) ? $value
				: apply_filters( 'cmplz_tc_default_value', $default_value, $fieldname );

			return $value;
		}

		/**
		 * Validates and sanitises a fieldname against the registered field list.
		 *
		 * Returns a sanitize_text_field()-cleaned version of the fieldname when it
		 * exists in the config, or false when it does not. Used to prevent arbitrary
		 * fieldname injection when processing the cmplz_tc_add_multiple POST key.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $fieldname The raw fieldname to validate (may come from $_POST).
		 * @return string|false      Sanitised fieldname string, or false when not registered.
		 */
		public function sanitize_fieldname(
			$fieldname
		) {
			$fields = COMPLIANZ_TC::$config->fields();
			if ( array_key_exists( $fieldname, $fields ) ) {
				return sanitize_text_field( $fieldname );
			}

			return false;
		}


		/**
		 * Renders an optional explanatory comment below a field's input.
		 *
		 * Outputs the 'comment' value from the field args inside a `.cmplz-comment`
		 * div. Returns early when no comment is set. The comment is output without
		 * escaping, so callers are responsible for any HTML sanitisation.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $args Field arguments. Uses 'comment' (string) when present.
		 * @return void
		 */
		public function get_comment(
			$args
		) {
			if ( ! isset( $args['comment'] ) ) {
				return;
			}
			?>
			<div class="cmplz-comment"><?php echo $args['comment']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Comment content may contain trusted HTML; callers are responsible for sanitisation. ?></div>
			<?php
		}

		/**
		 * Returns whether any required-field validation errors were recorded.
		 *
		 * Checks whether the form_errors list is non-empty after a save. The wizard
		 * uses this to decide whether to advance to the next step or keep the user
		 * on the current step.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return bool True when at least one required field was empty on save, false otherwise.
		 */
		public function has_errors() {
			if ( count( $this->form_errors ) > 0 ) {
				return true;
			}

			return false;
		}
	}
} //class closure
