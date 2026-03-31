<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Template: Outer HTML wrapper for all Complianz T&C admin pages.
 *
 * Provides the shared page shell rendered around every admin screen in the
 * plugin. It outputs the standard WordPress `.wrap` container, a sticky `<h1>`
 * placeholder that keeps admin notices above the plugin UI, and a branded
 * header bar with links to documentation and support. The body content is
 * injected by the admin controller via the `{content}` and `{page}` tokens,
 * which are replaced with real values before the template is echoed.
 *
 * High-contrast mode is detected from the Complianz GDPR/CCPA plugin's
 * `high_contrast` setting (when available) and applied as an additional CSS
 * class on the root element to improve accessibility.
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

// Inherit the high-contrast CSS class from the Complianz GDPR/CCPA plugin when
// it is active; fall back to an empty string so no extra class is added.
if ( function_exists( 'cmplz_get_value' ) ) {
	$high_contrast = cmplz_get_value( 'high_contrast', false, 'settings' ) ? 'cmplz-high-contrast' : '';
} else {
	$high_contrast = '';
} ?>
<div class="cmplz wrap <?php echo esc_attr( $high_contrast ); ?>" id="complianz">
	<?php // Placeholder <h1> keeps WordPress admin notices anchored above the plugin UI rather than injected mid-page. ?>
	<h1 class="cmplz-notice-hook-element"></h1>
	<div class="cmplz-{page}">
		<div class="cmplz-header-container">
			<div class="cmplz-header">
				<img src="<?php echo esc_url( trailingslashit( cmplz_tc_url ) . 'assets/images/cmplz-logo.svg' ); ?>" alt="Complianz - Terms & Conditions">
				<div class="cmplz-header-right">
					<a href="https://complianz.io/docs/" class="link-black" target="_blank"><?php esc_html_e( 'Documentation', 'complianz-terms-conditions' ); ?></a>
					<a href="https://wordpress.org/support/plugin/complianz-terms-conditions/" class="button button-black" target="_blank"><?php echo esc_html_e( 'Support', 'complianz-terms-conditions' ); ?></a>
				</div>
			</div>
		</div>
		<div class="cmplz-content-area">
			{content}
		</div>
	</div>
</div>
