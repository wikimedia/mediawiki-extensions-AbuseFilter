/**
 * Check a filter against a change
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */

( function( mw, $ ) {
	'use strict';

	// Syntax result div
	// @type {jQuery}
	var $syntaxResult;

	/**
	 * Tests the filter against an rc event or abuse log entry.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function examinerTestFilter() {
		/*jshint validthis:true */
		var filter = $( '#wpTestFilter' ).val(),
			examine = mw.config.get( 'abuseFilterExamine' ),
			params = {
				action: 'abusefiltercheckmatch',
				filter: filter
			},
			api = new mw.Api();

		$( this ).injectSpinner( 'filter-check' );

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

	/**
	 * Processes the results of the filter test
	 *
	 * @param {Object} data
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
	 */
	function examinerTestProcessFailure( error ) {
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
			// The 'abusefilter-modify' right is needed to use this API
			msg = 'abusefilter-mustbeeditor';
		} else {
			msg = 'unknown-error';
		}

		$syntaxResult
			.text( mw.msg( msg ) )
			.show();
	}

	$( document ).ready( function() {
		$syntaxResult = $( '#mw-abusefilter-syntaxresult' );
		$( '#mw-abusefilter-examine-test' ).click( examinerTestFilter );
	} );
} ( mediaWiki, jQuery ) );
