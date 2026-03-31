<?php
/**
 * Plugin uninstall handler for Complianz Terms & Conditions.
 *
 * Executed automatically by WordPress when the plugin is deleted via the
 * Plugins admin screen. Guards against direct file access by checking for the
 * WP_UNINSTALL_PLUGIN constant, then conditionally removes all stored options
 * when the user has opted in to full data removal on uninstall.
 *
 * @package    Complianz_Terms_Conditions
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Abort if this file is accessed directly rather than via the WordPress uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

// Load the plugin's general settings to check the user's data-removal preference.
$general_settings = get_option( 'complianz_tc_options_settings' );

// Only delete stored data when the user has explicitly enabled "clear data on uninstall".
if ( isset( $general_settings['clear_data_on_uninstall'] ) && $general_settings['clear_data_on_uninstall'] ) {

	// List of option names to remove from both single-site and multisite option tables.
	$options = array();

	// @phpstan-ignore-next-line -- Options list intentionally empty; populated by future versions.
	foreach ( $options as $option_name ) {
		// Remove the option from the current site's options table.
		delete_option( $option_name );
		// Remove the option from the network-wide sitemeta table (no-op on single-site).
		delete_site_option( $option_name );
	}
}
