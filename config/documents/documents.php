<?php
/**
 * Document page definitions shared across all regions.
 *
 * Populates the `all` key of the `$this->pages` property on `cmplz_tc_config`
 * with the document types that are available regardless of jurisdiction. Currently
 * registers the `terms-conditions` document type with its translated title and
 * default (empty) document-elements list, which is later filtered by
 * `cmplz_document_elements` in `cmplz_tc_config::init()`.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Config/Documents
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

// Prevent direct file access outside of the WordPress bootstrap.
defined( 'ABSPATH' ) || die( 'you do not have access to this page!' );

$this->pages['all'] = array(
	'terms-conditions' => array(
		'title'             => __( 'Terms and Conditions', 'complianz-terms-conditions' ),
		'public'            => true,
		'document_elements' => '',
	),
);
