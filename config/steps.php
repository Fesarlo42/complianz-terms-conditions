<?php
/**
 * Wizard step and section configuration for the Terms & Conditions document type.
 *
 * Defines the ordered array of wizard steps and their child sections for the
 * `terms-conditions` document type. Each step may contain a title, an optional
 * translated intro paragraph, and an optional `sections` sub-array. This file
 * is included by `cmplz_tc_config` and the resulting `$this->steps` property
 * is consumed by `cmplz_tc_wizard` to build the left-hand navigation menu and
 * the content area for each step.
 *
 * The entire structure is passed through the `cmplz_tc_steps` filter so that
 * add-ons and third-party code can add, remove, or reorder steps without
 * touching this file.
 *
 * Step structure reference:
 * ```
 * array(
 *     'document-type' => array(
 *         $step_index => array(
 *             'id'       => string,   // CSS/slug identifier for the step.
 *             'title'    => string,   // Translated step heading.
 *             'intro'    => string,   // Optional translated intro HTML shown above the fields.
 *             'sections' => array(    // Optional ordered child sections.
 *                 $section_index => array(
 *                     'title' => string,  // Translated section label in the sidebar menu.
 *                     'intro' => string,  // Optional translated intro shown above section fields.
 *                 ),
 *             ),
 *         ),
 *     ),
 * )
 * ```
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Config
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
 * Filters the wizard step and section configuration.
 *
 * Add-ons can hook here to append, remove, or reorder wizard steps for any
 * document type without modifying this file.
 *
 * @since 1.0.0
 *
 * @param array $steps Nested array of document-type → step → section definitions.
 */
$this->steps = apply_filters(
	'cmplz_tc_steps',
	array(
		// Configuration for the Terms & Conditions document type.
		'terms-conditions' =>
		array(
			// Step 1: General — company details and introductory wizard copy.
			1 => array(
				'id'    => 'company',
				'title' => __( 'General', 'complianz-terms-conditions' ),
				'intro' => '<h1 class="h4">' . __( 'Terms & Conditions', 'complianz-terms-conditions' ) . '</h1><p>' .
				/* translators: 1 and 2 are opening and closing anchor tags around the word "know", 3 and 4 are opening and closing anchor tags around the words "support ticket". */
								sprintf( __( 'We have tried to make our Wizard as simple and fast as possible. Although these questions are all necessary, if there\'s any way you think we can improve the plugin, please let us %1$sknow%2$s!', 'complianz-terms-conditions' ), '<a target="_blank" href="https://complianz.io/contact">', '</a>' ) .
								/* translators: 1 and 2 are opening and closing anchor tags around the word "documentation", 3 and 4 are opening and closing anchor tags around the words "support ticket". */
							'&nbsp;' . sprintf( __( ' Please note that you can always save and finish the wizard later, use our %1$sdocumentation%2$s for additional information or log a %3$ssupport ticket%4$s if you need our assistance.', 'complianz-terms-conditions' ), '<a target="_blank" href="https://complianz.io/docs/terms-conditions">', '</a>', '<a target="_blank" href="https://wordpress.org/support/plugin/complianz-terms-conditions/">', '</a>' ) . '</p>',

			),

			// Step 2: Questions — grouped into five thematic sections.
			2 => array(
				'title'    => __( 'Questions', 'complianz-terms-conditions' ),
				'id'       => 'questions',
				'sections' => array(
					// Section 2.1: Website content and feature-specific questions.
					1 => array(
						'title' => __( 'Content', 'complianz-terms-conditions' ),
						'intro' => __( 'These questions will concern the content presented on your website and specific functionalities that might need to be included in the Terms & Conditions.', 'complianz-terms-conditions' ) . cmplz_tc_read_more( 'https://complianz.io/docs/terms-conditions?tc&step=2&section=1' ),
					),
					// Section 2.2: Communication obligations toward customers.
					2 => array(
						'title' => __( 'Communication', 'complianz-terms-conditions' ),
						'intro' => __( 'These questions will explicitly explain your efforts in communicating with your customers or visitors regarding the services you provide.', 'complianz-terms-conditions' ),

					),
					// Section 2.3: Liability limitation options.
					3 => array(
						'title' => __( 'Liability', 'complianz-terms-conditions' ),
						'intro' => __( 'Based on earlier answers you can now choose to limit liability if needed.', 'complianz-terms-conditions' ) . cmplz_tc_read_more( 'https://complianz.io/docs/terms-conditions?tc&step=2&section=3' ),

					),
					// Section 2.4: Creative Commons licensing for original content.
					4 => array(
						'title' => __( 'Copyright', 'complianz-terms-conditions' ),
						'intro' => __( 'Creative Commons (CC) is an American non-profit organization devoted to expanding the range of creative works available for others to build upon legally and to share.', 'complianz-terms-conditions' ),
					),
					// Section 2.5: Return and service-withdrawal policy questions.
					5 => array(
						'title' => __( 'Returns', 'complianz-terms-conditions' ),
						'intro' => __( 'If you offer returns of goods or the withdrawal of services you can specify the terms below.', 'complianz-terms-conditions' ) . cmplz_tc_read_more( 'https://complianz.io/docs/terms-conditions?tc&step=2&section=5' ),
					),
				),
			),

			// Step 3: Documents — create the T&C page and add it to the nav menu.
			3 => array(
				'id'       => 'menu',
				'title'    => __( 'Documents', 'complianz-terms-conditions' ),
				'intro'    =>
					'<h1>' . __( 'Get ready to finish your configuration.', 'complianz-terms-conditions' ) . '</h1>' .
					'<p>'
					. __( 'Generate the Terms & Conditions, then you can add them to your menu directly or do it manually after the wizard is finished.', 'complianz-terms-conditions' ) . '</p>',
				'sections' => array(
					// Section 3.1: Generate and publish the T&C document page.
					1 => array(
						'title' => __( 'Create document', 'complianz-terms-conditions' ),
					),
					// Section 3.2: Assign the document page to a navigation menu.
					2 => array(
						'title' => __( 'Link to menu', 'complianz-terms-conditions' ),
					),
				),

			),
			// Step 4: Finish — completion screen with tips, tricks, and companion plugin cards.
			4 => array(
				'title' => __( 'Finish', 'complianz-terms-conditions' ),
			),
		),
	)
);
