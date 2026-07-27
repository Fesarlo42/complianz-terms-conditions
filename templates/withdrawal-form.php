<?php
/**
 * Template: interactive EU withdrawal form (Directive 2023/2673, Art. 11a).
 *
 * Renders the online withdrawal function a consumer submits to withdraw from a distance
 * contract. Collects the EU model-form fields: name, email and goods/service are required;
 * address, order reference, order date and an additional message are optional. The merchant
 * identity/address is shown as a pre-filled, non-editable heading. The date of withdrawal is
 * the submission timestamp, not a field.
 *
 * All labels/messages are translatable and rendered in the runtime locale, with labels
 * associated to their controls and required/error state announced. The three hidden integrity
 * fields (nonce, honeypot, render timestamp) are rendered as placeholders and consumed by the
 * submission handler.
 *
 * Rendered through cmplz_tc_get_template(), so `$args` is available in scope and a
 * theme override at `{theme}/complianz-terms-conditions/templates/withdrawal-form.php`
 * is honoured. Recognised `$args`:
 *   - merchant_identity string  Pre-filled merchant name/address (plain text).
 *   - errors            array   field-name => error message (re-render after PRG).
 *   - values            array   field-name => submitted value (preserved input).
 *   - form_action       string  Submission endpoint; defaults to admin-post.php.
 *
 * @package    Complianz_Terms_Conditions
 * @subpackage Templates
 * @license    GPL-2.0-or-later
 * @link       https://complianz.io
 *
 * @since      1.4.0
 */

$args = isset( $args ) && is_array( $args ) ? $args : array();

$wf_errors   = isset( $args['errors'] ) && is_array( $args['errors'] ) ? $args['errors'] : array();
$wf_values   = isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
$wf_merchant = isset( $args['merchant_identity'] ) ? (string) $args['merchant_identity'] : '';
$wf_action   = ! empty( $args['form_action'] ) ? (string) $args['form_action'] : admin_url( 'admin-post.php' );

// Fields: name/email/goods required (Art. 11a minimum), the rest optional.
$wf_fields = array(
	array(
		'key'      => 'cmplz_tc_wf_name',
		'id'       => 'cmplz-tc-wf-name',
		'label'    => __( 'Name', 'complianz-terms-conditions' ),
		'type'     => 'text',
		'required' => true,
	),
	array(
		'key'      => 'cmplz_tc_wf_email',
		'id'       => 'cmplz-tc-wf-email',
		'label'    => __( 'Email address', 'complianz-terms-conditions' ),
		'type'     => 'email',
		'required' => true,
	),
	array(
		'key'      => 'cmplz_tc_wf_goods',
		'id'       => 'cmplz-tc-wf-goods',
		'label'    => __( 'Goods or service being withdrawn', 'complianz-terms-conditions' ),
		'type'     => 'textarea',
		'required' => true,
	),
	array(
		'key'      => 'cmplz_tc_wf_address',
		'id'       => 'cmplz-tc-wf-address',
		'label'    => __( 'Address', 'complianz-terms-conditions' ),
		'type'     => 'textarea',
		'required' => false,
	),
	array(
		'key'      => 'cmplz_tc_wf_order_ref',
		'id'       => 'cmplz-tc-wf-order-ref',
		'label'    => __( 'Order or contract reference', 'complianz-terms-conditions' ),
		'type'     => 'text',
		'required' => false,
	),
	array(
		'key'      => 'cmplz_tc_wf_order_date',
		'id'       => 'cmplz-tc-wf-order-date',
		'label'    => __( 'Order date', 'complianz-terms-conditions' ),
		'type'     => 'date',
		'required' => false,
	),
	array(
		'key'      => 'cmplz_tc_wf_message',
		'id'       => 'cmplz-tc-wf-message',
		'label'    => __( 'Additional message', 'complianz-terms-conditions' ),
		'type'     => 'textarea',
		'required' => false,
	),
);

// Autofill hints for personal-data fields (WCAG 1.3.5, "as easy to use as sign-up").
$wf_autocomplete_map = array(
	'cmplz_tc_wf_name'    => 'name',
	'cmplz_tc_wf_email'   => 'email',
	'cmplz_tc_wf_address' => 'street-address',
);

