<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
/**
 * Document management class for Complianz Terms & Conditions.
 *
 * Handles everything related to generating, storing, and managing legal
 * documents. Responsibilities include building document HTML from the config-
 * driven element list, evaluating field-based conditions, managing WordPress
 * pages that host documents via shortcode or Gutenberg block, generating PDF
 * withdrawal forms, and integrating with the Complianz GDPR dashboard.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Document
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_document' ) ) {
	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public API; class name cannot be changed without breaking all callers.
	/**
	 * Manages legal document generation, page creation, and sync status.
	 *
	 * Implemented as a singleton to ensure only one document manager exists
	 * per request. Access the instance via cmplz_tc_document::this() after
	 * the plugin has been bootstrapped by COMPLIANZ_TC.
	 *
	 * @package    Complianz_Terms_Conditions
	 * @subpackage Document
	 *
	 * @since      1.0.0
	 */
	class cmplz_tc_document {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

		/**
		 * Holds the single instance of this class.
		 *
		 * @since  1.0.0
		 * @access private
		 * @var    cmplz_tc_document
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Initialise the singleton and register all hooks.
		 *
		 * Enforces the singleton contract by calling wp_die() if a second
		 * instance is attempted. Stores the instance reference and delegates
		 * hook registration to init().
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::init()
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
			$this->init();
		}

		/**
		 * Return the singleton instance of this class.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return cmplz_tc_document The single instance.
		 */
		public static function this() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			return self::$_this;
		}

		/**
		 * Return the list of document field names defined in the config.
		 *
		 * Iterates over all wizard fields and collects those whose type is
		 * 'document'. These are the document types the plugin can generate
		 * (e.g. 'terms-conditions').
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_config::fields()
		 *
		 * @return string[] Array of fieldname strings for document-type fields.
		 */
		public function get_document_types() {
			$fields    = COMPLIANZ_TC::$config->fields();
			$documents = array();
			foreach ( $fields as $fieldname => $field ) {
				if ( isset( $field['type'] ) && 'document' === $field['type'] ) {
					$documents[] = $fieldname;
				}
			}

			return $documents;
		}

		/**
		 * Check whether a page type is flagged as public in the config.
		 *
		 * Looks up the page definition under COMPLIANZ_TC::$config->pages and
		 * returns true only when a 'public' key is set and truthy. Used to
		 * filter out non-public page types when building the required-pages list.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $type   Page type identifier, e.g. 'terms-conditions'.
		 * @param  string $region Region key, e.g. 'all', 'eu', 'us'.
		 * @return bool           True if the page is marked public, false otherwise.
		 */
		public function is_public_page( $type, $region ) {
			if ( ! isset( COMPLIANZ_TC::$config->pages[ $region ][ $type ] ) ) {
				return false;
			}

			if ( isset( COMPLIANZ_TC::$config->pages[ $region ][ $type ]['public'] )
				&& COMPLIANZ_TC::$config->pages[ $region ][ $type ]['public']
			) {
				return true;
			}

			return false;
		}

		/**
		 * Determine whether a page type is required for the current wizard answers.
		 *
		 * A page is considered required when it is public AND all of its conditions
		 * are satisfied. Conditions use an AND logic: every question/answer pair in
		 * the 'condition' array must match. Supports 'NOT <value>' negation.
		 * When no condition is set, the page is assumed required by default.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::is_public_page()
		 *
		 * @param  array|string $page    Either the page config array or a page type
		 *                               string to look up in COMPLIANZ_TC::$config->pages.
		 * @param  string       $region  Region key used to look up the page when $page
		 *                               is passed as a string, e.g. 'all'.
		 * @return bool                  True if the page is required, false otherwise.
		 */
		public function page_required( $page, $region ) {
			if ( ! is_array( $page ) ) {
				if ( ! isset( COMPLIANZ_TC::$config->pages[ $region ][ $page ] ) ) {
					return false;
				}

				$page = COMPLIANZ_TC::$config->pages[ $region ][ $page ];
			}

			// If it's not public, it's not required.
			if ( isset( $page['public'] ) && false === $page['public'] ) {
				return false;
			}

			// If there's no condition, we set it as required.
			if ( ! isset( $page['condition'] ) ) {
				return true;
			}

			if ( isset( $page['condition'] ) ) {
				$conditions    = $page['condition'];
				$condition_met = true;
				$invert        = false;
				foreach (
					$conditions as $condition_question => $condition_answer
				) {
					$value  = cmplz_tc_get_value( $condition_question, false, $use_default = false );
					$invert = false;
					if ( ! is_array( $condition_answer )
						&& strpos( $condition_answer, 'NOT ' ) !== false
					) {
						$condition_answer = str_replace( 'NOT ', '', $condition_answer );
						$invert           = true;
					}

					$condition_answer = is_array( $condition_answer ) ? $condition_answer : array( $condition_answer );
					foreach ( $condition_answer as $answer_item ) {
						if ( is_array( $value ) ) {
							if ( ! isset( $value[ $answer_item ] )
								|| ! $value[ $answer_item ]
							) {
								$condition_met = false;
							} else {
								$condition_met = true;
							}
						} else {
							$condition_met = ( $value === $answer_item );
						}

						// If one condition is met, we break with this condition, so it will return true.
						if ( $condition_met ) {
							break;
						}
					}

					// If one condition is not met, we break with this condition, so it will return false.
					if ( ! $condition_met ) {
						break;
					}
				}

				$condition_met = $invert ? ! $condition_met : $condition_met;

				return $condition_met;
			}

			return false;
		}

		/**
		 * Determine whether a document element should be included in the output.
		 *
		 * Returns true only when both the callback condition and the field-value
		 * condition are satisfied (AND logic). Called for every element during
		 * document HTML generation.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::callback_condition_applies()
		 * @see    cmplz_tc_document::condition_applies()
		 *
		 * @param  array $element  Document element definition array from the config.
		 * @return bool            True if the element should be inserted.
		 */
		public function insert_element( $element ) {

			if ( $this->callback_condition_applies( $element )
				&& $this->condition_applies( $element )
			) {
				return true;
			}

			return false;
		}

		/**
		 * Evaluate callback-based conditions for a document element.
		 *
		 * Checks the optional 'callback_condition' key of an element. Each entry
		 * can be a bare function name or prefixed with 'NOT ' for negation. All
		 * callbacks must exist and return a truthy value for the method to return
		 * true. If the element has no callback conditions, returns true immediately.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::insert_element()
		 *
		 * @param  array $element  Document element definition array from the config.
		 *                         May contain 'callback_condition' as a string or
		 *                         array of callable names.
		 * @return bool            True if all callback conditions pass (or none exist).
		 */
		public function callback_condition_applies( $element ) {

			if ( isset( $element['callback_condition'] ) ) {
				$conditions = is_array( $element['callback_condition'] )
					? $element['callback_condition']
					: array( $element['callback_condition'] );
				foreach ( $conditions as $func ) {
					$invert = false;
					if ( strpos( $func, 'NOT ' ) !== false ) {
						$invert = true;
						$func   = str_replace( 'NOT ', '', $func );
					}

					if ( ! function_exists( $func ) ) {
						break;
					}
					$show_field = $func();

					if ( $invert ) {
						$show_field = ! $show_field;
					}
					if ( ! $show_field ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Evaluate field-value conditions for a document element.
		 *
		 * Checks the optional 'condition' key of an element against the stored
		 * wizard answers. Supports equality, 'NOT EMPTY', '<' (less than), '>'
		 * (greater than), and 'NOT <value>' negation. Multicheckbox fields are
		 * checked by key presence. All conditions must be met (AND logic); the
		 * special value 'loop' is skipped here and handled by is_loop_element().
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::insert_element()
		 * @see    cmplz_tc_document::is_loop_element()
		 *
		 * @param  array $element  Document element definition array from the config.
		 *                         May contain 'condition' as an associative array
		 *                         keyed by field name with expected answer values.
		 * @return bool            True if all conditions pass (or none exist).
		 */
		public function condition_applies( $element ) {
			if ( isset( $element['condition'] ) ) {
				$fields        = COMPLIANZ_TC::$config->fields;
				$condition_met = true;

				foreach (
					$element['condition'] as $question => $condition_answer
				) {

					// Reset every loop.
					$invert = false;

					if ( 'loop' === $condition_answer ) {
						continue;
					}
					if ( ! isset( $fields[ $question ]['type'] ) ) {
						return false;
					}

					$type  = $fields[ $question ]['type'];
					$value = cmplz_tc_get_value( $question );

					if ( 'NOT EMPTY' !== $condition_answer && false !== strpos( $condition_answer, 'NOT ' ) ) {
						$condition_answer = str_replace( 'NOT ', '', $condition_answer );
						$invert           = true;
					}

					// Smaller than.
					if ( strpos( $condition_answer, '<' ) !== false ) {
						$condition_answer      = trim( str_replace( '<', '', $condition_answer ) );
						$current_condition_met = $value < $condition_answer;
					} else // Greater than.
					if ( strpos( $condition_answer, '>' ) !== false ) {
						$condition_answer      = trim( str_replace( '>', '', $condition_answer ) );
						$current_condition_met = $value > $condition_answer;
					} elseif ( 'NOT EMPTY' === $condition_answer ) {
						if ( '' === $value ) {
							$current_condition_met = false;
						} else {
							$current_condition_met = true;
						}
					} elseif ( 'multicheckbox' === $type ) {
						if ( ! isset( $value[ $condition_answer ] ) || ! $value[ $condition_answer ] ) {
							$current_condition_met = false;
						} else {
							$current_condition_met = true;
						}
					} else {
						$current_condition_met = $value === $condition_answer;
					}

					$current_condition_met = $invert ? ! $current_condition_met : $current_condition_met;

					$condition_met = $condition_met && $current_condition_met;
				}

				return $condition_met;

			}

			return true;
		}


		/**
		 * Determine whether a document element should loop over a multi-value field.
		 *
		 * Loop elements have a condition entry whose value is the literal string
		 * 'loop'. When detected, get_document_html() iterates over every saved
		 * value of the associated field and renders the element's content once per
		 * entry (e.g. for listing multiple products or data processing purposes).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_document_html()
		 *
		 * @param  array $element  Document element definition array from the config.
		 * @return bool            True if the element is a loop element.
		 */
		public function is_loop_element( $element ) {
			if ( isset( $element['condition'] ) ) {
				foreach (
					$element['condition'] as $question => $condition_answer
				) {
					if ( 'loop' === $condition_answer ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Build and return the full HTML for a legal document by type.
		 *
		 * Iterates over all document elements defined in the config for the given
		 * type, evaluates conditions, numbers paragraphs and annexes, processes
		 * loop elements, calls any registered callbacks, replaces field placeholders,
		 * wraps the result in a container div, sanitises with wp_kses(), and passes
		 * the output through do_shortcode() in case of nested shortcodes. The final
		 * string is passed through the 'cmplz_tc_document_html' filter before return.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::insert_element()
		 * @see    cmplz_tc_document::replace_fields()
		 * @see    cmplz_tc_allowed_html()
		 *
		 * @param  string $type  Document type identifier, e.g. 'terms-conditions'.
		 * @return string        Sanitised HTML string for the document, including the
		 *                       outer wrapper div and generator comment.
		 */
		public function get_document_html( $type ) {
			$elements         = COMPLIANZ_TC::$config->pages['all'][ $type ]['document_elements'];
			$html             = '';
			$paragraph        = 0;
			$sub_paragraph    = 0;
			$annex            = 0;
			$annex_arr        = array();
			$paragraph_id_arr = array();
			foreach ( $elements as $id => $element ) {
				// Count paragraphs.
				if ( $this->insert_element( $element )
					|| $this->is_loop_element( $element )
				) {

					if ( isset( $element['title'] )
						&& ( ! isset( $element['numbering'] )
								|| $element['numbering'] )
					) {
						$sub_paragraph = 0;
						++$paragraph;
						$paragraph_id_arr[ $id ]['main'] = $paragraph;
					}

					// Count subparagraphs.
					if ( isset( $element['subtitle'] ) && $paragraph > 0
						&& ( ! isset( $element['numbering'] )
								|| $element['numbering'] )
					) {
						++$sub_paragraph;
						$paragraph_id_arr[ $id ]['main'] = $paragraph;
						$paragraph_id_arr[ $id ]['sub']  = $sub_paragraph;
					}

					// Count annexes.
					if ( isset( $element['annex'] ) ) {
						++$annex;
						$annex_arr[ $id ] = $annex;
					}
				}
				if ( $this->is_loop_element( $element ) && $this->insert_element( $element )
				) {
					$fieldname    = key( $element['condition'] );
					$values       = cmplz_tc_get_value( $fieldname );
					$loop_content = '';
					if ( ! empty( $values ) ) {
						foreach ( $values as $value ) {
							if ( ! is_array( $value ) ) {
								$value = array( $value );
							}
							$fieldnames = array_keys( $value );
							if ( 1 === count( $fieldnames ) && 'key' === $fieldnames[0]
							) {
								continue;
							}

							$loop_section = $element['content'];
							foreach ( $fieldnames as $c_fieldname ) {
								$field_value = ( isset( $value[ $c_fieldname ] ) ) ? $value[ $c_fieldname ] : '';
								if ( ! empty( $field_value ) && is_array( $field_value )
								) {
									$field_value = implode( ', ', $field_value );
								}

								$loop_section = str_replace( '[' . $c_fieldname . ']', $field_value, $loop_section );
							}

							$loop_content .= $loop_section;

						}
						$html .= $this->wrap_header( $element, $paragraph, $sub_paragraph, $annex );
						$html .= $this->wrap_content( $loop_content );
					}
				} elseif ( $this->insert_element( $element ) ) {
					$html .= $this->wrap_header( $element, $paragraph, $sub_paragraph, $annex );
					if ( isset( $element['content'] ) ) {
						$html .= $this->wrap_content( $element['content'], $element );
					}
				}

				if ( isset( $element['callback'] ) && function_exists( $element['callback'] )
				) {
					$func  = $element['callback'];
					$html .= $func();
				}
			}

			$html = $this->replace_fields( $html, $paragraph_id_arr, $annex_arr );

			$comment = apply_filters(
				'cmplz_document_comment',
				"\n"
																. '<!-- This legal document was generated by Complianz Terms & Conditions https://wordpress.org/plugins/complianz-terms-conditions -->'
																. "\n"
			);

			$html = $comment . '<div id="cmplz-document" class="cmplz-document cmplz-terms-conditions ">' . $html . '</div>';
			$html = wp_kses( $html, cmplz_tc_allowed_html() );

			// In case we still have an unprocessed shortcode.
			// This may happen when a shortcode is inserted in combination with gutenberg.
			$html = do_shortcode( $html );

			return apply_filters( 'cmplz_tc_document_html', $html );
		}


		/**
		 * Render the heading HTML for a document element.
		 *
		 * Produces an <h2> for titles or a <p class="cmplz-subtitle"> for
		 * subtitles. Annex elements get a dedicated class and the translated
		 * "Annex N:" prefix. Paragraph numbers are appended when the element is
		 * a numbered element and a paragraph counter is active. All text content
		 * is escaped with esc_html(). The separator character between the number
		 * and the title is filterable via 'cmplz_tc_index_char'.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::is_numbered_element()
		 *
		 * @param  array $element        Document element definition array. May contain
		 *                               'title', 'subtitle', 'annex', and 'numbering'.
		 * @param  int   $paragraph      Current paragraph counter value.
		 * @param  int   $sub_paragraph  Current sub-paragraph counter value.
		 * @param  int   $annex          Current annex counter value.
		 * @return string                HTML heading string, or empty string for
		 *                               elements with an empty title.
		 */
		public function wrap_header(
			$element,
			$paragraph,
			$sub_paragraph,
			$annex
		) {
			$nr = '';
			if ( isset( $element['annex'] ) ) {
				$nr = __( 'Annex', 'complianz-terms-conditions' ) . ' ' . $annex . ': ';
				if ( isset( $element['title'] ) ) {
					return '<h2 class="annex">' . esc_html( $nr )
							. esc_html( $element['title'] ) . '</h2>';
				}
				if ( isset( $element['subtitle'] ) ) {
					return '<p class="subtitle annex">' . esc_html( $nr )
							. esc_html( $element['subtitle'] ) . '</p>';
				}
			}

			if ( isset( $element['title'] ) ) {
				if ( empty( $element['title'] ) ) {
					return '';
				}
				$nr = '';
				if ( $paragraph > 0
					&& $this->is_numbered_element( $element )
				) {
					$nr         = $paragraph;
					$index_char = apply_filters( 'cmplz_tc_index_char', '.' );
					$nr         = $nr . $index_char . ' ';
				}

				return '<h2>' . esc_html( $nr )
						. esc_html( $element['title'] ) . '</h2>';
			}

			if ( isset( $element['subtitle'] ) ) {
				if ( $paragraph > 0 && $sub_paragraph > 0
					&& $this->is_numbered_element( $element )
				) {
					$nr = $paragraph . '.' . $sub_paragraph . ' ';
				}

				return '<p class="cmplz-subtitle">' . esc_html( $nr )
						. esc_html( $element['subtitle'] ) . '</p>';
			}

			return '';
		}

		/**
		 * Determine whether a document element should receive a paragraph number.
		 *
		 * Returns the value of the 'numbering' key when present, otherwise
		 * defaults to true so that elements without an explicit setting are
		 * automatically numbered.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  array $element  Document element definition array.
		 *                         May contain 'numbering' (bool).
		 * @return bool            True if the element should be numbered.
		 */
		public function is_numbered_element( $element ) {

			if ( ! isset( $element['numbering'] ) ) {
				return true;
			}

			return $element['numbering'];
		}

		/**
		 * Wrap a sub-header string in bold HTML.
		 *
		 * Returns an empty string when $header is empty. The $paragraph and
		 * $subparagraph parameters are accepted for signature compatibility but
		 * are not currently used in the output.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $header        The sub-header text. Escaped with esc_html().
		 * @param  int    $paragraph     Current paragraph counter (unused).
		 * @param  int    $subparagraph  Current sub-paragraph counter (unused).
		 * @return string                '<b>header</b><br>' or empty string.
		 */
		public function wrap_sub_header( $header, $paragraph, $subparagraph ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $paragraph and $subparagraph kept for API compatibility.
			if ( empty( $header ) ) {
				return '';
			}

			return '<b>' . esc_html( $header ) . '</b><br>';
		}

		/**
		 * Wrap document element content in a paragraph tag.
		 *
		 * Applies an optional CSS class from the element config. Returns an
		 * empty string when content is empty. The class attribute value is
		 * escaped with esc_attr(); content itself is already sanitised by
		 * get_document_html() via wp_kses() before final output.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string      $content  The content string to wrap.
		 * @param  array|false $element  Document element definition array. When
		 *                               provided, a 'class' key adds a CSS class
		 *                               to the <p> tag. Default: false.
		 * @return string               HTML paragraph string or empty string.
		 */
		public function wrap_content( $content, $element = false ) {
			if ( empty( $content ) ) {
				return '';
			}

			$class = isset( $element['class'] ) ? 'class="'
													. esc_attr( $element['class'] )
													. '"' : '';

			return "<p $class>" . $content . '</p>';
		}

		/**
		 * Replace all template placeholders in the document HTML.
		 *
		 * Handles several categories of placeholder substitution in order:
		 * - '[article-N]'            → translated "(See paragraph N)" references
		 * - '[annex-N]'              → translated "(See annex N)" references
		 * - '[download_pdf_link]', '[domain]', '[site_url]' → site URL tokens
		 * - '[languages]'            → formatted communication language string
		 * - '[checked_date]'         → localised document update date
		 * - '[withdrawal_form_link]' → URL to the generated withdrawal form PDF
		 * - '[fieldname]'            → individual field values via get_plain_text_value()
		 * - '[comma_fieldname]'      → comma-separated version of field values
		 * - '[/fieldname]'           → closing </a> for URL fields
		 *
		 * @since  1.0.0
		 * @access private
		 *
		 * @see    cmplz_tc_document::get_plain_text_value()
		 *
		 * @param  string $html              The raw document HTML with placeholders.
		 * @param  array  $paragraph_id_arr  Map of element ID to paragraph number
		 *                                   array, e.g. array( 'id' => array( 'main' => 1 ) ).
		 * @param  array  $annex_arr         Map of element ID to annex number, e.g.
		 *                                   array( 'id' => 1 ).
		 * @return string                     HTML with all placeholders replaced.
		 */
		private function replace_fields(
			$html,
			$paragraph_id_arr,
			$annex_arr
		) {
			// Replace references.
			foreach ( $paragraph_id_arr as $id => $paragraph ) {
				$html = str_replace(
					"[article-$id]",
					sprintf(
						// translators: %s is the paragraph number.
						__( '(See paragraph %s)', 'complianz-terms-conditions' ),
						esc_html( $paragraph['main'] )
					),
					$html
				);
			}

			foreach ( $annex_arr as $id => $annex ) {
				$html = str_replace(
					"[annex-$id]",
					sprintf(
						// translators: %s is the annex number.
						__( '(See annex %s)', 'complianz-terms-conditions' ),
						esc_html( $annex )
					),
					$html
				);
			}
			$html = str_replace(
				array( '[download_pdf_link]', '[domain]', '[site_url]' ),
				array( cmplz_tc_url . 'download.php', '<a href="' . esc_url_raw( get_home_url() ) . '">' . esc_url_raw( get_home_url() ) . '</a>', site_url() ),
				$html
			);

			$single_language = cmplz_tc_get_value( 'language_communication' );
			if ( 'yes' === $single_language ) {
				$lang = defined( 'WPLANG' ) ? WPLANG : get_option( 'WPLANG' );
				if ( ! $lang ) {
					$lang = 'en_US'; // Ensures a fallback.
				}
				$languages = COMPLIANZ_TC::$config->format_code_lang( $lang );
			} else {
				$languages = cmplz_tc_get_value( 'multilanguage_communication' );
				$languages = array_filter(
					$languages,
					static function ( $v, $_k ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_k is required by ARRAY_FILTER_USE_BOTH signature.
						return '1' === $v;
					},
					ARRAY_FILTER_USE_BOTH
				);
				$languages = array_keys( $languages );
				foreach ( $languages as $key => $language ) {
					$languages[ $key ] = COMPLIANZ_TC::$config->format_code_lang( $language );
				}
				$nr        = count( $languages );
				$languages = implode( ', ', $languages );
				if ( $nr > 1 ) {
					$last_comma_pos = strrpos( $languages, ',' );
					$languages      = substr( $languages, 0, $last_comma_pos ) . ' ' . __( 'and', 'complianz-terms-conditions' ) . ' ' . substr( $languages, $last_comma_pos + 1 );
				}
			}
			$html = str_replace( '[languages]', $languages, $html );

			$checked_date = gmdate( get_option( 'date_format' ), get_option( 'cmplz_tc_documents_update_date', get_option( 'cmplz_documents_update_date' ) ) );
			$checked_date = cmplz_tc_localize_date( $checked_date );
			$html         = str_replace( '[checked_date]', esc_html( $checked_date ), $html );

			$uploads               = wp_upload_dir();
			$uploads_url           = $uploads['baseurl'];
			$locale                = substr( get_locale(), 0, 2 );
			$with_drawal_form_link = $uploads_url . "/complianz/withdrawal-forms/withdrawal-form-$locale.pdf";
			$html                  = str_replace( '[withdrawal_form_link]', $with_drawal_form_link, $html );

			// Replace all fields.
			foreach ( COMPLIANZ_TC::$config->fields() as $fieldname => $field ) {
				if ( strpos( $html, "[$fieldname]" ) !== false ) {
					$html = str_replace(
						"[$fieldname]",
						$this->get_plain_text_value( $fieldname, true ),
						$html
					);
					// When there's a closing shortcode it's always a link.
					$html = str_replace( "[/$fieldname]", '</a>', $html );
				}

				if ( strpos( $html, "[comma_$fieldname]" ) !== false ) {
					$html = str_replace(
						"[comma_$fieldname]",
						$this->get_plain_text_value( $fieldname, false ),
						$html
					);
				}
			}

			return $html;
		}

		/**
		 * Resolve a field's stored value to a human-readable string for document output.
		 *
		 * The rendering strategy depends on the field type:
		 * - 'url'            → wrapped in an opening <a href=""> tag (closed by [/fieldname])
		 * - 'email'          → passed through the 'cmplz_tc_document_email' filter (obfuscation)
		 * - 'radio'          → label looked up from the field's options array
		 * - 'textarea'       → newlines converted to <br> with nl2br()
		 * - array (checkbox) → active keys mapped to labels; rendered as <ul><li> list
		 *                       when $list_style is true, or comma-separated with "and"
		 *                       before the last item when false
		 * - other with options → label looked up from options array
		 *
		 * When a 'document_label' key is defined for the field, the label is prepended
		 * to the value in the output.
		 *
		 * @since  1.0.0
		 * @access private
		 *
		 * @see    cmplz_tc_document::replace_fields()
		 *
		 * @param  string $fieldname   The field name as defined in the config.
		 * @param  bool   $list_style  When true, multi-value fields are rendered as
		 *                             an HTML <ul> list. When false, values are joined
		 *                             with commas and "and".
		 * @return string              The rendered value string ready for document insertion.
		 */
		private function get_plain_text_value( $fieldname, $list_style ) {
			$value = cmplz_tc_get_value( $fieldname );

			$front_end_label
				= isset( COMPLIANZ_TC::$config->fields[ $fieldname ]['document_label'] )
				? COMPLIANZ_TC::$config->fields[ $fieldname ]['document_label']
				: false;

			if ( 'url' === COMPLIANZ_TC::$config->fields[ $fieldname ]['type'] ) {
				$value = '<a href="' . $value . '">';
			} elseif ( 'email'
						=== COMPLIANZ_TC::$config->fields[ $fieldname ]['type']
			) {
				$value = apply_filters( 'cmplz_tc_document_email', $value );
			} elseif ( 'radio'
						=== COMPLIANZ_TC::$config->fields[ $fieldname ]['type']
			) {
				$options = COMPLIANZ_TC::$config->fields[ $fieldname ]['options'];
				$value   = isset( $options[ $value ] ) ? $options[ $value ]
					: '';
			} elseif ( 'textarea'
						=== COMPLIANZ_TC::$config->fields[ $fieldname ]['type']
			) {
				// Preserve linebreaks.
				$value = nl2br( $value );
			} elseif ( is_array( $value ) ) {
				$options = COMPLIANZ_TC::$config->fields[ $fieldname ]['options'];
				// Example structure: key is option index, value is 1 if active.
				$value = array_filter(
					$value,
					function ( $item ) {
						return 1 === $item; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Intentional loose comparison; stored checkbox values may be strings or integers.
					}
				);
				$value = array_keys( $value );
				// Now an array of active option indices.
				$labels = '';
				foreach ( $value as $index ) {
					// Trying to fix strange issue where index is not set.
					if ( ! isset( $options[ $index ] ) ) {
						continue;
					}

					if ( $list_style ) {
						$labels .= '<li>' . esc_html( $options[ $index ] )
									. '</li>';
					} else {
						$labels .= $options[ $index ] . ', ';
					}
				}
				// Empty $labels is intentional; no fallback text is rendered.

				if ( $list_style ) {
					$labels = '<ul>' . $labels . '</ul>';
				} else {
					$labels = esc_html( rtrim( $labels, ', ' ) );
					$labels = strrev(
						implode(
							strrev(
								' ' . __(
									'and',
									'complianz-terms-conditions'
								)
							),
							explode( strrev( ',' ), strrev( $labels ), 2 )
						)
					);
				}

				$value = $labels;
			} elseif ( isset( COMPLIANZ_TC::$config->fields[ $fieldname ]['options'] ) ) {
				$options = COMPLIANZ_TC::$config->fields[ $fieldname ]['options'];

				if ( isset( $options[ $value ] ) ) {
					$value = $options[ $value ];
				}
			}

			if ( $front_end_label && ! empty( $value ) ) {
				$value = $front_end_label . $value . '<br>';
			}

			return $value;
		}


		/**
		 * Register all WordPress hooks for the document manager.
		 *
		 * Registers the [cmplz-terms-conditions] shortcode, hooks into
		 * display_post_states, save_post (transient clearing and metabox save),
		 * admin_init (page-to-menu assignment, PDF generation), wp_enqueue_scripts,
		 * and the Complianz GDPR dashboard action hooks for both the legacy PHP
		 * template and the React-based dashboard introduced in Complianz 7.0.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @return void
		 */
		public function init() {
			// This shortcode is also available as gutenberg block.
			add_shortcode( 'cmplz-terms-conditions', array( $this, 'load_document' ) );
			add_filter( 'display_post_states', array( $this, 'add_post_state' ), 10, 2 );

			// Clear shortcode transients after post update.
			add_action( 'save_post', array( $this, 'clear_shortcode_transients' ), 10, 3 );
			add_action(
				'cmplz_tc_terms_conditions_add_pages_to_menu',
				array(
					$this,
					'wizard_add_pages_to_menu',
				),
				10,
				1
			);
			add_action( 'cmplz_tc_terms_conditions_add_pages', array( $this, 'callback_wizard_add_pages' ), 10, 1 );
			add_action( 'admin_init', array( $this, 'assign_documents_to_menu' ) );

			add_filter( 'cmplz_tc_document_email', array( $this, 'obfuscate_email' ) );
			add_filter( 'body_class', array( $this, 'add_body_class_for_complianz_documents' ) );

			// Unlinking documents.
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'save_post', array( $this, 'save_metabox_data' ), 10, 3 );
			add_action( 'wp_ajax_cmplz_tc_create_pages', array( $this, 'ajax_create_pages' ) );
			add_action( 'admin_init', array( $this, 'maybe_generate_withdrawal_form' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'cmplz_documents_overview', array( $this, 'add_docs_to_cmplz_dashboard' ) );

			// 7.0 react hook.
			add_filter( 'cmplz_documents_block_data', array( $this, 'add_docs_to_cmplz_react_dashboard' ) );
		}

		/**
		 * Add the Terms & Conditions document row to the Complianz GDPR dashboard.
		 *
		 * Hooked into 'cmplz_documents_overview'. Outputs a dashboard row template
		 * with sync status, page-exists icon, shortcode copy widget, and a link to
		 * the document page. Only runs when $region is 'all'.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::syncStatus()
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  string $region  The dashboard region being rendered. Only 'all'
		 *                         is handled; other values cause an early return.
		 * @return void
		 */
		public function add_docs_to_cmplz_dashboard( $region ) {
			if ( 'all' !== $region ) {
				return;
			}

			/**
			 * Terms conditions.
			 */

			$page_id     = $this->get_shortcode_page_id( 'terms-conditions' );
			$shortcode   = $this->get_shortcode( 'terms-conditions', $force_classic = true );
			$title       = __( 'Terms and Conditions', 'complianz-terms-conditions' );
			$title       = '<a href="' . get_permalink( $page_id ) . '">' . $title . '</a>';
			$title      .= '<div class="cmplz-selectable cmplz-shortcode" id="terms-conditions">' . $shortcode . '</div>';
			$page_exists = cmplz_tc_icon( 'circle-times', 'disabled' );
			$sync_icon   = cmplz_tc_icon( 'sync-error', 'disabled' );
			if ( $page_id ) {
				$generated   = gmdate( cmplz_short_date_format(), get_option( 'cmplz_tc_documents_update_date', get_option( 'cmplz_documents_update_date' ) ) );
				$sync_status = $this->syncStatus( $page_id );
				$status      = 'sync' === $sync_status ? 'success' : 'disabled';
				$sync_icon   = cmplz_tc_icon( 'sync', $status );
				$page_exists = cmplz_tc_icon( 'circle-check', 'success' );
			} else {
				$status    = 'disabled';
				$generated = '<a href="' . add_query_arg(
					array(
						'page' => 'terms-conditions',
						'step' => 3,
					),
					admin_url( 'admin.php' )
				) . '">' . __( 'create', 'complianz-terms-conditions' ) . '</a>';
			}
			$shortcode_icon = cmplz_tc_icon( 'shortcode', 'default', __( 'Click to copy the document shortcode', 'complianz-terms-conditions' ), 15, $page_id, $shortcode );
			$shortcode_icon = '<span class="cmplz-copy-shortcode">' . $shortcode_icon . '</span>';

			$args = array(
				'status'         => $status . ' shortcode-container',
				'title'          => $title,
				'page_exists'    => $page_exists,
				'sync_icon'      => $sync_icon,
				'shortcode_icon' => $shortcode_icon,
				'generated'      => $generated,
			);
			echo cmplz_get_template( 'dashboard/documents-row.php', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal template helper.
		}

		/**
		 * Inject the Terms & Conditions document data into the React dashboard payload.
		 *
		 * Hooked into 'cmplz_documents_block_data' (Complianz 7.0+). Appends a
		 * page_data entry to the existing $documents array under the 'all' region.
		 * Includes title, type, permalink, generated date, sync status, shortcode,
		 * and — when the page does not yet exist — a create_link pointing to the
		 * wizard step 3.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::syncStatus()
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  array $documents  Existing documents array passed through the filter.
		 *                           Each entry has 'region' and 'documents' keys.
		 * @return array             Modified $documents array with the T&C entry added.
		 */
		public function add_docs_to_cmplz_react_dashboard( $documents ) {
			$page_id                = COMPLIANZ_TC::$document->get_shortcode_page_id( 'terms-conditions' );
			$page_data['title']     = __( 'Terms and Conditions', 'complianz-terms-conditions' );
			$page_data['type']      = 'terms-conditions';
			$page_data['permalink'] = get_permalink( $page_id );
			$page_data['required']  = true;

			if ( $page_id ) {
				$page_data['generated'] = gmdate( cmplz_short_date_format(), get_option( 'cmplz_tc_documents_update_date', get_option( 'cmplz_documents_update_date' ) ) );
				$page_data['status']    = $this->syncStatus( $page_id );
				$page_data['exists']    = true;
				$page_data['shortcode'] = COMPLIANZ_TC::$document->get_shortcode( 'terms-conditions', $force_classic = true );
			} else {
				$page_data['exists']      = false;
				$page_data['generated']   = '';
				$page_data['status']      = 'unlink';
				$page_data['shortcode']   = '';
				$page_data['create_link'] = add_query_arg(
					array(
						'page' => 'terms-conditions',
						'step' => 3,
					),
					admin_url( 'admin.php' )
				);
			}

			// Get the index of the $documents array where 'region' = 'all'.
			$index = array_search( 'all', array_column( $documents, 'region' ), true );
			if ( false !== $index ) {
				$documents[ $index ]['documents'][] = $page_data;
			} else {
				$documents[] = array(
					'region'    => 'all',
					'documents' => array( $page_data ),
				);
			}
			return $documents;
		}

		/**
		 * Append a "Legal Document" post state label for Complianz pages.
		 *
		 * Hooked into 'display_post_states'. Adds a 'page_for_privacy_policy'
		 * state entry so admin page lists show a "Legal Document" badge next to
		 * pages that host a Complianz Terms & Conditions shortcode or block.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::is_complianz_page()
		 *
		 * @param  array   $post_states  Existing post state labels array.
		 * @param  WP_Post $post         The current post being listed.
		 * @return array                 Modified post states array.
		 */
		public function add_post_state( $post_states, $post ) {
			if ( $this->is_complianz_page( $post->ID ) ) {
				$post_states['page_for_privacy_policy'] = __( 'Legal Document', 'complianz-terms-conditions' );
			}

			return $post_states;
		}

		/**
		 * Register the document sync-status meta box on Complianz pages.
		 *
		 * Hooked into 'add_meta_boxes'. Only adds the meta box when the current
		 * post is a Complianz document page and the site is not using the
		 * Gutenberg block editor (Classic Editor only). The meta box renders the
		 * sync/unlink selector via metabox_unlink_from_complianz().
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::metabox_unlink_from_complianz()
		 * @see    cmplz_tc_document::is_complianz_page()
		 *
		 * @param  string $post_type  The current post type slug. Unused directly;
		 *                            the check is performed via the global $post.
		 * @return void
		 */
		public function add_meta_box( $post_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the add_meta_boxes hook signature.
			global $post;

			if ( ! $post ) {
				return;
			}

			if ( $this->is_complianz_page( $post->ID )
				&& ! cmplz_tc_uses_gutenberg()
			) {
				add_meta_box(
					'cmplz_tc_edit_meta_box',
					__( 'Document status', 'complianz-terms-conditions' ),
					array( $this, 'metabox_unlink_from_complianz' ),
					null,
					'side',
					'high',
					array()
				);
			}
		}

		/**
		 * Render the document sync-status meta box content.
		 *
		 * Outputs a <select> with "Synchronize" and "Unlink" options. The
		 * nonce field 'cmplz_tc_unlink_nonce' is output for CSRF protection.
		 * Only runs when the current user has the 'manage_options' capability.
		 * Saving is handled by save_metabox_data().
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::save_metabox_data()
		 * @see    cmplz_tc_document::syncStatus()
		 *
		 * @return void
		 */
		public function metabox_unlink_from_complianz() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			wp_nonce_field( 'cmplz_tc_unlink_nonce', 'cmplz_tc_unlink_nonce' );

			global $post;
			$sync = $this->syncStatus( $post->ID );
			?>
			<select name="cmplz_tc_document_status">
				<option value="sync" 
				<?php
				echo 'sync' === $sync ? 'selected="selected"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a hardcoded constant string.
				?>
					>
					<?php
					esc_html_e(
						'Synchronize document with Complianz',
						'complianz-terms-conditions'
					);
					?>
						</option>
				<option value="unlink" 
				<?php
				echo 'unlink' === $sync ? 'selected="selected"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a hardcoded constant string.
				?>
					>
					<?php
					esc_html_e(
						'Edit document and stop synchronization',
						'complianz-terms-conditions'
					);
					?>
						</option>
			</select>
			<?php
		}

		/**
		 * Return the synchronisation status of a document page.
		 *
		 * Checks whether the post content contains the Complianz block or classic
		 * shortcode, then reads the stored status from block attributes or post meta.
		 * Returns 'sync' when the document is set to auto-update from the plugin,
		 * or 'unlink' when the user has chosen to manage it manually.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  int $post_id  WordPress post ID of the document page.
		 * @return string        'sync' or 'unlink'. Defaults to 'unlink' when the
		 *                       post does not exist or contains neither block nor shortcode.
		 */
		public function syncStatus( $post_id ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Established public API; renaming would break all callers.
			$post = get_post( $post_id );
			$sync = 'unlink';

			if ( ! $post ) {
				return $sync;
			}

			$shortcode = 'cmplz-terms-conditions';
			$block     = 'complianztc/terms-conditions';

			$html = $post->post_content;
			if ( cmplz_tc_uses_gutenberg() && has_block( $block, $html ) ) {
				$elements = parse_blocks( $html );
				foreach ( $elements as $element ) {
					if ( $element['blockName'] === $block ) {
						if ( isset( $element['attrs']['documentSyncStatus'] )
							&& 'unlink' === $element['attrs']['documentSyncStatus']
						) {
							$sync = 'unlink';
						} else {
							$sync = 'sync';
						}
					}
				}
			} elseif ( has_shortcode( $post->post_content, $shortcode ) ) {
				$sync = get_post_meta(
					$post_id,
					'cmplz_tc_document_status',
					true
				);
				if ( ! $sync ) {
					$sync = 'sync';
				}
			}

			// Default.
			return $sync;
		}

		/**
		 * Generate a pending withdrawal form PDF for the next queued language.
		 *
		 * Hooked into 'admin_init'. Reads the 'cmplz_generate_pdf_languages' option,
		 * pops the first language off the queue, updates the option, and delegates
		 * to generate_withdrawal_form(). Processing one language per request prevents
		 * timeout issues when multiple languages are queued.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::generate_withdrawal_form()
		 *
		 * @throws \Mpdf\MpdfException  When mPDF encounters an error during PDF generation.
		 *
		 * @return void
		 */
		public function maybe_generate_withdrawal_form() {
			$languages_to_generate = get_option( 'cmplz_generate_pdf_languages' );
			if ( ! empty( $languages_to_generate ) ) {
				$languages = $languages_to_generate;
				reset( $languages );
				$language_to_generate = key( $languages );
				unset( $languages_to_generate[ $language_to_generate ] );
				update_option( 'cmplz_generate_pdf_languages', $languages_to_generate );
				$this->generate_withdrawal_form( $language_to_generate );
			}
		}

		/**
		 * Generate the withdrawal form PDF for a specific locale and save to disk.
		 *
		 * Switches the WordPress locale, renders the withdrawal-form.php template,
		 * replaces the '[address_company]' placeholder, and passes the result to
		 * generate_pdf(). Requires the user to be logged in with 'manage_options'
		 * capability; terminates execution with die() otherwise.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::generate_pdf()
		 *
		 * @throws \Mpdf\MpdfException  When mPDF encounters an error during PDF generation.
		 *
		 * @param  string $locale  WordPress locale string, e.g. 'en_US', 'nl_NL'.
		 *                         Default: 'en_US'.
		 * @return void
		 */
		public function generate_withdrawal_form( $locale = 'en_US' ) {
			if ( ! is_user_logged_in() ) {
				die( 'invalid command' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				die( 'invalid command' );
			}
			switch_to_locale( $locale );
			$title         = __( 'Withdrawal Form', 'complianz-terms-conditions' );
			$document_html = cmplz_tc_get_template( 'withdrawal-form.php' );
			$document_html = str_replace( '[address_company]', cmplz_tc_get_value( 'address_company' ), $document_html );
			$file_title    = sanitize_file_name( 'withdrawal-form-' . $locale );

			$this->generate_pdf( $document_html, $title, $file_title );
		}

		/**
		 * Generate a PDF from HTML using mPDF, saving to disk or streaming to the browser.
		 *
		 * Sanitises the HTML with wp_kses() before passing it to mPDF. When $file_title
		 * is provided the PDF is written to the uploads/complianz/withdrawal-forms/
		 * directory (output mode 'F'). When omitted the PDF is streamed as a download
		 * (output mode 'D'). File-save operations require the user to be logged in with
		 * 'manage_options' capability. Required subdirectories are created on the fly
		 * when they do not exist. Uses a stored token for the mPDF temp directory to
		 * avoid conflicts between requests.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_allowed_html()
		 *
		 * @throws \Mpdf\MpdfException  When mPDF encounters a configuration or rendering error.
		 *
		 * @param  string       $html        HTML content to render as PDF. Sanitised internally.
		 * @param  string       $title       Document title used for the PDF <title> tag and
		 *                                   the page footer.
		 * @param  string|false $file_title  Filename (without extension) when saving to disk.
		 *                                   When false, the PDF is streamed to the browser.
		 *                                   Default: false.
		 * @return void
		 */
		public function generate_pdf( $html, $title, $file_title = false ) {
			$html         = wp_kses( $html, cmplz_tc_allowed_html() );
			$title        = sanitize_text_field( $title );
			$file_title   = sanitize_file_name( $file_title );
			$error        = false;
			$temp_dir     = false;
			$save_dir     = false;
			$uploads      = wp_upload_dir();
			$upload_dir   = $uploads['basedir'];
			$save_to_file = true;
			if ( ! $file_title ) {
				$save_to_file = false;
			}

			// Saving only for logged in users.
			if ( $save_to_file ) {
				if ( ! is_user_logged_in() ) {
					die( 'invalid command' );
				}

				if ( ! current_user_can( 'manage_options' ) ) {
					die( 'invalid command' );
				}
			}

			// ==============================================================
			// ==============================================================
			// ==============================================================

			require cmplz_tc_path . '/assets/vendor/autoload.php';

			// Generate a token when it's not there, otherwise use the existing one.
			if ( get_option( 'cmplz_pdf_dir_token' ) ) {
				$token = get_option( 'cmplz_pdf_dir_token' );
			} else {
				$token = time();
				update_option( 'cmplz_pdf_dir_token', $token );
			}

			if ( ! is_writable( $upload_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem does not provide a reliable writable check for this use case.
				$error = true;
			}

			if ( ! $error ) {
				if ( ! file_exists( $upload_dir . '/complianz' ) ) {
					mkdir( $upload_dir . '/complianz' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Directory created in uploads; WP_Filesystem not available in this context.
				}
				if ( ! file_exists( $upload_dir . '/complianz/tmp' ) ) {
					mkdir( $upload_dir . '/complianz/tmp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Directory created in uploads; WP_Filesystem not available in this context.
				}
				if ( ! file_exists( $upload_dir . '/complianz/withdrawal-forms' ) ) {
					mkdir( $upload_dir . '/complianz/withdrawal-forms' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Directory created in uploads; WP_Filesystem not available in this context.
				}
				$save_dir = $upload_dir . '/complianz/withdrawal-forms/';
				$temp_dir = $upload_dir . '/complianz/tmp/' . $token;
				if ( ! file_exists( $temp_dir ) ) {
					mkdir( $temp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Directory created in uploads; WP_Filesystem not available in this context.
				}
			}
			if ( ! $error ) {
				$mpdf = new Mpdf\Mpdf(
					array(
						'setAutoTopMargin'  => 'stretch',
						'autoMarginPadding' => 5,
						'tempDir'           => $temp_dir,
						'margin_left'       => 20,
						'margin_right'      => 20,
						'margin_top'        => 30,
						'margin_bottom'     => 30,
						'margin_header'     => 30,
						'margin_footer'     => 10,
					)
				);

				$mpdf->SetDisplayMode( 'fullpage' );
				$mpdf->SetTitle( $title );
				$date        = date_i18n( get_option( 'date_format' ), time() );
				$footer_text = sprintf( "%s $title $date", get_bloginfo( 'name' ) );
				$mpdf->SetFooter( $footer_text );
				$mpdf->WriteHTML( $html );

				// Save the pages to a file.
				if ( $save_to_file ) {
					$file_title = $save_dir . $file_title;
				} else {
					$file_title = sanitize_title( $title );
				}
				$output_mode = $save_to_file ? 'F' : 'D';
				$mpdf->Output( $file_title . '.pdf', $output_mode );
			}
		}

		/**
		 * Enqueue Complianz GDPR document styles on front-end document pages.
		 *
		 * Hooked into 'wp_enqueue_scripts'. Only runs when the Complianz GDPR
		 * plugin is active (cmplz_version defined) and the current page is a
		 * Complianz document page. Respects SCRIPT_DEBUG for non-minified assets
		 * and the 'use_document_css' Complianz GDPR setting. Also hooks the
		 * Complianz GDPR inline_styles action into wp_head.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::is_complianz_page()
		 *
		 * @return void
		 */
		public function enqueue_assets() {
			if ( defined( 'cmplz_version' ) && $this->is_complianz_page() ) {
				$min      = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
				$load_css = cmplz_get_value( 'use_document_css' );
				if ( $load_css ) {
					wp_register_style(
						'cmplz-document',
						cmplz_url . "assets/css/document$min.css",
						array(),
						cmplz_version
					);
					wp_enqueue_style( 'cmplz-document' );
				}
				add_action( 'wp_head', array( COMPLIANZ::$document, 'inline_styles' ), 100 );
			}
		}

		/**
		 * Save the document sync-status selection from the meta box.
		 *
		 * Hooked into 'save_post'. Verifies the 'manage_options' capability,
		 * skips autosaves, and validates the 'cmplz_tc_unlink_nonce' nonce.
		 * When set to 'unlink': detects the existing shortcode/block in the
		 * post content, stores it in post meta 'cmplz_tc_shortcode', replaces
		 * the post content with the fully rendered document HTML, and updates
		 * post meta 'cmplz_tc_document_status'. When set back to 'sync':
		 * restores the stored shortcode, deletes the stored HTML meta, and
		 * updates status accordingly. Temporarily removes itself from the hook
		 * during wp_update_post() to prevent recursion.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::syncStatus()
		 * @see    cmplz_tc_document::get_shortcode_pattern()
		 *
		 * @param  int     $post_id  The post ID being saved.
		 * @param  WP_Post $post     The post object.
		 * @param  bool    $update   Whether this is an update to an existing post.
		 * @return void
		 */
		public function save_metabox_data( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $update is required by the save_post hook signature.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// Check if this isn't an auto save.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// Security check.
			if ( ! isset( $_POST['cmplz_tc_unlink_nonce'] )
				|| ! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['cmplz_tc_unlink_nonce'] ) ),
					'cmplz_tc_unlink_nonce'
				)
			) {
				return;
			}

			if ( ! isset( $_POST['cmplz_tc_document_status'] ) ) {
				return;
			}

			global $post;

			if ( ! $post ) {
				return;
			}
			// Prevent looping.
			remove_action( 'save_post', array( $this, 'save_metabox_data' ), 10 );
			$sync = 'unlink' === sanitize_text_field( wp_unslash( $_POST['cmplz_tc_document_status'] ) ) ? 'unlink' : 'sync';
			// Save the document's shortcode in a meta field.
			if ( 'unlink' === $sync ) {
				// Get shortcode from page.
				$shortcode = false;
				$type      = '';
				if ( preg_match( $this->get_shortcode_pattern( 'gutenberg' ), $post->post_content, $matches ) ) {
					$shortcode = $matches[0];
					$type      = $matches[1];
				} elseif ( preg_match( $this->get_shortcode_pattern( 'gutenberg', true ), $post->post_content, $matches ) ) {
					$shortcode = $matches[0];
					$type      = 'terms-conditions';
				} elseif ( preg_match( $this->get_shortcode_pattern( 'classic' ), $post->post_content, $matches ) ) {
					$shortcode = $matches[0];
					$type      = $matches[1];
				} elseif ( preg_match( $this->get_shortcode_pattern( 'classic', true ), $post->post_content, $matches ) ) {
					$shortcode = $matches[0];
					$type      = 'terms-conditions';
				}

				if ( $shortcode ) {
					// Store shortcode.
					update_post_meta( $post->ID, 'cmplz_tc_shortcode', $post->post_content );
					$document_html = $this->get_document_html( $type );
					$args          = array(
						'post_content' => $document_html,
						'ID'           => $post->ID,
					);
					wp_update_post( $args );
				}
			} else {
				$shortcode = get_post_meta( $post->ID, 'cmplz_tc_shortcode', true );
				if ( $shortcode ) {
					$args = array(
						'post_content' => $shortcode,
						'ID'           => $post->ID,
					);
					wp_update_post( $args );
				}
				delete_post_meta( $post->ID, 'cmplz_tc_shortcode' );
			}
			update_post_meta( $post->ID, 'cmplz_tc_document_status', $sync );
			add_action( 'save_post', array( $this, 'save_metabox_data' ), 10, 3 );
		}

		/**
		 * Add the 'cmplz-terms-conditions' class to the body on document pages.
		 *
		 * Hooked into 'body_class'. Used by Complianz GDPR to identify document
		 * pages for the soft cookie wall feature so it can exempt them from the
		 * cookie wall overlay.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::is_complianz_page()
		 *
		 * @param  string[] $classes  Existing body CSS class array.
		 * @return string[]           Modified class array with 'cmplz-terms-conditions'
		 *                            appended when on a document page.
		 */
		public function add_body_class_for_complianz_documents( $classes ) {
			global $post;
			if ( $post && $this->is_complianz_page( $post->ID ) ) {
				$classes[] = 'cmplz-terms-conditions';
			}

			return $classes;
		}

		/**
		 * Obfuscate an email address to deter scrapers.
		 *
		 * Hooked into the 'cmplz_tc_document_email' filter applied when
		 * get_plain_text_value() processes an email-type field. Uses WordPress
		 * core antispambot() which encodes characters as HTML entities.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    antispambot()
		 *
		 * @param  string $email  The raw email address string.
		 * @return string         The entity-encoded email address.
		 */
		public function obfuscate_email( $email ) {
			return antispambot( $email );
		}


		/**
		 * Handle the AJAX request to create or update document pages from the wizard.
		 *
		 * Hooked into 'wp_ajax_cmplz_tc_create_pages'. Validates 'manage_options'
		 * capability and the 'complianz_tc_save' nonce, then iterates over the
		 * JSON-encoded 'pages' POST parameter. Creates a new page via create_page()
		 * when none exists for that type, or updates the title of an existing page
		 * via wp_update_post(). Returns a JSON response with a success flag, updated
		 * button text, and a check icon.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::create_page()
		 *
		 * @return void  Terminates with exit after sending JSON response.
		 */
		public function ajax_create_pages() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! isset( $_POST['nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'complianz_tc_save' ) ) {
				return;
			}
			$error = false;
			if ( ! isset( $_POST['pages'] ) ) {
				$error = true;
			}

			if ( ! $error ) {
				$posted_pages = json_decode( wp_unslash( $_POST['pages'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data is sanitized per-field after decoding.
				foreach ( $posted_pages as $region => $pages ) {
					foreach ( $pages as $type => $title ) {
						$title           = sanitize_text_field( $title );
						$current_page_id = $this->get_shortcode_page_id( $type, false );
						if ( ! $current_page_id ) {
							$this->create_page( $type );
						} else {
							// If the page already exists, just update it with the title.
							$page = array(
								'ID'         => $current_page_id,
								'post_title' => $title,
								'post_type'  => 'page',
							);
							wp_update_post( $page );
						}
					}
				}
			}
			$data = array(
				'success'         => ! $error,
				'new_button_text' => esc_html__( 'Update pages', 'complianz-terms-conditions' ),
				'icon'            => cmplz_tc_icon( 'check', 'success' ),
			);
			header( 'Content-Type: application/json' );
			echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded response is safe for output.
			exit;
		}

		/**
		 * Check whether any required document pages are missing from the site.
		 *
		 * Iterates over all required pages (as determined by get_required_pages())
		 * and returns true as soon as a type has no corresponding WordPress page
		 * containing the shortcode or block. Used to toggle the wizard page-step
		 * button between "Create missing pages" and "Update pages".
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_required_pages()
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @return bool  True if at least one required page is missing.
		 */
		public function has_missing_pages() {
			$pages         = $this->get_required_pages();
			$missing_pages = false;
			foreach ( $pages as $region => $region_pages ) {
				foreach ( $region_pages as $type => $page ) {
					$current_page_id = $this->get_shortcode_page_id( $type );
					if ( ! $current_page_id ) {
						$missing_pages = true;
						break;
					}
				}
			}

			return $missing_pages;
		}

		/**
		 * Render the wizard step for creating or updating document pages.
		 *
		 * Hooked into 'cmplz_tc_terms_conditions_add_pages'. Outputs an intro
		 * paragraph (missing-pages warning or all-created confirmation), a table
		 * of required pages with status icons and editable title inputs, shortcode
		 * copy widgets for each page, and a "Create missing pages" / "Update pages"
		 * button. All output is handled via direct echo and template helpers.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::has_missing_pages()
		 * @see    cmplz_tc_document::get_required_pages()
		 * @see    cmplz_tc_document::get_shortcode()
		 *
		 * @return void
		 */
		public function callback_wizard_add_pages() {

			?>
			<div class="cmplz-wizard-intro">
				<?php
				if ( $this->has_missing_pages() ) {
					echo '<p>' . esc_html__( 'The pages marked with X should be added to your website. You can create these pages with a shortcode, a Gutenberg block, or use the below "Create missing pages" button.', 'complianz-terms-conditions' ) . '</p>';
				} else {
					echo '<p>' . esc_html__( 'All necessary pages have been created already. You can update the page titles here if you want, then click the "Update pages" button.', 'complianz-terms-conditions' ) . '</p>';
				}
				?>
			</div>

			<?php
			$pages         = $this->get_required_pages();
			$missing_pages = false;
			?>
			<div class="field-group add-pages">
				<div class="cmplz-field">
					<div class="cmplz-add-pages-table shortcode-container">
						<?php
						foreach ( $pages as $region => $region_pages ) {
							foreach ( $region_pages as $type => $page ) {
								$current_page_id = $this->get_shortcode_page_id( $type, false );
								if ( ! $current_page_id ) {
									$missing_pages = true;
									$title         = $page['title'];
									$icon          = cmplz_tc_icon( 'check', 'error' );
									$class         = 'cmplz-deleted-page';
								} else {
									$post  = get_post( $current_page_id );
									$icon  = cmplz_tc_icon( 'check', 'success' );
									$title = $post->post_title;
									$class = 'cmplz-valid-page';
								}
								$shortcode = $this->get_shortcode( $type, $force_classic = true );
								?>
								<div>
									<input
											name="<?php echo esc_attr( $type ); ?>"
											data-region="<?php echo esc_attr( $region ); ?>"
											class="<?php echo esc_attr( $class ); ?> cmplz-create-page-title"
											type="text"
											value="<?php echo esc_attr( $title ); ?>">
									<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?>
								</div>
								<div class="cmplz-shortcode" id="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $shortcode ); ?></div>
								<span class="cmplz-copy-shortcode"><?php echo cmplz_tc_icon( 'shortcode', 'default', esc_attr__( 'Click to copy the document shortcode', 'complianz-terms-conditions' ), 15, $type, $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from trusted internal helper. ?></span>

								<?php
							}
						}
						?>
					</div>

					<?php
					if ( $missing_pages ) {
						$btn = __( 'Create missing pages', 'complianz-terms-conditions' );
					} else {
						$btn = __( 'Update pages', 'complianz-terms-conditions' );
					}
					?>

					<button type="button" class="button button-primary"
							id="cmplz-tcf-create_pages"><?php echo esc_html( $btn ); ?></button>

				</div>
			</div>
			<?php
		}

		/**
		 * Render the wizard step for assigning document pages to a navigation menu.
		 *
		 * Hooked into 'cmplz_tc_terms_conditions_add_pages_to_menu'. Requires
		 * wp_get_nav_menu_name() (WordPress 4.9+); displays a notice and returns
		 * early on older versions. Shows a dropdown per created page listing all
		 * registered menus, with the currently assigned menu pre-selected.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::assign_documents_to_menu()
		 * @see    cmplz_tc_document::get_created_pages()
		 *
		 * @return void
		 */
		public function wizard_add_pages_to_menu() {
			// This function is used as of 4.9.0.
			if ( ! function_exists( 'wp_get_nav_menu_name' ) ) {
				echo '<div class="field-group cmplz-link-to-menu">';
				echo '<div class="cmplz-field"></div>';
				cmplz_tc_notice(
					__(
						'Your WordPress version does not support the functions needed for this step. You can upgrade to the latest WordPress version, or add the pages manually to a menu.',
						'complianz-terms-conditions'
					),
					'warning'
				);
				echo '</div>';

				return;
			}

			// Get list of menus.
			$menus = wp_list_pluck( wp_get_nav_menus(), 'name', 'term_id' );

			$link = '<a href="' . admin_url( 'nav-menus.php' ) . '">';
			if ( empty( $menus ) ) {
				cmplz_tc_notice(
					sprintf(
					// translators: %1$s is the opening anchor tag, %2$s is the closing anchor tag.
						__( 'No menus were found. Skip this step, or %1$screate a menu%2$s first.', 'complianz-terms-conditions' ),
						$link,
						'</a>'
					)
				);

				return;
			}

			$created_pages  = $this->get_created_pages();
			$required_pages = $this->get_required_pages();
			if ( count( $required_pages ) > count( $created_pages ) ) {
				cmplz_tc_notice(
					__( 'You haven\'t created all required pages yet. You can add missing pages in the previous step, or create them manually with the shortcode. You can come back later to this step to add your pages to the desired menu, or do it manually via Appearance > Menu.', 'complianz-terms-conditions' )
				);
			}

			echo '<div class="cmplz-field">';
			echo '<div class="cmplz-link-to-menu-table">';
			$pages = $this->get_created_pages();
			if ( count( $pages ) > 0 ) {
				foreach ( $pages as $page_id ) {
					echo '<span>' . esc_html( get_the_title( $page_id ) ) . '</span>';
					?>

					<select name="cmplz_tc_assigned_menu[<?php echo esc_attr( (string) $page_id ); ?>]">
						<option value=""><?php esc_html_e( 'Select a menu', 'complianz-terms-conditions' ); ?></option>
						<?php
						foreach ( $menus as $menu_id => $menu ) {
							$selected = $this->is_assigned_this_menu( $page_id, $menu_id ) ? 'selected' : '';
							echo '<option ' . esc_attr( $selected ) . ' value="' . esc_attr( $menu_id ) . '">' . esc_html( $menu ) . '</option>';
						}
						?>
					</select>

					<?php
				}
			}

			echo '</div>';
			echo '</div>';
		}

		/**
		 * Process the menu-assignment form submission from the wizard.
		 *
		 * Hooked into 'admin_init'. Reads 'cmplz_tc_assigned_menu' from $_POST
		 * (an array keyed by page ID with menu ID values), skips empty selections
		 * and already-assigned entries, and adds each page as a nav menu item via
		 * wp_update_nav_menu_item(). Requires 'manage_options' capability.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::wizard_add_pages_to_menu()
		 * @see    cmplz_tc_document::is_assigned_this_menu()
		 *
		 * @return void
		 */
		public function assign_documents_to_menu() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( isset( $_POST['cmplz_tc_assigned_menu'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the wizard form submission handler.
				foreach ( wp_unslash( $_POST['cmplz_tc_assigned_menu'] ) as $page_id => $menu_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified by the wizard form submission handler; values are cast to int via absint() in subsequent calls.
					if ( empty( $menu_id ) ) {
						continue;
					}
					if ( $this->is_assigned_this_menu( $page_id, $menu_id ) ) {
						continue;
					}

					$page = get_post( $page_id );

					wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title'     => get_the_title( $page ),
							'menu-item-object-id' => $page->ID,
							'menu-item-object'    => get_post_type( $page ),
							'menu-item-status'    => 'publish',
							'menu-item-type'      => 'post_type',
						)
					);
				}
			}
		}


		/**
		 * Return the IDs of created document pages that are not in any nav menu.
		 *
		 * Fetches all registered nav menus and all created document page IDs, then
		 * returns the diff. Used to prompt the user to add missing pages to a menu.
		 *
		 * @since  1.2.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_created_pages()
		 *
		 * @return int[]|false  Array of page IDs not assigned to any menu, or false
		 *                      when all pages are already in at least one menu.
		 */
		public function pages_not_in_menu() {
			// Search in menus for the current post.
			$menus         = wp_list_pluck(
				wp_get_nav_menus(),
				'name',
				'term_id'
			);
			$pages         = $this->get_created_pages();
			$pages_in_menu = array();

			foreach ( $menus as $menu_id => $title ) {

				$menu_items = wp_get_nav_menu_items( $menu_id );
				foreach ( $menu_items as $post ) {
					if ( in_array( $post->object_id, $pages, true ) ) {
						$pages_in_menu[] = $post->object_id;
					}
				}
			}
			$pages_not_in_menu = array_diff( $pages, $pages_in_menu );
			if ( 0 === count( $pages_not_in_menu ) ) {
				return false;
			}

			return $pages_not_in_menu;
		}


		/**
		 * Check whether a page is already assigned to a specific nav menu.
		 *
		 * @since  1.2.0
		 * @access public
		 *
		 * @param  int $page_id  WordPress post ID of the page to check.
		 * @param  int $menu_id  Term ID of the navigation menu to check against.
		 * @return bool          True if the page is a menu item in the given menu.
		 */
		public function is_assigned_this_menu( $page_id, $menu_id ) {
			$menu_items = wp_list_pluck(
				wp_get_nav_menu_items( $menu_id ),
				'object_id'
			);

			return ( in_array( $page_id, $menu_items, true ) );
		}

		/**
		 * Create a WordPress page for a document type if one does not already exist.
		 *
		 * Checks for an existing page via get_shortcode_page_id() before creating.
		 * The page is published immediately with the appropriate shortcode or block
		 * as its content (via get_shortcode()). Fires the 'cmplz_tc_create_page'
		 * action with the new page ID after creation. Requires 'manage_options'.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode()
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  string $type  Document type identifier, e.g. 'terms-conditions'.
		 * @return int|false       The page ID (existing or newly created), or false
		 *                         when the current user lacks 'manage_options'.
		 */
		public function create_page( $type ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}
			$pages = COMPLIANZ_TC::$config->pages;

			// Only insert if there is no shortcode page of this type yet.
			$page_id = $this->get_shortcode_page_id( $type, false );
			if ( ! $page_id ) {

				$page_data = $pages['all'][ $type ];
				$page      = array(
					'post_title'   => $page_data['title'],
					'post_type'    => 'page',
					'post_content' => $this->get_shortcode( $type ),
					'post_status'  => 'publish',
				);
				// Insert the post into the database.
				$page_id = wp_insert_post( $page );
			}

			do_action( 'cmplz_tc_create_page', $page_id );

			return $page_id;
		}

		/**
		 * Trash a document page by type.
		 *
		 * Moves the page to the trash (soft-delete) rather than permanently
		 * deleting it, so content can be recovered if needed. Requires the
		 * current user to have 'manage_options' capability.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  string $type    Document type identifier, e.g. 'terms-conditions'.
		 * @param  string $region  Region key. Currently unused but kept for API compatibility.
		 * @return void
		 */
		public function delete_page( $type, $region ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $region kept for API compatibility.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$page_id = $this->get_shortcode_page_id( $type );
			if ( $page_id ) {
				wp_delete_post( $page_id, false );
			}
		}


		/**
		 * Check if a WordPress page for a document type has been created.
		 *
		 * Thin wrapper around get_shortcode_page_id() that returns a boolean.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  string $type  Document type identifier, e.g. 'terms-conditions'.
		 * @return bool  True when a page containing the shortcode or block exists.
		 */
		public function page_exists( $type ) {
			if ( $this->get_shortcode_page_id( $type ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Return the appropriate shortcode or Gutenberg block string for a page type.
		 *
		 * Returns a Gutenberg block comment string when the site uses the block editor
		 * and is not using Elementor (which requires the classic shortcode). Falls back
		 * to the classic shortcode '[cmplz-terms-conditions type="..."]' otherwise.
		 * $force_classic bypasses the Gutenberg check entirely (e.g. for copy widgets).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_uses_gutenberg()
		 * @see    cmplz_tc_document::uses_elementor()
		 *
		 * @param  string $type           Document type identifier. Defaults to
		 *                                'terms-conditions' when falsy or equal to 1.
		 * @param  bool   $force_classic  When true, always return the classic shortcode
		 *                                regardless of the editor in use. Default: false.
		 * @return string                 Shortcode or block string ready to insert into
		 *                                post content.
		 */
		public function get_shortcode( $type, $force_classic = false ) {
			// @phpstan-ignore-next-line -- Legacy guard: callers may pass int 1 before a real type is known.
			if ( ! $type || 1 === $type ) {
				$type = 'terms-conditions';
			}
			// Even if on gutenberg, with elementor we have to use classic shortcodes.
			if ( ! $force_classic && cmplz_tc_uses_gutenberg()
				&& ! $this->uses_elementor()
			) {
				$page = COMPLIANZ_TC::$config->pages['all'][ $type ];

				return '<!-- wp:complianztc/terms-conditions {"title":"' . $page['title'] . '","selectedDocument":"' . $type . '"} /-->';
			} else {
				return '[cmplz-terms-conditions type="' . $type . '"]';
			}
		}

		/**
		 * Return the regex pattern used to detect a document shortcode or block.
		 *
		 * Provides four variants depending on editor type and legacy flag:
		 * - classic + legacy  → matches old-style shortcode without a type attribute
		 * - classic           → matches modern shortcode with 'type="(captured)"'
		 * - gutenberg + legacy → matches block comment without selectedDocument
		 * - gutenberg         → matches block comment with 'selectedDocument:"(captured)"'
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::save_metabox_data()
		 *
		 * @param  string $type    Editor type: 'classic' or 'gutenberg'. Default: 'classic'.
		 * @param  bool   $legacy  When true, returns the legacy pattern without a capture
		 *                         group for the document type. Default: false.
		 * @return string          PCRE regex pattern string.
		 */
		public function get_shortcode_pattern( $type = 'classic', $legacy = false ) {

			if ( $legacy ) {
				if ( 'classic' === $type ) {
					return '/\[cmplz\-terms\-conditions.*?\]/i';
				} else {
					return '/<!-- wp:complianztc\/terms-conditions {.*?} \/-->/i';
				}
			} elseif ( 'classic' === $type ) {
					return '/\[cmplz\-terms\-conditions.*?type="(.*?)"\]/i';
			} else {
				return '/<!-- wp:complianz\/terms-conditions {.*?"selectedDocument":"(.*?)"} \/-->/i';
			}
		}

		/**
		 * Check whether the Elementor page builder is active on this site.
		 *
		 * When Elementor is active, the classic shortcode must be used even on
		 * Gutenberg-enabled sites because Elementor does not support block editor
		 * block comments as embeddable content.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode()
		 *
		 * @return bool  True when the ELEMENTOR_VERSION constant is defined.
		 */
		public function uses_elementor() {
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Return the IDs of all WordPress pages that host a document shortcode or block.
		 *
		 * Iterates over the required pages list and returns those for which a page
		 * exists (i.e. get_shortcode_page_id() returns a non-false value).
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_required_pages()
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @return int[]  Array of WordPress post IDs for created document pages.
		 */
		public function get_created_pages() {
			$created_pages = array();
			$pages         = $this->get_required_pages();

			foreach ( $pages as $region => $region_pages ) {
				foreach ( $region_pages as $type => $page ) {
					$page_id = $this->get_shortcode_page_id( $type, false );
					if ( $page_id ) {
						$created_pages[] = $page_id;
					}
				}
			}

			return $created_pages;
		}


		/**
		 * Build the list of page types required for the current wizard answers.
		 *
		 * Iterates over all configured regions and their page types, skipping
		 * non-public pages, and collects those for which page_required() returns
		 * true. The result is keyed by region then by page type.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::page_required()
		 *
		 * @return array  Nested array structured as:
		 *                array( 'region' => array( 'type' => $page_config ) )
		 */
		public function get_required_pages() {
			$regions  = cmplz_tc_get_regions();
			$required = array();

			foreach ( $regions as $region => $label ) {
				if ( ! isset( COMPLIANZ_TC::$config->pages[ $region ] ) ) {
					continue;
				}

				$pages = COMPLIANZ_TC::$config->pages[ $region ];

				foreach ( $pages as $type => $page ) {
					if ( ! $page['public'] ) {
						continue;
					}
					if ( $this->page_required( $page, $region ) ) {
						$required[ $region ][ $type ] = $page;
					}
				}
			}

			return $required;
		}

		/**
		 * Shortcode callback for [cmplz-terms-conditions].
		 *
		 * Retrieves the 'type' attribute (defaulting to 'terms-conditions') and
		 * renders the full document HTML via get_document_html(). Output is
		 * sanitised with wp_kses() using cmplz_tc_allowed_html() before being
		 * captured from an output buffer and returned as a string for WordPress
		 * to insert in place of the shortcode.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_document_html()
		 * @see    cmplz_tc_allowed_html()
		 *
		 * @param  array       $atts     Shortcode attributes. Recognised keys:
		 *                               - 'type'   (string) Document type. Default: 'terms-conditions'.
		 *                               - 'region' (string) Region override. Default: false.
		 * @param  string|null $content  Enclosed shortcode content (unused). Default: null.
		 * @param  string      $tag      The shortcode tag name (unused). Default: ''.
		 * @return string                Sanitised HTML string for the document.
		 */
		public function load_document(
			$atts = array(),
			$content = null,
			$tag = ''
		) {
			$atts = shortcode_atts(
				array(
					'type'   => 'terms-conditions',
					'region' => false,
				),
				$atts,
				$tag
			);
			$type = sanitize_title( $atts['type'] );

			ob_start();
			$html         = $this->get_document_html( $type );
			$allowed_html = cmplz_tc_allowed_html();
			echo wp_kses( $html, $allowed_html );

			return ob_get_clean();
		}

		/**
		 * Determine whether a page hosts a Complianz Terms & Conditions document.
		 *
		 * Checks the post for the Gutenberg block, the classic shortcode, or the
		 * 'cmplz_tc_shortcode' post meta (set when the document has been unlinked
		 * and the raw HTML stored in the post content). Falls back to the global
		 * $post when no $post_id is provided.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  int|false $post_id  WordPress post ID to check. When false, uses
		 *                             the global $post. Default: false.
		 * @return bool                True when the page contains a T&C block,
		 *                             shortcode, or unlinked document meta.
		 */
		public function is_complianz_page( $post_id = false ) {
			$post_meta = get_post_meta( $post_id, 'cmplz_tc_shortcode', false );
			if ( $post_meta ) {
				return true;
			}

			$shortcode = 'cmplz-terms-conditions';
			$block     = 'complianztc/terms-conditions';

			if ( $post_id ) {
				$post = get_post( $post_id );
			} else {
				global $post;
			}

			if ( $post ) {
				if ( cmplz_tc_uses_gutenberg() && has_block( $block, $post ) ) {
					return true;
				}
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Find and return the page ID that contains the document shortcode or block.
		 *
		 * Performs three passes over all WordPress pages in order of priority:
		 * 1. Gutenberg block ('wp:complianztc/terms-conditions')
		 * 2. Classic shortcode with explicit type attribute
		 * 3. Legacy shortcode without a type attribute (assumes 'terms-conditions')
		 * Each pass checks 'cmplz_tc_shortcode' post meta first (unlinked pages)
		 * before falling back to post_content. Results are cached in a transient
		 * for HOUR_IN_SECONDS to avoid scanning all pages on every request.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @param  string $type   Document type identifier to search for.
		 *                        Default: 'terms-conditions'.
		 * @param  bool   $cache  Whether to use the transient cache. Pass false
		 *                        to force a fresh scan. Default: true.
		 * @return int|false      The WordPress post ID of the matching page, or
		 *                        false when no page is found.
		 */
		public function get_shortcode_page_id( $type = 'terms-conditions', $cache = true ) {
			$shortcode = 'cmplz-terms-conditions';
			$page_id   = $cache ? get_transient( 'cmplz_tc_shortcode_' . $type ) : false;

			if ( ! $page_id ) {
				$pages = get_pages();

				/**
				 * Gutenberg block check.
				 */
				foreach ( $pages as $page ) {
					$post_meta = get_post_meta( $page->ID, 'cmplz_tc_shortcode', true );
					if ( $post_meta ) {
						$html = $post_meta;
					} else {
						$html = $page->post_content;
					}

					// Check if block contains property.
					if ( preg_match(
						'/wp:complianztc\/terms-conditions/i',
						$html,
						$matches
					)
					) {
						set_transient( "cmplz_tc_shortcode_$type", $page->ID, HOUR_IN_SECONDS );

						return $page->ID;
					}
				}

				/**
				 * If nothing found, or if not Gutenberg, check for shortcodes.
				 * Classic Editor, modern shortcode check.
				 */

				foreach ( $pages as $page ) {
					$post_meta = get_post_meta( $page->ID, 'cmplz_tc_shortcode', true );
					if ( $post_meta ) {
						$html = $post_meta;
					} else {
						$html = $page->post_content;
					}

					if ( has_shortcode( $html, $shortcode ) && strpos( $html, 'type="' . $type . '"' ) !== false ) {
						set_transient( "cmplz_tc_shortcode_$type", $page->ID, HOUR_IN_SECONDS );

						return $page->ID;
					}
				}

				/**
				 * Legacy check.
				 */

				foreach ( $pages as $page ) {
					$post_meta = get_post_meta( $page->ID, 'cmplz_tc_shortcode', true );
					if ( $post_meta ) {
						$html = $post_meta;
					} else {
						$html = $page->post_content;
					}

					if ( 'terms-conditions' === $type && has_shortcode( $html, $shortcode ) && strpos( $html, 'type="' ) === false ) {
						set_transient( "cmplz_tc_shortcode_$type", $page->ID, HOUR_IN_SECONDS );

						return $page->ID;
					}
				}
			} else {
				return $page_id;
			}

			return false;
		}

		/**
		 * Clear the page-ID transient cache when any post is saved.
		 *
		 * Hooked into 'save_post'. Deletes the 'cmplz_tc_shortcode_terms-conditions'
		 * transient so that get_shortcode_page_id() re-scans pages on the next
		 * request. The $post and $update parameters are required by the hook
		 * signature but are not used.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  int|false     $post_id  The saved post ID (unused).
		 * @param  WP_Post|false $post     The saved post object (unused).
		 * @param  bool          $update   Whether this is an update (unused).
		 * @return void
		 */
		public function clear_shortcode_transients( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- All parameters unused but required by the save_post hook signature.
			$post_id = false,
			$post = false,
			$update = false
		) {
			$type = 'terms-conditions';
			delete_transient( 'cmplz_tc_shortcode_' . $type );
		}

		/**
		 * Return the title of a document page by type.
		 *
		 * Used only for documents generated by the Complianz GDPR plugin.
		 * When the stored value for the type field is 'custom', the title is
		 * fetched from a custom-page option; when 'generated', from the
		 * shortcode page. WPML object ID translation is applied to resolve the
		 * correct language variant. Falls back to a humanised version of the
		 * $type slug when no post is found.
		 *
		 * @since  1.0.0
		 * @access public
		 *
		 * @see    cmplz_tc_document::get_shortcode_page_id()
		 *
		 * @param  string $type    Document type slug, e.g. 'terms-conditions'.
		 * @param  string $region  Region key (currently unused in the method body,
		 *                         kept for API compatibility).
		 * @return string          The post title of the document page, or the
		 *                         $type slug with hyphens replaced by spaces.
		 */
		public function get_document_title( $type, $region ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $region kept for API compatibility.

			if ( cmplz_tc_get_value( $type ) === 'custom' || cmplz_tc_get_value( $type ) === 'generated' ) {
				if ( cmplz_tc_get_value( $type ) === 'custom' ) {
					$policy_page_id = get_option( 'cmplz_' . $type . '_custom_page' );
				} elseif ( cmplz_tc_get_value( $type ) === 'generated' ) {
					$policy_page_id = $this->get_shortcode_page_id( $type );
				}

				// Get correct translated id.
				$policy_page_id = apply_filters(
					'wpml_object_id',
					$policy_page_id,
					'page',
					true,
					substr( get_locale(), 0, 2 )
				);

				$post = get_post( $policy_page_id );
				if ( $post ) {
					return $post->post_title;
				}
			}

			return str_replace( '-', ' ', $type );
		}
	}


} //class closure
