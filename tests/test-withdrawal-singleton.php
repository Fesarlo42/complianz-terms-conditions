<?php
/**
 * Tests for the withdrawal handler's singleton contract: a single shared
 * instance reachable via ::this(), and a hard guard against a second
 * construction, matching the other component classes.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * @group withdrawal-singleton
 */
class Test_Withdrawal_Singleton extends WP_UnitTestCase {

	/** ::this() returns the one bootstrap instance, stable across calls. */
	public function test_this_returns_the_shared_instance() {
		$instance = cmplz_tc_withdrawal::this();
		$this->assertInstanceOf( 'cmplz_tc_withdrawal', $instance );
		$this->assertSame( $instance, cmplz_tc_withdrawal::this() );
		$this->assertSame( COMPLIANZ_TC::$withdrawal, $instance );
	}

	/** A second construction hits the singleton guard and dies. */
	public function test_second_construction_is_blocked() {
		$this->expectException( WPDieException::class );
		new cmplz_tc_withdrawal();
	}
}
