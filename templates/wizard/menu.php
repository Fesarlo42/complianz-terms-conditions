<?php
/**
 * Template: Left-hand navigation sidebar for the T&C setup wizard.
 *
 * Renders the wizard's sidebar panel, which contains the wizard title, a
 * progress bar, the current step title, and the full step list. The
 * `{percentage-complete}`, `{title}`, and `{steps}` tokens are replaced at
 * runtime by `cmplz_tc_wizard` before this markup is output.
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
<div class="cmplz-wizard-menu">
	<div class="cmplz-wizard-title"><h1 class="h4"><?php esc_html_e( 'The Wizard', 'complianz-terms-conditions' ); ?></h1></div>
	<div class="cmplz-wizard-progress-bar">
		<?php /* Width is set inline; {percentage-complete} is replaced by the wizard controller with an integer 0–100. */ ?>
		<div class="cmplz-wizard-progress-bar-value" style="width: {percentage-complete}%"></div>
	</div>
	<?php /* {title} is replaced with the current step's translated heading. */ ?>
	{title}
	<div class="cmplz-wizard-menu-menus">
		<?php /* {steps} is replaced with the rendered list of wizard step links. */ ?>
		{steps}
	</div>
</div>
