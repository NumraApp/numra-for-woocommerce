/**
 * Numra for WooCommerce — Admin JS
 * Handles: connection test and announcement dismissal. No usage tracking.
 * API key is NEVER handled client-side — all auth is server-side (PHP).
 */

/* global numraAdmin, jQuery */
( function ( $ ) {
	'use strict';

	var AJAX_URL    = numraAdmin.ajaxUrl;
	var TEST_NONCE  = numraAdmin.testNonce;
	var EVENT_NONCE = numraAdmin.dismissNonce;

	// ── Connection test ───────────────────────────────────────────────────────

	$( '#numra-test-connection' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#numra-test-result' );

		$btn.prop( 'disabled', true ).text( numraAdmin.strings.testing );
		$result.text( '' ).removeClass( 'numra-ok numra-fail' );

		$.post( AJAX_URL, {
			action: 'numra_test_connection',
			nonce:  TEST_NONCE,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$result.text( '\u2713 ' + numraAdmin.strings.connected ).addClass( 'numra-ok' );
			} else {
				var msg = ( res.data && res.data.message ) ? res.data.message : numraAdmin.strings.failed;
				$result.text( '\u2715 ' + msg ).addClass( 'numra-fail' );
			}
		} )
		.fail( function () {
			$result.text( '\u2715 ' + numraAdmin.strings.failed ).addClass( 'numra-fail' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( numraAdmin.strings.testConnection );
		} );
	} );

	// ── Announcements ─────────────────────────────────────────────────────────
	//
	// There is no impression or click tracking. There used to be: every
	// announcement fired a request back to Numra on every admin page load
	// simply to be counted, and clicking its link fired another. That is
	// bandwidth and latency taken from the merchant's own dashboard, on every
	// screen, forever — paid so we could look at numbers we were not acting on.
	//
	// The only request left is the one below, and a merchant causes it
	// deliberately by closing an announcement. It is not a measurement; it is
	// the instruction "do not show me this again", and the server needs it or
	// the banner returns on the next page load.

	/**
	 * Tell the server this merchant dismissed an announcement.
	 * The API key is handled server-side — never touched here.
	 */
	function sendDismiss( placementId, placementKey ) {
		if ( ! placementId || ! placementKey ) {
			return;
		}
		$.post( AJAX_URL, {
			action:         'numra_announcement_dismiss',
			nonce:          EVENT_NONCE,
			placement_id:   placementId,
			event_type:     'DISMISS',
			placement_key:  placementKey,
		} );
		// Fire-and-forget: the banner is already gone from the DOM either way.
	}

	// ── DISMISS — hide banner + tell the server ──────────────────────────────

	$( document ).on( 'click', '.numra-banner-dismiss', function () {
		var $btn         = $( this );
		var $banner      = $btn.closest( '.numra-announcement' );
		var placementId  = $btn.data( 'placement-id' );
		var placementKey = $btn.data( 'placement-key' );

		// Animate out then remove from DOM.
		$banner.addClass( 'numra-hiding' );
		setTimeout( function () {
			$banner.remove();
		}, 260 );

		sendDismiss( placementId, placementKey );
	} );

} )( jQuery );
