<?php
/**
 * Release tests for 1.4.0 (Task 11: i18n, readme & end-to-end verification).
 *
 * Guards the release-mechanics that are easy to forget and hard to catch by eye:
 * the version constant and plugin header agree on 1.4.0, readme.txt's Stable tag
 * tracks the constant, the regenerated .pot captured the new withdrawal strings,
 * and the 1.3.1 -> 1.4.0 upgrade runs both migrations (legacy PDF cleanup +
 * own-link pin) and then advances the stored version so they stop re-firing.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group release-1-4-0
 */
class Test_Release_1_4_0 extends WP_UnitTestCase {

	/**
	 * Terms & Conditions options row read/written by the upgrade migrations.
	 *
	 * @var string
	 */
	private $options_key = 'complianz_tc_options_terms-conditions';

	/**
	 * Absolute path to the (legacy) generated-withdrawal-forms directory.
	 *
	 * @var string
	 */
	private $withdrawal_dir;

	public function set_up() {
		parent::set_up();
		$uploads              = wp_upload_dir();
		$this->withdrawal_dir = $uploads['basedir'] . '/complianz/withdrawal-forms';
	}

	public function tear_down() {
		// Files are not rolled back with the DB transaction; clean up defensively.
		if ( is_dir( $this->withdrawal_dir ) ) {
			foreach ( (array) glob( $this->withdrawal_dir . '/*' ) as $file ) {
				wp_delete_file( $file );
			}
			rmdir( $this->withdrawal_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of an uploads subdirectory.
		}
		parent::tear_down();
	}

	/**
	 * Return the admin controller (mirrors the other upgrade tests).
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
	 * The plugin header "Version:" value (the canonical release version).
	 *
	 * @return string
	 */
	private function header_version() {
		$data = get_file_data( cmplz_tc_plugin_file, array( 'Version' => 'Version' ) );
		return $data['Version'];
	}

	/**
	 * The .pot content with gettext line-continuations collapsed, so a full
	 * msgid can be matched even when make-pot wraps it across several lines.
	 *
	 * @return string
	 */
	private function normalized_pot() {
		$pot = file_get_contents( cmplz_tc_path . 'languages/complianz-terms-conditions.pot' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a shipped source file in a test.
		// Join adjacent quoted fragments: `"a "` \n `"b"` -> `"a b"`.
		return (string) preg_replace( '/"\s*\n\s*"/', '', (string) $pot );
	}

	// ---------------------------------------------------------------------
	// Version constant + plugin header.
	// ---------------------------------------------------------------------

	/** The plugin header and the runtime constant must both read 1.4.0. */
	public function test_version_constant_and_header_are_1_4_0() {
		$this->assertSame( '1.4.0', $this->header_version(), 'Plugin header Version: must be 1.4.0.' );
		// The constant may carry a SCRIPT_DEBUG cache-buster suffix; match the base.
		$this->assertSame(
			0,
			strpos( cmplz_tc_version, '1.4.0' ),
			'cmplz_tc_version must start with the header version 1.4.0.'
		);
	}

	// ---------------------------------------------------------------------
	// readme.txt.
	// ---------------------------------------------------------------------

	/** readme.txt's Stable tag must track the version, and a 1.4.0 changelog block must exist. */
	public function test_readme_stable_tag_and_changelog_track_version() {
		$readme = file_get_contents( cmplz_tc_path . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a shipped source file in a test.
		$this->assertIsString( $readme );

		$this->assertSame(
			1,
			preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $m ),
			'readme.txt must declare a Stable tag.'
		);
		$this->assertSame( $this->header_version(), $m[1], 'readme Stable tag must match the plugin version.' );

		$this->assertMatchesRegularExpression(
			'/^=\s*1\.4\.0\s*=$/m',
			$readme,
			'readme.txt changelog must have a = 1.4.0 = section.'
		);
	}

	// ---------------------------------------------------------------------
	// i18n: the regenerated .pot must carry the new withdrawal strings.
	// ---------------------------------------------------------------------

	/** New translatable strings from Tasks 2b/7/9 must be present in the .pot. */
	public function test_pot_contains_new_withdrawal_strings() {
		$pot = $this->normalized_pot();

		$expected = array(
			// Generator wording (Task 2b).
			'Use the Complianz withdrawal form',
			'Link to my own withdrawal function',
			// Submission validation + confirmation (Task 7).
			'Please enter a valid email address.',
			'Withdrawal request sent',
			// Consumer acknowledgement + delivery-failure UX (Task 9).
			'This is an automated confirmation that your withdrawal request has been received and sent to the merchant.',
			'Something went wrong and we could not deliver your withdrawal request. Please contact the merchant directly to complete your withdrawal:',
		);

		foreach ( $expected as $string ) {
			$this->assertStringContainsString(
				'msgid "' . $string . '"',
				$pot,
				'The .pot is missing a new string; regenerate it: ' . $string
			);
		}
	}

	// ---------------------------------------------------------------------
	// End-to-end upgrade: 1.3.1 -> 1.4.0 (spec §13 scenarios 5 & 9).
	// ---------------------------------------------------------------------

	/**
	 * Upgrading a fast-fix install from 1.3.1 must, in one check_upgrade():
	 *  - purge the legacy PDF queue option + withdrawal-forms dir (Task 10, §13.5),
	 *  - pin a saved own-link install to the own-link path (Task 2, §13.9),
	 *  - advance the stored version to 1.4.0 so the < 1.4.0 blocks stop re-firing.
	 */
	public function test_upgrade_from_1_3_1_runs_both_migrations_and_advances_version() {
		// Legacy PDF-cleanup fixtures (§13 scenario 5).
		update_option( 'cmplz_generate_pdf_languages', array( 'en_US' => 1 ) );
		wp_mkdir_p( $this->withdrawal_dir );
		file_put_contents( $this->withdrawal_dir . '/withdrawal-form-en_US.pdf', '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a stale PDF fixture in a test.

		// Own-link fast-fix install (§13 scenario 9): saved URL, no explicit choice.
		update_option(
			$this->options_key,
			array(
				'if_returns'             => 'yes',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);

		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		// Task 10 cleanup fired.
		$this->assertFalse(
			get_option( 'cmplz_generate_pdf_languages' ),
			'Upgrade must delete the legacy cmplz_generate_pdf_languages option.'
		);
		$this->assertDirectoryDoesNotExist(
			$this->withdrawal_dir,
			'Upgrade must purge the stale withdrawal-forms directory.'
		);

		// Task 2 own-link pin fired.
		$options = get_option( $this->options_key );
		$this->assertSame(
			'yes',
			$options['if_returns_custom'],
			'A saved own-link URL must keep the install on the own-link path.'
		);

		// Version advanced so the < 1.4.0 blocks stop re-firing next request.
		$this->assertSame(
			0,
			strpos( (string) get_option( 'cmplz-tc-current-version' ), '1.4.0' ),
			'check_upgrade must store the new 1.4.0 version.'
		);
	}
}
