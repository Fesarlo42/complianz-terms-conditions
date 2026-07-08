<?php
/**
 * Tests for the interactive withdrawal-form template + its front-end asset enqueue
 * (FR-10, FR-11, FR-12, NFR-A1, NFR-A2, NFR-I1).
 *
 * The template must render the EU §8 fields (name/email/goods required; address,
 * order reference, order date and message optional), the pre-filled non-editable
 * merchant heading, and the three hidden integrity placeholders (nonce, honeypot,
 * render timestamp) consumed later by Task 7. Labels must be programmatically
 * associated, the required state and validation errors announced, all strings
 * translatable, and the theme-override path preserved. The form's CSS/JS must
 * enqueue on the Withdrawal page only.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-form-template
 */
class Test_Withdrawal_Form_Template extends WP_UnitTestCase {

	/** Render the bundled template with the given args. */
	private function render( $args = array() ) {
		return cmplz_tc_get_template( 'withdrawal-form.php', $args );
	}

	/** The document controller under test. */
	private function doc() {
		return COMPLIANZ_TC::$document;
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Style/script registries are global singletons; reset so enqueue state
		// does not leak between tests.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	public function tear_down() {
		delete_option( 'cmplz_tc_withdrawal_page_id' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// FR-10 — the §8 fields render, with the correct required/optional split.
	// ---------------------------------------------------------------------

	/** The template must resolve through the (overridable) loader, not be missing. */
	public function test_template_renders() {
		$html = $this->render();
		$this->assertIsString( $html, 'The withdrawal-form template must exist and render.' );
		$this->assertStringContainsString( '<form', $html );
		$this->assertMatchesRegularExpression( '/<form[^>]*method=["\']post["\']/i', $html, 'The form must POST.' );
	}

	/** Name, email and goods/service are the Art. 11a required fields. */
	public function test_required_fields_are_present_and_marked_required() {
		$html = $this->render();
		foreach ( array( 'cmplz_tc_wf_name', 'cmplz_tc_wf_email', 'cmplz_tc_wf_goods' ) as $name ) {
			$this->assertMatchesRegularExpression(
				'/(name|id)=["\'][^"\']*' . preg_quote( $name, '/' ) . '/i',
				$html,
				"Required field {$name} must be present."
			);
		}
		// The email field is a proper email input.
		$this->assertMatchesRegularExpression( '/<input[^>]*type=["\']email["\']/i', $html, 'Email must use type=email.' );
		// Each required control carries the required + aria-required state.
		$this->assertSame( 3, substr_count( $html, 'aria-required="true"' ), 'Exactly the three Art. 11a fields must be required.' );
	}

	/** Address, order reference, order date and message are optional. */
	public function test_optional_fields_are_present_and_not_required() {
		$html = $this->render();
		foreach ( array( 'cmplz_tc_wf_address', 'cmplz_tc_wf_order_ref', 'cmplz_tc_wf_order_date', 'cmplz_tc_wf_message' ) as $name ) {
			$this->assertMatchesRegularExpression(
				'/(name|id)=["\'][^"\']*' . preg_quote( $name, '/' ) . '/i',
				$html,
				"Optional field {$name} must be present."
			);
		}
		$this->assertMatchesRegularExpression( '/<input[^>]*type=["\']date["\']/i', $html, 'The order date must use type=date.' );
	}

	/** Every visible control must have a programmatically associated label (NFR-A1). */
	public function test_every_field_has_an_associated_label() {
		$html = $this->render();
		// Collect all input/textarea ids, then assert a <label for="id"> exists for each.
		preg_match_all( '/<(?:input|textarea)[^>]*\bid=["\']([^"\']+)["\']/i', $html, $matches );
		$ids = array_filter(
			$matches[1],
			static function ( $id ) {
				// The honeypot is intentionally hidden from assistive tech (aria-hidden).
				return 'cmplz-tc-wf-website' !== $id;
			}
		);
		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression(
				'/<label[^>]*\bfor=["\']' . preg_quote( $id, '/' ) . '["\']/i',
				$html,
				"Field #{$id} must have an associated <label for>."
			);
		}
	}

	// ---------------------------------------------------------------------
	// Hidden integrity placeholders (rendered here, consumed by Task 7).
	// ---------------------------------------------------------------------

	/** The nonce, honeypot and render-timestamp placeholders must be rendered. */
	public function test_hidden_integrity_fields_are_rendered() {
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/name=["\']cmplz_tc_wf_nonce["\']/', $html, 'Nonce placeholder must be present.' );
		$this->assertMatchesRegularExpression( '/name=["\']cmplz_tc_wf_rendered["\']/', $html, 'Render-timestamp placeholder must be present.' );
		$this->assertMatchesRegularExpression( '/name=["\']cmplz_tc_wf_website["\']/', $html, 'Honeypot field must be present.' );
	}

	/** The honeypot must be hidden from users and assistive tech, and not autofilled. */
	public function test_honeypot_is_hidden_from_assistive_tech() {
		$html = $this->render();
		$this->assertMatchesRegularExpression(
			'/<input[^>]*name=["\']cmplz_tc_wf_website["\'][^>]*>/i',
			$html
		);
		// The wrapper or field must be aria-hidden, out of the tab order and not autocompleted.
		$this->assertStringContainsString( 'tabindex="-1"', $html, 'Honeypot must be out of the tab order.' );
		$this->assertStringContainsString( 'autocomplete="off"', $html, 'Honeypot must not be autofilled.' );
		$this->assertStringContainsString( 'aria-hidden="true"', $html, 'Honeypot must be hidden from assistive tech.' );
	}

	// ---------------------------------------------------------------------
	// FR-10 — merchant identity is a pre-filled, NON-editable heading.
	// ---------------------------------------------------------------------

	public function test_merchant_identity_renders_as_non_editable_heading() {
		$identity = "ACME Ltd\n1 Market Street\nBrussels";
		$html     = $this->render( array( 'merchant_identity' => $identity ) );
		$this->assertStringContainsString( 'ACME Ltd', $html, 'The merchant identity must be shown.' );
		// It must be plain text, never an editable input/textarea value.
		$this->assertDoesNotMatchRegularExpression(
			'/<(?:input|textarea)[^>]*ACME Ltd/i',
			$html,
			'The merchant identity must be a non-editable heading, not a form field.'
		);
	}

	// ---------------------------------------------------------------------
	// NFR-A1 — errors are programmatically associated and announced; values kept.
	// ---------------------------------------------------------------------

	public function test_errors_are_announced_and_values_preserved() {
		$html = $this->render(
			array(
				'errors' => array( 'cmplz_tc_wf_email' => 'Please enter a valid email address.' ),
				'values' => array( 'cmplz_tc_wf_name' => 'Jane Doe' ),
			)
		);
		// An assertive live region announces the error summary.
		$this->assertMatchesRegularExpression( '/role=["\']alert["\']/', $html, 'An error summary must be announced (role=alert).' );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
		// The invalid field is flagged and points at its message.
		$this->assertMatchesRegularExpression(
			'/<input[^>]*id=["\']cmplz-tc-wf-email["\'][^>]*aria-invalid=["\']true["\']/i',
			$html,
			'The invalid field must carry aria-invalid=true.'
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]*id=["\']cmplz-tc-wf-email["\'][^>]*aria-describedby=["\'][^"\']*cmplz-tc-wf-email-error/i',
			$html,
			'The invalid field must be described by its error message.'
		);
		// The consumer\'s entered value is preserved (PRG).
		$this->assertMatchesRegularExpression(
			'/<input[^>]*id=["\']cmplz-tc-wf-name["\'][^>]*value=["\']Jane Doe["\']/i',
			$html,
			'Entered values must be preserved on re-render.'
		);
	}

	/** Passing array args must not leak a literal token or an "Array" cast. */
	public function test_array_args_do_not_leak() {
		$html = $this->render(
			array(
				'errors' => array( 'cmplz_tc_wf_email' => 'Bad email.' ),
				'values' => array( 'cmplz_tc_wf_name' => 'Jane' ),
			)
		);
		$this->assertStringNotContainsString( '{errors}', $html );
		$this->assertStringNotContainsString( '{values}', $html );
		$this->assertStringNotContainsString( 'Array', $html, 'Array args must not be cast into the output.' );
	}

	// ---------------------------------------------------------------------
	// NFR-I1 — all strings are translatable in the plugin text domain.
	// ---------------------------------------------------------------------

	public function test_strings_are_translatable() {
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				if ( 'complianz-terms-conditions' === $domain && 'Email address' === $text ) {
					return 'ZZ_TRANSLATED_EMAIL';
				}
				return $translation;
			},
			10,
			3
		);
		$html = $this->render();
		$this->assertStringContainsString( 'ZZ_TRANSLATED_EMAIL', $html, 'Form labels must be translatable in the plugin text domain.' );
	}

	// ---------------------------------------------------------------------
	// Theme-override path preserved (loads via cmplz_tc_get_template).
	// ---------------------------------------------------------------------

	public function test_theme_override_path_is_preserved() {
		$fixture = wp_tempnam( 'cmplz-wf-override' ) . '.php';
		file_put_contents( $fixture, '<p>OVERRIDDEN WITHDRAWAL FORM</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$filter = static function ( $file, $filename ) use ( $fixture ) {
			return 'withdrawal-form.php' === $filename ? $fixture : $file;
		};
		add_filter( 'cmplz_tc_template_file', $filter, 10, 2 );
		$html = $this->render();
		remove_filter( 'cmplz_tc_template_file', $filter, 10 );
		wp_delete_file( $fixture );

		$this->assertStringContainsString( 'OVERRIDDEN WITHDRAWAL FORM', $html, 'The template must load through the overridable loader.' );
	}

	// ---------------------------------------------------------------------
	// Asset enqueue — form CSS/JS load on the Withdrawal page only.
	// ---------------------------------------------------------------------

	public function test_form_assets_enqueue_on_the_withdrawal_page() {
		$page_id = $this->doc()->create_page( 'withdrawal' );
		$this->go_to( get_permalink( $page_id ) );

		$this->doc()->enqueue_assets();

		$this->assertTrue( wp_style_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form stylesheet must enqueue on the Withdrawal page.' );
		$this->assertTrue( wp_script_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form script must enqueue on the Withdrawal page.' );
	}

	public function test_form_assets_do_not_enqueue_elsewhere() {
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $other ) );

		$this->doc()->enqueue_assets();

		$this->assertFalse( wp_style_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form stylesheet must not load off the Withdrawal page.' );
		$this->assertFalse( wp_script_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form script must not load off the Withdrawal page.' );
	}
}
