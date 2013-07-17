/**
 * AbuseFilter editing JavaScript
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */

( function( mw, $ ) {
	'use strict';

	// Filter textarea
	// @var {jQuery}
	var $filterBox;

	/**
	 * Returns the currently selected warning message
	 *
	 * @returns {string} current warning message
	 */
	function getCurrentWarningMessage() {
		var message = $( '#mw-abusefilter-warn-message-existing' ).val();

		if ( message === 'other' ) {
			message = $( '#mw-abusefilter-warn-message-other' ).val();
		}

		return message;
	}

	/**
	 * Sends the current filter text to be checked for syntax issues.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function doSyntaxCheck() {
		/*jshint validthis:true */
		var filter = $filterBox.val(),
			api = new mw.Api();

		$( this )
			.attr( 'disabled', 'disabled' )
			.injectSpinner( 'abusefilter-syntaxcheck' );

		api.post( {
			action: 'abusefilterchecksyntax',
			filter: filter
		} )
		.done( processSyntaxResult )
		.fail( processSyntaxResultFailure );
	}

	/**
	 * Things always needed after syntax checks
	 *
	 * @param {string} resultText
	 * @param {string} className Class to add
	 * @param {bool} syntaxOk Is the syntax ok?
	 */
	function processSyntaxResultAlways( resultText, className, syntaxOk ) {
		$.removeSpinner( 'abusefilter-syntaxcheck' );
		$( '#mw-abusefilter-syntaxcheck' ).removeAttr( 'disabled' );

		$( '#mw-abusefilter-syntaxresult' )
			.show()
			.removeClass( 'mw-abusefilter-syntaxresult-ok mw-abusefilter-syntaxresult-error' )
			.text( resultText )
			.addClass( className )
			.data( 'syntaxOk', syntaxOk );
	}

	/**
	 * Takes the data retrieved in doSyntaxCheck and processes it
	 *
	 * @param {Object} data Data returned from the AJAX request
	 */
	function processSyntaxResult( data ) {
		data = data.abusefilterchecksyntax;

		if ( data.status === 'ok' ) {
			// Successful
			processSyntaxResultAlways(
				mw.msg( 'abusefilter-edit-syntaxok' ),
				'mw-abusefilter-syntaxresult-ok',
				true
			);
		} else {
			// Set a custom error message as we're aware of the actual problem
			processSyntaxResultAlways(
				mw.message( 'abusefilter-edit-syntaxerr', data.message ).toString(),
				'mw-abusefilter-syntaxresult-error',
				false
			);

			$filterBox
				.focus()
				.textSelection( 'setSelection', { start: data.character } );
		}
	}

	/**
	 * Acts on errors after doSyntaxCheck
	 */
	function processSyntaxResultFailure() {
		processSyntaxResultAlways(
			mw.msg( 'unknown-error' ),
			'mw-abusefilter-syntaxresult-error',
			false
		);
	}

	/**
	 * Adds text to the filter textarea
	 * Fired by a change event from the #wpFilterBuilder dropdown
	 */
	function addText() {
		var $filterBuilder = $( '#wpFilterBuilder' );

		if ( $filterBuilder.prop( 'selectedIndex' ) === 0 ) {
			return;
		}

		$filterBox.textSelection(
			'encapsulateSelection', { 'pre': $filterBuilder.val() + ' ' }
		);
		$filterBuilder.prop( 'selectedIndex', 0 );
	}

	/**
	 * Fetches a filter from the API and inserts it into the filter box.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function fetchFilter() {
		/*jshint validthis:true */
		var filterId = $.trim( $( '#mw-abusefilter-load-filter' ).val() ),
			api;

		if ( filterId === '' ) {
			return;
		}

		$( this ).injectSpinner( 'fetch-spinner' );

		// We just ignore errors or unexisting filters over here
		api = new mw.Api();
		api.get( {
			action: 'query',
			list: 'abusefilters',
			abfprop: 'pattern',
			abfstartid: filterId,
			abfendid: filterId,
			abflimit: 1
		} )
		.always( function() {
			$.removeSpinner( 'fetch-spinner' );
		} )
		.done( function( data ) {
			if ( data.query.abusefilters[0] !== undefined ) {
				$filterBox.val( data.query.abusefilters[0].pattern );
			}
		} );
	}

	/**
	 * Cycles through all action checkboxes and hides parameter divs
	 * that don't have checked boxes
	 */
	function hideDeselectedActions() {
		$( 'input.mw-abusefilter-action-checkbox' ).each( function() {
			// mw-abusefilter-action-checkbox-{$action}
			var action = this.id.substr( 31 ),
				$params = $( '#mw-abusefilter-' + action + '-parameters' );

			if ( $params.length ) {
				if ( this.checked ) {
					$params.show();
				} else {
					$params.hide();
				}
			}
		} );
	}

	/**
	* Fetches the selected warning message for previewing
	*/
	function previewWarnMessage() {
		$.get(
			mw.config.get( 'wgScript' ), {
				title: 'MediaWiki:' + getCurrentWarningMessage(),
				action: 'render'
			}
		)
		.done( function( messageHtml ) {
			// Replace $1 with the description of the filter
			messageHtml = messageHtml.replace(
				/\$1/g,
				mw.html.escape( $( 'input[name=wpFilterDescription]' ).val() )
			);

			$( '#mw-abusefilter-warn-preview' ).html( messageHtml );
		} );
	}

	/**
	 * Redirects the browser to the warning message for editing
	 */
	function editWarnMessage() {
		var message = getCurrentWarningMessage();

		window.location = mw.config.get( 'wgScript' ) + '?title=MediaWiki:' +  mw.util.wikiUrlencode( message ) + '&action=edit';
	}

	/**
	 * Called if the filter group (#mw-abusefilter-edit-group-input) is changed.
	 *
	 * @context HTMLELement
	 * @param {jQuery.Event} e
	 */
	function onFilterGroupChange() {
		/*jshint validthis:true */
		var $afWarnMessageExisting, $afWarnMessageOther, newVal;

		if ( !$( '#mw-abusefilter-action-warn-checkbox' ).is( ':checked' ) ) {
			$afWarnMessageExisting = $( '#mw-abusefilter-warn-message-existing' );
			$afWarnMessageOther = $( '#mw-abusefilter-warn-message-other' );
			newVal = mw.config.get( 'wgAbuseFilterDefaultWarningMessage' )[$( this ).val()];

			if ( $afWarnMessageExisting.find( 'option[value=\'' + newVal + '\']' ).length ) {
				$afWarnMessageExisting.val( newVal );
				$afWarnMessageOther.val( '' );
			} else {
				$afWarnMessageExisting.val( 'other' );
				$afWarnMessageOther.val( newVal );
			}
		}
	}

	/**
	 * Remove the options for warning messages if the filter is set to global
	 */
	function toggleCustomMessages() {
		// Use the table over here as hideDeselectedActions might alter the visibility of the div
		var $warnOptions = $( '#mw-abusefilter-warn-parameters > table' );

		if ( $( '#wpFilterGlobal' ).is( ':checked' ) ) {
			// It's a global filter, so use the default message and hide the option from the user
			$( '#mw-abusefilter-warn-message-existing option[value="abusefilter-warning"]' )
				.prop( 'selected', true );

			$warnOptions.hide();
		} else {
			$warnOptions.show();
		}
	}

	/**
	 * Called if the user presses a key in the load filter field
	 *
	 * @context HTMLELement
	 * @param {jQuery.Event} e
	 */
	function onFilterKeypress( e ) {
		if ( e.type === 'keypress' && e.which === 13 ) {
			e.preventDefault();
			$( '#mw-abusefilter-load' ).click();
		}
	}

	// On ready initialization
	$( document ).ready( function() {
		var $exportBox = $( '#mw-abusefilter-export' );
		$filterBox = $( '#' + mw.config.get( 'abuseFilterBoxName' ) );
		// Hide the syntax ok message when the text changes
		$filterBox.keyup( function() {
			var $el = $( '#mw-abusefilter-syntaxresult' );

			if ( $el.data( 'syntaxOk' ) ) {
				$el.hide();
			}
		} );

		$( '#mw-abusefilter-load' ).click( fetchFilter );
		$( '#mw-abusefilter-load-filter' ).keypress( onFilterKeypress );
		$( '#mw-abusefilter-warn-preview-button' ).click( previewWarnMessage );
		$( '#mw-abusefilter-warn-edit-button' ).click( editWarnMessage );
		$( 'input.mw-abusefilter-action-checkbox' ).click( hideDeselectedActions );
		hideDeselectedActions();

		$( '#wpFilterGlobal' ).change( toggleCustomMessages );
		toggleCustomMessages();

		$( '#mw-abusefilter-syntaxcheck' ).click( doSyntaxCheck );
		$( '#wpFilterBuilder' ).change( addText );
		$( '#mw-abusefilter-edit-group-input' ).change( onFilterGroupChange );

		$( '#mw-abusefilter-export-link' ).toggle(
			function() {
				$exportBox.show();
			}, function() {
				$exportBox.hide();
			}
		);
	} );
} ( mediaWiki, jQuery ) );
