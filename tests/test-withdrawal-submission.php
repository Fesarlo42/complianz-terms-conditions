<?php
/**
 * Tests for the withdrawal submission handler (Task 7): cache-safe nonce
 * (FR-13/NFR-P1), anti-abuse (FR-14), server-side validation (FR-15),
 * Post/Redirect/Get with no PII in the URL (FR-16), and the on-screen
 * confirmation (FR-19). No email is sent here — that is Task 9's seam.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-submission
 */
class Test_Withdrawal_Submission extends WP_UnitTestCase {

	/** @var cmplz_tc_withdrawal */
	private $wd;

	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_scripts']       = null;
		$GLOBALS['wp_styles']        = null;
		$_SERVER['REMOTE_ADDR']      = '203.0.113.7';
		$this->wd                    = new cmplz_tc_withdrawal();
	}

	public function tear_down() {
		unset( $_GET['cmplz-tc-wf'] );
		delete_option( 'cmplz_tc_withdrawal_page_id' );
		parent::tear_down();
	}

	/** A valid input array with a fresh nonce and a non-instant render time. */
	private function valid_input( array $overrides = array() ) {
		$base = array(
			'cmplz_tc_wf_nonce'    => wp_create_nonce( cmplz_tc_withdrawal::NONCE_ACTION ),
			'cmplz_tc_wf_rendered' => time() - 60,
			'cmplz_tc_wf_website'  => '',
			'cmplz_tc_wf_name'     => 'Jane Consumer',
			'cmplz_tc_wf_email'    => 'jane@example.com',
			'cmplz_tc_wf_goods'    => 'Blue widget, order 123',
		);
		return array_merge( $base, $overrides );
	}

	// -----------------------------------------------------------------
	// Handler wiring.
	// -----------------------------------------------------------------

	/** The public submission endpoint must be registered for both auth states. */
	public function test_handler_hooks_registered() {
		$this->wd->init();
		$this->assertNotFalse( has_action( 'admin_post_cmplz_tc_submit_withdrawal' ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_cmplz_tc_submit_withdrawal' ) );
	}

	// -----------------------------------------------------------------
	// FR-15 — server-side validation & sanitization.
	// -----------------------------------------------------------------

	/** A complete, valid submission succeeds. */
	public function test_valid_submission_returns_success() {
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'success', $result['status'] );
	}

	/** Required fields: missing name yields a field-level error, values preserved. */
	public function test_missing_required_field_returns_invalid_with_errors() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '   ' ) ) );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertArrayHasKey( 'cmplz_tc_wf_name', $result['errors'] );
		$this->assertSame( 'jane@example.com', $result['values']['cmplz_tc_wf_email'] );
	}

	/** The email must be a valid address. */
	public function test_invalid_email_returns_error() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_email' => 'not-an-email' ) ) );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertArrayHasKey( 'cmplz_tc_wf_email', $result['errors'] );
	}

	/** Optional fields are not required. */
	public function test_optional_fields_not_required() {
		$result = $this->wd->process( $this->valid_input() ); // no address/order_ref/date/message.
		$this->assertSame( 'success', $result['status'] );
	}

	/** All consumer input is sanitized server-side (NFR-S1). */
	public function test_input_is_sanitized() {
		$captured = array();
		add_action(
			'cmplz_tc_withdrawal_validated',
			static function ( $data ) use ( &$captured ) {
				$captured = $data;
			}
		);
		$this->wd->process(
			$this->valid_input(
				array(
					'cmplz_tc_wf_name'  => '<script>alert(1)</script>Jane',
					'cmplz_tc_wf_goods' => "Widget<script>x</script>",
				)
			)
		);
		$this->assertStringNotContainsString( '<script>', $captured['fields']['cmplz_tc_wf_name'] );
		$this->assertStringNotContainsString( '<script>', $captured['fields']['cmplz_tc_wf_goods'] );
	}

	// -----------------------------------------------------------------
	// Task 9 seam — a valid submission fires the email-dispatch action.
	// -----------------------------------------------------------------

	/**
	 * A valid submission fires the seam exactly once.
	 *
	 * The handler sends no email inline — dispatch is attached to this action by
	 * Task 9 (covered in Test_Withdrawal_Emails), keeping the concerns decoupled.
	 */
	public function test_valid_submission_fires_seam() {
		$fired = 0;
		add_action(
			'cmplz_tc_withdrawal_validated',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->wd->process( $this->valid_input() );
		$this->assertSame( 1, $fired, 'The Task-9 seam action must fire once on a valid submission.' );
	}

	// -----------------------------------------------------------------
	// FR-14 — anti-abuse.
	// -----------------------------------------------------------------

	/** A filled honeypot is a silent spam reject: no email, no preserved state. */
	public function test_honeypot_filled_is_silent_spam() {
		reset_phpmailer_instance();
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_website' => 'http://spam.example' ) ) );
		$this->assertSame( 'spam', $result['status'] );
		$this->assertSame( '', $result['token'], 'A spam reject stores no state token.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/** Submitting faster than the minimum time is a soft-fail with values preserved. */
	public function test_too_fast_is_soft_fail_preserving_values() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_rendered' => time() ) ) );
		$this->assertSame( 'too_fast', $result['status'] );
		$this->assertSame( 'Jane Consumer', $result['values']['cmplz_tc_wf_name'] );
	}

	/** No render timestamp (no-JS consumer) skips the min-time check rather than blocking. */
	public function test_missing_render_timestamp_skips_min_time() {
		$input = $this->valid_input();
		unset( $input['cmplz_tc_wf_rendered'] );
		$result = $this->wd->process( $input );
		$this->assertSame( 'success', $result['status'] );
	}

	/** The per-client form-post rate limit rejects once the threshold is exceeded. */
	public function test_rate_limit_blocks_after_threshold() {
		add_filter( 'cmplz_tc_withdrawal_rate_limit_max', static fn() => 2 );
		$this->assertSame( 'success', $this->wd->process( $this->valid_input() )['status'] );
		$this->assertSame( 'success', $this->wd->process( $this->valid_input() )['status'] );
		$this->assertSame( 'rate_limited', $this->wd->process( $this->valid_input() )['status'] );
	}

	// -----------------------------------------------------------------
	// FR-13 — nonce is a soft signal, never a permanent block.
	// -----------------------------------------------------------------

	/** A present-but-invalid nonce soft-fails and preserves values (never rejects hard). */
	public function test_present_but_invalid_nonce_soft_fails() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_nonce' => 'deadbeef' ) ) );
		$this->assertSame( 'invalid_nonce', $result['status'] );
		$this->assertSame( 'Jane Consumer', $result['values']['cmplz_tc_wf_name'] );
	}

	/** An absent nonce (no-JS consumer) must not block a genuine submission (NFR-S3). */
	public function test_absent_nonce_does_not_block() {
		$input = $this->valid_input();
		unset( $input['cmplz_tc_wf_nonce'] );
		$result = $this->wd->process( $input );
		$this->assertSame( 'success', $result['status'] );
	}

	/**
	 * The endpoint nonce must verify even for a logged-in visitor.
	 *
	 * A cookie-authenticated REST request without an X-WP-Nonce is downgraded to
	 * logged-out by core, so the nonce is minted for user 0 while the admin-post
	 * submission runs as the logged-in user. Minting and verifying in the same
	 * forced context keeps them consistent, so a genuine submit is never rejected.
	 */
	public function test_endpoint_nonce_verifies_when_logged_in() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$nonce = cmplz_tc_withdrawal::create_nonce();

		// A naive verify in the logged-in context rejects the endpoint nonce (the original bug).
		$this->assertFalse( (bool) wp_verify_nonce( $nonce, cmplz_tc_withdrawal::NONCE_ACTION ) );

		// The handler verifies in the same forced context, so the submission succeeds.
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_nonce' => $nonce ) ) );
		$this->assertSame( 'success', $result['status'] );
	}

	// -----------------------------------------------------------------
	// FR-16 / NFR-S4 — PRG with no personal data in the URL.
	// -----------------------------------------------------------------

	/** The redirect carries only a token; personal data lives in the transient, not the URL. */
	public function test_prg_keeps_personal_data_out_of_url() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '' ) ) );
		$this->assertNotEmpty( $result['token'] );
		$this->assertStringContainsString( 'cmplz-tc-wf=', $result['redirect'] );
		$this->assertStringNotContainsString( 'jane@example.com', $result['redirect'] );
		$this->assertStringNotContainsString( 'Consumer', $result['redirect'] );

		$state = cmplz_tc_withdrawal::consume_state( $result['token'] );
		$this->assertSame( 'jane@example.com', $state['values']['cmplz_tc_wf_email'] );
	}

	/** consume_state is one-shot: a second read returns null. */
	public function test_state_is_one_shot() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '' ) ) );
		$this->assertIsArray( cmplz_tc_withdrawal::consume_state( $result['token'] ) );
		$this->assertNull( cmplz_tc_withdrawal::consume_state( $result['token'] ) );
	}

	// -----------------------------------------------------------------
	// FR-13 / NFR-P1 — uncached nonce endpoint.
	// -----------------------------------------------------------------

	/** The nonce endpoint returns a verifiable nonce and a render timestamp. */
	public function test_nonce_endpoint_returns_fresh_nonce() {
		$request  = new WP_REST_Request( 'GET', '/complianz_tc/v1/withdrawal-nonce' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( (bool) wp_verify_nonce( $data['nonce'], cmplz_tc_withdrawal::NONCE_ACTION ) );
		$this->assertIsInt( $data['rendered'] );
	}

	/** The nonce endpoint must be uncacheable (NFR-P1). */
	public function test_nonce_endpoint_is_uncacheable() {
		$request  = new WP_REST_Request( 'GET', '/complianz_tc/v1/withdrawal-nonce' );
		$response = rest_get_server()->dispatch( $request );
		$headers  = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	// -----------------------------------------------------------------
	// FR-16 / FR-19 — render_withdrawal_form consumes the PRG state.
	// -----------------------------------------------------------------

	/** After an invalid PRG, the re-rendered form shows the error and the preserved value. */
	public function test_render_injects_errors_and_values() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '' ) ) );
		$_GET['cmplz-tc-wf'] = $result['token'];

		$out = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'jane@example.com', $out, 'The preserved email must be re-rendered.' );
		$this->assertStringContainsString( 'aria-invalid="true"', $out, 'The invalid field must be flagged.' );
		$this->assertStringContainsString( '<form', $out );
	}

	/** A success PRG shows an on-screen confirmation instead of the form (FR-19). */
	public function test_render_shows_confirmation_on_success() {
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'success', $result['status'] );
		$_GET['cmplz-tc-wf'] = $result['token'];

		$out = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'cmplz-tc-wf-confirmation', $out );
		$this->assertStringNotContainsString( '<form', $out, 'The form must not re-render after success.' );
	}

	// -----------------------------------------------------------------
	// #4 — own-link path renders a link, not the form.
	// -----------------------------------------------------------------

	/** On the own-link path the block/shortcode renders a link to the merchant's own function. */
	public function test_own_link_path_renders_link_not_form() {
		update_option(
			'complianz_tc_options_terms-conditions',
			array(
				'if_returns'             => 'yes',
				'if_returns_custom'      => 'yes',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);
		$out = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'https://merchant.example/withdraw', $out, 'Own-link path must link to the merchant function.' );
		$this->assertStringNotContainsString( '<form', $out, 'Own-link path must not render the form.' );
	}

	// -----------------------------------------------------------------
	// #5 — delivery-failure surfaces to the consumer (FR-19/FR-20 refinement).
	// -----------------------------------------------------------------

	/** A delivery failure yields a delivery_error status rather than success. */
	public function test_delivery_failure_yields_delivery_error_status() {
		add_filter( 'pre_wp_mail', '__return_false' );
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'delivery_error', $result['status'] );
	}

	/** The consumer sees an error naming the merchant contact, not the success screen. */
	public function test_delivery_error_renders_consumer_error_with_contact() {
		update_option(
			'complianz_tc_options_terms-conditions',
			array(
				'organisation_name'             => 'Acme Webshop BV',
				'withdrawal_notification_email' => 'merchant@shop.example',
				'contact_company'               => 'manually',
			)
		);
		add_filter( 'pre_wp_mail', '__return_false' );
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'delivery_error', $result['status'] );

		$_GET['cmplz-tc-wf'] = $result['token'];
		$out                 = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'contact the merchant', $out, 'The consumer must be told to contact the merchant.' );
		$this->assertStringContainsString( 'merchant@shop.example', $out, 'The merchant contact must be shown.' );
		$this->assertStringNotContainsString( 'cmplz-tc-wf-confirmation', $out, 'The success confirmation must not show on failure.' );
		$this->assertStringNotContainsString( '<form', $out, 'The form must not re-render after a delivery failure.' );
	}

	// -----------------------------------------------------------------
	// FR-13 / NFR-P1 — the JS is told where the uncached nonce lives.
	// -----------------------------------------------------------------

	/** Enqueuing the form assets localizes the uncached nonce endpoint URL. */
	public function test_assets_localize_nonce_endpoint() {
		COMPLIANZ_TC::$document->enqueue_withdrawal_assets();
		$data = wp_scripts()->get_data( 'cmplz-tc-withdrawal-form', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'withdrawal-nonce', $data );
	}
}
