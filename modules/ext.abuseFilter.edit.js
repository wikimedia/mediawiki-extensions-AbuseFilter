/**
 * AbuseFilter editing JavaScript
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */
/* global ace */

( function ( mw, $ ) {
	'use strict';

	// Filter editor for JS and jQuery handling
	// @var {jQuery}
	var $filterBox,
		// Filter editor for Ace specific functions
		filterEditor,
		// Hidden textarea for submitting form
		// @var {jQuery}
		$plainTextBox,
		// Bool to determine what editor to use
		useAce = false;

	/**
	 * Returns the currently selected warning message
	 *
	 * @return {string} current warning message
	 */
	function getCurrentWarningMessage() {
		var message = $( '#mw-abusefilter-warn-message-existing' ).val();

		if ( message === 'other' ) {
			message = $( '#mw-abusefilter-warn-message-other' ).val();
		}

		return message;
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
		$( '#mw-abusefilter-syntaxcheck' ).prop( 'disabled', false );

		$( '#mw-abusefilter-syntaxresult' )
			.show()
			.attr( 'class', className )
			.text( resultText )
			.data( 'syntaxOk', syntaxOk );
	}

	/**
	 * Converts index (used in textareas) in position {row, column} for ace
	 *
	 * @author danyaPostfactum (https://github.com/ajaxorg/ace/issues/1162)
	 *
	 * @param {string} index Part of data returned from the AJAX request
	 * @return {Object} row and column
	 */
	function indexToPosition( index ) {
		var lines = filterEditor.session.getDocument().$lines,
			newLineChar = filterEditor.session.doc.getNewLineCharacter(),
			currentIndex = 0,
			row, length;
		for ( row = 0; row < lines.length; row++ ) {
			length = filterEditor.session.getLine( row ).length;
			if ( currentIndex + length >= index ) {
				return {
					row: row,
					column: index - currentIndex
				};
			}
			currentIndex += length + newLineChar.length;
		}
	}

	/**
	 * Switch between Ace Editor and classic textarea
	 */
	function switchEditor() {
		if ( useAce ) {
			useAce = false;
			$filterBox.hide();
			$plainTextBox.show();
		} else {
			useAce = true;
			filterEditor.session.setValue( $plainTextBox.val() );
			$filterBox.show();
			$plainTextBox.hide();
		}
	}

	/**
	 * Takes the data retrieved in doSyntaxCheck and processes it
	 *
	 * @param {Object} data Data returned from the AJAX request
	 */
	function processSyntaxResult( data ) {
		var position;
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

			if ( useAce ) {
				filterEditor.focus();
				position = indexToPosition( data.character );
				filterEditor.navigateTo( position.row, position.column );
				filterEditor.scrollToRow( position.row );
			} else {
				$plainTextBox
					.focus()
					.textSelection( 'setSelection', { start: data.character } );
			}
		}
	}

	/**
	 * Acts on errors after doSyntaxCheck
	 *
	 * @param {string} error Error code returned from the AJAX request
	 * @param {Object} details Details about the error
	 */
	function processSyntaxResultFailure( error, details ) {
		var msg = error === 'http' ? 'abusefilter-http-error' : 'unknown-error';
		processSyntaxResultAlways(
			mw.msg( msg, details && details.exception ),
			'mw-abusefilter-syntaxresult-error',
			false
		);
	}

	/**
	 * Sends the current filter text to be checked for syntax issues.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function doSyntaxCheck() {
		var filter = $plainTextBox.val(),
			api = new mw.Api();

		$( this )
			.prop( 'disabled', true )
			.injectSpinner( { id: 'abusefilter-syntaxcheck', size: 'large' } );

		api.post( {
			action: 'abusefilterchecksyntax',
			filter: filter
		} )
			.done( processSyntaxResult )
			.fail( processSyntaxResultFailure );
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

		if ( useAce ) {
			filterEditor.insert( $filterBuilder.val() + ' ' );
			$plainTextBox.val( filterEditor.getSession().getValue() );
		} else {
			$plainTextBox.textSelection(
				'encapsulateSelection', { pre: $filterBuilder.val() + ' ' }
			);
		}
		$filterBuilder.prop( 'selectedIndex', 0 );
	}

	/**
	 * Fetches a filter from the API and inserts it into the filter box.
	 *
	 * @context HTMLElement
	 * @param {jQuery.Event} e
	 */
	function fetchFilter() {
		var filterId = $.trim( $( '#mw-abusefilter-load-filter input' ).val() ),
			api;

		if ( filterId === '' ) {
			return;
		}

		$( this ).injectSpinner( { id: 'fetch-spinner', size: 'large' } );

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
			.always( function () {
				$.removeSpinner( 'fetch-spinner' );
			} )
			.done( function ( data ) {
				if ( data.query.abusefilters[ 0 ] !== undefined ) {
					if ( useAce ) {
						filterEditor.setValue( data.query.abusefilters[ 0 ].pattern );
					}
					$plainTextBox.val( data.query.abusefilters[ 0 ].pattern );
				}
			} );
	}

	/**
	 * Cycles through all action checkboxes and hides parameter divs
	 * that don't have checked boxes
	 */
	function hideDeselectedActions() {
		$( 'input.mw-abusefilter-action-checkbox' ).each( function () {
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
		var api = new mw.Api(),
			args = [
				'<nowiki>' + $( 'input[name=wpFilterDescription]' ).val() + '</nowiki>',
				$( '#mw-abusefilter-edit-id' ).children().last().text()
			],
			message = getCurrentWarningMessage();
		api.get( {
			action: 'query',
			meta: 'allmessages',
			ammessages: message,
			amargs: args.join( '|' )
		} )
			.done( function ( data ) {
				api.parse( data.query.allmessages[ 0 ][ '*' ], {
					disablelimitreport: '',
					preview: '',
					prop: 'text',
					title: 'MediaWiki:' + message
				} )
					.done( function ( html ) {
						$( '#mw-abusefilter-warn-preview' ).html( html );
					} );
			} );
	}

	/**
	 * Redirects the browser to the warning message for editing
	 */
	function editWarnMessage() {
		var message = getCurrentWarningMessage();

		window.location = mw.config.get( 'wgScript' ) +
			'?title=MediaWiki:' + mw.util.wikiUrlencode( message ) +
			'&action=edit&preload=MediaWiki:abusefilter-warning';
	}

	/**
	 * Called if the filter group (#mw-abusefilter-edit-group-input) is changed.
	 *
	 * @context HTMLELement
	 * @param {jQuery.Event} e
	 */
	function onFilterGroupChange() {
		var $afWarnMessageExisting, $afWarnMessageOther, newVal;

		if ( !$( '#mw-abusefilter-action-warn-checkbox' ).is( ':checked' ) ) {
			$afWarnMessageExisting = $( '#mw-abusefilter-warn-message-existing' );
			$afWarnMessageOther = $( '#mw-abusefilter-warn-message-other' );
			newVal = mw.config.get( 'wgAbuseFilterDefaultWarningMessage' )[ $( this ).val() ];

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
	$( document ).ready( function () {
		var basePath, readOnly,
			$exportBox = $( '#mw-abusefilter-export' );

		$plainTextBox = $( '#' + mw.config.get( 'abuseFilterBoxName' ) );

		if ( $( '#wpAceFilterEditor' ).length ) {
			// CodeEditor is installed.
			mw.loader.using( [ 'ext.abuseFilter.ace' ] ).then( function () {
				$filterBox = $( '#wpAceFilterEditor' );

				filterEditor = ace.edit( 'wpAceFilterEditor' );
				filterEditor.session.setMode( 'ace/mode/abusefilter' );

				// Ace setup from codeEditor extension
				basePath = mw.config.get( 'wgExtensionAssetsPath', '' );
				if ( basePath.slice( 0, 2 ) === '//' ) {
					// ACE uses web workers, which have importScripts, which don't like relative links.
					// This is a problem only when the assets are on another server, so this rewrite should suffice
					// Protocol relative
					basePath = window.location.protocol + basePath;
				}
				ace.config.set( 'basePath', basePath + '/CodeEditor/modules/ace' );

				// Settings for Ace editor box
				readOnly = mw.config.get( 'aceConfig' ).aceReadOnly;

				filterEditor.setTheme( 'ace/theme/textmate' );
				filterEditor.session.setOption( 'useWorker', false );
				filterEditor.setReadOnly( readOnly );
				filterEditor.$blockScrolling = Infinity;

				// Display Ace editor
				switchEditor();

				// Hide the syntax ok message when the text changes and sync dummy box
				$filterBox.keyup( function () {
					var $el = $( '#mw-abusefilter-syntaxresult' );

					if ( $el.data( 'syntaxOk' ) ) {
						$el.hide();
					}

					$plainTextBox.val( filterEditor.getSession().getValue() );
				} );

				$( '#mw-abusefilter-switcheditor' ).click( switchEditor );
			} );
		}

		// Hide the syntax ok message when the text changes
		$plainTextBox.keyup( function () {
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

		$( '#mw-abusefilter-export-link' ).click(
			function ( e ) {
				e.preventDefault();
				$exportBox.toggle();
			}
		);
	} );
}( mediaWiki, jQuery ) );
