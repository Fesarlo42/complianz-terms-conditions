<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
/**
 * Withdrawal submission handler: validation, anti-abuse and Post/Redirect/Get.
 *
 * Handles posts to admin-post.php from the interactive withdrawal form. The
 * public endpoint performs no privileged action (NFR-S3): it validates and
 * sanitizes server-side (FR-15), screens for abuse (FR-14), and either returns
 * the consumer to the form with preserved values and errors or shows an
 * on-screen confirmation (FR-16/FR-19) — all via a short-lived transient so no
 * personal data appears in the URL (NFR-S4). Emails are Task 9's; a valid
 * submission fires the cmplz_tc_withdrawal_validated action as that seam.
 *
 * @package Complianz_Terms_Conditions
 * @license GPL-2.0-or-later
 * @link    https://complianz.io
 *
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'cmplz_tc_withdrawal' ) ) {

	// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Matches the established cmplz_tc_* class convention used across this codebase.
	/**
	 * Processes withdrawal-form submissions.
	 *
	 * @since 1.4.0
	 */
	class cmplz_tc_withdrawal {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

		/** Nonce action shared by the uncached nonce endpoint and the handler. */
		const NONCE_ACTION = 'cmplz_tc_withdrawal';

		/** Transient key prefix for the Post/Redirect/Get state. */
		const STATE_PREFIX = 'cmplz_tc_wf_state_';

		/** Option flag raised when an email could not be sent (FR-20). */
		const MAIL_FAILURE_OPTION = 'cmplz_tc_withdrawal_mail_failure';

		/** Query arg that dismisses the delivery-failure admin notice. */
		const DISMISS_ARG = 'cmplz_tc_wf_dismiss_mail_failure';

		/**
		 * Register the public submission endpoints and the email/notice hooks.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'admin_post_cmplz_tc_submit_withdrawal', array( $this, 'handle_submission' ) );
			add_action( 'admin_post_nopriv_cmplz_tc_submit_withdrawal', array( $this, 'handle_submission' ) );

			// FR-20: surface a delivery failure to the merchant via a persistent admin notice.
			add_action( 'admin_notices', array( $this, 'render_mail_failure_notice' ) );
			add_action( 'admin_init', array( $this, 'maybe_dismiss_mail_failure_notice' ) );

			// NB dispatch_emails() is NOT hooked here: process() calls it directly so its
			// success/failure can drive the consumer's on-screen outcome. The
			// cmplz_tc_withdrawal_validated action remains a fire-and-forget seam for integrators.
		}

		/**
		 * Handle an admin-post submission: process, then redirect (PRG).
		 *
		 * The nonce is a soft signal verified in process() (FR-13) and every field
		 * is sanitized there, so the raw $_POST is passed through as-is.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function handle_submission() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Soft nonce (FR-13) + per-field sanitization happen in process().
			$result = $this->process( (array) wp_unslash( $_POST ) );
			wp_safe_redirect( $result['redirect'] );
			exit;
		}

		/**
		 * Validate, screen and route a submission without redirecting.
		 *
		 * Separated from handle_submission() so every branch is unit-testable.
		 *
		 * @since 1.4.0
		 *
		 * @param  array $input Raw (unslashed) submitted values.
		 * @return array {
		 *     @type string $status   One of success|invalid|invalid_nonce|too_fast|rate_limited|spam.
		 *     @type array  $errors   Field-name (or general key) => message.
		 *     @type array  $values   Sanitized values preserved for re-render.
		 *     @type string $token    State transient token, or '' when none was stored.
		 *     @type string $redirect Target URL for the PRG redirect.
		 * }
		 */
		public function process( array $input ) {
			// Per-client form-post rate limit (the email-send limit is Task 9's).
			if ( ! $this->within_rate_limit() ) {
				return $this->fail( 'rate_limited', $this->preserve( $input ), __( 'Too many attempts. Please wait a moment and try again.', 'complianz-terms-conditions' ) );
			}

			// Honeypot: a filled hidden field means a bot. Silent drop, no state.
			if ( '' !== trim( (string) ( isset( $input['cmplz_tc_wf_website'] ) ? $input['cmplz_tc_wf_website'] : '' ) ) ) {
				return $this->reject_spam();
			}

			// Nonce: present-but-invalid is a soft failure; absent does not block (NFR-S3).
			$nonce = (string) ( isset( $input['cmplz_tc_wf_nonce'] ) ? $input['cmplz_tc_wf_nonce'] : '' );
			if ( '' !== $nonce && ! $this->verify_nonce( $nonce ) ) {
				return $this->fail( 'invalid_nonce', $this->preserve( $input ), __( 'Your session has expired. Please review the details below and submit again.', 'complianz-terms-conditions' ) );
			}

			// Minimum time-to-submit, checked only when the render timestamp is present.
			if ( isset( $input['cmplz_tc_wf_rendered'] ) && '' !== (string) $input['cmplz_tc_wf_rendered'] ) {
				if ( ( time() - (int) $input['cmplz_tc_wf_rendered'] ) < $this->min_submit_seconds() ) {
					return $this->fail( 'too_fast', $this->preserve( $input ), __( 'That was a little too quick. Please review the details below and submit again.', 'complianz-terms-conditions' ) );
				}
			}

			// Authoritative server-side validation.
			$clean = array();
			foreach ( $this->fields() as $key => $spec ) {
				$clean[ $key ] = $this->sanitize_field( (string) ( isset( $input[ $key ] ) ? $input[ $key ] : '' ), $spec['sanitize'] );
			}
			$errors = $this->validate( $clean );
			if ( ! empty( $errors ) ) {
				return $this->fail( 'invalid', $clean, '', $errors );
			}

			// Valid. Build the payload for the seam and the emails.
			$data = array(
				'fields'       => $clean,
				'submitted_at' => time(),
				'source_url'   => $this->redirect_base(),
			);

			/**
			 * Fires when a withdrawal submission passes validation and anti-abuse.
			 *
			 * @since 1.4.0
			 *
			 * @param array $data Sanitized fields, submission timestamp and source URL.
			 */
			do_action( 'cmplz_tc_withdrawal_validated', $data );

			// Send the plugin's emails; the result decides what the consumer sees (FR-19/FR-20).
			$status = $this->dispatch_emails( $data ) ? 'success' : 'delivery_error';
			$token  = $this->store_state( array( 'status' => $status ) );

			return array(
				'status'   => $status,
				'errors'   => array(),
				'values'   => array(),
				'data'     => $data,
				'token'    => $token,
				'redirect' => $this->redirect_with_token( $token ),
			);
		}

		/**
		 * Mint the withdrawal nonce in a consistent (logged-out) context.
		 *
		 * A cookie-authenticated REST request without an X-WP-Nonce header is
		 * downgraded to the logged-out user by core, so a per-user nonce minted
		 * there would never verify against a logged-in admin-post submission.
		 * Minting and verifying both in a forced logged-out context keeps them
		 * consistent for logged-in and logged-out visitors alike; the session
		 * token still comes from the cookie, so the nonce stays per-browser.
		 *
		 * @since 1.4.0
		 *
		 * @return string The nonce.
		 */
		public static function create_nonce() {
			return (string) self::with_logged_out_user(
				static function () {
					return wp_create_nonce( self::NONCE_ACTION );
				}
			);
		}

		/**
		 * Verify a withdrawal nonce in the same forced context it was minted in.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $nonce The submitted nonce.
		 * @return bool           True when valid.
		 */
		private function verify_nonce( $nonce ) {
			return (bool) self::with_logged_out_user(
				static function () use ( $nonce ) {
					return wp_verify_nonce( $nonce, self::NONCE_ACTION );
				}
			);
		}

		/**
		 * Run a callback with the current user temporarily forced to logged-out.
		 *
		 * @since 1.4.0
		 *
		 * @param  callable $callback Callback to run.
		 * @return mixed               The callback's return value.
		 */
		private static function with_logged_out_user( $callback ) {
			$current = get_current_user_id();
			if ( $current ) {
				wp_set_current_user( 0 );
			}
			$result = $callback();
			if ( $current ) {
				wp_set_current_user( $current );
			}
			return $result;
		}

		/**
		 * Read, delete and return the one-shot PRG state for a token.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $token The token carried in the redirect query.
		 * @return array|null     The stored state, or null when absent/consumed.
		 */
		public static function consume_state( $token ) {
			$token = sanitize_text_field( (string) $token );
			if ( '' === $token ) {
				return null;
			}
			$key   = self::STATE_PREFIX . $token;
			$state = get_transient( $key );
			if ( ! is_array( $state ) ) {
				return null;
			}
			delete_transient( $key );
			return $state;
		}

		/**
		 * Build a re-render response, storing preserved values and errors.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $status  Result status.
		 * @param  array  $values  Sanitized values to preserve.
		 * @param  string $message Optional general message shown above the form.
		 * @param  array  $errors  Optional field-level errors.
		 * @return array           The process() result array.
		 */
		private function fail( $status, array $values, $message = '', array $errors = array() ) {
			if ( '' !== $message ) {
				$errors = array_merge( array( 'cmplz_tc_wf_form' => $message ), $errors );
			}
			$state = array( 'status' => $status );
			if ( ! empty( $values ) ) {
				$state['values'] = $values;
			}
			if ( ! empty( $errors ) ) {
				$state['errors'] = $errors;
			}
			$token = $this->store_state( $state );

			return array(
				'status'   => $status,
				'errors'   => $errors,
				'values'   => $values,
				'token'    => $token,
				'redirect' => $this->redirect_with_token( $token ),
			);
		}

		/**
		 * Silently reject a spam submission: no email, no preserved state.
		 *
		 * @since 1.4.0
		 *
		 * @return array The process() result array.
		 */
		private function reject_spam() {
			return array(
				'status'   => 'spam',
				'errors'   => array(),
				'values'   => array(),
				'token'    => '',
				'redirect' => $this->redirect_base(),
			);
		}

		/**
		 * Field specification: which are required and how each is sanitized.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string,array{required:bool,sanitize:string}>
		 */
		private function fields() {
			return array(
				'cmplz_tc_wf_name'       => array(
					'required' => true,
					'sanitize' => 'text',
				),
				'cmplz_tc_wf_email'      => array(
					'required' => true,
					'sanitize' => 'email',
				),
				'cmplz_tc_wf_goods'      => array(
					'required' => true,
					'sanitize' => 'textarea',
				),
				'cmplz_tc_wf_address'    => array(
					'required' => false,
					'sanitize' => 'textarea',
				),
				'cmplz_tc_wf_order_ref'  => array(
					'required' => false,
					'sanitize' => 'text',
				),
				'cmplz_tc_wf_order_date' => array(
					'required' => false,
					'sanitize' => 'text',
				),
				'cmplz_tc_wf_message'    => array(
					'required' => false,
					'sanitize' => 'textarea',
				),
			);
		}

		/**
		 * Sanitize a single value by field type (NFR-S1).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $value Raw value.
		 * @param  string $type  One of text|email|textarea.
		 * @return string        Sanitized value.
		 */
		private function sanitize_field( $value, $type ) {
			switch ( $type ) {
				case 'email':
					return sanitize_email( $value );
				case 'textarea':
					return sanitize_textarea_field( $value );
				default:
					return sanitize_text_field( $value );
			}
		}

		/**
		 * Validate the Art. 11a minimum required fields.
		 *
		 * @since 1.4.0
		 *
		 * @param  array $clean Sanitized values.
		 * @return array        Field-name => error message (empty when valid).
		 */
		private function validate( array $clean ) {
			$errors = array();

			if ( '' === trim( $clean['cmplz_tc_wf_name'] ) ) {
				$errors['cmplz_tc_wf_name'] = __( 'Please enter your name.', 'complianz-terms-conditions' );
			}

			$email = trim( $clean['cmplz_tc_wf_email'] );
			if ( '' === $email ) {
				$errors['cmplz_tc_wf_email'] = __( 'Please enter your email address.', 'complianz-terms-conditions' );
			} elseif ( ! is_email( $email ) ) {
				$errors['cmplz_tc_wf_email'] = __( 'Please enter a valid email address.', 'complianz-terms-conditions' );
			}

			if ( '' === trim( $clean['cmplz_tc_wf_goods'] ) ) {
				$errors['cmplz_tc_wf_goods'] = __( 'Please describe the goods or service you are withdrawing from.', 'complianz-terms-conditions' );
			}

			return $errors;
		}

		/**
		 * Sanitize and collect only the recognised form fields for re-render.
		 *
		 * @since 1.4.0
		 *
		 * @param  array $input Raw submitted values.
		 * @return array        Field-name => sanitized value.
		 */
		private function preserve( array $input ) {
			$values = array();
			foreach ( $this->fields() as $key => $spec ) {
				if ( isset( $input[ $key ] ) ) {
					$values[ $key ] = $this->sanitize_field( (string) $input[ $key ], $spec['sanitize'] );
				}
			}
			return $values;
		}

		/**
		 * Increment and check the best-effort per-client form-post rate limit.
		 *
		 * @since 1.4.0
		 *
		 * @return bool True while the client is within the limit.
		 */
		private function within_rate_limit() {
			$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$key   = 'cmplz_tc_wf_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, $this->rate_limit_window() );
			return $count <= $this->rate_limit_max();
		}

		/**
		 * Store PRG state in a short-lived transient and return its token.
		 *
		 * @since 1.4.0
		 *
		 * @param  array $state State to preserve across the redirect.
		 * @return string       The unguessable token.
		 */
		private function store_state( array $state ) {
			$token = wp_generate_password( 32, false );
			set_transient( self::STATE_PREFIX . $token, $state, $this->state_ttl() );
			return $token;
		}

		/**
		 * Resolve the page to redirect back to after a submission.
		 *
		 * @since 1.4.0
		 *
		 * @return string The submitting page, the Withdrawal page, or the home URL.
		 */
		private function redirect_base() {
			$referer = wp_get_referer();
			if ( $referer ) {
				return $referer;
			}
			$url = COMPLIANZ_TC::$document->get_withdrawal_page_url();
			return $url ? $url : home_url( '/' );
		}

		/**
		 * Append the state token to the redirect base (no personal data).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $token State token.
		 * @return string        The redirect URL.
		 */
		private function redirect_with_token( $token ) {
			$base = remove_query_arg( 'cmplz-tc-wf', $this->redirect_base() );
			return add_query_arg( 'cmplz-tc-wf', $token, $base );
		}

		/**
		 * Minimum seconds between form render and submit.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function min_submit_seconds() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_min_submit_seconds', 3 );
		}

		/**
		 * Maximum form posts allowed per client within the rate-limit window.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function rate_limit_max() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_rate_limit_max', 15 );
		}

		/**
		 * Rate-limit window in seconds.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function rate_limit_window() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_rate_limit_window', 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Lifetime in seconds of the PRG state transient.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function state_ttl() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_state_ttl', 10 * MINUTE_IN_SECONDS );
		}

		/**
		 * Send the merchant notification and consumer acknowledgement (FR-17/FR-18).
		 *
		 * Hooked to cmplz_tc_withdrawal_validated. The acknowledgement goes to a
		 * consumer-supplied address, so the dispatch is rate-limited in its own
		 * right (FR-14); on any wp_mail() failure a persistent admin notice is
		 * raised (FR-20) since Phase 1 stores no record to retry from.
		 *
		 * @since 1.4.0
		 *
		 * @param  mixed $data Action payload; expected to be the array of sanitized
		 *                     fields, submission timestamp and source URL.
		 * @return bool         True when nothing needed sending or both emails were
		 *                      accepted; false when a send failed (a notice is recorded).
		 */
		public function dispatch_emails( $data ) {
			if ( ! is_array( $data ) || empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
				return true;
			}

			// Own-link (or returns-off) path: the plugin sends no email (spec §7 / scenario 4).
			if ( ! COMPLIANZ_TC::$document->uses_withdrawal_form() ) {
				return true;
			}

			// FR-14: rate-limit the send, not only the form post. Suppression is intentional,
			// not a delivery failure, so the consumer is not shown an error.
			if ( ! $this->within_email_rate_limit() ) {
				$this->log( 'withdrawal email dispatch skipped: send rate limit exceeded' );
				return true;
			}

			$fields       = $data['fields'];
			$submitted_at = isset( $data['submitted_at'] ) ? (int) $data['submitted_at'] : time();
			$source_url   = isset( $data['source_url'] ) ? (string) $data['source_url'] : '';
			$consumer     = isset( $fields['cmplz_tc_wf_email'] ) ? (string) $fields['cmplz_tc_wf_email'] : '';

			$ok = $this->send_merchant_notification( $fields, $submitted_at, $source_url, $consumer );
			$ok = $this->send_consumer_acknowledgement( $fields, $submitted_at, $consumer ) && $ok;

			if ( ! $ok ) {
				$this->record_mail_failure();
			}

			return $ok;
		}

		/**
		 * Email the configured recipient with the full submission (FR-17 / §9.1).
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields       Sanitized §8 fields.
		 * @param  int    $submitted_at Submission timestamp.
		 * @param  string $source_url   The page the form was submitted from.
		 * @param  string $consumer     The consumer's email (Reply-To).
		 * @return bool                 True when wp_mail() reports success.
		 */
		private function send_merchant_notification( $fields, $submitted_at, $source_url, $consumer ) {
			// Recipient resolves never-empty via the Task-2b read-time filter.
			$recipient = (string) cmplz_tc_get_value( 'withdrawal_notification_email' );

			$subject = __( 'New withdrawal request', 'complianz-terms-conditions' );
			$body    = $this->merchant_body( $fields, $submitted_at, $source_url );
			$headers = $this->plain_text_headers();

			// Reply-To carries the consumer's validated address only (NFR-S2).
			$reply_to = $this->safe_email( $consumer );
			if ( '' !== $reply_to ) {
				$headers[] = 'Reply-To: ' . $reply_to;
			}

			/**
			 * Filters the merchant notification recipient.
			 *
			 * @since 1.4.0
			 *
			 * @param string $recipient The resolved recipient address.
			 * @param array  $fields    Sanitized §8 fields.
			 */
			$recipient = (string) apply_filters( 'cmplz_tc_withdrawal_merchant_recipient', $recipient, $fields );

			/**
			 * Filters the merchant notification subject.
			 *
			 * @since 1.4.0
			 *
			 * @param string $subject The subject line.
			 * @param array  $fields  Sanitized §8 fields.
			 */
			$subject = (string) apply_filters( 'cmplz_tc_withdrawal_merchant_subject', $subject, $fields );

			/**
			 * Filters the merchant notification body.
			 *
			 * @since 1.4.0
			 *
			 * @param string $body         The plain-text body.
			 * @param array  $fields       Sanitized §8 fields.
			 * @param int    $submitted_at Submission timestamp.
			 * @param string $source_url   The submitting page URL.
			 */
			$body = (string) apply_filters( 'cmplz_tc_withdrawal_merchant_body', $body, $fields, $submitted_at, $source_url );

			/**
			 * Filters the merchant notification headers.
			 *
			 * @since 1.4.0
			 *
			 * @param array  $headers  Email headers.
			 * @param string $consumer The consumer's email address.
			 */
			$headers = (array) apply_filters( 'cmplz_tc_withdrawal_merchant_headers', $headers, $consumer );

			return (bool) wp_mail( $recipient, $subject, $body, $headers );
		}

		/**
		 * Email the consumer the durable-medium acknowledgement (FR-18 / §9.2).
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields       Sanitized §8 fields.
		 * @param  int    $submitted_at Submission timestamp.
		 * @param  string $consumer     The consumer's email (recipient).
		 * @return bool                 True when wp_mail() reports success, or when
		 *                              there is no valid recipient to send to.
		 */
		private function send_consumer_acknowledgement( $fields, $submitted_at, $consumer ) {
			$recipient = $this->safe_email( $consumer );
			if ( '' === $recipient ) {
				// Validation guarantees a valid address upstream; nothing to send here.
				return true;
			}

			$subject = __( 'We have received your withdrawal request', 'complianz-terms-conditions' );
			$body    = $this->consumer_body( $fields, $submitted_at );
			$headers = $this->plain_text_headers();

			/** This filter is documented in send_merchant_notification(). */
			$subject = (string) apply_filters( 'cmplz_tc_withdrawal_consumer_subject', $subject, $fields );
			/** This filter is documented in send_merchant_notification(). */
			$body = (string) apply_filters( 'cmplz_tc_withdrawal_consumer_body', $body, $fields, $submitted_at );
			/** This filter is documented in send_merchant_notification(). */
			$headers = (array) apply_filters( 'cmplz_tc_withdrawal_consumer_headers', $headers, $recipient );

			return (bool) wp_mail( $recipient, $subject, $body, $headers );
		}

		/**
		 * Build the plain-text merchant notification body (§9.1).
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields       Sanitized §8 fields.
		 * @param  int    $submitted_at Submission timestamp.
		 * @param  string $source_url   The submitting page URL.
		 * @return string               Plain-text body.
		 */
		private function merchant_body( $fields, $submitted_at, $source_url ) {
			$lines   = array();
			$lines[] = __( 'A withdrawal request was submitted with the following details:', 'complianz-terms-conditions' );
			$lines[] = '';
			$lines   = array_merge( $lines, $this->field_lines( $fields ) );
			$lines[] = '';
			$lines[] = __( 'Submitted on', 'complianz-terms-conditions' ) . ': ' . $this->format_timestamp( $submitted_at );
			if ( '' !== $source_url ) {
				$lines[] = __( 'Submitted from', 'complianz-terms-conditions' ) . ': ' . $source_url;
			}
			return implode( "\n", $lines );
		}

		/**
		 * Build the plain-text consumer acknowledgement body (§9.2).
		 *
		 * @since 1.4.0
		 *
		 * @param  array $fields       Sanitized §8 fields.
		 * @param  int   $submitted_at Submission timestamp.
		 * @return string              Plain-text body.
		 */
		private function consumer_body( $fields, $submitted_at ) {
			$lines   = array();
			$lines[] = __( 'This is an automated confirmation that your withdrawal request has been received and sent to the merchant.', 'complianz-terms-conditions' );
			$lines[] = __( 'Receiving this message does not mean the merchant has already reviewed your request. They will contact you with any further information in due course.', 'complianz-terms-conditions' );
			$lines[] = '';
			$lines[] = __( 'For your records, these are the details you submitted:', 'complianz-terms-conditions' );
			$lines[] = '';
			$lines   = array_merge( $lines, $this->field_lines( $fields ) );
			$lines[] = '';
			$lines[] = __( 'Submitted on', 'complianz-terms-conditions' ) . ': ' . $this->format_timestamp( $submitted_at );

			$merchant = COMPLIANZ_TC::$document->get_merchant_contact_block();
			if ( '' !== $merchant ) {
				$lines[] = '';
				$lines[] = __( 'This acknowledgement was sent on behalf of:', 'complianz-terms-conditions' );
				$lines[] = $merchant;
			}
			return implode( "\n", $lines );
		}

		/**
		 * Human-readable, translatable labels for the §8 fields, in display order.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string,string> Field key => label.
		 */
		private function field_labels() {
			return array(
				'cmplz_tc_wf_name'       => __( 'Name', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_email'      => __( 'Email', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_address'    => __( 'Address', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_order_ref'  => __( 'Order or contract reference', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_order_date' => __( 'Order date', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_goods'      => __( 'Goods or service', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_message'    => __( 'Additional message', 'complianz-terms-conditions' ),
			);
		}

		/**
		 * Render the non-empty submitted fields as "Label: value" lines.
		 *
		 * Values are already sanitized (Task 7); the body is plain text, so no HTML
		 * escaping is applied (it would corrupt legitimate characters).
		 *
		 * @since 1.4.0
		 *
		 * @param  array $fields Sanitized §8 fields.
		 * @return array<int,string> Body lines.
		 */
		private function field_lines( $fields ) {
			$lines = array();
			foreach ( $this->field_labels() as $key => $label ) {
				$value = isset( $fields[ $key ] ) ? trim( (string) $fields[ $key ] ) : '';
				if ( '' !== $value ) {
					$lines[] = $label . ': ' . $value;
				}
			}
			return $lines;
		}

		/**
		 * Format a timestamp in the site's date/time format and timezone.
		 *
		 * @since 1.4.0
		 *
		 * @param  int $timestamp Unix timestamp.
		 * @return string         Localized date-time string.
		 */
		private function format_timestamp( $timestamp ) {
			return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
		}

		/**
		 * Default plain-text email headers.
		 *
		 * @since 1.4.0
		 *
		 * @return array<int,string>
		 */
		private function plain_text_headers() {
			return array( 'Content-Type: text/plain; charset=UTF-8' );
		}

		/**
		 * Validate and sanitize an address before it may touch a header (NFR-S2).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $email Candidate address.
		 * @return string        A valid address, or '' when invalid.
		 */
		private function safe_email( $email ) {
			$email = sanitize_email( (string) $email );
			return ( '' !== $email && is_email( $email ) ) ? $email : '';
		}

		/**
		 * Increment and check the best-effort per-client email-send rate limit.
		 *
		 * Separate from the form-post limit because the acknowledgement is sent to a
		 * consumer-supplied address (FR-14).
		 *
		 * @since 1.4.0
		 *
		 * @return bool True while the client is within the limit.
		 */
		private function within_email_rate_limit() {
			$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$key   = 'cmplz_tc_wf_email_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, $this->email_rate_limit_window() );
			return $count <= $this->email_rate_limit_max();
		}

		/**
		 * Maximum email dispatches allowed per client within the send window.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_rate_limit_max() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_rate_limit_max', 10 );
		}

		/**
		 * Email-send rate-limit window in seconds.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_rate_limit_window() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_rate_limit_window', 10 * MINUTE_IN_SECONDS );
		}

		/**
		 * Record a delivery failure so the persistent admin notice can show (FR-20).
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		private function record_mail_failure() {
			update_option( self::MAIL_FAILURE_OPTION, 1, false );
			$this->log( 'a withdrawal notification or acknowledgement email failed to send' );
		}

		/**
		 * Render the persistent delivery-failure admin notice (FR-20).
		 *
		 * Shown only to users who can act on the site's email configuration and only
		 * while a failure is on record; it carries no personal data (NFR-S4).
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function render_mail_failure_notice() {
			if ( ! current_user_can( 'manage_options' ) || ! get_option( self::MAIL_FAILURE_OPTION ) ) {
				return;
			}

			$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );
			?>
			<div class="notice notice-error">
				<p>
					<?php
					esc_html_e(
						'A withdrawal request was submitted but its notification email could not be delivered. Because Complianz Terms & Conditions does not store requests, please check your email or SMTP configuration so this does not happen again.',
						'complianz-terms-conditions'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'complianz-terms-conditions' ); ?></a>
				</p>
			</div>
			<?php
		}

		/**
		 * Clear the delivery-failure notice when the merchant dismisses it.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function maybe_dismiss_mail_failure_notice() {
			if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			check_admin_referer( self::DISMISS_ARG );
			delete_option( self::MAIL_FAILURE_OPTION );
			wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
			exit;
		}

		/**
		 * Log a message when debug logging is enabled (FR-20).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $message The message to log.
		 * @return void
		 */
		private function log( $message ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Complianz T&C withdrawal: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FR-20 requires logging a delivery failure; guarded by WP_DEBUG_LOG.
			}
		}
	}
}
