<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Configuration class for the Complianz Terms & Conditions plugin.
 *
 * Defines and initialises `cmplz_tc_config`, the central singleton that holds all
 * runtime configuration for the plugin: wizard fields, steps, document definitions,
 * country/region data, and supported site languages. Config sub-files are loaded on
 * the `init` hook so that translations are available before any strings are resolved.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Config
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Prevent direct file access outside of the WordPress bootstrap.
defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_config' ) ) {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.

	/**
	 * Central configuration singleton for the Terms & Conditions plugin.
	 *
	 * Holds all wizard fields, step definitions, document page configurations,
	 * country/region data, and language maps. A single instance is created at
	 * plugin boot and accessed globally via `cmplz_tc_config::this()` or the
	 * `CMPLZ_TC` constant. Attempting to instantiate a second copy causes a
	 * fatal `wp_die()`.
	 *
	 * @package    Complianz_Terms_Conditions
	 * @subpackage Config
	 *
	 * @since      1.0.0
	 */
	class cmplz_tc_config {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.

		/**
		 * Singleton instance reference.
		 *
		 * Holds the one permitted instance of this class. Set on first construction
		 * and used to enforce the singleton invariant in subsequent construction attempts.
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_config|null
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * All registered wizard fields, keyed by field name.
		 *
		 * Populated by the config sub-files (questions-wizard.php) and further
		 * modified by the `cmplz_fields_load_types` and `cmplz_fields` filters.
		 * Each value is an associative array describing the field's type, step,
		 * section, source, and other meta-data consumed by `cmplz_tc_field`.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array[]  Associative array of field name → field definition.
		 */
		public $fields = array();

		/**
		 * Section definitions for the wizard, if used separately from steps.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array|null
		 */
		public $sections;

		/**
		 * Document page configuration per region and document type.
		 *
		 * Populated by the documents config sub-files. Keyed first by region slug
		 * (e.g. `'eu'`, `'us'`) and then by document type (e.g. `'terms-conditions'`).
		 * Each leaf contains at minimum a `document_elements` key that is further
		 * filtered by `cmplz_document_elements` on `init`.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array[]
		 */
		public $pages;

		/**
		 * Warning type definitions used in the wizard notice system.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array|null
		 */
		public $warning_types;

		/**
		 * Translated yes/no option pair used by radio and select fields.
		 *
		 * Structure: `array( 'yes' => 'Yes', 'no' => 'No' )`.
		 * Populated in `load_config()` after translations are available.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[]
		 */
		public $yes_no;

		/**
		 * Full world-country list keyed by ISO 3166-1 alpha-2 code.
		 *
		 * Populated by `config/countries.php`. Maps country codes to their
		 * translated display names for use in wizard drop-downs.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[]
		 */
		public $countries;

		/**
		 * Privacy-law region definitions keyed by region slug.
		 *
		 * Populated by `config/countries.php`. Each value contains `label`,
		 * `countries`, `law`, and `type` keys.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array[]
		 */
		public $regions;

		/**
		 * ISO 3166-1 alpha-2 codes for EU/EEA member states.
		 *
		 * Populated by `config/countries.php`. Used to build the `eu` region
		 * definition and for GDPR jurisdiction checks throughout the plugin.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[]
		 */
		public $eu_countries;

		/**
		 * Supported site languages mapped to their human-readable names.
		 *
		 * Populated by `get_supported_languages()` during `load_config()`. Reflects
		 * the active WordPress locale plus any WPML or TranslatePress languages.
		 * Structure: `array( 'en' => 'English', 'nl' => 'Dutch', ... )`.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[]
		 */
		public $languages;

		/**
		 * Subset of ISO 639-1 language codes used in the wizard language selector.
		 *
		 * Populated by `config/countries.php`. Limited to major languages to keep
		 * the number of translatable strings manageable.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    string[]
		 */
		public $language_codes;

		/**
		 * Wizard step and section definitions per document type.
		 *
		 * Populated by `config/steps.php` and passed through the `cmplz_tc_steps`
		 * filter. Keyed by document type (e.g. `'terms-conditions'`), then by
		 * 1-based step index.
		 *
		 * @since  1.0.0
		 * @access public
		 * @var    array[]
		 */
		public $steps;

		/**
		 * Initialises the singleton and registers three `init` hook callbacks.
		 *
		 * Enforces a single-instance constraint by storing a reference in the static
		 * `$_this` property and calling `wp_die()` if a second instantiation is
		 * attempted. Config loading, field pre-filtering, and document-element
		 * filtering are deferred to `init` priorities 2, 3, and 4 respectively so
		 * that translations are available and add-ons have had time to register their
		 * own `init` hooks before the config is finalised.
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

			// Priority 2: load all config sub-files after translations are ready.
			add_action( 'init', array( $this, 'load_config' ), 2 );

			/**
			 * Preload fields with a filter, to allow for overriding types
			 */
			// Priority 3: allow add-ons to override field types before full init.
			add_action( 'init', array( $this, 'preload_init' ), 3 );

			/**
			 * The integrations are loaded with priority 10
			 * Because we want to initialize after that, we use 15 here
			 */
			// Priority 4: finalise field list and apply document-element filters.
			add_action( 'init', array( $this, 'init' ), 4 );
		}

		/**
		 * Loads all configuration sub-files on the `init` hook (priority 2).
		 *
		 * Builds the `$yes_no` option pair, resolves the list of supported site
		 * languages, and then includes the five configuration files that populate
		 * the remaining properties (`$countries`, `$regions`, `$eu_countries`,
		 * `$steps`, `$fields`, and `$pages`). Deferred to `init` so that
		 * WordPress translation functions are available.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_config::get_supported_languages()
		 */
		public function load_config() {
			// Common yes/no option pair used by radio and select field types.
			$this->yes_no = array(
				'yes' => __( 'Yes', 'complianz-terms-conditions' ),
				'no'  => __( 'No', 'complianz-terms-conditions' ),
			);

			$this->languages = $this->get_supported_languages();

			// Include config sub-files that populate the remaining properties.
			require_once cmplz_tc_path . '/config/countries.php';
			require_once cmplz_tc_path . '/config/steps.php';
			require_once cmplz_tc_path . '/config/questions-wizard.php';
			require_once cmplz_tc_path . '/config/documents/documents.php';
			require_once cmplz_tc_path . '/config/documents/terms-conditions.php';
		}

		/**
		 * Returns the singleton instance of this class.
		 *
		 * Provides global access to the one permitted `cmplz_tc_config` object
		 * without relying on a global variable. Typically aliased via the
		 * `CMPLZ_TC` constant defined in the main plugin file.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_config The singleton instance.
		 */
		public static function this() {
			return self::$_this;
		}

		/**
		 * Returns the 1-based section index for a given section ID within any step.
		 *
		 * Iterates over all steps for the `terms-conditions` document type and
		 * searches the first step that contains sections for a section whose `id`
		 * key matches `$id`. The section arrays are 1-indexed in the config, so the
		 * result of `array_search()` (0-based) is incremented by one before returning.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $id  The section ID slug to search for (e.g. `'content'`).
		 * @return int|false     1-based section index if found; `false` if not found.
		 */
		public function get_section_by_id( $id ) {

			$steps = $this->steps['terms-conditions'];
			foreach ( $steps as $step ) {
				if ( ! isset( $step['sections'] ) ) {
					continue;
				}
				$sections = $step['sections'];

				// Step arrays start at index 1, so add 1 to the 0-based array_search result.
				return array_search( $id, array_column( $sections, 'id' ), true ) + 1;
			}
			return false;
		}

		/**
		 * Returns the 1-based step index for a given step ID.
		 *
		 * Searches the `terms-conditions` step array for a step whose `id` key matches
		 * `$id`. The step array is 1-indexed in the config, so the 0-based
		 * `array_search()` result is incremented by one.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $id  The step ID slug to search for (e.g. `'company'`).
		 * @return int|false     1-based step index if found; `false` if not found.
		 */
		public function get_step_by_id( $id ) {
			$steps = $this->steps['terms-conditions'];

			// Step arrays start at index 1, so add 1 to the 0-based array_search result.
			return array_search( $id, array_column( $steps, 'id' ), true ) + 1;
		}

		/**
		 * Returns a filtered subset of the registered wizard fields.
		 *
		 * Supports four optional filters that can be combined: `$page` limits fields
		 * by their `source` key, `$step` and `$section` limit by the field's `step`
		 * and `section` keys (a field's step may be an array when it appears in multiple
		 * steps), and `$get_by_fieldname` returns only the single named field. When
		 * no filters are supplied, all registered fields are returned.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_array_filter_multidimensional()
		 *
		 * @param  string|false $page             Document-type/source slug to filter by
		 *                                        (e.g. `'terms-conditions'`). Default `false`.
		 * @param  int|false    $step             1-based step number to filter by. Default `false`.
		 * @param  int|false    $section          1-based section number to filter by.
		 *                                        Only applied when `$step` is also set. Default `false`.
		 * @param  string|false $get_by_fieldname Exact field name to retrieve. Default `false`.
		 * @return array[]                        Associative array of field name → field definition,
		 *                                        possibly empty if no fields match.
		 */
		public function fields(
			$page = false,
			$step = false,
			$section = false,
			$get_by_fieldname = false
		) {

			$output = array();
			$fields = $this->fields;

			// Narrow the working set to fields belonging to the requested source/page.
			if ( $page ) {
				$fields = cmplz_tc_array_filter_multidimensional(
					$this->fields,
					'source',
					$page
				);
			}

			foreach ( $fields as $fieldname => $field ) {
				// Skip all fields except the one explicitly requested.
				if ( $get_by_fieldname && $fieldname !== $get_by_fieldname ) {
					continue;
				}

				if ( $step ) {
					if ( $section && isset( $field['section'] ) ) {
						// Match fields that belong to both the requested step and section.
						if ( ( $field['step'] === $step
								|| ( is_array( $field['step'] )
									&& in_array( $step, $field['step'], true ) ) )
							&& ( $field['section'] === $section )
						) {
							$output[ $fieldname ] = $field;
						}
					} elseif ( ( $field['step'] === $step )
							|| ( is_array( $field['step'] )
									&& in_array( $step, $field['step'], true ) )
						) {
						// Match fields that belong to the requested step (any section).
							$output[ $fieldname ] = $field;
					}
				}
				if ( ! $step ) {
					// No step filter — include every field that survived earlier filters.
					$output[ $fieldname ] = $field;
				}
			}

			return $output;
		}

		/**
		 * Checks whether a given wizard step contains child sections.
		 *
		 * Used by the wizard renderer to decide whether to show the section
		 * navigation sub-menu or to treat the step as a flat single page.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $page  Document-type slug (e.g. `'terms-conditions'`).
		 * @param  int    $step  1-based step index.
		 * @return bool          `true` when the step has a `sections` sub-array;
		 *                       `false` otherwise.
		 */
		public function has_sections( $page, $step ) {
			if ( isset( $this->steps[ $page ][ $step ]['sections'] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Applies the `cmplz_fields_load_types` filter on `init` (priority 3).
		 *
		 * Runs before `init()` (priority 4) so that add-ons can modify field type
		 * definitions — e.g. replacing a `'select'` with a `'radio'` — before the
		 * final `cmplz_fields` filter fires.
		 *
		 * @since  1.0.0
		 * @access public
		 */
		public function preload_init() {
			/**
			 * Filters the wizard fields before full initialisation.
			 *
			 * Add-ons hook here (priority < 3) to override field `type` values or
			 * inject entirely new fields before the final `cmplz_fields` pass.
			 *
			 * @since 1.0.0
			 *
			 * @param array[] $fields Registered wizard fields keyed by field name.
			 */
			$this->fields = apply_filters( 'cmplz_fields_load_types', $this->fields );
		}

		/**
		 * Finalises fields and applies document-element filters on `init` (priority 4).
		 *
		 * First passes `$this->fields` through the `cmplz_fields` filter so add-ons
		 * can add or remove fields. Then, on the front-end only, iterates every
		 * configured region and document type and applies `cmplz_document_elements`
		 * to each document's element list, allowing add-ons to inject or strip
		 * document sections per region and type.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_get_regions()
		 */
		public function init() {
			/**
			 * Filters the complete wizard field list after all add-ons have loaded.
			 *
			 * @since 1.0.0
			 *
			 * @param array[] $fields Registered wizard fields keyed by field name.
			 */
			$this->fields = apply_filters( 'cmplz_fields', $this->fields );

			// Document-element filters are only needed on the front-end.
			if ( ! is_admin() ) {
				$regions = cmplz_tc_get_regions();
				foreach ( $regions as $region => $label ) {
					if ( ! isset( $this->pages[ $region ] ) ) {
						continue;
					}

					foreach ( $this->pages[ $region ] as $type => $data ) {
						/**
						 * Filters the document element list for a given region and document type.
						 *
						 * @since 1.0.0
						 *
						 * @param array   $document_elements Ordered list of document element definitions.
						 * @param string  $region            Region slug, e.g. `'eu'` or `'us'`.
						 * @param string  $type              Document type slug, e.g. `'terms-conditions'`.
						 * @param array[] $fields            All registered wizard fields.
						 */
						$this->pages[ $region ][ $type ]['document_elements']
							= apply_filters(
								'cmplz_document_elements',
								$this->pages[ $region ][ $type ]['document_elements'],
								$region,
								$type,
								$this->fields()
							);
					}
				}
			}
		}

		/**
		 * Builds the list of languages active on the current site.
		 *
		 * Always includes the site's default locale. When WPML is active
		 * (`icl_register_string` exists) all WPML-enabled languages are merged in,
		 * handling both the legacy `language_code` index and the newer `code` index.
		 * When TranslatePress is active (`TRP_Translate_Press` class exists) its
		 * translation languages are read directly from the `trp_settings` option.
		 * Each code is normalised to its first two characters and mapped to its
		 * human-readable name via `format_code_lang()`.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_config::format_code_lang()
		 * @see    cmplz_tc_sanitize_language()
		 *
		 * @param  bool $count  When `true`, return the count of active languages
		 *                      instead of the full array. Default `false`.
		 * @return int|array          Integer count when `$count` is `true`; otherwise
		 *                            an associative array of language code → language name.
		 */
		public function get_supported_languages( $count = false ) {
			$site_locale = cmplz_tc_sanitize_language( get_locale() );

			// Seed with the site's default locale.
			$languages = array( $site_locale => $site_locale );

			if ( function_exists( 'icl_register_string' ) ) {
				$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
				/**
				 * WPML has changed the index from 'language_code' to 'code' so
				 * we check for both.
				 */
				$wpml_test_index = reset( $wpml );
				if ( isset( $wpml_test_index['language_code'] ) ) {
					$wpml = wp_list_pluck( $wpml, 'language_code' );
				} elseif ( isset( $wpml_test_index['code'] ) ) {
					$wpml = wp_list_pluck( $wpml, 'code' );
				} else {
					$wpml = array();
				}
				$languages = array_merge( $wpml, $languages );
			}

			/**
			 * TranslatePress support
			 * There does not seem to be an easy accessible API to get the languages, so we retrieve from the settings directly
			 */

			if ( class_exists( 'TRP_Translate_Press' ) ) {
				$trp_settings = get_option( 'trp_settings', array() );
				if ( isset( $trp_settings['translation-languages'] ) ) {
					$trp_languages = $trp_settings['translation-languages'];
					foreach ( $trp_languages as $language_code ) {
						// Normalise locale codes like 'en_US' to the two-letter prefix 'en'.
						$key               = substr( $language_code, 0, 2 );
						$languages[ $key ] = $key;
					}
				}
			}

			if ( $count ) {
				return count( $languages );
			}

			// Map each two-letter code to its human-readable name.
			$languages = array_map( array( $this, 'format_code_lang' ), $languages );
			return $languages;
		}


		/**
		 * Returns the language name for a given ISO 639-1 language code.
		 *
		 * Normalises `$code` to its first two lowercase characters, then looks it up
		 * in a comprehensive translated map of ISO 639-1 codes. If the code is not
		 * found in the map, `strtr()` returns the original (normalised) code unchanged.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $code  Two-letter ISO 639-1 language code, e.g. `'en'` or `'nl'`.
		 *                       Longer strings (e.g. locale codes like `'en_US'`) are
		 *                       automatically truncated to two characters. Default empty string.
		 * @return string        Translated language name (e.g. `'English'`), or the
		 *                       first two characters of `$code` if no match is found.
		 */
		public function format_code_lang( $code = '' ) {
			$code       = strtolower( substr( $code, 0, 2 ) );
			$lang_codes = array(
				'aa' => __( 'Afar', 'complianz-terms-conditions' ),
				'ab' => __( 'Abkhazian', 'complianz-terms-conditions' ),
				'af' => __( 'Afrikaans', 'complianz-terms-conditions' ),
				'ak' => __( 'Akan', 'complianz-terms-conditions' ),
				'sq' => __( 'Albanian', 'complianz-terms-conditions' ),
				'am' => __( 'Amharic', 'complianz-terms-conditions' ),
				'ar' => __( 'Arabic', 'complianz-terms-conditions' ),
				'an' => __( 'Aragonese', 'complianz-terms-conditions' ),
				'hy' => __( 'Armenian', 'complianz-terms-conditions' ),
				'as' => __( 'Assamese', 'complianz-terms-conditions' ),
				'av' => __( 'Avaric', 'complianz-terms-conditions' ),
				'ae' => __( 'Avestan', 'complianz-terms-conditions' ),
				'ay' => __( 'Aymara', 'complianz-terms-conditions' ),
				'az' => __( 'Azerbaijani', 'complianz-terms-conditions' ),
				'ba' => __( 'Bashkir', 'complianz-terms-conditions' ),
				'bm' => __( 'Bambara', 'complianz-terms-conditions' ),
				'eu' => __( 'Basque', 'complianz-terms-conditions' ),
				'be' => __( 'Belarusian', 'complianz-terms-conditions' ),
				'bn' => __( 'Bengali', 'complianz-terms-conditions' ),
				'bh' => __( 'Bihari', 'complianz-terms-conditions' ),
				'bi' => __( 'Bislama', 'complianz-terms-conditions' ),
				'bs' => __( 'Bosnian', 'complianz-terms-conditions' ),
				'br' => __( 'Breton', 'complianz-terms-conditions' ),
				'bg' => __( 'Bulgarian', 'complianz-terms-conditions' ),
				'my' => __( 'Burmese', 'complianz-terms-conditions' ),
				'ca' => __( 'Catalan; Valencian', 'complianz-terms-conditions' ),
				'ch' => __( 'Chamorro', 'complianz-terms-conditions' ),
				'ce' => __( 'Chechen', 'complianz-terms-conditions' ),
				'zh' => __( 'Chinese', 'complianz-terms-conditions' ),
				'cu' => __( 'Church Slavic; Old Slavonic; Church Slavonic; Old Bulgarian; Old Church Slavonic', 'complianz-terms-conditions' ),
				'cv' => __( 'Chuvash', 'complianz-terms-conditions' ),
				'kw' => __( 'Cornish', 'complianz-terms-conditions' ),
				'co' => __( 'Corsican', 'complianz-terms-conditions' ),
				'cr' => __( 'Cree', 'complianz-terms-conditions' ),
				'cs' => __( 'Czech', 'complianz-terms-conditions' ),
				'da' => __( 'Danish', 'complianz-terms-conditions' ),
				'dv' => __( 'Divehi; Dhivehi; Maldivian', 'complianz-terms-conditions' ),
				'nl' => __( 'Dutch', 'complianz-terms-conditions' ),
				'dz' => __( 'Dzongkha', 'complianz-terms-conditions' ),
				'en' => __( 'English', 'complianz-terms-conditions' ),
				'eo' => __( 'Esperanto', 'complianz-terms-conditions' ),
				'et' => __( 'Estonian', 'complianz-terms-conditions' ),
				'ee' => __( 'Ewe', 'complianz-terms-conditions' ),
				'fo' => __( 'Faroese', 'complianz-terms-conditions' ),
				'fj' => __( 'Fijjian', 'complianz-terms-conditions' ),
				'fi' => __( 'Finnish', 'complianz-terms-conditions' ),
				'fr' => __( 'French', 'complianz-terms-conditions' ),
				'fy' => __( 'Western Frisian', 'complianz-terms-conditions' ),
				'ff' => __( 'Fulah', 'complianz-terms-conditions' ),
				'ka' => __( 'Georgian', 'complianz-terms-conditions' ),
				'de' => __( 'German', 'complianz-terms-conditions' ),
				'gd' => __( 'Gaelic; Scottish Gaelic', 'complianz-terms-conditions' ),
				'ga' => __( 'Irish', 'complianz-terms-conditions' ),
				'gl' => __( 'Galician', 'complianz-terms-conditions' ),
				'gv' => __( 'Manx', 'complianz-terms-conditions' ),
				'el' => __( 'Greek, Modern', 'complianz-terms-conditions' ),
				'gn' => __( 'Guarani', 'complianz-terms-conditions' ),
				'gu' => __( 'Gujarati', 'complianz-terms-conditions' ),
				'ht' => __( 'Haitian; Haitian Creole', 'complianz-terms-conditions' ),
				'ha' => __( 'Hausa', 'complianz-terms-conditions' ),
				'he' => __( 'Hebrew', 'complianz-terms-conditions' ),
				'hz' => __( 'Herero', 'complianz-terms-conditions' ),
				'hi' => __( 'Hindi', 'complianz-terms-conditions' ),
				'ho' => __( 'Hiri Motu', 'complianz-terms-conditions' ),
				'hu' => __( 'Hungarian', 'complianz-terms-conditions' ),
				'ig' => __( 'Igbo', 'complianz-terms-conditions' ),
				'is' => __( 'Icelandic', 'complianz-terms-conditions' ),
				'io' => __( 'Ido', 'complianz-terms-conditions' ),
				'ii' => __( 'Sichuan Yi', 'complianz-terms-conditions' ),
				'iu' => __( 'Inuktitut', 'complianz-terms-conditions' ),
				'ie' => __( 'Interlingue', 'complianz-terms-conditions' ),
				'ia' => __( 'Interlingua (International Auxiliary Language Association)', 'complianz-terms-conditions' ),
				'id' => __( 'Indonesian', 'complianz-terms-conditions' ),
				'ik' => __( 'Inupiaq', 'complianz-terms-conditions' ),
				'it' => __( 'Italian', 'complianz-terms-conditions' ),
				'jv' => __( 'Javanese', 'complianz-terms-conditions' ),
				'ja' => __( 'Japanese', 'complianz-terms-conditions' ),
				'kl' => __( 'Kalaallisut; Greenlandic', 'complianz-terms-conditions' ),
				'kn' => __( 'Kannada', 'complianz-terms-conditions' ),
				'ks' => __( 'Kashmiri', 'complianz-terms-conditions' ),
				'kr' => __( 'Kanuri', 'complianz-terms-conditions' ),
				'kk' => __( 'Kazakh', 'complianz-terms-conditions' ),
				'km' => __( 'Central Khmer', 'complianz-terms-conditions' ),
				'ki' => __( 'Kikuyu; Gikuyu', 'complianz-terms-conditions' ),
				'rw' => __( 'Kinyarwanda', 'complianz-terms-conditions' ),
				'ky' => __( 'Kirghiz; Kyrgyz', 'complianz-terms-conditions' ),
				'kv' => __( 'Komi', 'complianz-terms-conditions' ),
				'kg' => __( 'Kongo', 'complianz-terms-conditions' ),
				'ko' => __( 'Korean', 'complianz-terms-conditions' ),
				'kj' => __( 'Kuanyama; Kwanyama', 'complianz-terms-conditions' ),
				'ku' => __( 'Kurdish', 'complianz-terms-conditions' ),
				'lo' => __( 'Lao', 'complianz-terms-conditions' ),
				'la' => __( 'Latin', 'complianz-terms-conditions' ),
				'lv' => __( 'Latvian', 'complianz-terms-conditions' ),
				'li' => __( 'Limburgan; Limburger; Limburgish', 'complianz-terms-conditions' ),
				'ln' => __( 'Lingala', 'complianz-terms-conditions' ),
				'lt' => __( 'Lithuanian', 'complianz-terms-conditions' ),
				'lb' => __( 'Luxembourgish; Letzeburgesch', 'complianz-terms-conditions' ),
				'lu' => __( 'Luba-Katanga', 'complianz-terms-conditions' ),
				'lg' => __( 'Ganda', 'complianz-terms-conditions' ),
				'mk' => __( 'Macedonian', 'complianz-terms-conditions' ),
				'mh' => __( 'Marshallese', 'complianz-terms-conditions' ),
				'ml' => __( 'Malayalam', 'complianz-terms-conditions' ),
				'mi' => __( 'Maori', 'complianz-terms-conditions' ),
				'mr' => __( 'Marathi', 'complianz-terms-conditions' ),
				'ms' => __( 'Malay', 'complianz-terms-conditions' ),
				'mg' => __( 'Malagasy', 'complianz-terms-conditions' ),
				'mt' => __( 'Maltese', 'complianz-terms-conditions' ),
				'mo' => __( 'Moldavian', 'complianz-terms-conditions' ),
				'mn' => __( 'Mongolian', 'complianz-terms-conditions' ),
				'na' => __( 'Nauru', 'complianz-terms-conditions' ),
				'nv' => __( 'Navajo; Navaho', 'complianz-terms-conditions' ),
				'nr' => __( 'Ndebele, South; South Ndebele', 'complianz-terms-conditions' ),
				'nd' => __( 'Ndebele, North; North Ndebele', 'complianz-terms-conditions' ),
				'ng' => __( 'Ndonga', 'complianz-terms-conditions' ),
				'ne' => __( 'Nepali', 'complianz-terms-conditions' ),
				'nn' => __( 'Norwegian Nynorsk; Nynorsk, Norwegian', 'complianz-terms-conditions' ),
				'nb' => __( 'Bokmål, Norwegian, Norwegian Bokmål', 'complianz-terms-conditions' ),
				'no' => __( 'Norwegian', 'complianz-terms-conditions' ),
				'ny' => __( 'Chichewa; Chewa; Nyanja', 'complianz-terms-conditions' ),
				'oc' => __( 'Occitan, Provençal', 'complianz-terms-conditions' ),
				'oj' => __( 'Ojibwa', 'complianz-terms-conditions' ),
				'or' => __( 'Oriya', 'complianz-terms-conditions' ),
				'om' => __( 'Oromo', 'complianz-terms-conditions' ),
				'os' => __( 'Ossetian; Ossetic', 'complianz-terms-conditions' ),
				'pa' => __( 'Panjabi; Punjabi', 'complianz-terms-conditions' ),
				'fa' => __( 'Persian', 'complianz-terms-conditions' ),
				'pi' => __( 'Pali', 'complianz-terms-conditions' ),
				'pl' => __( 'Polish', 'complianz-terms-conditions' ),
				'pt' => __( 'Portuguese', 'complianz-terms-conditions' ),
				'ps' => __( 'Pushto', 'complianz-terms-conditions' ),
				'qu' => __( 'Quechua', 'complianz-terms-conditions' ),
				'rm' => __( 'Romansh', 'complianz-terms-conditions' ),
				'ro' => __( 'Romanian', 'complianz-terms-conditions' ),
				'rn' => __( 'Rundi', 'complianz-terms-conditions' ),
				'ru' => __( 'Russian', 'complianz-terms-conditions' ),
				'sg' => __( 'Sango', 'complianz-terms-conditions' ),
				'sa' => __( 'Sanskrit', 'complianz-terms-conditions' ),
				'sr' => __( 'Serbian', 'complianz-terms-conditions' ),
				'hr' => __( 'Croatian', 'complianz-terms-conditions' ),
				'si' => __( 'Sinhala; Sinhalese', 'complianz-terms-conditions' ),
				'sk' => __( 'Slovak', 'complianz-terms-conditions' ),
				'sl' => __( 'Slovenian', 'complianz-terms-conditions' ),
				'se' => __( 'Northern Sami', 'complianz-terms-conditions' ),
				'sm' => __( 'Samoan', 'complianz-terms-conditions' ),
				'sn' => __( 'Shona', 'complianz-terms-conditions' ),
				'sd' => __( 'Sindhi', 'complianz-terms-conditions' ),
				'so' => __( 'Somali', 'complianz-terms-conditions' ),
				'st' => __( 'Sotho, Southern', 'complianz-terms-conditions' ),
				'es' => __( 'Spanish; Castilian', 'complianz-terms-conditions' ),
				'sc' => __( 'Sardinian', 'complianz-terms-conditions' ),
				'ss' => __( 'Swati', 'complianz-terms-conditions' ),
				'su' => __( 'Sundanese', 'complianz-terms-conditions' ),
				'sw' => __( 'Swahili', 'complianz-terms-conditions' ),
				'sv' => __( 'Swedish', 'complianz-terms-conditions' ),
				'ty' => __( 'Tahitian', 'complianz-terms-conditions' ),
				'ta' => __( 'Tamil', 'complianz-terms-conditions' ),
				'tt' => __( 'Tatar', 'complianz-terms-conditions' ),
				'te' => __( 'Telugu', 'complianz-terms-conditions' ),
				'tg' => __( 'Tajik', 'complianz-terms-conditions' ),
				'tl' => __( 'Tagalog', 'complianz-terms-conditions' ),
				'th' => __( 'Thai', 'complianz-terms-conditions' ),
				'bo' => __( 'Tibetan', 'complianz-terms-conditions' ),
				'ti' => __( 'Tigrinya', 'complianz-terms-conditions' ),
				'to' => __( 'Tonga (Tonga Islands)', 'complianz-terms-conditions' ),
				'tn' => __( 'Tswana', 'complianz-terms-conditions' ),
				'ts' => __( 'Tsonga', 'complianz-terms-conditions' ),
				'tk' => __( 'Turkmen', 'complianz-terms-conditions' ),
				'tr' => __( 'Turkish', 'complianz-terms-conditions' ),
				'tw' => __( 'Twi', 'complianz-terms-conditions' ),
				'ug' => __( 'Uighur; Uyghur', 'complianz-terms-conditions' ),
				'uk' => __( 'Ukrainian', 'complianz-terms-conditions' ),
				'ur' => __( 'Urdu', 'complianz-terms-conditions' ),
				'uz' => __( 'Uzbek', 'complianz-terms-conditions' ),
				've' => __( 'Venda', 'complianz-terms-conditions' ),
				'vi' => __( 'Vietnamese', 'complianz-terms-conditions' ),
				'vo' => __( 'Volapük', 'complianz-terms-conditions' ),
				'cy' => __( 'Welsh', 'complianz-terms-conditions' ),
				'wa' => __( 'Walloon', 'complianz-terms-conditions' ),
				'wo' => __( 'Wolof', 'complianz-terms-conditions' ),
				'xh' => __( 'Xhosa', 'complianz-terms-conditions' ),
				'yi' => __( 'Yiddish', 'complianz-terms-conditions' ),
				'yo' => __( 'Yoruba', 'complianz-terms-conditions' ),
				'za' => __( 'Zhuang; Chuang', 'complianz-terms-conditions' ),
				'zu' => __( 'Zulu', 'complianz-terms-conditions' ),
			);

			return strtr( $code, $lang_codes );
		}
	}



} // end class cmplz_tc_config.
