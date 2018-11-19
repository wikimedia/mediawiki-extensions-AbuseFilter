/**
 * Check a filter against a change
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */

( function ( mw, $ ) {
	'use strict';

	// @var {jQuery} Syntax result div
	var $syntaxResult;

	/**
	 * Processes the results of the filter test
	 *
	 * @param {Object} data The response of the API request
	 */
	function examinerTestProcess( data ) {
		var msg, exClass;
		$.removeSpinner( 'filter-check' );

		if ( data.abusefiltercheckmatch.result ) {
			exClass = 'mw-abusefilter-examine-match';
			msg = 'abusefilter-examine-match';
		} else {
			exClass = 'mw-abusefilter-examine-nomatch';
			msg = 'abusefilter-examine-nomatch';
		}
		$syntaxResult
			.attr( 'class', exClass )
			.text( mw.msg( msg ) )
			.show();
	}

	/**
	 * Processes the results of the filter test in case of an error
	 *
	 * @param {string} error Error code returned from the AJAX request
	 * @param {Object} details Details about the error
	 */
	function examinerTestProcessFailure( error, details ) {
		var msg;
		$.removeSpinner( 'filter-check' );

		if ( error === 'badsyntax' ) {
			$syntaxResult.attr(
				'class', 'mw-abusefilter-syntaxresult-error'
			);
			msg = 'abusefilter-examine-syntaxerror';
		} else if ( error === 'nosuchrcid' || error === 'nosuchlogid' ) {
			msg = 'abusefilter-examine-notfound';
		} else if ( error === 'permissiondenied' ) {
			// The 'abusefilter-modify' or 'abusefilter-view-private' right is needed
			// to use this API
			msg = 'abusefilter-mustviewprivateoredit';
		} else if ( error === 'http' ) {
			msg = 'abusefilter-http-error';
		} else {
			msg = 'unknown-error';
		}

		$syntaxResult
			.text( mw.msg( msg, details && details.exception ) )
			.show();
	}

	/**
	 * Tests the filter against an rc event or abuse log entry.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function examinerTestFilter() {
		var filter = $( '#wpTestFilter' ).val(),
			examine = mw.config.get( 'abuseFilterExamine' ),
			params = {
				action: 'abusefiltercheckmatch',
				filter: filter
			},
			api = new mw.Api();

		$( this ).injectSpinner( { id: 'filter-check', size: 'large' } );

		if ( examine.type === 'rc' ) {
			params.rcid = examine.id;
		} else {
			params.logid = examine.id;
		}

		// Use post due to the rather large amount of data
		api.post( params )
			.done( examinerTestProcess )
			.fail( examinerTestProcessFailure );
	}

	$( document ).ready( function initialize() {
		$syntaxResult = $( '#mw-abusefilter-syntaxresult' );
		$( '#mw-abusefilter-examine-test' ).click( examinerTestFilter );
	} );
}( mediaWiki, jQuery ) );
