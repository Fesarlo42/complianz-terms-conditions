<?php
/**
 * REST API endpoints for Complianz Terms & Conditions.
 *
 * Registers and handles the WP REST API route used by the Gutenberg block
 * to retrieve the generated Terms & Conditions document. The single endpoint
 * is intentionally public so the block can fetch content for unauthenticated
 * previews and front-end rendering without requiring a nonce.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage REST_API
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

add_action( 'rest_api_init', 'cmplz_tc_documents_rest_route' );
/**
 * Register the REST API route for the Terms & Conditions document endpoint.
 *
 * Hooked into `rest_api_init` so the route is only registered when the REST
 * API is initialised. The endpoint is read-only (GET) and publicly accessible
 * because the Terms & Conditions content itself is public-facing.
 *
 * @since  1.0.0
 * @access public
 *
 * @see    cmplz_tc_rest_api_documents()  Callback that handles the request.
 * @see    register_rest_route()
 * @link   https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
 *
 * @return void
 */
function cmplz_tc_documents_rest_route() {
	register_rest_route(
		'complianz_tc/v1',
		'document/',
		array(
			'methods'             => 'GET',
			'callback'            => 'cmplz_tc_rest_api_documents',
			// The document content is public; no authentication is required.
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Return the Terms & Conditions document data for the Gutenberg block.
 *
 * Fetches the rendered HTML for the `terms-conditions` document type via
 * {@see COMPLIANZ_TC::$document} and returns a flat associative array
 * consumed by the `@complianz/terms-conditions` Gutenberg block. The content
 * is already sanitised and escaped by the document renderer, so it is safe
 * to output directly in a block context.
 *
 * @since  1.0.0
 * @access public
 *
 * @see    cmplz_tc_documents_rest_route()  Registers the route that maps to this callback.
 * @see    COMPLIANZ_TC::$document
 *
 * @return array {
 *     Associative array representing the document.
 *
 *     @type string $id      Fixed identifier for the document type. Always `'terms'`.
 *     @type string $title   Translated document title.
 *     @type string $content Rendered HTML content of the Terms & Conditions document.
 * }
 */
function cmplz_tc_rest_api_documents() {

	$html = COMPLIANZ_TC::$document->get_document_html( 'terms-conditions' );
	return array(
		'id'      => 'terms',
		'title'   => __( 'Terms and Conditions', 'complianz-terms-conditions' ),
		'content' => $html,
	);
}
