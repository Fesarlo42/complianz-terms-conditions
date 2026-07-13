<?php
/**
 * Tests for the withdrawal-form Gutenberg block + [cmplz-tc-withdrawal-form]
 * shortcode, and the merchant-identity heading fed from the generator config.
 *
 * Both entry points must render the template through
 * cmplz_tc_get_template( 'withdrawal-form.php', $args ) and produce identical
 * output. Because the form may be embedded on an arbitrary page (not only the
 * tracked Withdrawal page), the form's CSS/JS must be enqueued on render.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-block-shortcode
 */
class Test_Withdrawal_Block_Shortcode extends WP_UnitTestCase {

	/** The T&C wizard options key that holds the merchant identity fields. */
	private $options_key = 'complianz_tc_options_terms-conditions';

	/** The withdrawal-form block name. */
	private $block_name = 'complianztc/withdrawal-form';

	/** The withdrawal-form shortcode tag. */
	private $shortcode = 'cmplz-tc-withdrawal-form';

	/** The document controller under test. */
	private function doc() {
		return COMPLIANZ_TC::$document;
	}

	public function set_up() {
		parent::set_up();
		// Style/script registries are global singletons; reset so enqueue state
		// does not leak between tests.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		// The render guard persists on the shared document instance across tests.
		$this->doc()->reset_withdrawal_render_guard();
	}

	public function tear_down() {
		delete_option( $this->options_key );
		delete_option( 'cmplz_tc_withdrawal_page_id' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Shortcode.
	// ---------------------------------------------------------------------

	/** The shortcode must be registered. */
	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( $this->shortcode ) );
	}

	/** The shortcode must render the interactive form and consume its own tag. */
	public function test_shortcode_renders_form() {
		$out = do_shortcode( '[' . $this->shortcode . ']' );
		$this->assertStringContainsString( '<form', $out, 'The shortcode must render the form.' );
		$this->assertStringContainsString( 'cmplz-tc-withdrawal-form', $out );
		$this->assertMatchesRegularExpression( '/name=["\']cmplz_tc_wf_name["\']/', $out, 'A form field must be present.' );
		$this->assertStringNotContainsString( '[' . $this->shortcode . ']', $out, 'No raw shortcode may leak into the output.' );
	}

	// ---------------------------------------------------------------------
	// Block.
	// ---------------------------------------------------------------------

	/** The block must be registered with a render callback. */
	public function test_block_is_registered() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( $this->block_name );
		$this->assertInstanceOf( 'WP_Block_Type', $block, 'The withdrawal-form block must be registered.' );
		$this->assertTrue( is_callable( $block->render_callback ), 'The block must render server-side.' );
	}

	/** The block render callback must render the interactive form. */
	public function test_block_renders_form() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( $this->block_name );
		$out   = call_user_func( $block->render_callback, array(), '' );
		$this->assertStringContainsString( '<form', $out, 'The block must render the form.' );
		$this->assertMatchesRegularExpression( '/name=["\']cmplz_tc_wf_name["\']/', $out );
	}

	/** Block and shortcode must produce identical output. */
	public function test_block_and_shortcode_produce_identical_output() {
		$block        = WP_Block_Type_Registry::get_instance()->get_registered( $this->block_name );
		$block_output = call_user_func( $block->render_callback, array(), '' );
		// Both entry points share the once-per-request guard; reset so the shortcode
		// renders too and the two outputs can be compared.
		$this->doc()->reset_withdrawal_render_guard();
		$shortcode_output = do_shortcode( '[' . $this->shortcode . ']' );
		$this->assertSame( $shortcode_output, $block_output, 'Block and shortcode output must be identical.' );
	}

	/** The form renders at most once per page; a second embed outputs nothing and leaves no raw tag. */
	public function test_second_embed_on_page_renders_nothing() {
		$first  = do_shortcode( '[' . $this->shortcode . ']' );
		$second = do_shortcode( '[' . $this->shortcode . ']' );
		$this->assertStringContainsString( '<form', $first, 'The first embed must render the form.' );
		$this->assertSame( '', $second, 'A second embed on the same request must render nothing.' );

		// Two tags in one content string: exactly one form, and no raw shortcode leaks.
		$this->doc()->reset_withdrawal_render_guard();
		$combined = do_shortcode( '[' . $this->shortcode . '][' . $this->shortcode . ']' );
		$this->assertSame( 1, substr_count( $combined, '<form' ), 'Only one form may render per page.' );
		$this->assertStringNotContainsString( '[' . $this->shortcode . ']', $combined, 'No raw shortcode tag may leak into the content.' );
	}

	// ---------------------------------------------------------------------
	// Merchant identity heading from the generator config.
	// ---------------------------------------------------------------------

	/** The merchant name + address configured in the wizard appear as a heading. */
	public function test_merchant_identity_rendered_from_config() {
		update_option(
			$this->options_key,
			array(
				'organisation_name' => 'Acme Webshop BV',
				'address_company'   => "1 Market Street\n1000 AA Amsterdam",
			)
		);

		$out = do_shortcode( '[' . $this->shortcode . ']' );
		$this->assertStringContainsString( 'Acme Webshop BV', $out, 'The merchant name must be pre-filled.' );
		$this->assertStringContainsString( 'Amsterdam', $out, 'The merchant address must be pre-filled.' );
	}

	/** get_merchant_identity() joins the configured name and address. */
	public function test_get_merchant_identity_joins_name_and_address() {
		update_option(
			$this->options_key,
			array(
				'organisation_name' => 'Acme Webshop BV',
				'address_company'   => "1 Market Street\n1000 AA Amsterdam",
			)
		);

		$identity = $this->doc()->get_merchant_identity();
		$this->assertStringContainsString( 'Acme Webshop BV', $identity );
		$this->assertStringContainsString( '1 Market Street', $identity );
	}

	// ---------------------------------------------------------------------
	// Assets enqueue on render for arbitrary-page embeds.
	// ---------------------------------------------------------------------

	/** Rendering the form must enqueue its CSS/JS even off the tracked page. */
	public function test_assets_enqueue_on_render() {
		// Not on the tracked Withdrawal page (no page option, no global $post).
		$this->assertFalse( $this->doc()->is_withdrawal_page() );

		do_shortcode( '[' . $this->shortcode . ']' );

		$this->assertTrue( wp_style_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form CSS must enqueue on render.' );
		$this->assertTrue( wp_script_is( 'cmplz-tc-withdrawal-form', 'enqueued' ), 'The form JS must enqueue on render.' );
	}
}
