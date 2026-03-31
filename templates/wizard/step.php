<?php
/**
 * Template: Single step item in the wizard sidebar navigation menu.
 *
 * Renders one top-level wizard step inside the left-hand navigation panel.
 * A step may contain one or more sections, each rendered via the
 * `section.php` template and injected through the `{sections}` token. The
 * wizard controller replaces all token placeholders with real values before
 * this markup is output.
 *
 * Token reference (all replaced by cmplz_tc_wizard before rendering):
 * - `{active}`    — The string `'cmplz-active'` when this is the current step,
 *                   otherwise an empty string.
 * - `{completed}` — The string `'cmplz-completed'` when every section of this
 *                   step has been answered, otherwise an empty string.
 * - `{url}`       — The admin URL that navigates to the first section of this step.
 * - `{title}`     — The translated step heading text.
 * - `{sections}`  — The rendered HTML for all child section items (zero or more
 *                   `section.php` blocks concatenated together).
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
<div class="cmplz-step">
	<div class="cmplz-step-header {active} {completed}"><a href="{url}"><h2>{title}</h2></a></div>
	{sections}
</div>
