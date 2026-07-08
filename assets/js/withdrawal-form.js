/**
 * Front-end progressive enhancement for the Complianz T&C withdrawal form.
 *
 * Task 4 scope: after a Post/Redirect/Get re-render, move focus to the error
 * summary so assistive tech announces the validation errors. The uncached
 * nonce/render-timestamp fetch (FR-13/NFR-P1) is added by the submission
 * handler in Task 7.
 *
 * @package Complianz_Terms_Conditions
 */

( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var summary = document.querySelector(
			'.cmplz-tc-withdrawal-form .cmplz-tc-wf-errors[role="alert"]'
		);
		if ( summary ) {
			// Make the summary programmatically focusable, then focus it.
			summary.setAttribute( 'tabindex', '-1' );
			summary.focus();
		}
	} );
}() );
