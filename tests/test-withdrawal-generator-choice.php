<?php
/**
 * Tests for the withdrawal generator-mechanism choice + upgrade migration (FR-1–FR-5):
 * the restored if_returns_custom question, the mutual-exclusivity gating of the own-link
 * URL and notification-email fields, and the migration that keeps fast-fix installs with
 * a saved own-link URL on the own-link path.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-generator-choice
 */
class Test_Withdrawal_Generator_Choice extends WP_UnitTestCase {

	/**
	 * The T&C wizard options key.
	 *
	 * @var string
	 */
	private $options_key = 'complianz_tc_options_terms-conditions';

	/**
	 * Return the admin controller.
	 *
	 * is_admin() is false during tests, so the plugin does not instantiate the
	 * admin singleton at bootstrap; create it once and reuse it thereafter.
	 *
	 * @return cmplz_tc_admin
	 */
	private function get_admin() {
		if ( ! class_exists( 'cmplz_tc_admin' ) ) {
			require_once dirname( __DIR__ ) . '/class-admin.php';
		}
		$admin = cmplz_tc_admin::this();
		if ( ! $admin ) {
			$admin = new cmplz_tc_admin();
		}
		return $admin;
	}

	/**
	 * Fetch a single field definition from the loaded config.
	 *
	 * @param string $fieldname The field key.
	 * @return array|null The field definition, or null when not registered.
	 */
	private function get_field( $fieldname ) {
		$fields = COMPLIANZ_TC::$config->fields;
		return isset( $fields[ $fieldname ] ) ? $fields[ $fieldname ] : null;
	}

	/**
	 * Return the field controller (get_value lives here; the wizard reads through it).
	 *
	 * is_admin() is false during tests, so cmplz_tc_field is not instantiated at
	 * bootstrap; create it once and reuse it thereafter.
	 *
	 * @return cmplz_tc_field
	 */
	private function field_controller() {
		if ( ! class_exists( 'cmplz_tc_field' ) ) {
			require_once dirname( __DIR__ ) . '/class-field.php';
		}
		$field = cmplz_tc_field::this();
		if ( ! $field ) {
			$field = new cmplz_tc_field();
		}
		return $field;
	}

	// ---------------------------------------------------------------------
	// FR-1 — the restored generator choice.
	// ---------------------------------------------------------------------

	/** The if_returns_custom question must be registered (no longer commented out). */
	public function test_if_returns_custom_question_is_registered() {
		$field = $this->get_field( 'if_returns_custom' );
		$this->assertIsArray( $field, 'if_returns_custom must be registered in the config.' );
		$this->assertSame( 'radio', $field['type'], 'if_returns_custom must be a radio.' );
		$this->assertSame(
			array( 'no', 'yes' ),
			array_keys( $field['options'] ),
			'if_returns_custom must keep the no/yes values (polarity + clause conditions), Complianz-form ("no") listed first.'
		);
	}

	/** The question must only appear when returns/withdrawals are offered. */
	public function test_if_returns_custom_is_conditional_on_returns() {
		$field = $this->get_field( 'if_returns_custom' );
		$this->assertSame(
			array( 'if_returns' => 'yes' ),
			$field['condition'],
			'if_returns_custom must be shown only when if_returns = yes.'
		);
	}

	/** FR-1: the label must be reworded away from the legacy PDF-era phrasing. */
	public function test_if_returns_custom_label_is_reworded() {
		$field = $this->get_field( 'if_returns_custom' );
		$this->assertStringNotContainsStringIgnoringCase(
			'custom withdrawal form',
			$field['label'],
			'The restored label must not reuse the legacy PDF-era "custom withdrawal form" wording.'
		);
		$this->assertStringContainsStringIgnoringCase(
			'withdrawal function',
			$field['label'],
			'The restored label must be framed around the withdrawal-function choice (FR-1).'
		);
	}

	// ---------------------------------------------------------------------
	// FR-2 — compliant default (Complianz form) for fresh configurations.
	// ---------------------------------------------------------------------

	/** A fresh configuration must default to the Complianz-provided form. */
	public function test_default_is_the_complianz_form() {
		$field = $this->get_field( 'if_returns_custom' );
		$this->assertSame(
			'no',
			$field['default'],
			'The default must be "no" (use the Complianz form) so a merchant who changes nothing is compliant.'
		);
	}

