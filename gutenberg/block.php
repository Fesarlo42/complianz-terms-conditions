<?php
/**
 * Gutenberg block registration and server-side rendering for the T&C document block.
 *
 * Registers the `complianztc/terms-conditions` block type, enqueues its
 * compiled editor script with the required asset dependencies derived from the
 * Webpack build manifest, and provides a server-side render callback that
 * returns either the live-generated document HTML or a saved custom document
 * depending on the block's `documentSyncStatus` attribute.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Gutenberg
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Prevent direct file access outside of the WordPress bootstrap.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the compiled block editor script and its localised data.
 *
 * Reads the Webpack-generated `build/index.asset.php` manifest to obtain the
 * correct dependency list and content-hash version string, then registers and
 * enqueues the block's JavaScript bundle for the block editor only. Two data
 * objects are attached to the script handle:
 *
 * - `complianz_tc.site_url`          — The REST API root URL used by the block
 *                                       to fetch live document previews.
 * - `complianz_tc.cmplz_tc_preview`  — Absolute URL to the static block
 *                                       preview image shown in the block
 *                                       inserter before a document is loaded.
 *
 * Script translations are also registered so that strings inside the JS bundle
 * can be translated via the plugin's `.po`/`.mo` files.
 *
 * Hooked to: enqueue_block_editor_assets.
 *
 * @since  1.0.0
 * @access public
 *
 * @see    wp_enqueue_script()
 * @see    wp_localize_script()
 * @see    wp_set_script_translations()
 *
 * @return void
 */
function cmplz_tc_editor_assets() {
	// Load the Webpack asset manifest; provides 'dependencies' and 'version' keys.
	$asset_file = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	wp_enqueue_script(
		'cmplz-tc-block',                                      // Script handle.
		plugins_url( 'gutenberg/build/index.js', __DIR__ ),   // Compiled bundle URL.
		$asset_file['dependencies'],                           // Auto-detected dependencies from Webpack.
		$asset_file['version'],                                // Content-hash version for cache busting.
		true                                                   // Load in footer.
	);

	// Pass PHP-side data to the block's JavaScript runtime.
	wp_localize_script(
		'cmplz-tc-block',
		'complianz_tc',
		array(
			// REST API root URL used for live document preview requests.
			'site_url'         => get_rest_url(),
			// Static preview image displayed in the block inserter.
			'cmplz_tc_preview' => cmplz_tc_url . 'assets/images/gutenberg-preview.png',
		)
	);

	// Register JS translations so i18n strings in the bundle are localised.
	wp_set_script_translations( 'cmplz-tc-block', 'complianz-terms-conditions', cmplz_tc_path . '/languages' );
}
/**
 * Fires when block editor scripts and styles are enqueued.
 *
 * @since 1.0.0
 */
add_action( 'enqueue_block_editor_assets', 'cmplz_tc_editor_assets' );

/**
 * Renders the Terms & Conditions block on the front end.
 *
 * Called by the block editor's server-side rendering pipeline whenever a page
 * containing the `complianztc/terms-conditions` block is displayed. When the
 * block's `documentSyncStatus` attribute is `'unlink'` and a `customDocument`
 * attribute is present the saved custom HTML is returned as-is, allowing the
 * user's manual edits to the document to be preserved. In all other cases the
 * live document HTML is generated fresh from the wizard configuration via
 * `cmplz_tc_document::get_document_html()`.
 *
 * @since  1.0.0
 * @access public
 *
 * @see    cmplz_tc_document::get_document_html()  Generates the live T&C HTML.
 * @see    register_block_type()                   Where this callback is registered.
 *
 * @param  array  $attributes  Block attributes set in the editor, including:
 *                             - `documentSyncStatus` (string) 'sync' | 'unlink' — whether to
 *                               use the live document or a saved custom version.
 *                             - `customDocument`     (string) Raw HTML of the user-edited
 *                               document; only used when `documentSyncStatus` is 'unlink'.
 * @param  string $content     Inner block content (unused for this dynamic block).
 * @return string              The HTML string to render in place of the block.
 */
function cmplz_tc_render_document_block( $attributes, $content ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $content required by block render_callback signature.
	if ( isset( $attributes['documentSyncStatus'] ) && 'unlink' === $attributes['documentSyncStatus'] && isset( $attributes['customDocument'] ) ) {
		// Block is unlinked from the wizard: return the user's saved custom HTML.
		$html = $attributes['customDocument'];
	} else {
		// Block is synced: generate fresh HTML from the wizard-configured document.
		$type = 'terms-conditions';
		$html = COMPLIANZ_TC::$document->get_document_html( $type );
	}

	return $html;
}

/**
 * Registers the Terms & Conditions Gutenberg block with a server-side render callback.
 *
 * The `render_callback` replaces the static `save()` output with dynamically
 * generated document HTML so the block always reflects the latest wizard
 * settings without requiring manual re-saving of each post.
 *
 * @since 1.0.0
 */
register_block_type(
	'complianztc/terms-conditions',
	array(
		'render_callback' => 'cmplz_tc_render_document_block',
	)
);
