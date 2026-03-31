<?php
/**
 * Wizard field definitions for the Terms & Conditions setup wizard.
 *
 * Registers every question shown across all wizard steps and sections by
 * merging field definitions into `$this->fields` (a property of
 * `cmplz_tc_config`). Fields are grouped by step and section index and cover
 * the full wizard flow: company details (step 1), content/communication/
 * liability/copyright/returns questions (step 2), document-page creation and
 * menu assignment (step 3), and the finish screen (step 4).
 *
 * Each field entry is a key → array pair where the key is the fieldname and
 * the array may contain:
 * - `step`               (int)    Wizard step this field belongs to.
 * - `section`            (int)    Section within the step (optional).
 * - `source`             (string) Document type, e.g. 'terms-conditions'.
 * - `type`               (string) Field type: 'text', 'textarea', 'email', 'url',
 *                                 'radio', 'select', 'number', 'multicheckbox'.
 * - `label`              (string) Translated question label shown above the field.
 * - `options`            (array)  Key → translated-label pairs for radio/select/multicheckbox.
 * - `default`            (mixed)  Default value before the user answers.
 * - `required`           (bool)   Whether the field must be answered before continuing.
 * - `placeholder`        (string) Input placeholder text.
 * - `tooltip`            (string) Short help text shown inline next to the field.
 * - `help`               (string) Longer explanatory text shown below the field.
 * - `comment`            (string) Advisory note rendered beneath the field value.
 * - `condition`          (array)  Field-name → expected-value pairs that must all be met
 *                                 for this field to be visible.
 * - `translatable`       (bool)   When true, the field value can differ per WPML/Polylang language.
 * - `callback`           (string) Action suffix fired to render a custom step callback.
 * - `minimum`            (int)    Minimum allowed value for number fields.
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

/*
 * Field schema reference (all keys are optional unless noted):
 *
 * condition:          If a question should be dynamically shown or hidden depending on
 *                     another answer. Use 'NOT answer' to hide when the answer is not set.
 * callback_condition: Whether the field should be shown or hidden based on an answer
 *                     on a different screen (calls action cmplz_$page_$callback).
 * required:           Mandatory field — wizard cannot progress without an answer.
 * help:               Help text displayed beneath the field.
 *
 * Full default shape:
 *   "fieldname"          => '',
 *   "type"               => 'text',
 *   "required"           => false,
 *   'default'            => '',
 *   'label'              => '',
 *   'table'              => false,
 *   'callback_condition' => false,
 *   'condition'          => false,
 *   'callback'           => false,
 *   'placeholder'        => '',
 *   'optional'           => false,
 */

