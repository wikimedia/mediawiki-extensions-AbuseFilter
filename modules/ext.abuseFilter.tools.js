/**
 * JavaScript for AbuseFilter tools
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */

( function( mw, $ ) {
	'use strict';

	/**
	 * Submits the expression to be evaluated.
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function doExprSubmit() {
		/*jshint validthis:true */
		var expr = $( '#wpTestExpr' ).val(),
			api = new mw.Api();
		$( this ).injectSpinner( 'abusefilter-expr' );

		api.get( {
			action: 'abusefilterevalexpression',
			expression: expr
		} )
		.fail( function() {
			$.removeSpinner( 'abusefilter-expr' );

			$( '#mw-abusefilter-expr-result' )
				.text( mw.msg( 'unknown-error' ) );
		} )
		.done( function( data ) {
			$.removeSpinner( 'abusefilter-expr' );

			$( '#mw-abusefilter-expr-result' )
				.text( data.abusefilterevalexpression.result );
		} );
	}

	/**
	 * Submits a call to reautoconfirm a user.
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function doReautoSubmit() {
		/*jshint validthis:true */
		var name = $( '#reautoconfirm-user' ).val(),
			api;

		if ( name === '' ) {
			return;
		}

		$( this ).injectSpinner( 'abusefilter-reautoconfirm' );

		api = new mw.Api();
		api.post( {
			action: 'abusefilterunblockautopromote',
			user: name,
			token: mw.user.tokens.get( 'editToken' )
		} )
		.done( processReautoconfirm )
		.fail( processReautoconfirmFailure );
	}

	/**
	 * Processes the result of the unblocking autopromotions for a user
	 *
	 * @param {Object} data
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
	 * @param {string} errorCode
	 * @param {Object} data
	 */
	function processReautoconfirmFailure( errorCode, data ) {
		var msg;

		switch ( errorCode ) {
			case 'permissiondenied':
				msg = mw.msg( 'abusefilter-reautoconfirm-notallowed' );
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

	$( document ).ready( function() {
		$( '#mw-abusefilter-submitexpr' ).click( doExprSubmit );
		$( '#mw-abusefilter-reautoconfirmsubmit' ).click( doReautoSubmit );
	} );
} ( mediaWiki, jQuery ) );