	/** With nothing stored, the runtime value resolves to the compliant default. */
	public function test_fresh_config_resolves_to_form_path_at_runtime() {
		delete_option( $this->options_key );
		$this->assertSame(
			'no',
			cmplz_tc_get_value( 'if_returns_custom' ),
			'A fresh install with no stored answer must resolve to the Complianz-form path.'
		);
	}

	// ---------------------------------------------------------------------
	// FR-3 / FR-4 / FR-5 — path-exclusive dependent fields.
	// ---------------------------------------------------------------------

	/** FR-3/FR-5: the own-link URL is required only on the own-link path. */
	public function test_own_link_field_is_gated_to_own_link_path() {
		$field = $this->get_field( 'if_returns_custom_link' );
		$this->assertIsArray( $field );
		$this->assertSame(
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'yes',
			),
			$field['condition'],
			'The own-link URL must be shown/required only when the merchant chooses their own link.'
		);
		$this->assertTrue( (bool) $field['required'], 'The own-link URL must stay required when shown.' );
	}

	/** FR-4/FR-5: the notification email is captured only on the Complianz-form path. */
	public function test_notification_email_field_is_gated_to_form_path() {
		$field = $this->get_field( 'withdrawal_notification_email' );
		$this->assertIsArray( $field, 'withdrawal_notification_email must be registered.' );
		$this->assertSame( 'email', $field['type'], 'The notification recipient must be an email field.' );
		$this->assertTrue( (bool) $field['required'], 'The notification email must be required on the form path.' );
		$this->assertSame(
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'no',
			),
			$field['condition'],
			'The notification email must be shown only when the Complianz form is used.'
		);
	}

	/** FR-4: the notification recipient defaults to the site administrator address. */
	public function test_notification_email_defaults_to_admin_email() {
		$field = $this->get_field( 'withdrawal_notification_email' );
		$this->assertSame(
			get_option( 'admin_email' ),
			$field['default'],
			'The notification recipient must default to the site administrator address.'
		);
	}

	// ---------------------------------------------------------------------
	// FR-2 — upgrade migration (fast fix -> restored choice).
	// ---------------------------------------------------------------------

	/** A fast-fix install with a saved own-link URL must stay on the own-link path. */
	public function test_migration_pins_saved_own_link_installs_to_own_link_path() {
		update_option(
			$this->options_key,
			array(
				'if_returns'             => 'yes',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);
		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		$options = get_option( $this->options_key );
		$this->assertSame(
			'yes',
			$options['if_returns_custom'],
			'A saved own-link URL must migrate the install to the own-link path.'
		);
	}

	/** An install with no saved own-link URL must fall through to the form default. */
	public function test_migration_leaves_form_installs_on_the_default_path() {
		update_option(
			$this->options_key,
			array(
				'if_returns'             => 'yes',
				'if_returns_custom_link' => '',
			)
		);
		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		$options = get_option( $this->options_key );
		$this->assertArrayNotHasKey(
			'if_returns_custom',
			$options,
			'With no saved own-link URL the migration must not pin a path; the compliant default applies.'
		);
		$this->assertSame( 'no', cmplz_tc_get_value( 'if_returns_custom' ) );
	}

	/** The migration must never overwrite a choice the merchant has already made. */
	public function test_migration_does_not_clobber_an_explicit_choice() {
		update_option(
			$this->options_key,
			array(
				'if_returns'             => 'yes',
				'if_returns_custom'      => 'no',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);
		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		$options = get_option( $this->options_key );
		$this->assertSame(
			'no',
			$options['if_returns_custom'],
			'An explicit stored choice must be preserved by the migration.'
		);
	}

	/** The migration must be a no-op on a fresh install (no stored options). */
	public function test_migration_is_a_noop_on_fresh_install() {
		delete_option( $this->options_key );
		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		$this->assertFalse(
			get_option( $this->options_key ),
			'The migration must not create the options row on a fresh install.'
		);
	}

	// ---------------------------------------------------------------------
	// Task 2b — reworded mechanism options (Complianz form listed first).
	// ---------------------------------------------------------------------

	/** The mechanism must use bespoke labels (not the generic Yes/No pair). */
	public function test_mechanism_options_are_reworded_not_generic_yes_no() {
		$field = $this->get_field( 'if_returns_custom' );
		$this->assertStringContainsStringIgnoringCase(
			'complianz',
			$field['options']['no'],
			'The "no" option must describe the Complianz withdrawal form, not read "No".'
		);
		$this->assertStringContainsStringIgnoringCase(
			'own',
			$field['options']['yes'],
			'The "yes" option must describe linking to the merchant\'s own function, not read "Yes".'
		);
	}

	/** The compliant default (Complianz form = "no") must be listed first. */
	public function test_mechanism_lists_complianz_form_first() {
		$field = $this->get_field( 'if_returns_custom' );
		$keys  = array_keys( $field['options'] );
		$this->assertSame( 'no', $keys[0], 'The Complianz-form option must render first so the compliant default leads.' );
	}

	// ---------------------------------------------------------------------
	// Task 2b — no-storage / SMTP note on the recipient field (form path).
	// ---------------------------------------------------------------------

	/** The recipient field's help note must cover the no-storage fact and the SMTP requirement. */
	public function test_notification_email_help_covers_storage_and_smtp() {
		$field = $this->get_field( 'withdrawal_notification_email' );
		$this->assertNotEmpty( $field['help'], 'The no-storage/SMTP note lives in the recipient field help (blue sidebar).' );
		$this->assertStringContainsStringIgnoringCase( 'not stored', $field['help'], 'The note must state that requests are not stored.' );
		$this->assertStringContainsStringIgnoringCase( 'SMTP', $field['help'], 'The note must tell the merchant to configure SMTP.' );
	}

	// ---------------------------------------------------------------------
	// Task 2b — notification-email default prefers the general contact email.
	// ---------------------------------------------------------------------

	/** The default-recipient helper prefers the general contact email when set. */
	public function test_notification_default_prefers_company_email() {
		update_option( $this->options_key, array( 'email_company' => 'shop@example.test' ) );
		$this->assertSame(
			'shop@example.test',
			cmplz_tc_default_withdrawal_notification_email(),
			'When a general contact email is set, it must be the default withdrawal recipient.'
		);
	}

	/** The default-recipient helper falls back to the admin email when no contact email is set. */
	public function test_notification_default_falls_back_to_admin_email() {
		delete_option( $this->options_key );
		$this->assertSame(
			get_option( 'admin_email' ),
			cmplz_tc_default_withdrawal_notification_email(),
			'With no general contact email, the recipient must fall back to the site administrator address.'
		);
	}

	// ---------------------------------------------------------------------
	// Task 2b — recipient is never empty (read-time resolution, both paths).
	// ---------------------------------------------------------------------

	/** A stored-empty recipient resolves to the general contact email on read. */
	public function test_empty_recipient_resolves_to_company_email() {
		update_option(
			$this->options_key,
			array(
				'withdrawal_notification_email' => '',
				'email_company'                 => 'shop@example.test',
			)
		);
		$this->assertSame( 'shop@example.test', cmplz_tc_get_value( 'withdrawal_notification_email' ), 'Document/email reads must resolve empty to the contact email.' );
		$this->assertSame( 'shop@example.test', $this->field_controller()->get_value( 'withdrawal_notification_email' ), 'The wizard field must resolve empty to the contact email.' );
	}

	/** A stored-empty recipient with no contact email resolves to the admin email — never empty. */
	public function test_empty_recipient_resolves_to_admin_email_without_company_email() {
		update_option(
			$this->options_key,
			array(
				'withdrawal_notification_email' => '',
				'email_company'                 => '',
			)
		);
		$this->assertSame( get_option( 'admin_email' ), cmplz_tc_get_value( 'withdrawal_notification_email' ), 'Document/email reads must never be empty.' );
		$this->assertSame( get_option( 'admin_email' ), $this->field_controller()->get_value( 'withdrawal_notification_email' ), 'The wizard field must never be empty.' );
	}

	/** A real stored recipient is returned unchanged (resolution only fills empties). */
	public function test_stored_recipient_is_returned_unchanged() {
		update_option(
			$this->options_key,
			array(
				'withdrawal_notification_email' => 'orders@merchant.test',
				'email_company'                 => 'shop@example.test',
			)
		);
		$this->assertSame( 'orders@merchant.test', cmplz_tc_get_value( 'withdrawal_notification_email' ) );
		$this->assertSame( 'orders@merchant.test', $this->field_controller()->get_value( 'withdrawal_notification_email' ) );
	}
}
