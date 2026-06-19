<?php
/**
 * Template: "Our Plugins" panel shown on the last step of the T&C wizard.
 *
 * Defines a list of companion plugins (Complianz GDPR/CCPA and Really Simple
 * Security) and renders a grid of plugin cards that indicate whether each
 * plugin is already installed, active, or available for download. Each card
 * links to the plugin's WordPress.org page and uses
 * `cmplz_tc_admin::get_status_link()` to display a context-aware install /
 * activate / upgrade call-to-action.
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

defined( 'ABSPATH' ) || die( 'you do not have access to this page!' );

/**
 * Registry of companion plugins to advertise on the wizard completion screen.
 *
 * Each entry is keyed by an uppercase plugin identifier and contains:
 * - `constant_free`    (string) PHP constant defined by the free edition.
 * - `constant_premium` (string) PHP constant defined by the premium edition.
 * - `website`          (string) URL to the plugin's premium / pricing page.
 * - `url`              (string) URL to the plugin's WordPress.org listing.
 * - `search`           (string) WordPress.org search query used to find the plugin.
 * - `title`            (string) Display name shown in the plugin card.
 *
 * @var array<string, array{
 *     constant_free: string,
 *     constant_premium: string,
 *     website: string,
 *     url: string,
 *     search: string,
 *     title: string
 * }> $other_plugins
 */
$other_plugins = array(

	'COMPLIANZ' => array(
		'constant_free'    => 'cmplz_plugin',
		'constant_premium' => 'cmplz_premium',
		'website'          => 'https://complianz.io/pricing',
		'url'              => 'https://wordpress.org/plugins/complianz-gdpr/',
		'search'           => 'complianz-gdpr',
		'title'            => 'Complianz GDPR/CCPA - ' . __( 'The Privacy Suite for WordPress', 'complianz-terms-conditions' ),
	),
	'RSSSL'     => array(
		'constant_free'    => 'rsssl_version',
		'constant_premium' => 'rsssl_pro_version',
		'website'          => 'https://really-simple-ssl.com/pro',
		'search'           => 'really+simple+ssl',
		'url'              => 'https://wordpress.org/plugins/really-simple-ssl/',
		'title'            => 'Really Simple Security - ' . __( 'Lightweight Plugin, Heavyweight Security Features.', 'complianz-terms-conditions' ),
	),
);
?>
<div class="cmplz-other-plugins-container">
	<div class="cmplz-grid-header">
		<h2 class="cmplz-grid-title h4"> <div class="cmplz-other-plugin-title"><?php esc_html_e( 'Our Plugins', 'complianz-terms-conditions' ); ?></div></h2>
	</div>
	<?php
	// Render one card per companion plugin.
	foreach ( $other_plugins as $plugin_id => $other_plugin ) {
		// Derive the lowercase CSS class prefix from the plugin identifier (e.g. 'COMPLIANZ' → 'complianz').
		$prefix = strtolower( $plugin_id );
		?>
		<div class="cmplz-other-plugins-element cmplz-<?php echo esc_attr( $prefix ); ?>">
			<a href="<?php echo esc_url_raw( $other_plugin['url'] ); ?>" target="_blank" title="<?php echo esc_html( $other_plugin['title'] ); ?>">
				<div class="cmplz-bullet"></div>
				<div class="cmplz-other-plugins-content"><?php echo esc_html( $other_plugin['title'] ); ?></div>
			</a>
			<div class="cmplz-other-plugin-status">
				<?php
				// Outputs an install/activate/upgrade link based on whether the plugin is already present.
				echo COMPLIANZ_TC::$admin->get_status_link( $other_plugin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML link returned by trusted internal method.
				?>
			</div>
		</div>
	<?php } ?>
</div>
