/**
 * Front-end progressive enhancement for the Complianz T&C withdrawal form.
 *
 * Two jobs:
 *  1. Fetch a fresh nonce + render timestamp from the uncached REST endpoint and
 *     populate the hidden integrity fields, so a fully cached form page can never
 *     submit a stale nonce.
 *  2. After a Post/Redirect/Get re-render, move focus to the validation error
 *     summary — or, on the result screens, to the confirmation/error message —
 *     so assistive tech announces it (static server-rendered content that
 *     role="status"/"alert" alone would not announce).
 *
 * @package Complianz_Terms_Conditions
 */

( function () {
	function fetchNonce( form ) {
		var config = window.cmplz_tc_withdrawal;
		if ( ! config || ! config.nonceEndpoint ) {
			return;
		}
		fetch( config.nonceEndpoint, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}
				var nonce = form.querySelector( 'input[name="cmplz_tc_wf_nonce"]' );
				var rendered = form.querySelector( 'input[name="cmplz_tc_wf_rendered"]' );
				if ( nonce && data.nonce ) {
					nonce.value = data.nonce;
				}
				if ( rendered && data.rendered ) {
					rendered.value = data.rendered;
				}
			} )
			.catch( function () {} );
	}

	function focusErrorSummary() {
		var summary = document.querySelector(
			'.cmplz-tc-withdrawal-form .cmplz-tc-wf-errors[role="alert"]'
		);
		if ( summary ) {
			summary.setAttribute( 'tabindex', '-1' );
			summary.focus();
		}
	}

	function focusResult() {
		// After the PRG, the confirmation/error is server-rendered static content, so
		// role="status"/"alert" alone will not announce it — move focus to it.
		var result = document.querySelector(
			'.cmplz-tc-wf-confirmation, .cmplz-tc-wf-error'
		);
		if ( result ) {
			result.setAttribute( 'tabindex', '-1' );
			result.focus();
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.cmplz-tc-withdrawal-form' );
		if ( form ) {
			fetchNonce( form );
		}
		focusErrorSummary();
		focusResult();
	} );
}() );
