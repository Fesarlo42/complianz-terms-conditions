<?php
/**
 * Template: Single section/step item in the wizard sidebar navigation menu.
 *
 * Renders one navigation entry in the left-hand wizard menu. The wizard
 * controller replaces the token placeholders with real values before this
 * markup is echoed. Each item contains a status icon, a linked heading, and
 * CSS classes that indicate whether the section is currently active or has
 * already been completed.
 *
 * Token reference (all replaced by cmplz_tc_wizard before rendering):
 * - `{active}`    — The string `'cmplz-active'` when this is the current section,
 *                   otherwise an empty string.
 * - `{completed}` — The string `'cmplz-completed'` when this section has been
 *                   answered, otherwise an empty string.
 * - `{icon}`      — SVG/dashicon HTML for the section status indicator.
 * - `{url}`       — The admin URL that navigates directly to this section.
 * - `{title}`     — The translated section heading text.
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
<div class="cmplz-section {active} {completed}">
	{icon}
	<a href="{url}">
		<h3>{title}</h3>
	</a>
</div>