// Field name => control id, so error-summary items can link to their field.
$wf_id_by_key = array();
foreach ( $wf_fields as $wf_f ) {
	$wf_id_by_key[ $wf_f['key'] ] = $wf_f['id'];
}
?>
<form class="cmplz-tc-withdrawal-form" method="post" action="<?php echo esc_url( $wf_action ); ?>" aria-labelledby="cmplz-tc-wf-title">
	<?php /* The form starts at <h2>; when embedded mid-page this could skip a level, but correct heading order is the host theme's responsibility. Override this template to change it. */ ?>
	<h2 id="cmplz-tc-wf-title"><?php esc_html_e( 'Withdrawal form', 'complianz-terms-conditions' ); ?></h2>
	<p><?php esc_html_e( 'Complete and submit this form only if you wish to withdraw from your contract.', 'complianz-terms-conditions' ); ?></p>
	<p class="cmplz-tc-wf-note"><?php esc_html_e( 'Required fields are marked with an asterisk (*).', 'complianz-terms-conditions' ); ?></p>

	<?php if ( ! empty( $wf_errors ) ) : ?>
		<div class="cmplz-tc-wf-errors" role="alert">
			<p><?php esc_html_e( 'Please correct the following:', 'complianz-terms-conditions' ); ?></p>
			<ul>
				<?php foreach ( $wf_errors as $wf_error_key => $wf_error ) : ?>
					<li>
						<?php if ( isset( $wf_id_by_key[ $wf_error_key ] ) ) : ?>
							<a href="#<?php echo esc_attr( $wf_id_by_key[ $wf_error_key ] ); ?>"><?php echo esc_html( $wf_error ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $wf_error ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $wf_merchant ) : ?>
		<div class="cmplz-tc-wf-merchant">
			<h3><?php esc_html_e( 'To:', 'complianz-terms-conditions' ); ?></h3>
			<p><?php echo wp_kses( nl2br( esc_html( $wf_merchant ) ), array( 'br' => array() ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	foreach ( $wf_fields as $wf_field ) :
		$wf_key       = $wf_field['key'];
		$wf_id        = $wf_field['id'];
		$wf_has_error = isset( $wf_errors[ $wf_key ] );
		$wf_value     = isset( $wf_values[ $wf_key ] ) ? $wf_values[ $wf_key ] : '';
		$wf_error_id  = $wf_id . '-error';
		$wf_autocmp   = isset( $wf_autocomplete_map[ $wf_key ] ) ? $wf_autocomplete_map[ $wf_key ] : '';
		?>
		<div class="cmplz-tc-wf-field">
			<label for="<?php echo esc_attr( $wf_id ); ?>">
				<?php echo esc_html( $wf_field['label'] ); ?>
				<?php if ( $wf_field['required'] ) : ?>
					<span class="cmplz-tc-wf-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<?php if ( 'textarea' === $wf_field['type'] ) : ?>
				<textarea
					id="<?php echo esc_attr( $wf_id ); ?>"
					name="<?php echo esc_attr( $wf_key ); ?>"
					<?php
					if ( '' !== $wf_autocmp ) {
						echo ' autocomplete="' . esc_attr( $wf_autocmp ) . '"';
					}
					if ( $wf_field['required'] ) {
						echo ' required aria-required="true"';
					}
					if ( $wf_has_error ) {
						echo ' aria-invalid="true" aria-describedby="' . esc_attr( $wf_error_id ) . '"';
					}
					?>
				><?php echo esc_textarea( $wf_value ); ?></textarea>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( $wf_field['type'] ); ?>"
					id="<?php echo esc_attr( $wf_id ); ?>"
					name="<?php echo esc_attr( $wf_key ); ?>"
					value="<?php echo esc_attr( $wf_value ); ?>"
					<?php
					if ( '' !== $wf_autocmp ) {
						echo ' autocomplete="' . esc_attr( $wf_autocmp ) . '"';
					}
					if ( $wf_field['required'] ) {
						echo ' required aria-required="true"';
					}
					if ( $wf_has_error ) {
						echo ' aria-invalid="true" aria-describedby="' . esc_attr( $wf_error_id ) . '"';
					}
					?>
				/>
			<?php endif; ?>
			<?php if ( $wf_has_error ) : ?>
				<span class="cmplz-tc-wf-field-error" id="<?php echo esc_attr( $wf_error_id ); ?>"><?php echo esc_html( $wf_errors[ $wf_key ] ); ?></span>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php /* Honeypot: hidden from users and assistive tech; genuine consumers never reach it. The focusable input inside this aria-hidden wrapper is intentional — tabindex="-1" so no real user reaches it, and it must stay submittable so bots fill it. */ ?>
	<div class="cmplz-tc-wf-hp" aria-hidden="true">
		<label for="cmplz-tc-wf-website"><?php esc_html_e( 'Leave this field empty', 'complianz-terms-conditions' ); ?></label>
		<input type="text" id="cmplz-tc-wf-website" name="cmplz_tc_wf_website" value="" tabindex="-1" autocomplete="off" />
	</div>

	<?php // Nonce stays empty (JS hydrates it from the uncached endpoint, keeping the page cacheable); the render timestamp is server-side so the mandatory min-time gate works without JS. ?>
	<input type="hidden" name="cmplz_tc_wf_nonce" value="" />
	<input type="hidden" name="cmplz_tc_wf_rendered" value="<?php echo esc_attr( (string) time() ); ?>" />
	<input type="hidden" name="action" value="cmplz_tc_submit_withdrawal" />

	<button type="submit" class="cmplz-tc-wf-submit"><?php esc_html_e( 'Send withdrawal request', 'complianz-terms-conditions' ); ?></button>
</form>
