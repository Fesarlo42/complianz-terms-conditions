<?php
/**
 * PDF download endpoint for the Terms & Conditions document.
 *
 * This file serves as a standalone WordPress bootstrap entry point that generates
 * and streams a PDF of the Terms & Conditions document directly to the browser.
 * It locates the WordPress installation root, bootstraps the WordPress core without
 * loading the active theme, retrieves the T&C content (either live-generated HTML
 * or the saved post content, depending on sync status), and hands it off to the
 * document class for PDF generation.
 *
 * @package    Complianz_Terms_Conditions
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Skip theme loading since this endpoint only streams a PDF, not a themed page.
define( 'WP_USE_THEMES', false );

// Resolve the WordPress root directory before WordPress itself is loaded.
define( 'BASE_PATH', find_wordpress_base_path() . '/' );

// Bail out if WordPress is not found at the resolved path.
if ( ! file_exists( BASE_PATH . 'wp-load.php' ) ) {
	die( 'WordPress not installed here' );
}

// Bootstrap WordPress core so we can use its functions and database access.
require_once BASE_PATH . 'wp-load.php';

// From now on use ABSPATH; BASE_PATH may cause issues on setups with symlinked folders.
require_once ABSPATH . 'wp-includes/class-phpass.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Resolve the page ID associated with the terms-conditions shortcode.
$page_id = COMPLIANZ_TC::$document->get_shortcode_page_id( 'terms-conditions' );

// Determine whether the document should be regenerated from the wizard data or read from the saved post.
$sync_status = COMPLIANZ_TC::$document->syncStatus( $page_id );

if ( 'sync' === $sync_status ) {
	// Document is in sync: generate fresh HTML from the wizard-configured document.
	$html = COMPLIANZ_TC::$document->get_document_html( 'terms-conditions' );
} else {
	// Document is not in sync: fall back to the saved WordPress post content.
	$tc_post = get_post( $page_id );
	if ( $tc_post ) {
		// Run the post content through the_content filter to apply shortcodes and formatting.
		$html = apply_filters( 'the_content', $tc_post->post_content );
	} else {
		// No post found; use a placeholder so the PDF is not empty.
		$html = '--';
	}
}

// Translate the document title for use as the PDF filename and header.
$tc_title = __( 'Terms and Conditions', 'complianz-terms-conditions' );

// Stream the PDF to the browser and terminate; no further output should follow.
COMPLIANZ_TC::$document->generate_pdf( $html, $tc_title );
exit;

/**
 * Locates the WordPress installation root directory by traversing the filesystem.
 *
 * Walks up the directory tree from the current file's location, looking for a
 * `wp-config.php` file that signals the WordPress root. Handles edge cases where
 * WordPress core files are installed in a subdirectory of the config directory
 * (e.g. a `/wordpress/` sub-folder install). Also short-circuits for Bitnami
 * server images where WordPress is always at a fixed path.
 *
 * The resolved path is used to bootstrap WordPress before ABSPATH is available,
 * so it must work without any WordPress functions.
 *
 * @since  1.0.0
 *
 * @return string|false Absolute path to the WordPress root directory (no trailing
 *                      slash), or false if it could not be located.
 */
function find_wordpress_base_path() {
	// Bitnami server images keep WordPress at a fixed location; detect and short-circuit.
	if ( file_exists( '/opt/bitnami/wordpress/wp-load.php' ) && file_exists( '/bitnami/wordpress/wp-config.php' ) ) {
		return '/opt/bitnami/wordpress';
	}

	$path = __DIR__;

	// Walk up the directory tree looking for wp-config.php.
	do {
		$prev_path = $path;
		if ( file_exists( $path . '/wp-config.php' ) ) {
			// wp-load.php at the same level means this is the standard WordPress root.
			if ( file_exists( $path . '/wp-load.php' ) ) {
				return $path;
			} elseif ( file_exists( $path ) ) {
				// wp-config.php found but wp-load.php is absent here: WordPress core may
				// live in a subdirectory. Scan each direct child directory for wp-load.php.
				$handle = opendir( $path );
				if ( false !== $handle ) {
					while ( false !== ( $file = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard directory-traversal idiom.
						// Skip the current (.) and parent (..) directory entries.
						if ( '.' !== $file && '..' !== $file ) {
							$file = $path . '/' . $file;
							// If this child directory contains wp-load.php it is the WP root.
							if ( is_dir( $file ) && file_exists( $file . '/wp-load.php' ) ) {
								$path = $file;
								break;
							}
						}
					}
					closedir( $handle );
				}
			}

			return $path;
		}
	} while ( ( $path = realpath( "$path/.." ) ) && $path !== $prev_path ); // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard filesystem-traversal do-while idiom; $prev_path guards against infinite loop at filesystem root.

	// Exhausted all parent directories without finding wp-config.php.
	return false;
}
