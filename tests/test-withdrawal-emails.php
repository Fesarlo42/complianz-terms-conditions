<?php
/**
 * Tests for the withdrawal emails and delivery-failure handling: the merchant
 * notification with the consumer as Reply-To, the consumer acknowledgement on a
 * durable medium, plain-text translatable overridable bodies with sanitized
 * headers, the email-send rate limit, and the persistent admin notice raised when
 * wp_mail() fails. Emails are mocked with MockPHPMailer.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-emails
 */
class Test_Withdrawal_Emails extends WP_UnitTestCase {

	/** @var cmplz_tc_withdrawal */
	private $wd;

	/** @var string */
	private $options_key = 'complianz_tc_options_terms-conditions';

	public function set_up() {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->wd               = cmplz_tc_withdrawal::this();
		reset_phpmailer_instance();
		delete_option( 'cmplz_tc_withdrawal_mail_failure' );
	}

	public function tear_down() {
		delete_option( 'cmplz_tc_withdrawal_mail_failure' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Fixtures & helpers.
	// -----------------------------------------------------------------

	/** A representative sanitized submission payload as fired on the Task-7 seam. */
	private function payload( array $field_overrides = array() ) {
		$fields = array_merge(
			array(
				'cmplz_tc_wf_name'       => 'Jane Consumer',
				'cmplz_tc_wf_email'      => 'jane@example.com',
				'cmplz_tc_wf_goods'      => 'Blue widget',
				'cmplz_tc_wf_address'    => "1 Consumer Way\nRotterdam",
				'cmplz_tc_wf_order_ref'  => 'ORD-42',
				'cmplz_tc_wf_order_date' => '2026-07-01',
				'cmplz_tc_wf_message'    => 'Please refund to source.',
			),
			$field_overrides
		);
		return array(
			'fields'       => $fields,
			'submitted_at' => 1751371200, // Fixed epoch; Date.now() is banned in tests.
			'source_url'   => 'https://shop.example/withdrawal/',
		);
	}

	/** Configure the merchant identity + contact used by both emails. */
	private function set_merchant( array $overrides = array() ) {
		update_option(
			$this->options_key,
			array_merge(
				array(
					'organisation_name'             => 'Acme Webshop BV',
					'address_company'               => "1 Market Street\n1000 AA Amsterdam",
					'withdrawal_notification_email' => 'merchant@shop.example',
					'contact_company'               => 'manually',
					'email_company'                 => 'contact@shop.example',
				),
				$overrides
			)
		);
	}

	/** Return the sent email addressed to $address, or null. */
	private function sent_to( $address ) {
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $sent ) {
			if ( isset( $sent['to'][0][0] ) && $address === $sent['to'][0][0] ) {
				return $sent;
			}
		}
		return null;
	}

	/** Count of emails MockPHPMailer captured. */
	private function sent_count() {
		return count( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	// -----------------------------------------------------------------
	// Wiring — the dispatcher hooks the Task-7 seam (and the admin notice).
	// -----------------------------------------------------------------

	/**
	 * The dispatcher is NOT wired to the seam action.
	 *
	 * Email dispatch is called directly by process() so its success/failure can
	 * drive the consumer's on-screen outcome; the cmplz_tc_withdrawal_validated
	 * action stays a fire-and-forget seam for integrators only.
	 */
	public function test_dispatch_is_not_wired_to_seam() {
		$this->wd->init();
		$this->assertFalse( has_action( 'cmplz_tc_withdrawal_validated', array( $this->wd, 'dispatch_emails' ) ) );
	}

	/** init() registers the persistent delivery-failure admin notice. */
	public function test_init_wires_mail_failure_notice() {
		$this->wd->init();
		$this->assertNotFalse( has_action( 'admin_notices', array( $this->wd, 'render_mail_failure_notice' ) ) );
	}

	// -----------------------------------------------------------------
	// Merchant notification.
	// -----------------------------------------------------------------

	/** A valid submission emails the configured recipient and the consumer. */
	public function test_dispatch_sends_both_emails() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );

		$this->assertSame( 2, $this->sent_count(), 'Both a merchant notification and a consumer acknowledgement must be sent.' );
		$this->assertNotNull( $this->sent_to( 'merchant@shop.example' ), 'Merchant notification must reach the configured recipient.' );
		$this->assertNotNull( $this->sent_to( 'jane@example.com' ), 'Consumer acknowledgement must reach the consumer.' );
	}

	/** A malformed configured recipient is validated and falls back to the site admin email. */
	public function test_merchant_recipient_validated_falls_back_to_admin() {
		$this->set_merchant( array( 'withdrawal_notification_email' => 'definitely not an email' ) );
		update_option( 'admin_email', 'siteadmin@shop.example' );

		$this->wd->dispatch_emails( $this->payload() );

		$this->assertNotNull( $this->sent_to( 'siteadmin@shop.example' ), 'A malformed recipient must fall back to the valid admin email.' );
		$this->assertNull( $this->sent_to( 'definitely not an email' ), 'The malformed address must never be used as a recipient.' );
	}

	/** The recipient resolves never-empty to the contact email, not blank. */
	public function test_merchant_recipient_never_empty_falls_back_to_contact() {
		$this->set_merchant(
			array(
				'withdrawal_notification_email' => '',
				'email_company'                 => 'contact@shop.example',
			)
		);
		$this->wd->dispatch_emails( $this->payload() );
		$this->assertNotNull( $this->sent_to( 'contact@shop.example' ), 'Empty recipient must resolve to email_company.' );
	}

	/** The merchant email carries all submitted fields, the timestamp and the source link. */
	public function test_merchant_body_contains_all_fields_timestamp_and_source() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'merchant@shop.example' );
		$this->assertNotNull( $sent );
		$body = $sent['body'];

