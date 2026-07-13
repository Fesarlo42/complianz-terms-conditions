<?php
/**
 * Tests for the legacy withdrawal-form PDF removal + upgrade cleanup.
 *
 * Covers the "Legacy PDF removal + upgrade cleanup" task: the old withdrawal-form
 * PDF generation path (maybe_generate_withdrawal_form / generate_withdrawal_form,
 * the cmplz_generate_pdf_languages seeding, and stale generated PDFs) must be gone,
 * while the Terms & Conditions document PDF (generate_pdf + download.php) must keep
 * working unchanged.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-pdf-removal
 */
class Test_Withdrawal_Pdf_Removal extends WP_UnitTestCase {

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
	 * Return the admin controller.
	 *
	 * is_admin() is false during tests, so the plugin does not instantiate the
	 * admin singleton at bootstrap; create it once and reuse it thereafter.
	 *
	 * @return cmplz_tc_admin
	 */
	private function get_admin() {
		// Admin-only files are not loaded on non-admin requests (tests included).
		if ( ! class_exists( 'cmplz_tc_admin' ) ) {
			require_once dirname( __DIR__ ) . '/class-admin.php';
		}
		$admin = cmplz_tc_admin::this();
		if ( ! $admin ) {
			$admin = new cmplz_tc_admin();
		}
		return $admin;
	}

	// ---------------------------------------------------------------------
	// Removal of the legacy withdrawal-form PDF generation.
	// ---------------------------------------------------------------------

	/** The per-request withdrawal PDF queue processor must be removed. */
	public function test_maybe_generate_withdrawal_form_is_removed() {
		$this->assertFalse(
			method_exists( COMPLIANZ_TC::$document, 'maybe_generate_withdrawal_form' ),
			'maybe_generate_withdrawal_form() should be removed.'
		);
	}

	/** The withdrawal PDF renderer must be removed. */
	public function test_generate_withdrawal_form_is_removed() {
		$this->assertFalse(
			method_exists( COMPLIANZ_TC::$document, 'generate_withdrawal_form' ),
			'generate_withdrawal_form() should be removed.'
		);
	}

	/** The admin_init hook that queued withdrawal PDFs must be unregistered. */
	public function test_admin_init_withdrawal_hook_is_removed() {
		$this->assertFalse(
			has_action( 'admin_init', array( COMPLIANZ_TC::$document, 'maybe_generate_withdrawal_form' ) ),
			'admin_init should no longer call maybe_generate_withdrawal_form().'
		);
	}

	/** Activation must not seed the PDF-languages queue, but must still set the redirect transient. */
	public function test_activation_does_not_seed_pdf_languages() {
		delete_option( 'cmplz_generate_pdf_languages' );
		delete_transient( 'cmplz_tc_redirect_to_settings' );

		cmplz_tc_activation();

		$this->assertFalse(
			get_option( 'cmplz_generate_pdf_languages' ),
			'Activation should not seed cmplz_generate_pdf_languages.'
		);
		$this->assertTrue(
			(bool) get_transient( 'cmplz_tc_redirect_to_settings' ),
			'Activation should still set the settings-redirect transient.'
		);
	}

	// ---------------------------------------------------------------------
	// Upgrade cleanup.
	// ---------------------------------------------------------------------

	/** Upgrading from a pre-1.4.0 version must delete the stale PDF-languages option. */
	public function test_upgrade_deletes_pdf_languages_option() {
		update_option( 'cmplz_generate_pdf_languages', array( 'en_US' => 1 ) );
		update_option( 'cmplz-tc-current-version', '1.3.1' );

		$this->get_admin()->check_upgrade();

		$this->assertFalse(
			get_option( 'cmplz_generate_pdf_languages' ),
			'Upgrade should delete the cmplz_generate_pdf_languages option.'
		);
	}

	/** Upgrading from a pre-1.4.0 version must purge stale generated withdrawal PDFs. */
	public function test_upgrade_purges_withdrawal_forms_directory() {
		wp_mkdir_p( $this->withdrawal_dir );
		file_put_contents( $this->withdrawal_dir . '/withdrawal-form-en_US.pdf', '%PDF-stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a stale test fixture.
		$this->assertFileExists( $this->withdrawal_dir . '/withdrawal-form-en_US.pdf' );

		update_option( 'cmplz-tc-current-version', '1.3.1' );
		$this->get_admin()->check_upgrade();

		$this->assertDirectoryDoesNotExist(
			$this->withdrawal_dir,
			'Upgrade should purge the withdrawal-forms directory.'
		);
	}

	/** A fresh install (no prior version) must not error and has nothing to purge. */
	public function test_upgrade_on_fresh_install_is_a_noop() {
		delete_option( 'cmplz-tc-current-version' );

		$this->get_admin()->check_upgrade();

		// No fatal, and the current version is now recorded.
		$this->assertNotFalse( get_option( 'cmplz-tc-current-version' ) );
	}

	// ---------------------------------------------------------------------
	// The Terms & Conditions document PDF must keep working.
	// ---------------------------------------------------------------------

	/** The T&C document PDF generator must be kept. */
	public function test_generate_pdf_is_kept() {
		$this->assertTrue(
			method_exists( COMPLIANZ_TC::$document, 'generate_pdf' ),
			'generate_pdf() must be kept for the T&C download.'
		);
	}

	/** generate_pdf() must no longer accept the withdrawal-only file-save parameter. */
	public function test_generate_pdf_signature_is_stream_only() {
		$ref = new ReflectionMethod( 'cmplz_tc_document', 'generate_pdf' );
		$this->assertSame(
			2,
			$ref->getNumberOfParameters(),
			'generate_pdf() should take only $html and $title (stream mode).'
		);
	}

	/** The T&C download endpoint must still delegate to generate_pdf(). */
	public function test_download_endpoint_uses_generate_pdf() {
		$download = file_get_contents( dirname( __DIR__ ) . '/download.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file for a structural assertion.
		$this->assertStringContainsString(
			'generate_pdf(',
			$download,
			'download.php must still call generate_pdf().'
		);
	}

	/**
	 * End-to-end: the T&C PDF render still produces a valid PDF document.
	 *
	 * generate_pdf() streams via mPDF's Output('D'), which throws under the CLI
	 * test harness because PHPUnit has already written progress output to stdout
	 * (headers_sent() is true). We therefore exercise the render seam build_pdf()
	 * — the exact mPDF configuration + WriteHTML that generate_pdf() streams — and
	 * capture the document with STRING_RETURN to assert real PDF bytes.
	 */
	public function test_tc_pdf_render_produces_valid_pdf() {
		$html  = '<h1>Terms and Conditions</h1><p>Example clause.</p>';
		$title = 'Terms and Conditions';

		$build = new ReflectionMethod( 'cmplz_tc_document', 'build_pdf' );
		$build->setAccessible( true );
		$mpdf = $build->invoke( COMPLIANZ_TC::$document, $html, $title );

		$this->assertInstanceOf( \Mpdf\Mpdf::class, $mpdf, 'build_pdf() should return an mPDF instance.' );

		$pdf = $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
		$this->assertStringStartsWith(
			'%PDF',
			$pdf,
			'The T&C PDF render should produce a valid PDF document.'
		);
	}
}
