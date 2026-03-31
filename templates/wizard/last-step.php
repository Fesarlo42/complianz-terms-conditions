<?php
/**
 * Template: "Tips & Tricks" cards shown on the final wizard step.
 *
 * Renders a list of helpful article links displayed after the user completes
 * the T&C wizard. Each card links to a Complianz documentation page covering
 * a common post-setup task: translating, styling, editing, and adding the
 * generated document to WooCommerce. This template contains no dynamic tokens
 * and requires no controller pre-processing before it is included.
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
<div class="cmplz-trick">
	<a href="https://complianz.io/translating-terms-conditions/" target="_blank">
		<div class="cmplz-bullet" style=""></div>
		<?php /* translators: %s is the document type name, e.g. "Terms & Conditions". */ ?>
		<div class="cmplz-tips-tricks-content"><?php printf( esc_html__( 'Translating %s', 'complianz-terms-conditions' ), esc_html__( 'Terms & Conditions', 'complianz-terms-conditions' ) ); ?></div>
	</a>
</div>

<div class="cmplz-trick">
	<a href="https://complianz.io/styling-terms-conditions/" target="_blank">
		<div class="cmplz-bullet"></div>
		<?php /* translators: %s is the document type name, e.g. "Terms & Conditions". */ ?>
		<div class="cmplz-tips-tricks-content"><?php printf( esc_html__( 'Styling %s', 'complianz-terms-conditions' ), esc_html__( 'Terms & Conditions', 'complianz-terms-conditions' ) ); ?></div>
	</a>
</div>

<div class="cmplz-trick">
	<a href="https://complianz.io/editing-terms-conditions/" target="_blank">
		<div class="cmplz-bullet" style=""></div>
		<?php /* translators: %s is the document type name, e.g. "Terms & Conditions". */ ?>
		<div class="cmplz-tips-tricks-content"><?php printf( esc_html__( 'Editing %s', 'complianz-terms-conditions' ), esc_html__( 'Terms & Conditions', 'complianz-terms-conditions' ) ); ?></div>
	</a>
</div>

<div class="cmplz-trick">
	<a href="https://complianz.io/woocommerce-terms-conditions/" target="_blank">
		<div class="cmplz-bullet"></div>
		<div class="cmplz-tips-tricks-content"><?php esc_html_e( 'Adding to WooCommerce', 'complianz-terms-conditions' ); ?></div>
	</a>
</div>