		$this->assertStringContainsString( 'Jane Consumer', $body );
		$this->assertStringContainsString( 'jane@example.com', $body );
		$this->assertStringContainsString( 'Blue widget', $body );
		$this->assertStringContainsString( 'Rotterdam', $body );
		$this->assertStringContainsString( 'ORD-42', $body );
		$this->assertStringContainsString( 'Please refund to source.', $body );
		$this->assertStringContainsString( 'https://shop.example/withdrawal/', $body, 'The source page link must be included.' );
		$this->assertStringContainsString( '2026', $body, 'The formatted submission timestamp must be included.' );
	}

	/** Reply-To on the merchant email is the consumer's address. */
	public function test_merchant_reply_to_is_consumer() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'merchant@shop.example' );
		$this->assertNotNull( $sent );
		$this->assertStringContainsString( 'Reply-To: jane@example.com', $sent['header'] );
	}

	// -----------------------------------------------------------------
	// No consumer value reaches a header unsanitized.
	// -----------------------------------------------------------------

	/** A header-injection payload in the consumer email must not inject headers. */
	public function test_reply_to_rejects_header_injection() {
		$this->set_merchant();
		$this->wd->dispatch_emails(
			$this->payload( array( 'cmplz_tc_wf_email' => "jane@example.com\r\nBcc: victim@evil.example" ) )
		);
		$sent = $this->sent_to( 'merchant@shop.example' );
		$this->assertNotNull( $sent );
		$this->assertStringNotContainsString( 'victim@evil.example', $sent['header'], 'A CRLF payload must not inject a Bcc header.' );
		$this->assertStringNotContainsString( 'Bcc:', $sent['header'] );
	}

	// -----------------------------------------------------------------
	// Consumer acknowledgement on a durable medium.
	// -----------------------------------------------------------------

	/** The acknowledgement is an automated receipt confirmation and echoes the details. */
	public function test_consumer_ack_is_automated_receipt_confirmation() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'jane@example.com' );
		$this->assertNotNull( $sent );
		$body = $sent['body'];

		// Confirms receipt while making clear the merchant, not this email, acts next.
		$this->assertStringContainsString( 'automated confirmation', $body );
		$this->assertStringContainsString( 'has been received', $body );
		$this->assertStringContainsString( 'contact you', $body, 'The ack must set the expectation that the merchant follows up.' );
		$this->assertStringContainsString( 'Jane Consumer', $body );
		$this->assertStringContainsString( 'Blue widget', $body );
	}

	/** The acknowledgement includes the merchant name, address AND contact. */
	public function test_consumer_ack_includes_merchant_name_address_and_contact() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'jane@example.com' );
		$this->assertNotNull( $sent );
		$body = $sent['body'];

		$this->assertStringContainsString( 'Acme Webshop BV', $body, 'Merchant name is required.' );
		$this->assertStringContainsString( 'Amsterdam', $body, 'Merchant address is required.' );
		$this->assertStringContainsString( 'merchant@shop.example', $body, 'Contact shown is the withdrawal address, not the P&T email (#2).' );
		$this->assertStringNotContainsString( 'contact@shop.example', $body, 'The general P&T email must not be used as the withdrawal contact (#2).' );
	}

	// -----------------------------------------------------------------
	// Merchant contact block variant (on cmplz_tc_document).
	// -----------------------------------------------------------------

	/** The contact block uses the withdrawal email (not the P&T email) for the email contact (#2). */
	public function test_merchant_contact_block_uses_withdrawal_email() {
		$this->set_merchant( array( 'contact_company' => 'manually' ) );
		$block = COMPLIANZ_TC::$document->get_merchant_contact_block();
		$this->assertStringContainsString( 'Acme Webshop BV', $block );
		$this->assertStringContainsString( '1000 AA Amsterdam', $block );
		$this->assertStringContainsString( 'merchant@shop.example', $block, 'Withdrawal-specific address (#2).' );
		$this->assertStringNotContainsString( 'contact@shop.example', $block, 'Not the general P&T email (#2).' );
	}

	/** The contact block uses the contact page URL when that is how the merchant is reached. */
	public function test_merchant_contact_block_uses_page_when_webpage() {
		$this->set_merchant(
			array(
				'contact_company' => 'webpage',
				'email_company'   => '',
				'page_company'    => 'https://shop.example/contact/',
			)
		);
		$block = COMPLIANZ_TC::$document->get_merchant_contact_block();
		$this->assertStringContainsString( 'https://shop.example/contact/', $block );
	}

	/** get_merchant_identity() stays name+address only — the heading is not overloaded. */
	public function test_form_heading_identity_excludes_contact() {
		$this->set_merchant();
		$identity = COMPLIANZ_TC::$document->get_merchant_identity();
		$this->assertStringContainsString( 'Acme Webshop BV', $identity );
		$this->assertStringNotContainsString( 'contact@shop.example', $identity, 'The form heading must not carry the contact line.' );
	}

	// -----------------------------------------------------------------
	// Both emails are plain-text, translatable and overridable.
	// -----------------------------------------------------------------

	/** Both emails are sent as plain text by default. */
	public function test_emails_are_plain_text() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		foreach ( array( 'merchant@shop.example', 'jane@example.com' ) as $address ) {
			$sent = $this->sent_to( $address );
			$this->assertNotNull( $sent );
			$this->assertStringContainsString( 'text/plain', $sent['header'], "$address email must be plain text." );
		}
	}

	/** Integrators can override the subject, body, headers and recipient via filters. */
	public function test_merchant_email_is_filterable() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_merchant_subject', static fn() => 'CUSTOM SUBJECT' );
		add_filter( 'cmplz_tc_withdrawal_merchant_recipient', static fn() => 'redirected@shop.example' );

		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'redirected@shop.example' );
		$this->assertNotNull( $sent, 'The recipient filter must redirect the merchant email.' );
		$this->assertSame( 'CUSTOM SUBJECT', $sent['subject'] );
	}

	/** The consumer acknowledgement body is overridable. */
	public function test_consumer_body_is_filterable() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_consumer_body', static fn() => 'CUSTOM ACK BODY' );
		$this->wd->dispatch_emails( $this->payload() );
		$sent = $this->sent_to( 'jane@example.com' );
		$this->assertNotNull( $sent );
		// PHPMailer normalizes the body's trailing line ending, so match on content.
		$this->assertStringContainsString( 'CUSTOM ACK BODY', $sent['body'] );
	}

	// -----------------------------------------------------------------
	// Rate-limit the SEND, not just the form post.
	// -----------------------------------------------------------------

	/** Once the send limit is hit, further dispatches send nothing (ack goes to a supplied address). */
	public function test_email_send_rate_limit_blocks_dispatch() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_email_rate_limit_max', static fn() => 1 );

		$this->wd->dispatch_emails( $this->payload() );
		$this->assertSame( 2, $this->sent_count(), 'The first dispatch sends both emails.' );

		$this->wd->dispatch_emails( $this->payload( array( 'cmplz_tc_wf_email' => 'other@example.com' ) ) );
		$this->assertSame( 2, $this->sent_count(), 'A dispatch over the send limit must send nothing.' );
	}

	// -----------------------------------------------------------------
	// #5 — dispatch reports delivery so process() can drive the consumer UX.
	// -----------------------------------------------------------------

	/** Dispatch reports success and records no failure when both emails send. */
	public function test_dispatch_reports_success_when_both_succeed() {
		$this->set_merchant();
		$this->assertSame( 'success', $this->wd->dispatch_emails( $this->payload() ) );
		$this->assertEmpty( get_option( 'cmplz_tc_withdrawal_mail_failure' ) );
	}

	/** Dispatch reports delivery_error (and records a failure) when the consumer email fails. */
	public function test_dispatch_reports_delivery_error_when_consumer_email_fails() {
		$this->set_merchant();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) {
				$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
				return ( false !== strpos( $to, 'jane@example.com' ) ) ? false : $short;
			},
			10,
			2
		);
		$this->assertSame( 'delivery_error', $this->wd->dispatch_emails( $this->payload() ) );
		$this->assertNotEmpty( get_option( 'cmplz_tc_withdrawal_mail_failure' ) );
	}

	/** Dispatch reports delivery_error when only the merchant notification fails (either-fails). */
	public function test_dispatch_reports_delivery_error_when_merchant_email_fails() {
		$this->set_merchant();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) {
				$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
				return ( false !== strpos( $to, 'merchant@shop.example' ) ) ? false : $short;
			},
			10,
			2
		);
		$this->assertSame( 'delivery_error', $this->wd->dispatch_emails( $this->payload() ) );
	}

	// -----------------------------------------------------------------
	// De-amplification: per-recipient + global send throttles.
	// -----------------------------------------------------------------

	/** A single consumer address cannot receive more acks than the per-recipient cap. */
	public function test_per_recipient_throttle_caps_repeat_acks() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_email_per_recipient_max', static fn() => 1 );

		$this->assertSame( 'success', $this->wd->dispatch_emails( $this->payload() ) );
		$this->assertSame( 2, $this->sent_count(), 'The first dispatch to this address sends both emails.' );

		// A second attempt to the SAME consumer address is throttled: nothing sent.
		$this->assertSame( 'try_again_later', $this->wd->dispatch_emails( $this->payload() ) );
		$this->assertSame( 2, $this->sent_count(), 'A repeat to the same address must send nothing.' );
	}

	/** The global site-wide ceiling suppresses sends beyond the limit, regardless of address. */
	public function test_global_ceiling_suppresses_beyond_limit() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_email_global_max', static fn() => 1 );

		$this->assertSame( 'success', $this->wd->dispatch_emails( $this->payload() ) );
		$this->assertSame( 2, $this->sent_count() );

		// A different consumer address still trips the global ceiling.
		$this->assertSame( 'try_again_later', $this->wd->dispatch_emails( $this->payload( array( 'cmplz_tc_wf_email' => 'bob@example.com' ) ) ) );
		$this->assertSame( 2, $this->sent_count(), 'No send is allowed once the global ceiling is reached.' );
	}

	/** The merchant can raise the global ceiling at will via the filter. */
	public function test_global_ceiling_can_be_raised_by_filter() {
		$this->set_merchant();
		add_filter( 'cmplz_tc_withdrawal_email_global_max', static fn() => 100 );

		foreach ( array( 'a@example.com', 'b@example.com', 'c@example.com' ) as $address ) {
			$this->assertSame( 'success', $this->wd->dispatch_emails( $this->payload( array( 'cmplz_tc_wf_email' => $address ) ) ) );
		}
		$this->assertSame( 6, $this->sent_count(), 'A raised ceiling lets all three dispatches through.' );
	}

	/** When the merchant send fails, the consumer copy drops the "sent to the merchant" claim. */
	public function test_consumer_ack_reflects_failed_merchant_delivery() {
		$this->set_merchant();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) {
				$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
				return ( false !== strpos( $to, 'merchant@shop.example' ) ) ? false : $short;
			},
			10,
			2
		);

		$this->wd->dispatch_emails( $this->payload() );

		$sent = $this->sent_to( 'jane@example.com' );
		$this->assertNotNull( $sent, 'The consumer still receives a copy even when the merchant send fails.' );
		$body = $sent['body'];
		$this->assertStringContainsString( 'copy of the withdrawal request', $body );
		$this->assertStringContainsString( 'contact them directly', $body );
		$this->assertStringNotContainsString( 'sent to the merchant', $body, 'The ack must not claim a delivery the merchant never received.' );
	}

	// -----------------------------------------------------------------
	// #4 — own-link path sends no email.
	// -----------------------------------------------------------------

	/** On the own-link path the plugin sends no email. */
	public function test_own_link_path_sends_no_email() {
		update_option(
			$this->options_key,
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'yes',
			)
		);
		$this->assertSame( 'success', $this->wd->dispatch_emails( $this->payload() ), 'Own-link suppression is not a delivery failure.' );
		$this->assertSame( 0, $this->sent_count(), 'No email may be sent on the own-link path.' );
	}

	// -----------------------------------------------------------------
	// Delivery failure → log + persistent, PII-free admin notice.
	// -----------------------------------------------------------------

	/** A wp_mail() failure records the persistent admin-notice flag. */
	public function test_wp_mail_failure_records_notice_flag() {
		$this->set_merchant();
		add_filter( 'pre_wp_mail', '__return_false' );
		$this->wd->dispatch_emails( $this->payload() );
		$this->assertNotEmpty( get_option( 'cmplz_tc_withdrawal_mail_failure' ), 'A failed send must be recorded for the admin notice.' );
	}

	/** A successful dispatch records no failure flag. */
	public function test_successful_dispatch_records_no_failure() {
		$this->set_merchant();
		$this->wd->dispatch_emails( $this->payload() );
		$this->assertEmpty( get_option( 'cmplz_tc_withdrawal_mail_failure' ) );
	}

	/** The admin notice renders for an admin when a failure is recorded, and carries no PII. */
	public function test_mail_failure_notice_renders_without_pii() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'cmplz_tc_withdrawal_mail_failure', 1, false );

		ob_start();
		$this->wd->render_mail_failure_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice', $html, 'A recorded failure must render an admin notice.' );
		$this->assertStringNotContainsString( 'jane@example.com', $html, 'The notice must not leak the consumer email.' );
		$this->assertStringNotContainsString( 'Jane Consumer', $html, 'The notice must not leak consumer data.' );
	}

	/** With no recorded failure, the notice renders nothing. */
	public function test_no_notice_without_recorded_failure() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'cmplz_tc_withdrawal_mail_failure' );

		ob_start();
		$this->wd->render_mail_failure_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/** The notice is suppressed for users who cannot act on site email configuration. */
	public function test_mail_failure_notice_hidden_from_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		update_option( 'cmplz_tc_withdrawal_mail_failure', 1, false );

		ob_start();
		$this->wd->render_mail_failure_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}
}
