/**
 * AbuseFilter editing JavaScript
 *
 * @author John Du Hart
 * @author Marius Hoch <hoo@online.de>
 */
/* global ace */

( function () {
	'use strict';

	// @var {jQuery} Filter editor for JS and jQuery handling
	let $filterBox,
		// Filter editor for Ace specific functions
		filterEditor,
		// @var {jQuery} Hidden textarea for submitting form
		$plainTextBox,
		// @var {boolean} To determine what editor to use
		useAce = false,
		// Infused OOUI elements
		toggleWarnPreviewButton, warnMessageExisting, warnMessageOther,
		toggleDisallowPreviewButton, disallowMessageExisting, disallowMessageOther;

	/**
	 * Returns the currently selected warning or disallow message.
	 *
	 * @param {string} action The action to get the message for
	 * @return {string} current warning message
	 */
	function getCurrentMessage( action ) {
		const existing = action === 'warn' ? warnMessageExisting : disallowMessageExisting,
			other = action === 'warn' ? warnMessageOther : disallowMessageOther;
		let message = existing.getValue();

		if ( message === 'other' ) {
			message = other.getValue();
		}

		return message;
	}

	/**
	 * Things always needed after syntax checks
	 *
	 * @param {string} resultText The message to show, telling if the syntax is valid
	 * @param {string} className Class to add
	 * @param {boolean} syntaxOk Is the syntax ok?
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
	 * Switch between Ace Editor and classic textarea
	 */
	function switchEditor() {
		useAce = !useAce;
		$filterBox.toggle( useAce );
		$plainTextBox.toggle( !useAce );
		if ( useAce ) {
			filterEditor.session.setValue( $plainTextBox.val() );
		}
	}

	/**
	 * Takes the data retrieved in doSyntaxCheck and processes it.
	 *
	 * @param {Object} response Data returned from the AJAX request
	 */
	function processSyntaxResult( response ) {
		const data = response.abusefilterchecksyntax;

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
				// Convert index (used in textareas) in position {row, column} for ace
				const position = filterEditor.session.getDocument()
					.indexToPosition( data.character );
				filterEditor.navigateTo( position.row, position.column );
				filterEditor.scrollToRow( position.row );
			} else {
				$plainTextBox
					.trigger( 'focus' )
					.textSelection( 'setSelection', { start: data.character } );
			}
		}
	}

	/**
	 * Acts on errors after doSyntaxCheck.
	 *
	 * @param {string} error Error code returned from the AJAX request
	 * @param {Object} details Details about the error
	 */
	function processSyntaxResultFailure( error, details ) {
		processSyntaxResultAlways(
			mw.msg(
				error === 'http' ? 'abusefilter-http-error' : 'unknown-error',
				details && details.exception
			),
			'mw-abusefilter-syntaxresult-error',
			false
		);
	}

	/**
	 * Sends the current filter text to be checked for syntax issues.
	 *
	 * @this HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function doSyntaxCheck() {
		const filter = $plainTextBox.val();

		$( this )
			.prop( 'disabled', true )
			.injectSpinner( { id: 'abusefilter-syntaxcheck', size: 'large' } );

		new mw.Api().post( {
			action: 'abusefilterchecksyntax',
			filter: filter
		} )
			.done( processSyntaxResult )
			.fail( processSyntaxResultFailure );
	}

	/**
	 * Adds text to the filter textarea.
	 * Fired by a change event from the #wpFilterBuilder dropdown
	 */
	function addText() {
		const $filterBuilder = $( '#wpFilterBuilder' );

		if ( $filterBuilder.prop( 'selectedIndex' ) === 0 ) {
			return;
		}

		if ( useAce ) {
			filterEditor.insert( $filterBuilder.val() + ' ' );
			$plainTextBox.val( filterEditor.getSession().getValue() );
			filterEditor.focus();
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
	 * @this HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function fetchFilter() {
		const filterId = $( '#mw-abusefilter-load-filter input' ).val().trim();

		if ( filterId === '' ) {
			return;
		}

		$( this ).injectSpinner( { id: 'fetch-spinner', size: 'large' } );

		// We just ignore errors or unexisting filters over here
		new mw.Api().get( {
			action: 'query',
			list: 'abusefilters',
			abfprop: 'pattern',
			abfstartid: filterId,
			abfendid: filterId,
			abflimit: 1
		} )
			.always( () => {
				$.removeSpinner( 'fetch-spinner' );
			} )
			.done( ( data ) => {
				if ( data.query.abusefilters[ 0 ] !== undefined ) {
					if ( useAce ) {
						filterEditor.setValue( data.query.abusefilters[ 0 ].pattern );
					}
					$plainTextBox.val( data.query.abusefilters[ 0 ].pattern );
				}
			} );
	}

	/**
	 * Cycles through all action checkboxes and hides parameter divs.
	 * that don't have checked boxes
	 */
	function hideDeselectedActions() {
		$( '.mw-abusefilter-action-checkbox input' ).each( ( i, element ) => {
			// mw-abusefilter-action-checkbox-{$action}
			const action = element.parentNode.id.slice( 31 );
			$( '#mw-abusefilter-' + action + '-parameters' ).toggle( element.checked );
		} );
	}

	/**
	 * Fetches the selected warning message for previewing.
	 *
	 * @param {string} action The action the message refers to
	 */
	function previewMessage( action ) {
		// The following messages are generated here:
		// * mw-abusefilter-warn-preview
		// * mw-abusefilter-disallow-preview
		const $element = $( '#mw-abusefilter-' + action + '-preview' ),
			previewButton = action === 'warn' ? toggleWarnPreviewButton : toggleDisallowPreviewButton;

		if ( $element.css( 'display' ) !== 'none' ) {
			$element.hide();
			previewButton.setFlags( { destructive: false, progressive: true } );
		} else {
			const api = new mw.Api();
			const args = [
				'<nowiki>' + $( 'input[name=wpFilterDescription]' ).val() + '</nowiki>',
				$( '#mw-abusefilter-edit-id' ).children().last().text()
			];
			const message = getCurrentMessage( action );

			api.get( {
				action: 'query',
				meta: 'allmessages',
				ammessages: message,
				amargs: args.join( '|' )
			} )
				.done( ( data ) => {
					api.parse( data.query.allmessages[ 0 ][ '*' ], {
						disablelimitreport: '',
						preview: '',
						prop: 'text',
						title: 'MediaWiki:' + message
					} )
						.done( ( html ) => {
							$element.show().html( html );
							previewButton.setFlags(
								{ destructive: true, progressive: false }
							);
						} );
				} );
		}
	}

	/**
	 * Redirects the browser to the message for editing.
	 *
	 * @param {string} action The action for which the message is used
	 */
	function editMessage( action ) {
		const message = getCurrentMessage( action ),
			defaultMsg = action === 'warn' ? 'warning' : 'disallowed',
			url = mw.util.getUrl( 'MediaWiki:' + message, {
				action: 'edit',
				preload: 'MediaWiki:abusefilter-' + defaultMsg
			} );

		window.open( url, '_blank' );
	}

	/**
	 * Called if the filter group (#mw-abusefilter-edit-group-input select) is changed. Allows
	 * using different default messages for 'warn' and 'disallow', based on the group.
	 *
	 * @this HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function onFilterGroupChange() {
		let newVal;

		if (
			!$( '#mw-abusefilter-action-checkbox-warn input' ).is( ':checked' ) &&
			$( this ).val() in mw.config.get( 'wgAbuseFilterDefaultWarningMessage' )
		) {
			const $afWarnMessageExisting = $( '#mw-abusefilter-warn-message-existing select' );
			newVal = mw.config.get( 'wgAbuseFilterDefaultWarningMessage' )[ $( this ).val() ];

			if ( $afWarnMessageExisting.find( 'option[value=\'' + newVal + '\']' ).length ) {
				$afWarnMessageExisting.val( newVal );
				warnMessageOther.setValue( '' );
			} else {
				$afWarnMessageExisting.val( 'other' );
				warnMessageOther.setValue( newVal );
			}
		}

		if (
			!$( '#mw-abusefilter-action-checkbox-disallow input' ).is( ':checked' ) &&
			$( this ).val() in mw.config.get( 'wgAbuseFilterDefaultDisallowMessage' )
		) {
			const $afDisallowMessageExisting = $( '#mw-abusefilter-disallow-message-existing select' );
			newVal = mw.config.get( 'wgAbuseFilterDefaultDisallowMessage' )[ $( this ).val() ];

			if ( $afDisallowMessageExisting.find( 'option[value=\'' + newVal + '\']' ).length ) {
				$afDisallowMessageExisting.val( newVal );
				disallowMessageOther.setValue( '' );
			} else {
				$afDisallowMessageExisting.val( 'other' );
				disallowMessageOther.setValue( newVal );
			}
		}
	}

	/**
	 * Remove the options for warning and disallow messages if the filter is set to global.
	 */
	function toggleCustomMessages() {
		// Use the table over here as hideDeselectedActions might alter the visibility of the div
		const $warnOptions = $( '#mw-abusefilter-warn-parameters > table' ),
			$disallowOptions = $( '#mw-abusefilter-disallow-parameters > table' );

		const isGlobalFilter = $( '#wpFilterGlobal' ).is( ':checked' );
		if ( isGlobalFilter ) {
			// It's a global filter, so use the default message and hide the option from the user
			warnMessageExisting.setValue( 'abusefilter-warning' );
			disallowMessageExisting.setValue( 'abusefilter-disallowed' );
		}
		$warnOptions.toggle( !isGlobalFilter );
		$disallowOptions.toggle( !isGlobalFilter );
	}

	/**
	 * Called if the user presses a key in the load filter field.
	 *
	 * @this HTMLElement
	 * @param {jQuery.Event} e The event fired when the function is called
	 */
	function onFilterKeypress( e ) {
		if ( e.type === 'keypress' && e.which === OO.ui.Keys.ENTER ) {
			e.preventDefault();
			$( '#mw-abusefilter-load' ).trigger( 'click' );
		}
	}

	/**
	 * Warn if the user changed anything and tries to leave the window
	 */
	function setWarnOnLeave() {
		const $form = $( '#mw-abusefilter-editing-form' ),
			origValues = $form.serialize();

		const warnOnLeave = mw.confirmCloseWindow( {
			test: () => $form.serialize() !== origValues,
			message: mw.msg( 'abusefilter-edit-warn-leave' )
		} );

		$form.on( 'submit', () => {
			warnOnLeave.release();
		} );
	}

	/**
	 * Builds a TagMultiselectWidget, to be used both for throttle groups and change tags
	 *
	 * @param {string} action Either 'throttle' or 'tag', will be used to build element IDs
	 * @param {Array} config The array with configuration passed from PHP code
	 */
	function buildSelector( action, config ) {
		// mw-abusefilter-throttle-parameters, mw-abusefilter-tag-parameters
		const $container = $( '#mw-abusefilter-' + action + '-parameters' ),
			// Character used to separate elements in the textarea.
			separator = action === 'throttle' ? '\n' : ',';

		const selector = new OO.ui.TagMultiselectWidget( {
			inputPosition: 'outline',
			allowArbitrary: true,
			allowEditTags: true,

			selected: config.values,
			// The following messages are used here:
			// * abusefilter-edit-throttle-placeholder
			// * abusefilter-edit-tag-placeholder
			placeholder: OO.ui.msg( 'abusefilter-edit-' + action + '-placeholder' ),
			disabled: config.disabled
		} );

		const fieldOpts = {
			label: $( $.parseHTML( config.label ) ),
			align: 'top'
		};
		if ( action === 'throttle' ) {
			fieldOpts.help = new OO.ui.HtmlSnippet( config.help );
		}

		const field = new OO.ui.FieldLayout( selector, fieldOpts );

		// mw-abusefilter-hidden-throttle-field, mw-abusefilter-hidden-tag-field
		const hiddenField = OO.ui.infuse( $( '#mw-abusefilter-hidden-' + action + '-field' ) );
		selector.on( 'change', () => {
			hiddenField.setValue( selector.getValue().join( separator ) );
		} );

		// mw-abusefilter-hidden-throttle, mw-abusefilter-hidden-tag
		$( '#mw-abusefilter-hidden-' + action ).hide();
		$container.append( field.$element );
	}

	// On ready initialization
	$( () => {
		const $exportBox = $( '#mw-abusefilter-export' ),
			isFilterEditor = mw.config.get( 'isFilterEditor' ),
			tagConfig = mw.config.get( 'tagConfig' ),
			throttleConfig = mw.config.get( 'throttleConfig' ),
			$switchEditorBtn = $( '#mw-abusefilter-switcheditor' );

		if ( isFilterEditor ) {
			// Configure the actual editing interface
			if ( tagConfig ) {
				// Build the tag selector
				buildSelector( 'tag', tagConfig );
			}
			if ( throttleConfig ) {
				// Build the throttle groups selector
				buildSelector( 'throttle', throttleConfig );
			}

			toggleWarnPreviewButton = OO.ui.infuse( $( '#mw-abusefilter-warn-preview-button' ) );
			warnMessageExisting = OO.ui.infuse( $( '#mw-abusefilter-warn-message-existing' ) );
			warnMessageOther = OO.ui.infuse( $( '#mw-abusefilter-warn-message-other' ) );
			toggleDisallowPreviewButton = OO.ui.infuse( $( '#mw-abusefilter-disallow-preview-button' ) );
			disallowMessageExisting = OO.ui.infuse( $( '#mw-abusefilter-disallow-message-existing' ) );
			disallowMessageOther = OO.ui.infuse( $( '#mw-abusefilter-disallow-message-other' ) );
			setWarnOnLeave();
		}

		$plainTextBox = $( '#wpFilterRules' );

		if ( $( '#wpAceFilterEditor' ).length ) {
			// CodeEditor is installed.
			mw.loader.using( [ 'ext.abuseFilter.ace' ] ).then( () => {
				$filterBox = $( '#wpAceFilterEditor' );

				filterEditor = ace.edit( 'wpAceFilterEditor' );
				filterEditor.session.setMode( 'ace/mode/abusefilter' );

				// Ace setup from codeEditor extension
				let basePath = mw.config.get( 'wgExtensionAssetsPath', '' );
				if ( basePath.slice( 0, 2 ) === '//' ) {
					// ACE uses web workers, which have importScripts, which don't like
					// relative links. This is a problem only when the assets are on another
					// server, so this rewrite should suffice.
					basePath = window.location.protocol + basePath;
				}
				ace.config.set( 'basePath', basePath + '/CodeEditor/modules/lib/ace' );

				// Settings for Ace editor box
				const readOnly = mw.config.get( 'aceConfig' ).aceReadOnly;

				// Get theme of user and set it for the editor
				// Copied from CodeEditor extension commit 8b7b17f9ee357d2c789909f452a57fa7b4237384
				const htmlClasses = document.documentElement.classList;
				const inDarkMode = htmlClasses.contains( 'skin-theme-clientpref-night' ) || (
					htmlClasses.contains( 'skin-theme-clientpref-os' ) &&
					window.matchMedia && window.matchMedia( '( prefers-color-scheme: dark )' ).matches
				);

				filterEditor.setTheme( inDarkMode ? 'ace/theme/monokai' : 'ace/theme/textmate' );
				filterEditor.setReadOnly( readOnly );
				filterEditor.$blockScrolling = Infinity;

				// Display Ace editor
				switchEditor();

				// Hide the syntax ok message when the text changes and sync dummy box
				filterEditor.on( 'change', () => {
					const $el = $( '#mw-abusefilter-syntaxresult' );

					if ( $el.data( 'syntaxOk' ) ) {
						$el.hide();
					}

					$plainTextBox.val( filterEditor.getSession().getValue() );
				} );

				// Make Ace editor resizable
				// (uses ResizeObserver, which is not implemented in IE 11)
				if ( typeof ResizeObserver !== 'undefined' ) {
					// Make the container resizable
					$filterBox.css( 'resize', 'both' );
					// Refresh Ace editor size (notably its scrollbars) when the container
					// is resized, otherwise it would be refreshed only on window resize
					new ResizeObserver( () => {
						filterEditor.resize();
					} ).observe( $filterBox[ 0 ] );
				}

				$switchEditorBtn.on( 'click', switchEditor ).show();
			} );
		}

		// Hide the syntax ok message when the text changes
		$plainTextBox.on( 'change', () => {
			const $el = $( '#mw-abusefilter-syntaxresult' );

			if ( $el.data( 'syntaxOk' ) ) {
				$el.hide();
			}
		} );

		$( '#mw-abusefilter-load' ).on( 'click', fetchFilter );
		$( '#mw-abusefilter-load-filter' ).on( 'keypress', onFilterKeypress );

		if ( isFilterEditor ) {
			// Add logic for flags and consequences
			$( '#mw-abusefilter-warn-preview-button' ).on( 'click',
				() => {
					previewMessage( 'warn' );
				}
			);
			$( '#mw-abusefilter-disallow-preview-button' ).on( 'click',
				() => {
					previewMessage( 'disallow' );
				}
			);
			$( '#mw-abusefilter-warn-edit-button' ).on( 'click',
				() => {
					editMessage( 'warn' );
				}
			);
			$( '#mw-abusefilter-disallow-edit-button' ).on( 'click',
				() => {
					editMessage( 'disallow' );
				}
			);
			$( '.mw-abusefilter-action-checkbox input' ).on( 'click', hideDeselectedActions );
			hideDeselectedActions();

			$( '#wpFilterGlobal' ).on( 'change', toggleCustomMessages );
			toggleCustomMessages();

			const cbEnabled = OO.ui.infuse( $( '#wpFilterEnabled' ) );
			const cbDeleted = OO.ui.infuse( $( '#wpFilterDeleted' ) );

			cbEnabled.on( 'change',
				() => {
					cbDeleted.setDisabled( cbEnabled.isSelected() );
					if ( cbEnabled.isSelected() ) {
						cbDeleted.setSelected( false );
					}
				}
			);

			cbDeleted.on( 'change',
				() => {
					if ( cbDeleted.isSelected() ) {
						cbEnabled.setSelected( false );
					}
				}
			);

			$( '#mw-abusefilter-edit-group-input select' ).on( 'change', onFilterGroupChange );

			$( '#mw-abusefilter-export-link' ).on( 'click',
				( e ) => {
					e.preventDefault();
					$exportBox.toggle();
				}
			);
		}

		$( '#mw-abusefilter-syntaxcheck' ).on( 'click', doSyntaxCheck );
		$( '#wpFilterBuilder' ).on( 'change', addText );
	} );
}() );
