<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows plugin slug convention; class name cannot be changed without breaking the codebase.
/**
 * Withdrawal submission handler: server-side validation, anti-abuse and Post/Redirect/Get.
 *
 * The public endpoint does nothing privileged; a valid submission fires the
 * cmplz_tc_withdrawal_validated action and dispatches the emails.
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

		/** Option flag raised when an email could not be sent. */
		const MAIL_FAILURE_OPTION = 'cmplz_tc_withdrawal_mail_failure';

		/** Query arg that dismisses the delivery-failure admin notice. */
		const DISMISS_ARG = 'cmplz_tc_wf_dismiss_mail_failure';

		/**
		 * Holds the single instance of this class.
		 *
		 * @since 1.4.0
		 *
		 * @var cmplz_tc_withdrawal|null
		 */
		private static $_this; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Underscore prefix is part of the established singleton accessor pattern used throughout this codebase.

		/**
		 * Enforce the singleton and register the hooks.
		 *
		 * @since 1.4.0
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
		 * Return the single instance of this class.
		 *
		 * @since 1.4.0
		 *
		 * @return cmplz_tc_withdrawal The singleton instance.
		 */
		public static function this() {
			return self::$_this;
		}

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

			add_action( 'admin_notices', array( $this, 'render_mail_failure_notice' ) );
			add_action( 'admin_init', array( $this, 'maybe_dismiss_mail_failure_notice' ) );

			// dispatch_emails() is intentionally not hooked here: process() calls it directly so its
			// result can drive the consumer's screen. cmplz_tc_withdrawal_validated stays a seam for integrators.
		}

		/**
		 * Handle an admin-post submission: process, then redirect (PRG).
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function handle_submission() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Soft nonce + per-field sanitization happen in process().
			$result = $this->process( (array) wp_unslash( $_POST ) );
			wp_safe_redirect( $result['redirect'] );
			exit;
		}

		/**
		 * Validate, screen and route a submission without redirecting (the testable core of the handler).
		 *
		 * @since 1.4.0
		 *
		 * @param  array $input Raw (unslashed) submitted values.
		 * @return array {
		 *     @type string $status   One of success|delivery_error|try_again_later|unavailable|invalid|invalid_nonce|too_fast|rate_limited|spam.
		 *     @type array  $errors   Field-name (or general key) => message.
		 *     @type array  $values   Sanitized values preserved for re-render.
		 *     @type string $token    State transient token, or '' when none was stored.
		 *     @type string $redirect Target URL for the PRG redirect.
		 * }
		 */
		public function process( array $input ) {
			// The endpoint stays live on every request, but only the built-in-form path may process a submission: on the own-link/returns-off path, reject before any side-effect (no action, no dispatch, no stored state, no success).
			if ( ! COMPLIANZ_TC::$document->uses_withdrawal_form() ) {
				return array(
					'status'   => 'unavailable',
					'errors'   => array(),
					'values'   => array(),
					'token'    => '',
					'redirect' => $this->redirect_base(),
				);
			}

			if ( ! $this->within_rate_limit() ) {
				// Reject with a tokenless status instead of storing per-request state, so a flood cannot inflate wp_options.
				return array(
					'status'   => 'rate_limited',
					'errors'   => array(),
					'values'   => array(),
					'token'    => '',
					'redirect' => $this->redirect_with_status( 'rate_limited' ),
				);
			}

			// Honeypot: a filled hidden field means a bot — silent drop.
			if ( '' !== trim( (string) ( isset( $input['cmplz_tc_wf_website'] ) ? $input['cmplz_tc_wf_website'] : '' ) ) ) {
				return $this->reject_spam();
			}

			/**
			 * Opt-in spam gate (off by default): return true to drop the submission silently.
			 *
			 * @since 1.4.0
			 *
			 * @param bool  $is_spam Whether to treat the submission as spam. Default false.
			 * @param array $input   Raw (unslashed) submitted values.
			 */
			if ( true === apply_filters( 'cmplz_tc_withdrawal_spam_check', false, $input ) ) {
				return $this->reject_spam();
			}

			// Nonce: present-but-invalid is a soft failure; absent does not block.
			$nonce = (string) ( isset( $input['cmplz_tc_wf_nonce'] ) ? $input['cmplz_tc_wf_nonce'] : '' );
			if ( '' !== $nonce && ! $this->verify_nonce( $nonce ) ) {
				return $this->fail( 'invalid_nonce', $this->preserve( $input ), __( 'Your session has expired. Please review the details below and submit again.', 'complianz-terms-conditions' ) );
			}

			// Minimum time-to-submit: the timestamp is mandatory and rendered server-side, Missing/non-numeric/too-recent soft-fail.
			$rendered = (string) ( isset( $input['cmplz_tc_wf_rendered'] ) ? $input['cmplz_tc_wf_rendered'] : '' );
			if ( '' === $rendered || ! ctype_digit( $rendered ) || ( time() - (int) $rendered ) < $this->min_submit_seconds() ) {
				return $this->fail( 'too_fast', $this->preserve( $input ), __( 'That was a little too quick. Please review the details below and submit again.', 'complianz-terms-conditions' ) );
			}

			$clean = array();
			foreach ( $this->fields() as $key => $spec ) {
				$clean[ $key ] = $this->sanitize_field( (string) ( isset( $input[ $key ] ) ? $input[ $key ] : '' ), $spec['sanitize'] );
			}
			$errors = $this->validate( $clean );
			if ( ! empty( $errors ) ) {
				return $this->fail( 'invalid', $clean, '', $errors );
			}

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

			$status = $this->dispatch_emails( $data );
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
		 * Mint the withdrawal nonce in a forced logged-out context.
		 *
		 * A cookie-authenticated REST GET without an X-WP-Nonce header is downgraded to the
		 * logged-out user by core, so minting and verifying both logged-out keeps the nonce
		 * consistent for logged-in and logged-out visitors alike.
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
		 * Field spec: required flag, sanitize type, and (for required fields) the presence-error
		 * message. validate() reads this, so required-ness is data rather than hardcoded.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string,array{required:bool,sanitize:string,error_message?:string}>
		 */
		private function fields() {
			return array(
				'cmplz_tc_wf_name'       => array(
					'required'      => true,
					'sanitize'      => 'text',
					'error_message' => __( 'Please enter your name.', 'complianz-terms-conditions' ),
				),
				'cmplz_tc_wf_email'      => array(
					'required'      => true,
					'sanitize'      => 'email',
					'error_message' => __( 'Please enter your email address.', 'complianz-terms-conditions' ),
				),
				'cmplz_tc_wf_goods'      => array(
					'required'      => true,
					'sanitize'      => 'textarea',
					'error_message' => __( 'Please describe the goods or service you are withdrawing from.', 'complianz-terms-conditions' ),
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
		 * Sanitize a single value by field type.
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

			// Required-field presence, driven by the field spec.
			foreach ( $this->fields() as $key => $spec ) {
				if ( empty( $spec['required'] ) ) {
					continue;
				}
				if ( '' === trim( (string) ( isset( $clean[ $key ] ) ? $clean[ $key ] : '' ) ) ) {
					$errors[ $key ] = isset( $spec['error_message'] ) ? (string) $spec['error_message'] : '';
				}
			}

			// Email format is a rule beyond presence, so it stays a dedicated check.
			$email = trim( (string) ( isset( $clean['cmplz_tc_wf_email'] ) ? $clean['cmplz_tc_wf_email'] : '' ) );
			if ( ! isset( $errors['cmplz_tc_wf_email'] ) && '' !== $email && ! is_email( $email ) ) {
				$errors['cmplz_tc_wf_email'] = __( 'Please enter a valid email address.', 'complianz-terms-conditions' );
			}

			// Length caps.
			foreach ( $this->field_max_lengths() as $key => $limit ) {
				if ( isset( $errors[ $key ] ) ) {
					continue;
				}
				$value = isset( $clean[ $key ] ) ? (string) $clean[ $key ] : '';
				if ( mb_strlen( $value ) > (int) $limit ) {
					$errors[ $key ] = __( 'This value is too long. Please shorten it and try again.', 'complianz-terms-conditions' );
				}
			}

			return $errors;
		}

		/**
		 * Maximum accepted length per field, in characters. Generous enough that a genuine
		 * consumer never hits them; they only stop multi-megabyte payloads inflating the emails.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string,int> Field key => maximum length.
		 */
		private function field_max_lengths() {
			$defaults = array(
				'cmplz_tc_wf_name'       => 200,
				'cmplz_tc_wf_email'      => 254,
				'cmplz_tc_wf_goods'      => 5000,
				'cmplz_tc_wf_address'    => 5000,
				'cmplz_tc_wf_order_ref'  => 200,
				'cmplz_tc_wf_order_date' => 20,
				'cmplz_tc_wf_message'    => 5000,
			);

			/**
			 * Filters the maximum accepted length per withdrawal field.
			 *
			 * @since 1.4.0
			 *
			 * @param array<string,int> $defaults Field key => maximum length in characters.
			 */
			return (array) apply_filters( 'cmplz_tc_withdrawal_field_max_lengths', $defaults );
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
			return $this->hit_counter( $this->client_key( 'cmplz_tc_wf_rl_' ), $this->rate_limit_window(), $this->rate_limit_max() );
		}

		/**
		 * The best-effort client IP used to key every rate limit.
		 *
		 * Uses REMOTE_ADDR (never the spoofable X-Forwarded-For); a site behind a CDN/proxy can
		 * supply the real client IP via the filter so a shared edge IP doesn't bucket everyone.
		 *
		 * @since 1.4.0
		 *
		 * @return string The client IP, or '' when unavailable.
		 */
		private function client_ip() {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			/**
			 * Filters the client IP used for withdrawal rate limiting.
			 *
			 * @since 1.4.0
			 *
			 * @param string $ip The REMOTE_ADDR-derived client IP.
			 */
			return (string) apply_filters( 'cmplz_tc_withdrawal_client_ip', $ip );
		}

		/**
		 * Increment a windowed transient counter and report whether it is within the max.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $key    Transient key.
		 * @param  int    $window Window in seconds.
		 * @param  int    $max    Maximum hits allowed within the window.
		 * @return bool           True while the counter is at or below the max.
		 */
		private function hit_counter( $key, $window, $max ) {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, $window );
			return $count <= $max;
		}

		/**
		 * Per-client transient key for a rate limit with the given prefix.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $prefix Transient key prefix.
		 * @return string         The prefixed, client-IP-derived key.
		 */
		private function client_key( $prefix ) {
			return $prefix . md5( $this->client_ip() . '|' . wp_salt( 'nonce' ) );
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
		 * Append a reserved, tokenless status to the redirect base (carries no stored state).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $status Reserved status keyword.
		 * @return string         The redirect URL.
		 */
		private function redirect_with_status( $status ) {
			$base = remove_query_arg( 'cmplz-tc-wf', $this->redirect_base() );
			return add_query_arg( 'cmplz-tc-wf', $status, $base );
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
		 * Kept short: the failure path briefly stores sanitized PII and the state is one-shot,
		 * so this only caps an abandoned redirect — 5 minutes is ample for the round-trip.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function state_ttl() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_state_ttl', 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Send the merchant notification and consumer acknowledgement.
		 *
		 * @since 1.4.0
		 *
		 * @param  mixed $data Action payload: sanitized fields, submission timestamp and source URL.
		 * @return string      'success' (both sent, or nothing to send), 'delivery_error' (a send
		 *                     failed; a notice is recorded), or 'try_again_later' (a throttle
		 *                     suppressed the send).
		 */
		public function dispatch_emails( $data ) {
			if ( ! is_array( $data ) || empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
				return 'success';
			}

			// Own-link (or returns-off) path: the plugin sends no email.
			if ( ! COMPLIANZ_TC::$document->uses_withdrawal_form() ) {
				return 'success';
			}

			$fields   = $data['fields'];
			$consumer = isset( $fields['cmplz_tc_wf_email'] ) ? (string) $fields['cmplz_tc_wf_email'] : '';

			// Rate-limit the SEND (not just the form post) across three layers: per-client IP,
			// per-recipient (amplification aimed at one address), and a site-wide ceiling
			// (distributed / IP-rotating floods). Any trip suppresses BOTH emails and asks the consumer to retry.
			if ( ! $this->within_email_rate_limit()
				|| ! $this->within_recipient_email_limit( $consumer )
				|| ! $this->within_global_email_limit() ) {
				$this->log( 'withdrawal email dispatch throttled; consumer asked to retry later' );
				return 'try_again_later';
			}

			$submitted_at = isset( $data['submitted_at'] ) ? (int) $data['submitted_at'] : time();
			$source_url   = isset( $data['source_url'] ) ? (string) $data['source_url'] : '';

			$merchant_ok = $this->send_merchant_notification( $fields, $submitted_at, $source_url, $consumer );
			// The acknowledgement wording depends on whether the merchant received the request.
			$consumer_ok = $this->send_consumer_acknowledgement( $fields, $submitted_at, $consumer, $merchant_ok );

			if ( $merchant_ok && $consumer_ok ) {
				return 'success';
			}

			$this->record_mail_failure();
			return 'delivery_error';
		}

		/**
		 * Email the configured recipient with the full submission.
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields       Sanitized form fields.
		 * @param  int    $submitted_at Submission timestamp.
		 * @param  string $source_url   The page the form was submitted from.
		 * @param  string $consumer     The consumer's email (Reply-To).
		 * @return bool                 True when wp_mail() reports success.
		 */
		private function send_merchant_notification( $fields, $submitted_at, $source_url, $consumer ) {
			// Recipient resolves never-empty via the read-time filter.
			$recipient = (string) cmplz_tc_get_value( 'withdrawal_notification_email' );

			$recipient = $this->safe_email( $recipient );
			if ( '' === $recipient ) {
				$recipient = $this->safe_email( (string) get_option( 'admin_email' ) );
			}

			$subject = __( 'New withdrawal request', 'complianz-terms-conditions' );
			$body    = $this->merchant_body( $fields, $submitted_at, $source_url );
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

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
			 * @param array  $fields    Sanitized form fields.
			 */
			$recipient = (string) apply_filters( 'cmplz_tc_withdrawal_merchant_recipient', $recipient, $fields );

			/**
			 * Filters the merchant notification subject.
			 *
			 * @since 1.4.0
			 *
			 * @param string $subject The subject line.
			 * @param array  $fields  Sanitized form fields.
			 */
			$subject = (string) apply_filters( 'cmplz_tc_withdrawal_merchant_subject', $subject, $fields );

			/**
			 * Filters the merchant notification body.
			 *
			 * @since 1.4.0
			 *
			 * @param string $body         The plain-text body.
			 * @param array  $fields       Sanitized form fields.
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
		 * Email the consumer the durable-medium acknowledgement.
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields            Sanitized form fields.
		 * @param  int    $submitted_at      Submission timestamp.
		 * @param  string $consumer          The consumer's email (recipient).
		 * @param  bool   $merchant_delivered Whether the merchant notification was accepted.
		 * @return bool                       True when wp_mail() reports success, or when
		 *                                    there is no valid recipient to send to.
		 */
		private function send_consumer_acknowledgement( $fields, $submitted_at, $consumer, $merchant_delivered = true ) {
			$recipient = $this->safe_email( $consumer );
			if ( '' === $recipient ) {
				// Validation guarantees a valid address upstream; nothing to send here.
				return true;
			}

			$subject = __( 'We have received your withdrawal request', 'complianz-terms-conditions' );
			$body    = $this->consumer_body( $fields, $submitted_at, $merchant_delivered );
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

			/** This filters are documented in send_merchant_notification(). */
			$subject = (string) apply_filters( 'cmplz_tc_withdrawal_consumer_subject', $subject, $fields );
			$body    = (string) apply_filters( 'cmplz_tc_withdrawal_consumer_body', $body, $fields, $submitted_at );
			$headers = (array) apply_filters( 'cmplz_tc_withdrawal_consumer_headers', $headers, $recipient );

			return (bool) wp_mail( $recipient, $subject, $body, $headers );
		}

		/**
		 * Build the plain-text merchant notification body.
		 *
		 * @since 1.4.0
		 *
		 * @param  array  $fields       Sanitized form fields.
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
		 * Build the plain-text consumer acknowledgement body.
		 *
		 * @since 1.4.0
		 *
		 * @param  array $fields            Sanitized form fields.
		 * @param  int   $submitted_at      Submission timestamp.
		 * @param  bool  $merchant_delivered Whether the merchant notification was accepted.
		 * @return string                    Plain-text body.
		 */
		private function consumer_body( $fields, $submitted_at, $merchant_delivered = true ) {
			$lines = array();
			if ( $merchant_delivered ) {
				$lines[] = __( 'This is an automated confirmation that your withdrawal request has been received and sent to the merchant.', 'complianz-terms-conditions' );
				$lines[] = __( 'Receiving this message does not mean the merchant has already reviewed your request. They will contact you with any further information in due course.', 'complianz-terms-conditions' );
			} else {
				$lines[] = __( 'This is a copy of the withdrawal request you submitted. We could not deliver it to the merchant automatically, so please contact them directly to complete your withdrawal.', 'complianz-terms-conditions' );
			}
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
		 * Human-readable, translatable labels for the form fields, in display order.
		 *
		 * @since 1.4.0
		 *
		 * @return array<string,string> Field key => label.
		 */
		private function field_labels() {
			// Order mirrors the form template so the email reads in the same sequence.
			return array(
				'cmplz_tc_wf_name'       => __( 'Name', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_email'      => __( 'Email', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_goods'      => __( 'Goods or service', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_address'    => __( 'Address', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_order_ref'  => __( 'Order or contract reference', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_order_date' => __( 'Order date', 'complianz-terms-conditions' ),
				'cmplz_tc_wf_message'    => __( 'Additional message', 'complianz-terms-conditions' ),
			);
		}

		/**
		 * Render the non-empty submitted fields as "Label: value" lines.
		 *
		 * Values are already sanitized and the body is plain text, so no HTML escaping is
		 * applied (it would corrupt legitimate characters).
		 *
		 * @since 1.4.0
		 *
		 * @param  array $fields Sanitized form fields.
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
		 * Validate and sanitize an address before it may touch a header.
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
		 * Per-client email-send rate limit, separate from the form-post limit because the
		 * acknowledgement goes to a consumer-supplied address.
		 *
		 * @since 1.4.0
		 *
		 * @return bool True while the client is within the limit.
		 */
		private function within_email_rate_limit() {
			return $this->hit_counter( $this->client_key( 'cmplz_tc_wf_email_rl_' ), $this->email_rate_limit_window(), $this->email_rate_limit_max() );
		}

		/**
		 * Per-recipient acknowledgement throttle, keyed on the consumer address so the form
		 * can't be turned into an amplifier blasting one victim from the merchant's domain.
		 * An empty address is not throttled (validation guarantees a real one upstream).
		 *
		 * @since 1.4.0
		 *
		 * @param  string $consumer The consumer email address.
		 * @return bool              True while the address is within the limit.
		 */
		private function within_recipient_email_limit( $consumer ) {
			$consumer = strtolower( trim( (string) $consumer ) );
			if ( '' === $consumer ) {
				return true;
			}
			$key = 'cmplz_tc_wf_email_rcpt_' . md5( $consumer . '|' . wp_salt( 'nonce' ) );
			return $this->hit_counter( $key, $this->email_recipient_window(), $this->email_recipient_max() );
		}

		/**
		 * Site-wide email-send ceiling: one filterable counter bounding total outbound
		 * withdrawal emails per window — the control that survives IP rotation and distributed
		 * floods. Set generously; raise via the max filter for high legitimate volume.
		 *
		 * @since 1.4.0
		 *
		 * @return bool True while the site is within the ceiling.
		 */
		private function within_global_email_limit() {
			return $this->hit_counter( 'cmplz_tc_wf_email_global', $this->email_global_window(), $this->email_global_max() );
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
		 * Maximum acknowledgements sent to a single address within the recipient window.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_recipient_max() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_per_recipient_max', 3 );
		}

		/**
		 * Per-recipient throttle window in seconds.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_recipient_window() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_per_recipient_window', HOUR_IN_SECONDS );
		}

		/**
		 * Site-wide maximum withdrawal emails per global window. Raise via the filter.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_global_max() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_global_max', 50 );
		}

		/**
		 * Site-wide email-ceiling window in seconds.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function email_global_window() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_email_global_window', HOUR_IN_SECONDS );
		}

		/**
		 * Per-IP throttle on the uncached nonce endpoint, bounding mass nonce minting.
		 * Harmless to a genuine consumer: an absent nonce is accepted, so a throttled load still submits.
		 *
		 * @since 1.4.0
		 *
		 * @return bool True while the client is within the limit.
		 */
		public function within_nonce_endpoint_rate_limit() {
			return $this->hit_counter( $this->client_key( 'cmplz_tc_wf_nonce_rl_' ), $this->nonce_endpoint_window(), $this->nonce_endpoint_max() );
		}

		/**
		 * Maximum nonce-endpoint requests allowed per client within the window.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function nonce_endpoint_max() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_nonce_endpoint_max', 30 );
		}

		/**
		 * Nonce-endpoint throttle window in seconds.
		 *
		 * @since 1.4.0
		 *
		 * @return int
		 */
		private function nonce_endpoint_window() {
			return (int) apply_filters( 'cmplz_tc_withdrawal_nonce_endpoint_window', MINUTE_IN_SECONDS );
		}

		/**
		 * Record a delivery failure so the persistent admin notice can show.
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
		 * Render the persistent delivery-failure admin notice.
		 *
		 * Shown only to users who can fix the site's email config and only while a failure is
		 * on record; carries no personal data.
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
		 * Log a message when debug logging is enabled.
		 *
		 * @since 1.4.0
		 *
		 * @param  string $message The message to log.
		 * @return void
		 */
		private function log( $message ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Complianz T&C withdrawal: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Delivery-failure logging, guarded by WP_DEBUG_LOG.
			}
		}
	}
}