// -------------------------------------------------------------------------
// Step 1 — General: company identity, contact preferences, and legal links.
// -------------------------------------------------------------------------
$this->fields = $this->fields + array(

	// Website or company owner name shown in the generated document.
	'organisation_name'  => array(
		'step'        => 1,
		'section'     => 1,
		'source'      => 'terms-conditions',
		'type'        => 'text',
		'default'     => '',
		'placeholder' => __( 'Company or personal name', 'complianz-terms-conditions' ),
		'label'       => __( 'Who is the owner of the website?', 'complianz-terms-conditions' ),
		'required'    => true,
	),

	// Full company address inserted into the generated document and withdrawal form.
	'address_company'    => array(
		'step'        => 1,
		'section'     => 1,
		'source'      => 'terms-conditions',
		'placeholder' => __( 'Address, City and Zipcode', 'complianz-terms-conditions' ),
		'type'        => 'textarea',
		'default'     => '',
		'label'       => __( 'Address', 'complianz-terms-conditions' ),
	),

	// Country/jurisdiction — pre-filled from the WordPress locale via callback_notices.php.
	'country_company'    => array(
		'step'     => 1,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'select',
		'options'  => $this->countries,
		'default'  => '',
		'label'    => __( 'Jurisdiction', 'complianz-terms-conditions' ),
		'required' => true,
		'tooltip'  => __( 'This setting is automatically pre-filled based on your WordPress language setting.', 'complianz-terms-conditions' ),
	),

	// How the site owner prefers to receive contact from visitors.
	'contact_company'    => array(
		'step'     => 1,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'options'  => array(
			'manually'         => __( 'I would like to add an email address to the terms & conditions', 'complianz-terms-conditions' ),
			'webpage'          => __( 'I would like to select an existing contact page', 'complianz-terms-conditions' ),
			'refer_to_contact' => __( 'I would like to refer to a phone number published on the website', 'complianz-terms-conditions' ),
		),
		'default'  => '',
		'tooltip'  => __(
			"An existing page would be a contact or an 'about us' page where your contact details are readily available, or a contact form is present.",
			'complianz-terms-conditions'
		),
		'label'    => __( 'How do you wish visitors to contact you?', 'complianz-terms-conditions' ),
		'required' => true,
	),

	// Shown only when contact_company = 'manually'; obfuscated on the front-end.
	'email_company'      => array(
		'step'      => 1,
		'section'   => 1,
		'source'    => 'terms-conditions',
		'type'      => 'email',
		'default'   => '',
		'tooltip'   => __( 'Your email address will be obfuscated on the front-end to prevent spidering.', 'complianz-terms-conditions' ),
		'label'     => __( 'What is the email address your visitors can use to contact you about the terms & conditions?', 'complianz-terms-conditions' ),
		'condition' => array(
			'contact_company' => 'manually',
		),
	),

	// Shown only when contact_company = 'webpage'; translatable for WPML/Polylang sites.
	'page_company'       => array(
		'step'         => 1,
		'section'      => 1,
		'source'       => 'terms-conditions',
		'translatable' => true,
		'default'      => home_url( '/contact/' ),
		'type'         => 'url',
		'label'        => __( 'Add the URL for your contact details', 'complianz-terms-conditions' ),
		'condition'    => array(
			'contact_company' => 'webpage',
		),
	),

	// Whether to include references to the Cookie Policy and Privacy Statement.
	// Can be empty and filled manually; pre-filled when Complianz GDPR is installed.
	'legal_mention'      => array(
		'step'     => 1,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'default'  => 'yes',
		'required' => true,
		'label'    => __( 'Do you want to refer to your cookie policy and privacy statement?', 'complianz-terms-conditions' ),
		'comment'  => __(
			"If you don't have the relevant documents, please have a look at Complianz - The Privacy Suite for WordPress.",
			'complianz-terms-conditions'
		) . cmplz_tc_read_more( 'https://complianz.io/pricing/?tc&step=1' ),
		'options'  => $this->yes_no,

	),

	// Cookie Policy URL; shown when legal_mention = 'yes'. Pre-filled from Complianz GDPR.
	'cookie_policy'      => array(
		'step'         => 1,
		'section'      => 1,
		'translatable' => true,
		'source'       => 'terms-conditions',
		'type'         => 'url',
		'placeholder'  => site_url( 'cookie-policy' ),
		'label'        => __( 'URL to your Cookie Policy', 'complianz-terms-conditions' ),
		'comment'      => __(
			'Complianz GDPR/CCPA Cookie Consent can create one for you!',
			'complianz-terms-conditions'
		) . cmplz_tc_read_more( 'https://wordpress.org/plugins/complianz-gdpr/' ),
		'condition'    => array(
			'legal_mention' => 'yes',
		),
	),

	// Privacy Statement URL; shown when legal_mention = 'yes'. Pre-filled from Complianz GDPR premium.
	'privacy_policy'     => array(
		'step'         => 1,
		'section'      => 1,
		'translatable' => true,
		'source'       => 'terms-conditions',
		'type'         => 'url',
		'placeholder'  => site_url( 'privacy-statement' ),
		'label'        => __( 'URL to your Privacy Statement', 'complianz-terms-conditions' ),
		'condition'    => array(
			'legal_mention' => 'yes',
		),
	),

	// Statutory disclosures URL (Impressum for DE/AT; company details page for other EU/UK).
	'disclosure_company' => array(
		'step'         => 1,
		'section'      => 1,
		'source'       => 'terms-conditions',
		'translatable' => true,
		'type'         => 'url',
		'help'         => __(
			'For Germany and Austria, refer to your Impressum, for other EU countries and the UK select a page with your company or personal details.',
			'complianz-terms-conditions'
		) . cmplz_tc_read_more( 'https://complianz.io/definitions/what-are-statutory-and-regulatory-disclosures?tc&step=1' ),
		'label'        => __( 'Where can your visitors find your statutory and regulatory disclosures?', 'complianz-terms-conditions' ),
	),

);

