<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines plugin constants and stubs for symbols that only exist at runtime
 * (e.g. defined by the main plugin file after load) so that PHPStan can
 * resolve them during static analysis without requiring a full WordPress boot.
 *
 * @package Complianz_Terms_Conditions
 */

// Plugin constants defined at runtime by the main plugin file.
define( 'cmplz_tc_version', '1.0.0' );
define( 'cmplz_tc_url', 'https://example.com/' );
define( 'cmplz_tc_path', __DIR__ . '/' );
define( 'cmplz_tc_plugin', 'complianz-terms-conditions/complianz-terms-conditions.php' );

// WordPress timing constants (also provided via dynamicConstantNames, but
// defining them here satisfies "constant not found" errors in stubs mode).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
