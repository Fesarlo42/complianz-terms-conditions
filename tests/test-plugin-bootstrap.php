<?php
/**
 * Smoke test: confirms the plugin boots correctly under the PHPUnit harness.
 *
 * This is the TDD baseline test. If this fails, the test environment itself is
 * broken — fix the harness before trusting any feature test.
 *
 * @package Complianz_Terms_Conditions
 */

/**
 * Plugin bootstrap test case.
 */
class Test_Plugin_Bootstrap extends WP_UnitTestCase {

	/**
	 * Core plugin constants must be defined once the plugin has loaded.
	 */
	public function test_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'cmplz_tc_version' ), 'cmplz_tc_version constant is not defined.' );
		$this->assertTrue( defined( 'cmplz_tc_path' ), 'cmplz_tc_path constant is not defined.' );
		$this->assertTrue( defined( 'cmplz_tc_url' ), 'cmplz_tc_url constant is not defined.' );
		$this->assertNotEmpty( cmplz_tc_version, 'cmplz_tc_version is empty.' );
	}

	/**
	 * The singleton and its always-on components must be available.
	 */
	public function test_singleton_and_document_are_available() {
		$this->assertTrue( class_exists( 'COMPLIANZ_TC' ), 'Main plugin class is missing.' );

		$instance = COMPLIANZ_TC::get_instance();
		$this->assertInstanceOf( 'COMPLIANZ_TC', $instance );

		// $config and $document are instantiated on every request (frontend + admin).
		$this->assertNotNull( COMPLIANZ_TC::$config, 'Config component was not instantiated.' );
		$this->assertNotNull( COMPLIANZ_TC::$document, 'Document component was not instantiated.' );
	}
}
