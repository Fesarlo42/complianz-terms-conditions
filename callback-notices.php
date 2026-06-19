<?php
/**
 * Field-level callback notices and smart default values for the T&C wizard.
 *
 * Provides two types of context-aware enhancements for wizard fields: smart
 * default-value callbacks that pre-populate fields from the active Complianz
 * GDPR/CCPA plugin data or from the current WordPress locale, and sidebar
 * notice callbacks that display informational messages next to specific fields
 * when relevant plugins (Complianz, WooCommerce, Easy Digital Downloads) are
 * detected. All functions are registered via WordPress hooks rather than called
 * directly.
 *
 * @package    Complianz_Terms_Conditions
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Prevent direct file access outside of the WordPress bootstrap.
defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

/**
 * Pre-populates wizard field default values from site context and active plugins.
 *
 * Hooked to the `cmplz_tc_default_value` filter (priority 10) so it runs when
 * a field has no stored value and needs a sensible starting point. Handles five
 * specific fields:
 *
 * - `country_company`  — Derives the ISO 3166-1 alpha-2 country code from the
 *                        WordPress locale (e.g. 'en_GB' → 'GB') and validates
 *                        it against the plugin's countries map.
 * - `privacy_policy`   — Pre-fills the Privacy Policy URL from the Complianz
 *                        premium plugin when available.
 * - `cookie_policy`    — Pre-fills the Cookie Policy URL from Complianz (premium
 *                        or free) when the base Complianz plugin is installed.
 * - `address_company`  — Copies the company address already stored in Complianz.
 * - `webshop_content`  — Defaults to true when WooCommerce or Easy Digital
 *                        Downloads is active.
 *
 * All other fieldnames are returned unchanged.
 *
 * @since  1.0.0
 *
 * @see    cmplz_tc_get_value()  Used indirectly via the filter applied there.
 *
 * @param  mixed  $value     The current default value (may be empty string or false).
 * @param  string $fieldname The wizard field identifier being evaluated.
 * @return mixed             The (possibly overridden) default value for the field.
 */
function cmplz_tc_set_default( $value, $fieldname ) {

	if ( 'country_company' === $fieldname ) {
		// Extract the territory portion of the locale code (e.g. 'en_GB' → 'GB').
		$country_code = substr( get_locale(), 3, 2 );
		// Only apply the derived code when it maps to a known country in the plugin config.
		if ( isset( COMPLIANZ_TC::$config->countries[ $country_code ] ) ) {
			$value = $country_code;
		}
	}

	// Pre-fill Privacy Policy URL from the Complianz premium plugin when installed.
	if ( 'privacy_policy' === $fieldname && defined( 'cmplz_premium' ) ) {
		$default_region = COMPLIANZ::$company->get_default_region();
		$value          = COMPLIANZ::$document->get_permalink( 'privacy-statement', $default_region, true );
	}

	// Pre-fill Cookie Policy URL from the Complianz base or premium plugin when installed.
	if ( 'cookie_policy' === $fieldname && defined( 'CMPLZ_VERSION' ) ) {
		$default_region = COMPLIANZ::$company->get_default_region();
		if ( defined( 'cmplz_premium' ) ) {
			// Premium: use get_permalink() which supports redirect options (introduced post-4.9.7).
			$value = COMPLIANZ::$document->get_permalink( 'cookie-statement', $default_region, true );
		} else {
			// Free Complianz: fall back to the simpler URL helper available since 4.9.7.
			$value = cmplz_get_document_url( 'cookie-statement', $default_region );
		}
	}

	// Mirror the company address already stored in the Complianz base plugin.
	if ( 'address_company' === $fieldname && defined( 'CMPLZ_VERSION' ) ) {
		$value = cmplz_get_value( 'address_company' );
	}

	// Default the webshop_content field to true when a supported e-commerce plugin is active.
	if ( 'webshop_content' === $fieldname ) {
		if ( class_exists( 'WooCommerce' ) || class_exists( 'Easy_Digital_Downloads' ) ) {
			$value = true;
		}
	}

	return $value;
}
/**
 * Filters the default value for a T&C wizard field based on site context.
 *
 * @since 1.0.0
 *
 * @param mixed  $value     The current default value.
 * @param string $fieldname The field identifier being evaluated.
 */
add_filter( 'cmplz_tc_default_value', 'cmplz_tc_set_default', 10, 2 );

/**
 * Renders a sidebar notice on the cookie_policy field when Complianz is active.
 *
 * Displays a contextual message explaining that the Cookie Policy URL (and,
 * when the premium Complianz plugin is present, the Privacy Policy URL too) was
 * pre-filled from the user's existing Complianz GDPR/CCPA settings, so they do
 * not need to enter it manually.
 *
 * Hooked to: cmplz_tc_notice_cookie_policy (fired by cmplz_tc_field::after_field()).
 *
 * @since  1.0.0
 *
 * @see    cmplz_tc_sidebar_notice()  Renders the sidebar notice HTML.
 *
 * @return void
 */
function cmplz_tc_cookie_policy() {
	if ( defined( 'cmplz_premium' ) ) {
		// Both Cookie Policy and Privacy Policy URLs were sourced from Complianz premium.
		cmplz_tc_sidebar_notice( __( 'Complianz GDPR/CCPA was detected, the Cookie Policy URL and Privacy Policy URL were prefilled based on your settings in Complianz', 'complianz-terms-conditions' ) );
	}

	if ( ! defined( 'cmplz_premium' ) && defined( 'CMPLZ_VERSION' ) ) {
		// Only the Cookie Policy URL was sourced from the free Complianz base plugin.
		cmplz_tc_sidebar_notice( __( 'Complianz GDPR/CCPA was detected, the Cookie Policy URL was prefilled based on your settings in Complianz', 'complianz-terms-conditions' ) );
	}
}
/**
 * Fires after the cookie_policy field to render contextual sidebar notices.
 *
 * @since 1.0.0
 */
add_action( 'cmplz_tc_notice_cookie_policy', 'cmplz_tc_cookie_policy' );

/**
 * Renders a sidebar notice on the webshop_content field when a webshop plugin is detected.
 *
 * Prompts the user to answer "Yes" to the webshop_content wizard question when
 * WooCommerce or Easy Digital Downloads is active, since an active e-commerce
 * plugin almost certainly means the site sells products.
 *
 * Hooked to: cmplz_tc_notice_webshop_content (fired by cmplz_tc_field::after_field()).
 *
 * @since  1.0.0
 *
 * @see    cmplz_tc_sidebar_notice()  Renders the sidebar notice HTML.
 *
 * @return void
 */
function cmplz_tc_webshop_content_notice() {
	if ( class_exists( 'WooCommerce' ) || class_exists( 'Easy_Digital_Downloads' ) ) {
		cmplz_tc_sidebar_notice( __( "We have detected a webshop plugin, so the answer should probably be 'Yes'", 'complianz-terms-conditions' ) );
	}
}
/**
 * Fires after the webshop_content field to render contextual sidebar notices.
 *
 * @since 1.0.0
 */
add_action( 'cmplz_tc_notice_webshop_content', 'cmplz_tc_webshop_content_notice' );