// -------------------------------------------------------------------------
// Step 2, Section 1 — Questions: Content
// -------------------------------------------------------------------------
$this->fields = $this->fields + array(

	// Whether the site operates a webshop; pre-filled when WooCommerce/EDD is active.
	'webshop_content'             => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'label'    => __( 'Are you running a webshop?', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether visitors can register an account on the site.
	'account_content'             => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Is there an option to register an account on your website for clients?', 'complianz-terms-conditions' ),
		'tooltip'  => __( 'This means any registration form or account creation for your customers or website visitors.', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Shown when account_content = 'yes'; adds an account-suspension/deletion paragraph.
	'delete'                      => array(
		'step'      => 2,
		'section'   => 1,
		'source'    => 'terms-conditions',
		'type'      => 'radio',
		'required'  => true,
		'default'   => '',
		'label'     => __( 'Do you want to suspend or delete user accounts of visitors that breach the terms & conditions?', 'complianz-terms-conditions' ),
		'tooltip'   => __( 'Appends a paragraph to your terms & conditions enabling your to delete any account breaching this document.', 'complianz-terms-conditions' ),
		'options'   => $this->yes_no,
		'condition' => array(
			'account_content' => 'yes',
		),
	),

	// Whether the site participates in affiliate marketing programs.
	'affiliate_content'           => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you engage in affiliate marketing?', 'complianz-terms-conditions' ),
		'tooltip'  => __( 'Either by accepting affiliate commission through your webshop or engaging in other affiliate programs.', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether visitors can post user-generated content (reviews, forum posts, comments).
	'forum_content'               => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Is there an option for visitors to post their own content on your websites?', 'complianz-terms-conditions' ),
		'tooltip'  => __( 'Think about reviews, a forum, comments and other moderated and unmoderated content.', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether to add a WCAG/accessibility reference paragraph.
	'accessibility_content'       => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you want to include your efforts concerning accessibility?', 'complianz-terms-conditions' ),
		'help'     => __( 'Extend your document with a reference to your efforts toward accessibility.', 'complianz-terms-conditions' )
						. cmplz_tc_read_more( 'https://complianz.io/definitions/what-is-wcag?tc&step=2&section=1' ),
		'options'  => $this->yes_no,
	),

	// Whether the site specifically targets minors; triggers the minimum_age field below.
	'age_content'                 => array(
		'step'     => 2,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Is your website specifically targeted at minors?', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Shown when age_content = 'yes'; adds a legal-guardian consent paragraph.
	'minimum_age'                 => array(
		'step'      => 2,
		'section'   => 1,
		'source'    => 'terms-conditions',
		'type'      => 'number',
		'default'   => 12,
		'label'     => __( 'What is the minimum appropriate age for your website? ', 'complianz-terms-conditions' ),
		'tooltip'   => __( 'This will ensure a paragraph explaining a legal guardian must review and agree to these terms & conditions', 'complianz-terms-conditions' ),
		'condition' => array(
			'age_content' => 'yes',
		),
	),

	// -----------------------------------------------------------------------
	// Step 2, Section 2 — Questions: Communication
	// -----------------------------------------------------------------------

	// Whether to add an electronic-communication paragraph (e.g. email is "in writing").
	'electronic_communication'    => array(
		'step'     => 2,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you want to state that communication in writing is done electronically?', 'complianz-terms-conditions' ),
		'tooltip'  => __(
			'This will contain a paragraph that communication in writing will be done electronically e.g., email and other digital communication tools.',
			'complianz-terms-conditions'
		),
		'options'  => $this->yes_no,
	),

	// Whether the site sends marketing newsletters (excludes transactional email).
	'newsletter_communication'    => array(
		'step'     => 2,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'tooltip'  => __( 'Order updates, customer service and other direct and specific communication with your clients or users should not be considered.', 'complianz-terms-conditions' ),
		'label'    => __( 'Do you send newsletters?', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether to include a force-majeure liability-exclusion paragraph.
	'majeure_communication'       => array(
		'step'     => 2,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you want to enable Force Majeure? ', 'complianz-terms-conditions' ),
		'help'     => __( 'Force majeure are occurrences beyond the reasonable control of a party and that will void liability', 'complianz-terms-conditions' ) . cmplz_tc_read_more( 'https://complianz.io/definition/what-is-force-majeure/?tc&step=2&section=2' ),
		'options'  => $this->yes_no,
	),

	// Whether changes to the T&C will be announced in writing before taking effect.
	'notice_communication'        => array(
		'step'     => 2,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Will you give a written notice of any changes or updates to the terms & conditions before these changes will become effective?', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether to limit the governing language to the current site language.
	'language_communication'      => array(
		'step'     => 2,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => 'yes',
		'label'    => __( 'Do you want to limit the interpretation of this document to your current language?', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Shown when language_communication = 'no'; lists all languages the document is available in.
	// Pre-filled from WPML/Polylang when a multilanguage plugin is active.
	'multilanguage_communication' => array(
		'step'      => 2,
		'section'   => 2,
		'source'    => 'terms-conditions',
		'type'      => 'multicheckbox',
		'required'  => true,
		'default'   => '',
		'condition' => array(
			'language_communication' => 'no',
		),
		'label'     => __( 'In which languages is this document available for interpretation?', 'complianz-terms-conditions' ),
		'help'      => __( 'This answer is pre-filled if a multilanguage plugin is available e.g. WPML or Polylang.', 'complianz-terms-conditions' )
							. cmplz_tc_read_more( 'https://complianz.io/translating-terms-conditions/' ),
		'options'   => $this->languages,
	),

	// -----------------------------------------------------------------------
	// Step 2, Section 3 — Questions: Liability
	// -----------------------------------------------------------------------

	// Whether the site offers professional advice; affects the disclaimer paragraph.
	'sensitive_liability'         => array(
		'step'     => 2,
		'section'  => 3,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you offer financial, legal or medical advice?', 'complianz-terms-conditions' ),
		'tooltip'  => __( "If you answer 'No', a paragraph will explain the content on your website does not constitute professional advice.", 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Whether to cap liability at a fixed monetary amount.
	'max_liability'               => array(
		'step'     => 2,
		'section'  => 3,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'required' => true,
		'default'  => '',
		'label'    => __( 'Do you want to limit liability with a fixed amount?', 'complianz-terms-conditions' ),
		'tooltip'  => __( 'If you choose no, liability will be fixed to the amount paid by your customer.', 'complianz-terms-conditions' ),
		'options'  => $this->yes_no,
	),

	// Shown when max_liability = 'yes'; the fixed liability cap (e.g. "$1000").
	'about_liability'             => array(
		'step'        => 2,
		'section'     => 3,
		'source'      => 'terms-conditions',
		'placeholder' => '$1000',
		'type'        => 'text',
		'default'     => '',
		'label'       => __( 'Regarding the previous question, fill in the fixed amount including the currency.', 'complianz-terms-conditions' ),
		'condition'   => array(
			'max_liability' => 'yes',
		),
	),

	// -----------------------------------------------------------------------
	// Step 2, Section 4 — Questions: Copyright
	// -----------------------------------------------------------------------

	// Intellectual property licensing choice for original content on the site.
	'about_copyright'             => array(
		'step'     => 2,
		'section'  => 4,
		'source'   => 'terms-conditions',
		'type'     => 'radio',
		'options'  => array(
			'allrights' => __( 'All rights reserved', 'complianz-terms-conditions' ),
			'norights'  => __( 'No rights are reserved', 'complianz-terms-conditions' ),
			'ccattr'    => __( 'Creative commons - Attribution', 'complianz-terms-conditions' ),
			'ccsal'     => __( 'Creative commons - Share a like', 'complianz-terms-conditions' ),
			'ccnod'     => __( 'Creative commons - No derivates', 'complianz-terms-conditions' ),
			'ccnon'     => __( 'Creative commons - Noncommercial', 'complianz-terms-conditions' ),
			'ccnonsal'  => __( 'Creative commons - Share a like Noncommercial', 'complianz-terms-conditions' ),
		),
		'default'  => '',
		'help'     => __( 'Want to know more about Creative Commons?', 'complianz-terms-conditions' )
						. cmplz_tc_read_more( 'https://complianz.io/definitions/what-is-creative-commons?tc&step=2&section=4' ),
		'label'    => __(
			'What do you want to do with any intellectual property claims?',
			'complianz-terms-conditions'
		),
		'required' => true,
	),

	// -----------------------------------------------------------------------
	// Step 2, Section 5 — Questions: Returns
	// -----------------------------------------------------------------------

	// Whether to add a returns/withdrawal-of-service section (mandatory for EU webshops).
	'if_returns'                  => array(
		'step'    => 2,
		'section' => 5,
		'source'  => 'terms-conditions',
		'type'    => 'radio',
		'options' => $this->yes_no,
		'default' => 'yes',
		'tooltip' => __( 'This will append the conditions for returns and withdrawals, mandatory when selling to consumers in the EU.  ', 'complianz-terms-conditions' ),
		'label'   => __( 'Do you offer returns of goods or the withdrawal of services?', 'complianz-terms-conditions' ),
	),

	// Whether to use a custom withdrawal form instead of the auto-generated PDF.
	'if_returns_custom'           => array(
		'step'    => 2,
		'section' => 5,
		'source'  => 'terms-conditions',
		'type'    => 'radio',
		'options' => $this->yes_no,
		'default' => 'no',
		'tooltip' => __( 'We will add a standard, translatable form to this paragraph. To use your own, you can add the link below.', 'complianz-terms-conditions' ),
		'label'   => __( 'Do you want to use a custom withdrawal form?', 'complianz-terms-conditions' ),
	),

	// URL of the custom withdrawal form PDF; shown when if_returns_custom = 'yes'.
	'if_returns_custom_link'      => array(
		'step'      => 2,
		'section'   => 5,
		'source'    => 'terms-conditions',
		'default'   => home_url( '/wp-content/uploads/custom-withdrawal-form.pdf' ),
		'type'      => 'url',
		'label'     => __( 'Add the URL for your custom withdrawal form', 'complianz-terms-conditions' ),
		'condition' => array(
			'if_returns_custom' => 'yes',
		),
	),

	// Refund period in days; EU legislation requires a minimum of 14 days.
	'refund_period'               => array(
		'step'      => 2,
		'section'   => 5,
		'minimum'   => 14,
		'required'  => true,
		'source'    => 'terms-conditions',
		'type'      => 'number',
		'default'   => 14,
		'label'     => __( 'What is your refund period in days?', 'complianz-terms-conditions' ),
		'tooltip'   => __( 'EU legislation requires you to offer a minimum of 14 days refund period.', 'complianz-terms-conditions' ),
		'condition' => array(
			'if_returns' => 'yes',
		),
	),

	// Type of contract closed through the website; determines which return clauses apply.
	'about_returns'               => array(
		'step'      => 2,
		'section'   => 5,
		'source'    => 'terms-conditions',
		'type'      => 'radio',
		'options'   => array(
			'nuts_services'  => __( 'Services and/or digital content.', 'complianz-terms-conditions' ),
			'nuts_utilities' => __( 'Utilities - Gas, water and electricity.', 'complianz-terms-conditions' ),
			'webshop'        => __( 'Products and goods.', 'complianz-terms-conditions' ),
			'multiples'      => __( 'A contract relating to goods ordered by the consumer and delivered separately.', 'complianz-terms-conditions' ),
			'subscription'   => __( 'Subscription-based delivery of goods.', 'complianz-terms-conditions' ),
		),
		'default'   => '',
		'help'      => cmplz_tc_read_more( 'https://complianz.io/definition/about-return-policies/' ),
		'label'     => __( 'Please choose the option that best describes the contract a consumer closes with you through the use of the website.', 'complianz-terms-conditions' ),
		'condition' => array(
			'if_returns' => 'yes',
		),
	),

	// Whether the seller offers to collect physical goods from the customer on withdrawal.
	'product_returns'             => array(
		'step'      => 2,
		'section'   => 5,
		'source'    => 'terms-conditions',
		'type'      => 'radio',
		'options'   => $this->yes_no,
		'default'   => '',
		'label'     => __( 'Do you want to offer your customer to collect the goods yourself in the event of withdrawal?', 'complianz-terms-conditions' ),
		'condition' => array(
			'about_returns' => 'webshop OR multiples OR subscription',
		),
	),

	// Who bears the cost of returning goods on withdrawal.
	'costs_returns'               => array(
		'step'      => 2,
		'section'   => 5,
		'source'    => 'terms-conditions',
		'type'      => 'radio',
		'options'   => array(
			'seller'   => __( 'We, the seller', 'complianz-terms-conditions' ),
			'customer' => __( 'The customer', 'complianz-terms-conditions' ),
			// Special case: goods cannot be posted; a maximum return cost applies.
			'maxcost'  => __( 'The goods, by their nature, cannot normally be returned by post and a maximum cost of return applies ', 'complianz-terms-conditions' ),
		),
		'default'   => '',
		'label'     => __( 'Who will bear the cost of returning the goods?', 'complianz-terms-conditions' ),
		'condition' => array(
			'about_returns' => 'webshop OR multiples OR subscription',
		),
	),

	// Shown when costs_returns = 'maxcost'; the maximum return cost including currency.
	'max_amount_returned'         => array(
		'step'        => 2,
		'section'     => 5,
		'source'      => 'terms-conditions',
		'type'        => 'text',
		'default'     => '',
		'placeholder' => '$1000',
		'label'       => __( 'Regarding the previous question, fill in the maximum amount including the currency.', 'complianz-terms-conditions' ),
		'condition'   => array(
			'costs_returns' => 'maxcost',
			'if_returns'    => 'yes',
		),
	),
);

// -------------------------------------------------------------------------
// Step 3, Section 1 — Documents: Create document pages.
// The 'callback' value triggers cmplz_tc_terms_conditions_add_pages action.
// -------------------------------------------------------------------------
$this->fields = $this->fields + array(
	'create_pages' => array(
		'step'     => 3,
		'section'  => 1,
		'source'   => 'terms-conditions',
		'callback' => 'terms_conditions_add_pages',
		'label'    => '',
	),
);

// Step 3, Section 2 — Documents: Assign document pages to a navigation menu.
// The 'callback' value triggers cmplz_tc_terms_conditions_add_pages_to_menu action.
$this->fields = $this->fields + array(
	'add_pages_to_menu' => array(
		'step'     => 3,
		'section'  => 2,
		'source'   => 'terms-conditions',
		'callback' => 'terms_conditions_add_pages_to_menu',
		'label'    => '',
	),
);

// Step 4 — Finish: Completion screen with tips, tricks, and companion plugin cards.
// The 'callback' value triggers the cmplz_tc_last_step action.
$this->fields = $this->fields + array(
	'finish_setup' => array(
		'step'     => 4,
		'source'   => 'terms-conditions',
		'callback' => 'last_step',
		'label'    => '',
	),
);
