<?php
/**
 * Tests for the generated withdrawal T&C clauses + [withdrawal_form_link] repoint
 * (FR-8, FR-21, FR-22).
 *
 * The provided-form clause must show on the Complianz-form path, the own-link clause
 * only on the own-link path, and the durable-medium acknowledgement clause on BOTH
 * paths. The [withdrawal_form_link] token must resolve to the Withdrawal page
 * permalink (never the legacy PDF, never a leftover token), degrading gracefully when
 * the page is absent.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-clauses
 */
class Test_Withdrawal_Clauses extends WP_UnitTestCase {

	/**
	 * The T&C wizard options key.
	 *
	 * @var string
	 */
	private $options_key = 'complianz_tc_options_terms-conditions';

	/**
	 * The document controller under test.
	 *
	 * @return cmplz_tc_document
	 */
	private function doc() {
		return COMPLIANZ_TC::$document;
	}

	/** The configured Terms & Conditions clause list. */
	private function clauses() {
		return COMPLIANZ_TC::$config->pages['all']['terms-conditions']['document_elements'];
	}

	/** Return the first clause whose content contains $needle, or null. */
	private function find_clause( $needle ) {
		foreach ( $this->clauses() as $clause ) {
			if ( isset( $clause['content'] ) && false !== strpos( $clause['content'], $needle ) ) {
				return $clause;
			}
		}
		return null;
	}

	private function set_form_path() {
		update_option(
			$this->options_key,
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'no',
			)
		);
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'cmplz_tc_withdrawal_page_id' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// FR-21 — the generated text reflects the selected path.
	// ---------------------------------------------------------------------

	/** The provided-form clause must be enabled on the Complianz-form path. */
	public function test_provided_form_clause_is_enabled_on_form_path() {
		$clause = $this->find_clause( '[withdrawal_form_link]' );
		$this->assertIsArray( $clause, 'The provided-form withdrawal clause must exist.' );
		$this->assertSame(
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'no',
			),
			$clause['condition'],
			'The provided-form clause must show on the Complianz-form path.'
		);
	}

	/** The sentinel that hid the provided-form clause must be gone. */
	public function test_provided_form_clause_no_longer_uses_the_disabled_sentinel() {
		$clause = $this->find_clause( '[withdrawal_form_link]' );
		$this->assertNotSame( 'disabled', $clause['condition']['if_returns_custom'] );
	}

	/** The own-link clause must show only on the own-link path. */
	public function test_own_link_clause_is_scoped_to_own_link_path() {
		$clause = $this->find_clause( '[if_returns_custom_link]' );
		$this->assertIsArray( $clause, 'The own-link withdrawal clause must exist.' );
		$this->assertSame(
			array(
				'if_returns'        => 'yes',
				'if_returns_custom' => 'yes',
			),
			$clause['condition'],
			'The own-link clause must be scoped to the own-link path (FR-21).'
		);
	}

	// ---------------------------------------------------------------------
	// FR-22 — acknowledgement clause stays on BOTH paths (landmine).
	// ---------------------------------------------------------------------

	/** The durable-medium acknowledgement clause must NOT be gated to a path. */
	public function test_acknowledgement_clause_stays_on_both_paths() {
		$clause = $this->find_clause( 'durable medium' );
		$this->assertIsArray( $clause, 'The durable-medium acknowledgement clause must exist.' );
		$this->assertSame(
			array( 'if_returns' => 'yes' ),
			$clause['condition'],
			'FR-22: the acknowledgement clause must stay on both paths; do not gate it to the form path.'
		);
	}

	/** The acknowledgement must read as a general statement, not scoped to a single option. */
	public function test_acknowledgement_clause_is_a_general_statement() {
		$clause = $this->find_clause( 'durable medium' );
		$this->assertStringNotContainsString(
			'If you use this option',
			$clause['content'],
			'The acknowledgement must apply to any withdrawal method (Art. 11a), not just the preceding option.'
		);
	}

	// ---------------------------------------------------------------------
	// FR-8 — the clause links to the Withdrawal page; no leftover token.
	// ---------------------------------------------------------------------

	/** On the form path the clause links to the Withdrawal page, not the legacy PDF. */
	public function test_document_links_withdrawal_clause_to_the_page() {
		$this->set_form_path();
		$page_id = $this->doc()->create_page( 'withdrawal' );
		$html    = $this->doc()->get_document_html( 'terms-conditions' );

		$this->assertStringNotContainsString( '[withdrawal_form_link]', $html, 'FR-21: no placeholder token may appear in the output.' );
		$this->assertStringContainsString( get_permalink( $page_id ), $html, 'FR-8: the withdrawal clause must link to the Withdrawal page.' );
		$this->assertStringNotContainsString( 'withdrawal-forms/withdrawal-form-', $html, 'The legacy withdrawal PDF link must be gone.' );
	}

	/** When the page is missing the document degrades without a token or an empty link. */
	public function test_document_degrades_when_page_missing() {
		$this->set_form_path();
		delete_option( 'cmplz_tc_withdrawal_page_id' );

		$html = $this->doc()->get_document_html( 'terms-conditions' );

		$this->assertStringNotContainsString( '[withdrawal_form_link]', $html, 'FR-8/FR-21: the token must not leak when the page is missing.' );
		$this->assertStringNotContainsString( 'href="">withdrawal function available', $html, 'The degraded withdrawal link must not be empty.' );
	}
}
