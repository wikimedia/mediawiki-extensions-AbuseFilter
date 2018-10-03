/**
 * JavaScript for AbuseFilter tools
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */

( function ( mw, $, OO ) {
	'use strict';

	/**
	 * Submits the expression to be evaluated.
	 * @context HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function doExprSubmit() {
		var expr = $( '#wpTestExpr' ).val(),
			api = new mw.Api();
		$( this ).injectSpinner( { id: 'abusefilter-expr', size: 'large' } );

		api.post( {
			action: 'abusefilterevalexpression',
			expression: expr
		} )
			.fail( function ( error, details ) {
				var msg = error === 'http' ? 'abusefilter-http-error' : 'unknown-error';
				$.removeSpinner( 'abusefilter-expr' );
				$( '#mw-abusefilter-expr-result' )
					.text( mw.msg( msg, details.exception ) );
			} )
			.done( function ( data ) {
				$.removeSpinner( 'abusefilter-expr' );

				$( '#mw-abusefilter-expr-result' )
					.text( data.abusefilterevalexpression.result );
			} );
	}

	/**
	 * Processes the result of the unblocking autopromotions for a user
	 *
	 * @param {Object} data The response of the API request
	 */
	function processReautoconfirm( data ) {
		mw.notify(
			mw.message( 'abusefilter-reautoconfirm-done', data.abusefilterunblockautopromote.user ).toString()
		);

		$.removeSpinner( 'abusefilter-reautoconfirm' );
	}

	/**
	 * Processes the result of the unblocking autopromotions for a user in case of an error
	 *
	 * @param {string} errorCode Identifier of the error
	 * @param {Object} data The response of the API request
	 */
	function processReautoconfirmFailure( errorCode, data ) {
		var msg;

		switch ( errorCode ) {
			case 'permissiondenied':
				msg = mw.msg( 'abusefilter-reautoconfirm-notallowed' );
				break;
			case 'http':
				msg = mw.msg( 'abusefilter-http-error', data && data.exception );
				break;
			case 'notsuspended':
				msg = data.error.info;
				break;
			default:
				msg = mw.msg( 'unknown-error' );
				break;
		}
		mw.notify( msg );

		$.removeSpinner( 'abusefilter-reautoconfirm' );
	}

	/**
	 * Submits a call to reautoconfirm a user.
	 * @context HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 * @return {boolean} False to prevent form submission
	 */
	function doReautoSubmit() {
		var nameField = OO.ui.infuse( $( '#reautoconfirm-user' ) ),
			name = nameField.getValue(),
			api;

		if ( name === '' ) {
			return false;
		}

		$( this ).injectSpinner( { id: 'abusefilter-reautoconfirm', size: 'large' } );

		api = new mw.Api();
		api.post( {
			action: 'abusefilterunblockautopromote',
			user: name,
			token: mw.user.tokens.get( 'editToken' )
		} )
			.done( processReautoconfirm )
			.fail( processReautoconfirmFailure );
		return false;
	}

	$( document ).ready( function () {
		$( '#mw-abusefilter-submitexpr' ).click( doExprSubmit );
		$( '#mw-abusefilter-reautoconfirmsubmit' ).click( doReautoSubmit );
	} );
}( mediaWiki, jQuery, OO ) );
