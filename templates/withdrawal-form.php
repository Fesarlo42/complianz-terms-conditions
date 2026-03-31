<?php
/**
 * Template: Withdrawal / Right-of-Withdrawal form for the Terms & Conditions document.
 *
 * Renders a printable EU right-of-withdrawal form that consumers can complete
 * and return to revoke a purchase agreement or request a return of goods.
 * The `[address_company]` shortcode token is substituted at runtime by the
 * document generator with the merchant's registered company address.
 *
 * To override this template in a theme or child-theme, copy it to
 * `{theme}/complianz-terms-conditions/templates/withdrawal-form.php` and edit
 * as needed. See https://complianz.io/edit-withdrawl-form-template/ for
 * guidance. When adding new fields, keep the text-domain attribute so that
 * translations provided via WPML or similar plugins continue to work.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Templates
 * @author     Complianz
 * @copyright  2023 Complianz.io
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.0.0
 */

?>
<h1><?php esc_html_e( 'Form for Withdrawal of Service, or Return of Goods', 'complianz-terms-conditions' ); ?></h1>
<p><?php esc_html_e( 'Please complete and return this form only if you wish to dissolve/revoke the agreement.', 'complianz-terms-conditions' ); ?></p>

<p><?php esc_html_e( 'Date', 'complianz-terms-conditions' ); ?></p>
<br>
______________________

<?php /* The [address_company] token is replaced by the document generator with the stored company address. */ ?>
<p>[address_company]</p>

<p><?php esc_html_e( '(*) Delete where not applicable.', 'complianz-terms-conditions' ); ?></p>

<p><?php esc_html_e( 'I / We (*) hereby give notice that I / We (*) withdraw from my / our (*) contract of sale of the following goods / the provision (*) of the following service:', 'complianz-terms-conditions' ); ?></p>

<br>
______________________
<p><?php esc_html_e( 'Ordered on (*)/Received on (*)', 'complianz-terms-conditions' ); ?></p>
<br>
______________________
<p><?php esc_html_e( 'Name', 'complianz-terms-conditions' ); ?></p>
<br>
______________________
<p><?php esc_html_e( 'Address', 'complianz-terms-conditions' ); ?></p>
<br>
______________________
<p><?php esc_html_e( 'Signature (if this form is submitted on paper)', 'complianz-terms-conditions' ); ?></p>
<br>
______________________
