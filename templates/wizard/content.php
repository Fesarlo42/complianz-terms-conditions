<?php
/**
 * Template: Main content area for a single wizard step.
 *
 * Renders the form shell used on every step of the T&C setup wizard. It
 * contains a nonce-protected POST form, hidden step/section tracking inputs,
 * and a series of token placeholders that the wizard controller replaces with
 * real markup before output. The footer row holds the save notices and the
 * Previous / Save / Next navigation buttons.
 *
 * Token reference (all replaced by cmplz_tc_wizard before rendering):
 * - `{page_url}`          — The form action URL for the current admin page.
 * - `{step}`              — Current step index (integer), stored in a hidden input.
 * - `{section}`           — Current section index (integer), stored in a hidden input.
 * - `{title}`             — Translated heading for the current wizard step.
 * - `{flags}`             — Language-flag icons for multilingual document generation.
 * - `{learn_notice}`      — Optional contextual "Learn more" informational notice.
 * - `{intro}`             — Introductory copy for the current step.
 * - `{post_id}`           — Hidden input carrying the associated WordPress post ID.
 * - `{fields}`            — Rendered HTML for all wizard fields on this step/section.
 * - `{save_as_notice}`    — Notice confirming what the document will be saved as.
 * - `{save_notice}`       — Inline validation / confirmation message after save.
 * - `{previous_button}`   — "Previous" navigation button (empty on step 1).
 * - `{save_button}`       — "Save" button rendered by cmplz_tc_field::save_button().
 * - `{next_button}`       — "Next" / "Finish" navigation button.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Templates/Wizard
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

?>
<div class="cmplz-section-content">
	<form class="cmplz-form" action="{page_url}" method="POST">
		<?php /* Hidden inputs carry the current step and section indices so the controller knows which data to save on submit. */ ?>
		<input type="hidden" value="{step}" name="step">
		<input type="hidden" value="{section}" name="section">
		<?php
		// Nonce field guards form submissions against CSRF; verified in cmplz_tc_field::process_save().
		wp_nonce_field( 'complianz_tc_save', 'complianz_tc_nonce' );
		?>

		<div class="cmplz-wizard-title cmplz-section-content-title-header">
			<h1 class="h4">{title}</h1>
			<?php /* {flags} outputs language-flag icons when multiple PDF languages are configured. */ ?>
			{flags}
		</div>
		<div class="cmplz-wizard-title cmplz-section-content-notifications-header">
			<h1 class="h4"><?php esc_html_e( 'Notifications', 'complianz-terms-conditions' ); ?></h1>
		</div>
		<?php /* {learn_notice} is replaced with a contextual informational notice, or an empty string when none applies. */ ?>
		{learn_notice}
		<?php /* {intro} contains the step's introductory copy, already escaped by the wizard controller. */ ?>
		{intro}
		<?php /* {post_id} renders a hidden input with the WordPress post ID of the generated T&C document. */ ?>
		{post_id}
		<?php /* {fields} is replaced with all rendered field HTML for the current wizard step/section. */ ?>
		{fields}
		<div class="cmplz-section-footer">
			<?php /* {save_as_notice} confirms the document type/name that will be updated when saved. */ ?>
			{save_as_notice}
			<?php /* {save_notice} shows inline validation feedback or a success message after form submission. */ ?>
			{save_notice}
			<div class="cmplz-buttons-container">
				<?php /* Navigation buttons: previous is omitted on step 1; next becomes "Finish" on the last step. */ ?>
				{previous_button}
				{save_button}
				{next_button}
			</div>
		</div>

	</form>
</div>
