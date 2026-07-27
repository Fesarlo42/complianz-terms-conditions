<?php
/**
 * Tests for the withdrawal submission handler: cache-safe nonce, anti-abuse,
 * server-side validation, Post/Redirect/Get with no PII in the URL, and the
 * on-screen confirmation. No email is sent here — that is the dispatch seam.
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
		$this->wd                    = cmplz_tc_withdrawal::this();
		// The render guard persists on the shared document instance across tests.
		COMPLIANZ_TC::$document->reset_withdrawal_render_guard();
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
	// Server-side validation & sanitization.
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

	/**
	 * Validation is driven by the field spec: every required field is enforced with
	 * its own message, and blanking an optional field never blocks the submission.
	 */
	public function test_required_validation_is_driven_by_field_spec() {
		$required = array(
			'cmplz_tc_wf_name'  => 'Please enter your name.',
			'cmplz_tc_wf_email' => 'Please enter your email address.',
			'cmplz_tc_wf_goods' => 'Please describe the goods or service you are withdrawing from.',
		);
		foreach ( $required as $key => $message ) {
			$result = $this->wd->process( $this->valid_input( array( $key => '   ' ) ) );
			$this->assertSame( 'invalid', $result['status'], $key . ' must be required.' );
			$this->assertSame( $message, $result['errors'][ $key ], $key . ' keeps its specific message.' );
		}

		// An optional field left blank still succeeds.
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_address' => '' ) ) );
		$this->assertSame( 'success', $result['status'] );
	}

	/** All consumer input is sanitized server-side. */
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
	// Dispatch seam — a valid submission fires the email-dispatch action.
	// -----------------------------------------------------------------

	/**
	 * A valid submission fires the seam exactly once.
	 *
	 * The handler sends no email inline — dispatch is driven from this action
	 * (covered in Test_Withdrawal_Emails), keeping the concerns decoupled.
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
	// Anti-abuse.
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

	/**
	 * The render timestamp is now mandatory: an absent one soft-fails.
	 *
	 * The template renders the timestamp server-side, so every genuine consumer
	 * (JS or no-JS) submits with it; a bare scripted POST that omits it is bounced
	 * to a soft re-render rather than sailing through the anti-abuse layer.
	 */
	public function test_missing_render_timestamp_is_soft_fail() {
		$input = $this->valid_input();
		unset( $input['cmplz_tc_wf_rendered'] );
		$result = $this->wd->process( $input );
		$this->assertSame( 'too_fast', $result['status'] );
		$this->assertSame( 'Jane Consumer', $result['values']['cmplz_tc_wf_name'], 'Values are preserved on the soft-fail.' );
	}

	/** A non-numeric render timestamp is treated as absent. */
	public function test_non_numeric_render_timestamp_is_soft_fail() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_rendered' => 'not-a-number' ) ) );
		$this->assertSame( 'too_fast', $result['status'] );
	}

	// -----------------------------------------------------------------
	// Field-length caps on attacker-controlled content.
	// -----------------------------------------------------------------

	/** An over-long field value is rejected as invalid with a field error. */
	public function test_overlong_field_is_invalid() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_goods' => str_repeat( 'a', 6000 ) ) ) );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertArrayHasKey( 'cmplz_tc_wf_goods', $result['errors'] );
	}

	/** The length caps are filterable so a site can loosen or tighten them. */
	public function test_field_length_caps_are_filterable() {
		add_filter(
			'cmplz_tc_withdrawal_field_max_lengths',
			static function ( $caps ) {
				$caps['cmplz_tc_wf_goods'] = 10;
				return $caps;
			}
		);
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_goods' => 'this is longer than ten' ) ) );
		$this->assertSame( 'invalid', $result['status'] );
		$this->assertArrayHasKey( 'cmplz_tc_wf_goods', $result['errors'] );
	}

	// -----------------------------------------------------------------
	// Opt-in spam-check hook (off by default).
	// -----------------------------------------------------------------

	/** A truthy cmplz_tc_withdrawal_spam_check silently drops the submission. */
	public function test_spam_check_filter_rejects_as_spam() {
		reset_phpmailer_instance();
		add_filter( 'cmplz_tc_withdrawal_spam_check', '__return_true' );
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'spam', $result['status'] );
		$this->assertSame( '', $result['token'], 'A spam reject stores no state token.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	// -----------------------------------------------------------------
	// A suppressed send is never reported as success.
	// -----------------------------------------------------------------

	/** When a send throttle trips, process() reports try_again_later and sends nothing. */
	public function test_throttled_dispatch_returns_try_again_later() {
		reset_phpmailer_instance();
		add_filter( 'cmplz_tc_withdrawal_email_global_max', static fn() => 0 );
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'try_again_later', $result['status'], 'A throttled send must not be reported as success.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->get_sent(), 'A throttled dispatch sends nothing.' );
	}

	/** The try-again-later state renders a general retry message, not the success or error screen. */
	public function test_render_shows_try_again_later_message() {
		add_filter( 'cmplz_tc_withdrawal_email_global_max', static fn() => 0 );
		$result              = $this->wd->process( $this->valid_input() );
		$_GET['cmplz-tc-wf'] = $result['token'];

		$out = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'try again', $out, 'The consumer is asked to retry later.' );
		$this->assertStringContainsString( 'tabindex="-1"', $out, 'The try-again message must be focusable so it is announced.' );
		$this->assertStringNotContainsString( 'cmplz-tc-wf-confirmation', $out, 'A throttled send must not show the success screen.' );
		$this->assertStringNotContainsString( 'contact the merchant', $out, 'A transient throttle must not tell the consumer to contact the merchant.' );
		$this->assertStringNotContainsString( '<form', $out, 'The form must not re-render on the try-again screen.' );
	}

	/** The per-client form-post rate limit rejects once the threshold is exceeded. */
	public function test_rate_limit_blocks_after_threshold() {
		add_filter( 'cmplz_tc_withdrawal_rate_limit_max', static fn() => 2 );
		$this->assertSame( 'success', $this->wd->process( $this->valid_input() )['status'] );
		$this->assertSame( 'success', $this->wd->process( $this->valid_input() )['status'] );

		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'rate_limited', $result['status'] );
		$this->assertSame( '', $result['token'], 'The rate-limited path stores no state token.' );
		$this->assertStringContainsString( 'cmplz-tc-wf=rate_limited', $result['redirect'], 'The reserved status rides the redirect query instead of a token.' );
	}

	/**
	 * Finding #8: a rate-limited submission must allocate no per-request state transient,
	 * so a sustained flood cannot inflate wp_options.
	 */
	public function test_rate_limited_allocates_no_state_transient() {
		global $wpdb;
		add_filter( 'cmplz_tc_withdrawal_rate_limit_max', static fn() => 1 );

		// The first request passes and legitimately stores its own success state.
		$this->wd->process( $this->valid_input() );

		$like    = $wpdb->esc_like( '_transient_' . cmplz_tc_withdrawal::STATE_PREFIX ) . '%';
		$sql     = "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s";
		$before  = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		// Every further request is rejected as rate_limited and must write nothing.
		$this->wd->process( $this->valid_input() );
		$this->wd->process( $this->valid_input() );
		$after = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		$this->assertSame( $before, $after, 'Rate-limited submissions must not allocate state transients.' );
	}

	/**
	 * The reserved ?cmplz-tc-wf=rate_limited sentinel re-renders the form with the
	 * generic message and reads/writes no transient (side-effect-free, idempotent).
	 */
	public function test_render_shows_rate_limited_message_without_transient() {
		global $wpdb;
		$_GET['cmplz-tc-wf'] = 'rate_limited';

		$like   = $wpdb->esc_like( '_transient_' . cmplz_tc_withdrawal::STATE_PREFIX ) . '%';
		$sql    = "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s";
		$before = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		$out   = COMPLIANZ_TC::$document->render_withdrawal_form();
		$after = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		$this->assertStringContainsString( 'Too many attempts', $out, 'The generic rate-limit message is shown.' );
		$this->assertStringContainsString( '<form', $out, 'The form re-renders so the consumer can retry.' );
		$this->assertSame( $before, $after, 'The reserved sentinel touches no transient.' );
	}

	// -----------------------------------------------------------------
	// Nonce is a soft signal, never a permanent block.
	// -----------------------------------------------------------------

	/** A present-but-invalid nonce soft-fails and preserves values (never rejects hard). */
	public function test_present_but_invalid_nonce_soft_fails() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_nonce' => 'deadbeef' ) ) );
		$this->assertSame( 'invalid_nonce', $result['status'] );
		$this->assertSame( 'Jane Consumer', $result['values']['cmplz_tc_wf_name'] );
	}

	/** An absent nonce (no-JS consumer) must not block a genuine submission. */
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
	// PRG with no personal data in the URL.
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

	/** The PRG state (which briefly holds sanitized PII on the failure path) expires quickly. */
	public function test_prg_state_ttl_is_short() {
		$result  = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '' ) ) );
		$timeout = (int) get_option( '_transient_timeout_' . cmplz_tc_withdrawal::STATE_PREFIX . $result['token'] );
		$this->assertGreaterThan( 0, $timeout, 'The state transient must carry an expiry.' );
		$this->assertLessThanOrEqual( 5 * MINUTE_IN_SECONDS + 5, $timeout - time(), 'The PRG state must expire within ~5 minutes.' );
	}

	/** consume_state is one-shot: a second read returns null. */
	public function test_state_is_one_shot() {
		$result = $this->wd->process( $this->valid_input( array( 'cmplz_tc_wf_name' => '' ) ) );
		$this->assertIsArray( cmplz_tc_withdrawal::consume_state( $result['token'] ) );
		$this->assertNull( cmplz_tc_withdrawal::consume_state( $result['token'] ) );
	}

	// -----------------------------------------------------------------
	// Uncached nonce endpoint.
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

	/** The nonce endpoint must be uncacheable. */
	public function test_nonce_endpoint_is_uncacheable() {
		$request  = new WP_REST_Request( 'GET', '/complianz_tc/v1/withdrawal-nonce' );
		$response = rest_get_server()->dispatch( $request );
		$headers  = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * The nonce endpoint is per-IP throttled to bound mass minting.
	 *
	 * Being throttled is harmless to a genuine consumer: an absent nonce is
	 * accepted (soft signal), so a rare throttled page load still submits fine.
	 */
	public function test_nonce_endpoint_throttled_after_limit() {
		add_filter( 'cmplz_tc_withdrawal_nonce_endpoint_max', static fn() => 1 );

		$first = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/complianz_tc/v1/withdrawal-nonce' ) );
		$this->assertSame( 200, $first->get_status() );

		$second = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/complianz_tc/v1/withdrawal-nonce' ) );
		$this->assertSame( 429, $second->get_status(), 'Requests beyond the per-IP limit are throttled.' );
	}

	// -----------------------------------------------------------------
	// render_withdrawal_form consumes the PRG state.
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

	/** A success PRG shows an on-screen confirmation instead of the form. */
	public function test_render_shows_confirmation_on_success() {
		$result = $this->wd->process( $this->valid_input() );
		$this->assertSame( 'success', $result['status'] );
		$_GET['cmplz-tc-wf'] = $result['token'];

		$out = COMPLIANZ_TC::$document->render_withdrawal_form();
		$this->assertStringContainsString( 'cmplz-tc-wf-confirmation', $out );
		$this->assertStringContainsString( 'tabindex="-1"', $out, 'The confirmation must be focusable so it is announced.' );
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

	/** Switch the site onto the own-link (returns-custom) path. */
	private function use_own_link_path() {
		update_option(
			'complianz_tc_options_terms-conditions',
			array(
				'if_returns'             => 'yes',
				'if_returns_custom'      => 'yes',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);
	}

	/**
	 * Finding #4: a submission on the own-link path must be rejected before any side-effect —
	 * no stored state, no token, no phantom success (dispatch_emails() would report success
	 * while sending nothing).
	 */
	public function test_own_link_submission_is_rejected_before_side_effects() {
		global $wpdb;
		$this->use_own_link_path();

		$like   = $wpdb->esc_like( '_transient_' . cmplz_tc_withdrawal::STATE_PREFIX ) . '%';
		$sql    = "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s";
		$before = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		$result = $this->wd->process( $this->valid_input() );
		$after  = (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) );

		$this->assertSame( 'unavailable', $result['status'], 'Off the form path a submission must not be processed.' );
		$this->assertSame( '', $result['token'], 'A rejected submission stores no state token.' );
		$this->assertSame( $before, $after, 'A rejected submission must not store any state transient.' );
	}

	/** Finding #4: the integrator seam must not fire for a submission off the form path. */
	public function test_own_link_submission_does_not_fire_validated_action() {
		$this->use_own_link_path();

		$fired = 0;
		add_action(
			'cmplz_tc_withdrawal_validated',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->wd->process( $this->valid_input() );
		$this->assertSame( 0, $fired, 'cmplz_tc_withdrawal_validated must not fire on the own-link path.' );
	}

	/** Finding #4: the built-in-form path is unaffected — the guard is a no-op there. */
	public function test_form_path_still_processes_normally() {
		update_option(
			'complianz_tc_options_terms-conditions',
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'no',
			)
		);
		$this->assertSame( 'success', $this->wd->process( $this->valid_input() )['status'] );
	}

	// -----------------------------------------------------------------
	// Delivery-failure surfaces to the consumer.
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
		$this->assertStringContainsString( 'tabindex="-1"', $out, 'The delivery error must be focusable so it is announced.' );
		$this->assertStringNotContainsString( 'cmplz-tc-wf-confirmation', $out, 'The success confirmation must not show on failure.' );
		$this->assertStringNotContainsString( '<form', $out, 'The form must not re-render after a delivery failure.' );
	}

	// -----------------------------------------------------------------
	// The JS is told where the uncached nonce lives.
	// -----------------------------------------------------------------

	/** Enqueuing the form assets localizes the uncached nonce endpoint URL. */
	public function test_assets_localize_nonce_endpoint() {
		COMPLIANZ_TC::$document->enqueue_withdrawal_assets();
		$data = wp_scripts()->get_data( 'cmplz-tc-withdrawal-form', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'withdrawal-nonce', $data );
	}
}
