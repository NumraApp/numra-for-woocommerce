/* Numra — manual phone check.
 *
 * Kept out of admin.js so the existing "Test connection" behaviour is not
 * touched. Vanilla: this runs on one tab of one admin page and does not need
 * jQuery to post a form field.
 */
( function () {
	'use strict';

	var cfg = window.numraAdmin || {};
	var S   = cfg.strings || {};

	function el( id ) { return document.getElementById( id ); }

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	/* Bands, not a numeric threshold.
	 *
	 * This used to compare `score >= threshold`, which is the pre-band logic
	 * the rest of the plugin moved away from — and it had no notion of an
	 * unrated number, so a server-side neutral 50 for a phone nobody has ever
	 * seen came back as "Cleared". The order screen deliberately refuses to do
	 * that; this screen should not contradict it.
	 *
	 * `rated` and `verdict` are threaded end to end for exactly this reason. */
	function verdict( d ) {
		if ( d.blacklisted || 'BLOCKED' === d.verdict ) {
			return { cls: 'is-blocked', label: S.blacklisted || 'Blacklisted', showScore: false };
		}
		if ( 'UNRATED' === d.verdict || ! d.rated ) {
			return { cls: 'is-new', label: S.noHistory || 'No history yet', showScore: false };
		}
		var lvl = String( d.level || '' ).toUpperCase();
		if ( 'HIGH' === lvl || 'CRITICAL' === lvl ) {
			return { cls: 'is-high', label: S.highRisk || 'High risk', showScore: true };
		}
		return { cls: 'is-ok', label: S.cleared || 'Cleared', showScore: true };
	}

	function render( box, d ) {
		var v = verdict( d );
		var rows = '';

		if ( d.level && v.showScore ) { rows += '<li><span>' + esc( S.riskLevel || 'Risk level' ) + '</span><span>' + esc( d.level ) + '</span></li>'; }
		if ( d.style )   { rows += '<li><span>' + esc( S.customerStyle || 'Customer type' ) + '</span><span>' + esc( d.style ) + '</span></li>'; }
		if ( d.carrier ) { rows += '<li><span>' + esc( S.carrier || 'Carrier' ) + '</span><span>' + esc( d.carrier ) + '</span></li>'; }
		if ( d.reason )  { rows += '<li><span>&nbsp;</span><span>' + esc( d.reason ) + '</span></li>'; }

		var left = '';
		if ( d.remaining !== null && typeof d.remaining !== 'undefined' ) {
			left = '<p class="numra-check-left">' + esc( d.remaining ) + ' ' + esc( S.checksLeft || 'checks left today' ) + '</p>';
		}

		box.className = 'numra-check-result has-result';
		box.innerHTML =
			'<div class="numra-check-head">' +
				/* A score is only shown when it means something. An unrated
				   number has no score to show, and printing the server's
				   neutral placeholder would invent one. */
				'<span class="numra-check-score ' + v.cls + '">' +
					( v.showScore && typeof d.score === 'number' ? esc( d.score ) : '&mdash;' ) +
				'</span>' +
				'<div><strong>' + esc( v.label ) + '</strong>' +
				'<div class="numra-check-phone">' + esc( d.phone ) + '</div></div>' +
			'</div>' +
			( rows ? '<ul class="numra-check-list">' + rows + '</ul>' : '' ) +
			left;
	}

	function fail( box, msg ) {
		box.className = 'numra-check-result has-error';
		box.innerHTML = '<p>' + esc( msg ) + '</p>';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn   = el( 'numra-check-run' );
		var input = el( 'numra-check-phone' );
		var box   = el( 'numra-check-result' );
		if ( ! btn || ! input || ! box ) { return; }

		function run() {
			var phone = ( input.value || '' ).trim();
			if ( ! phone ) { fail( box, S.enterNumber || 'Enter a phone number.' ); input.focus(); return; }

			btn.disabled = true;
			btn.textContent = S.checking || 'Checking…';
			box.className = 'numra-check-result is-loading';
			box.innerHTML = '';

			var body = new URLSearchParams();
			body.append( 'action', 'numra_check_phone' );
			body.append( 'nonce', cfg.checkNonce || '' );
			body.append( 'phone', phone );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) { render( box, res.data ); }
					else { fail( box, ( res && res.data && res.data.message ) || ( S.failed || 'Failed' ) ); }
				} )
				.catch( function () { fail( box, S.networkError || 'Network error. Try again.' ); } )
				.then( function () {
					btn.disabled = false;
					btn.textContent = S.checkNumber || 'Check number';
				} );
		}

		btn.addEventListener( 'click', run );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) { e.preventDefault(); run(); }
		} );
	} );
}() );
