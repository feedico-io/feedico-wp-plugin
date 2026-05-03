/**
 * Feedico Sync — front-end (copy coupon code, then open affiliate).
 */
(function () {
	'use strict';

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( err ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}

	function copyText( text ) {
		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			return navigator.clipboard.writeText( text ).then( function () {
				return true;
			} ).catch( function () {
				return fallbackCopy( text );
			} );
		}
		return Promise.resolve( fallbackCopy( text ) );
	}

	function selectionTouchesCodeBlock() {
		var sel = window.getSelection && window.getSelection();
		if ( ! sel || sel.rangeCount === 0 ) {
			return false;
		}
		var range = sel.getRangeAt( 0 );
		if ( range.collapsed ) {
			return false;
		}
		var container = range.commonAncestorContainer;
		var el = container.nodeType === 1 ? container : container.parentElement;
		return el && el.closest && el.closest( '.feedico-pub-code-value' );
	}

	document.addEventListener(
		'copy',
		function ( e ) {
			if ( selectionTouchesCodeBlock() ) {
				e.preventDefault();
				e.stopPropagation();
			}
		},
		true
	);

	document.addEventListener(
		'cut',
		function ( e ) {
			if ( selectionTouchesCodeBlock() ) {
				e.preventDefault();
				e.stopPropagation();
			}
		},
		true
	);

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.feedico-pub-copy-code' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();
		var code = btn.getAttribute( 'data-code' ) || '';
		var aff = btn.getAttribute( 'data-affiliate' ) || '';
		if ( ! code ) {
			return;
		}

		var feedicoPub = window.feedicoPub || {};
		var original = feedicoPub.copyLabel || 'Copy code';
		var copiedText = feedicoPub.copiedLabel || 'Copied!';
		var failedText = feedicoPub.copyFailed || 'Could not copy';

		function afterDelay() {
			if ( aff ) {
				window.open( aff, '_blank', 'noopener,noreferrer' );
			}
			btn.disabled = false;
			btn.textContent = original;
		}

		function startTimer() {
			btn.textContent = copiedText;
			btn.disabled = true;
			window.setTimeout( afterDelay, 2000 );
		}

		copyText( code ).then( function ( ok ) {
			if ( ! ok ) {
				btn.textContent = failedText;
				window.setTimeout( function () {
					btn.textContent = original;
				}, 2000 );
				return;
			}
			startTimer();
		} );
	} );
} )();
