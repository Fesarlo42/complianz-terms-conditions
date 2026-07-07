<?php
/**
 * Tests for the Withdrawal page creation + asset enqueue (FR-6, FR-8, FR-9, §7.1).
 *
 * The provided-form path must be able to create a published "Withdrawal" page that
 * embeds the withdrawal form block/shortcode, track it via the option
 * cmplz_tc_withdrawal_page_id, expose its permalink with graceful degrade for the
 * generated T&C link, and never delete that page when the merchant switches paths.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-page-creation
 */
class Test_Withdrawal_Page_Creation extends WP_UnitTestCase {

	/**
	 * The T&C wizard options key.
	 *
	 * @var string
	 */
	private $options_key = 'complianz_tc_options_terms-conditions';

	/**
	 * The option that tracks the created Withdrawal page.
	 *
	 * @var string
	 */
	private $page_option = 'cmplz_tc_withdrawal_page_id';

	/**
	 * The document controller under test.
	 *
	 * @return cmplz_tc_document
	 */
	private function doc() {
		return COMPLIANZ_TC::$document;
	}

	/** Store answers that select the Complianz-provided form path. */
	private function set_form_path() {
		update_option(
			$this->options_key,
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'no',
			)
		);
	}

	/** Store answers that select the own-link path. */
	private function set_own_link_path() {
		update_option(
			$this->options_key,
			array(
				'if_returns'             => 'yes',
				'if_returns_custom'      => 'yes',
				'if_returns_custom_link' => 'https://merchant.example/withdraw',
			)
		);
	}

	/** Read the source of a document method for structural (wiring) assertions. */
	private function method_source( $method ) {
		$ref   = new ReflectionMethod( 'cmplz_tc_document', $method );
		$lines = file( $ref->getFileName() );
		return implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
	}

	public function set_up() {
		parent::set_up();
		// Page creation is capability-gated on manage_options.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( $this->page_option );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Path detection (the provided-form path).
	// ---------------------------------------------------------------------

	/** The form path is active when returns are offered and the merchant keeps the Complianz form. */
	public function test_uses_withdrawal_form_true_on_form_path() {
		$this->set_form_path();
		$this->assertTrue( $this->doc()->uses_withdrawal_form() );
	}

	/** Choosing the own link opts out of the provided form. */
	public function test_uses_withdrawal_form_false_on_own_link_path() {
		$this->set_own_link_path();
		$this->assertFalse( $this->doc()->uses_withdrawal_form() );
	}

	/** With no returns offered there is no withdrawal function at all. */
	public function test_uses_withdrawal_form_false_when_returns_not_offered() {
		update_option( $this->options_key, array( 'if_returns' => 'no' ) );
		$this->assertFalse( $this->doc()->uses_withdrawal_form() );
	}

	/** FR-2: a fresh configuration defaults to the provided form. */
	public function test_uses_withdrawal_form_true_on_fresh_default() {
		delete_option( $this->options_key );
		$this->assertTrue( $this->doc()->uses_withdrawal_form() );
	}

	// ---------------------------------------------------------------------
	// FR-6 — page creation + option tracking.
	// ---------------------------------------------------------------------

	/** create_page('withdrawal') publishes a page and records its id in the option. */
	public function test_create_withdrawal_page_publishes_and_stores_option() {
		$this->assertFalse( get_option( $this->page_option ), 'No withdrawal page should exist to begin with.' );

		$page_id = $this->doc()->create_page( 'withdrawal' );

		$this->assertIsInt( $page_id );
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( $page_id, (int) get_option( $this->page_option ), 'The created page id must be stored in cmplz_tc_withdrawal_page_id.' );

		$post = get_post( $page_id );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
	}

	/** FR-6/FR-7: the page embeds the withdrawal form block or its equivalent shortcode. */
	public function test_withdrawal_page_content_embeds_the_form() {
		$page_id = $this->doc()->create_page( 'withdrawal' );
		$content = get_post( $page_id )->post_content;

		$has_block     = false !== strpos( $content, 'complianztc/withdrawal-form' );
		$has_shortcode = false !== strpos( $content, '[cmplz-tc-withdrawal-form' );
		$this->assertTrue(
			$has_block || $has_shortcode,
			'The Withdrawal page must embed the withdrawal form block or shortcode.'
		);
	}

	/** Re-running creation must reuse the existing page rather than create a duplicate. */
	public function test_create_withdrawal_page_is_idempotent() {
		$first  = $this->doc()->create_page( 'withdrawal' );
		$second = $this->doc()->create_page( 'withdrawal' );

		$this->assertSame( $first, $second, 'Re-creating must return the existing page id.' );

		$pages       = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);
		$withdrawal = array_filter(
			$pages,
			static function ( $p ) {
				return false !== strpos( $p->post_content, 'withdrawal-form' );
			}
		);
		$this->assertCount( 1, $withdrawal, 'There must be exactly one Withdrawal page.' );
	}

	// ---------------------------------------------------------------------
	// FR-8 — linkage + graceful degrade.
	// ---------------------------------------------------------------------

	/** With no page created the URL is empty so the T&C link can degrade gracefully. */
	public function test_get_withdrawal_page_url_is_empty_without_a_page() {
		delete_option( $this->page_option );
		$this->assertSame( '', $this->doc()->get_withdrawal_page_url() );
	}

	/** Once created, the URL resolves to the page permalink. */
	public function test_get_withdrawal_page_url_returns_permalink() {
		$page_id = $this->doc()->create_page( 'withdrawal' );
		$this->assertSame( get_permalink( $page_id ), $this->doc()->get_withdrawal_page_url() );
	}

	/** A trashed page must not be treated as live (graceful degrade, no broken link). */
	public function test_get_withdrawal_page_degrades_when_trashed() {
		$page_id = $this->doc()->create_page( 'withdrawal' );
		wp_trash_post( $page_id );

		$this->assertFalse( $this->doc()->get_withdrawal_page_id(), 'A trashed page must not resolve as the live withdrawal page.' );
		$this->assertSame( '', $this->doc()->get_withdrawal_page_url() );
	}

	/** A dangling option (page hard-deleted) must degrade rather than error. */
	public function test_get_withdrawal_page_degrades_when_post_missing() {
		update_option( $this->page_option, 999999 );
		$this->assertFalse( $this->doc()->get_withdrawal_page_id() );
		$this->assertSame( '', $this->doc()->get_withdrawal_page_url() );
	}

	// ---------------------------------------------------------------------
	// ajax_create_pages hook — create on the form path only.
	// ---------------------------------------------------------------------

	/** On the form path the page is created; the option is set. */
	public function test_maybe_create_withdrawal_page_creates_on_form_path() {
		$this->set_form_path();
		$page_id = $this->doc()->maybe_create_withdrawal_page();

		$this->assertIsInt( $page_id );
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( $page_id, (int) get_option( $this->page_option ) );
	}

	/** On the own-link path no page is created. */
	public function test_maybe_create_withdrawal_page_is_noop_on_own_link_path() {
		$this->set_own_link_path();

		$this->assertFalse( $this->doc()->maybe_create_withdrawal_page() );
		$this->assertFalse( get_option( $this->page_option ) );
	}

	/** The ajax page-creation handler must wire in withdrawal-page creation. */
	public function test_ajax_create_pages_wires_in_withdrawal_creation() {
		$this->assertStringContainsString(
			'maybe_create_withdrawal_page',
			$this->method_source( 'ajax_create_pages' ),
			'ajax_create_pages() must trigger withdrawal-page creation for the form path.'
		);
	}

	// ---------------------------------------------------------------------
	// FR-9 — switching paths must never delete content.
	// ---------------------------------------------------------------------

	/** After creating on the form path, switching to the own link keeps the page intact. */
	public function test_switching_to_own_link_preserves_the_withdrawal_page() {
		$this->set_form_path();
		$page_id = $this->doc()->maybe_create_withdrawal_page();
		$this->assertGreaterThan( 0, $page_id );

		// Merchant switches to their own link and re-runs page creation.
		$this->set_own_link_path();
		$this->doc()->maybe_create_withdrawal_page();

		$this->assertSame( $page_id, (int) get_option( $this->page_option ), 'FR-9: the tracked page id must survive a path switch.' );
		$post = get_post( $page_id );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertSame( 'publish', $post->post_status, 'FR-9: switching paths must never delete the withdrawal page.' );
	}

	// ---------------------------------------------------------------------
	// Asset enqueue.
	// ---------------------------------------------------------------------

	/** The tracked page is identified so its assets can be enqueued. */
	public function test_is_withdrawal_page_identifies_the_tracked_page() {
		$page_id = $this->doc()->create_page( 'withdrawal' );
		$other   = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertTrue( $this->doc()->is_withdrawal_page( $page_id ) );
		$this->assertFalse( $this->doc()->is_withdrawal_page( $other ) );
	}

	/** enqueue_assets() must target the Withdrawal page in addition to document pages. */
	public function test_enqueue_assets_targets_the_withdrawal_page() {
		$this->assertStringContainsString(
			'is_withdrawal_page',
			$this->method_source( 'enqueue_assets' ),
			'enqueue_assets() must load assets on the Withdrawal page too.'
		);
	}

	// ---------------------------------------------------------------------
	// Wizard "Create document" table — the Withdrawal row (FR-6).
	// ---------------------------------------------------------------------

	/** Capture the wizard pages step, loading the admin icon helper it relies on. */
	private function render_wizard_pages() {
		if ( ! function_exists( 'cmplz_tc_icon' ) ) {
			require_once dirname( __DIR__ ) . '/assets/icons.php';
		}
		ob_start();
		$this->doc()->callback_wizard_add_pages();
		return (string) ob_get_clean();
	}

	/** On the provided-form path the wizard lists a Withdrawal page row. */
	public function test_wizard_lists_withdrawal_row_on_form_path() {
		$this->set_form_path();
		$html = $this->render_wizard_pages();

		$this->assertStringContainsString( 'name="withdrawal"', $html, 'The wizard must list a Withdrawal page row on the form path.' );
		$this->assertStringContainsString( 'withdrawal-form', $html, 'The row must expose the withdrawal form block/shortcode.' );
	}

	/** On the own-link path there is no Withdrawal row. */
	public function test_wizard_hides_withdrawal_row_on_own_link_path() {
		$this->set_own_link_path();
		$html = $this->render_wizard_pages();

		$this->assertStringNotContainsString( 'name="withdrawal"', $html, 'The Withdrawal row must not appear on the own-link path.' );
	}

	/** A created Withdrawal page renders as a valid page in the table. */
	public function test_wizard_row_reflects_created_page() {
		$this->set_form_path();
		$this->doc()->create_page( 'withdrawal' );
		$html = $this->render_wizard_pages();

		$this->assertStringContainsString( 'cmplz-valid-page', $html, 'A created Withdrawal page must render as a valid page.' );
	}

	// ---------------------------------------------------------------------
	// Missing-pages prompt + option-based ajax handling.
	// ---------------------------------------------------------------------

	/** A missing Withdrawal page is reported so the wizard prompts creation. */
	public function test_has_missing_pages_flags_absent_withdrawal_on_form_path() {
		$this->set_form_path();
		delete_option( $this->page_option );
		$this->assertTrue( $this->doc()->has_missing_pages() );
	}

	/** With both the document and withdrawal pages created, nothing is missing. */
	public function test_has_missing_pages_clears_when_pages_created() {
		$this->set_form_path();
		$this->doc()->create_page( 'terms-conditions' );
		$this->doc()->create_page( 'withdrawal' );
		$this->assertFalse( $this->doc()->has_missing_pages() );
	}

	/** The ajax handler resolves the Withdrawal page by option, not the T&C scan. */
	public function test_ajax_create_pages_handles_withdrawal_by_option() {
		$this->assertStringContainsString(
			'get_withdrawal_page_id',
			$this->method_source( 'ajax_create_pages' ),
			'ajax_create_pages() must resolve the Withdrawal page by its option, not the T&C shortcode scan.'
		);
	}
}
