/**
 * AbuseFilter editing stuff
 */
new ( function( $, mw ) {
	/**
	 * Filter textarea
	 *
	 * @var {jQuery}
	 */
	var $filterBox = $( '#' + mw.config.get( 'abuseFilterBoxName' ) );

	/**
	 * Reference to this
	 *
	 * @var {this}
	 */
	var that = this;

	/**
	 * Returns the currently selected warning message
	 *
	 * @returns {String} current warning message
	 */
	var getCurrentWarningMessage = function() {
		var message = $('#mw-abusefilter-warn-message-existing').val();

		if ( message == 'other' ) {
			message = $('#mw-abusefilter-warn-message-other').val();
		}

		return message;
	};

	/**
	 * Sends the current filter text to be checked for syntax issues
	 */
	this.doSyntaxCheck = function() {
		var filter = $filterBox.val();
		$( this ).injectSpinner( 'abusefilter-syntaxcheck' );
		this.disabled = true;
		$.getJSON(
			mw.util.wikiScript( 'api' ), {
				action: 'checkfiltersyntax',
				filter: filter,
				format: 'json'
			}, that.processSyntaxResult
		);
	};

	/**
	 * Takes the data retrieved in doSyntaxCheck and processes it
	 *
	 * @param {Object} data Data returned from the AJAX request
	 */
	this.processSyntaxResult = function( data ) {
		data = data.checkfiltersyntax;
		$.removeSpinner( 'abusefilter-syntaxcheck' );
		$( '#mw-abusefilter-syntaxcheck' ).removeAttr("disabled");

		var $el = $( '#mw-abusefilter-syntaxresult' )
			.show()
			.removeClass('mw-abusefilter-syntaxresult-ok mw-abusefilter-syntaxresult-error');

		if ( data.status == 'ok' ) {
			// Successful
			$el.text( mw.msg( 'abusefilter-edit-syntaxok' ) )
				.attr( 'class', 'mw-abusefilter-syntaxresult-ok' )
				.data( 'syntaxOk', true );
		} else {
			var msg = mw.message( 'abusefilter-edit-syntaxerr', data.message ).toString();
			$el.text( msg )
				.attr( 'class', 'mw-abusefilter-syntaxresult-error' )
				.data( 'syntaxOk', false );

			$filterBox
				.focus()
				.textSelection( 'setSelection', { start: data.character } );
		}
	};

	/**
	 * Adds text to the filter textarea
	 *
	 * Fired by a change event rom the #wpFilterBuilder dropdown
	 */
	this.addText = function() {
		var $filterBuilder = $( '#wpFilterBuilder' );
		if ( $filterBuilder.prop( 'selectedIndex' ) == 0 ) {
			return;
		}

		$filterBox.insertAtCaret( $filterBuilder.val() + " " );
		$filterBuilder.prop( 'selectedIndex', 0 );
	};

	/**
	 * Fetches a filter from the API and inserts it into the filter box
	 */
	this.fetchFilter = function() {
		var filterId = $( '#mw-abusefilter-load-filter' ).val();

		if ( filterId == '' ) {
			return;
		}

		$( this ).injectSpinner( 'fetch-spinner' );
		$.getJSON(
			mw.util.wikiScript( 'api' ), {
				action: 'query',
				format: 'json',
				list: 'abusefilters',
				abfprop: 'pattern',
				abfstartid: filterId,
				abfendid: filterId,
				abflimit: 1
			}, function ( data ) {
				$.removeSpinner( 'fetch-spinner' );
				$filterBox.text( data.query.abusefilters[0].pattern );
			}
		);
	};

	/**
	 * Cycles through all action checkboxes and hides parameter divs
	 * that don't have checked boxes
	 */
	this.hideDeselectedActions = function() {
		$( 'input.mw-abusefilter-action-checkbox' ).each( function() {
			var action = this.id.substr( 31 );
			var $params = $( '#mw-abusefilter-' + action + '-parameters' );

			if ( $params.length ) {
				if ( this.checked ) {
					$params.show();
				} else {
					$params.hide();
				}
			}
		} );
	};

	/**
	 * Fetches the warning message selected for previewing
	 */
	this.previewWarnMessage = function() {
		var message = getCurrentWarningMessage();

		$.get( mw.config.get('wgScript'), {
				title: 'MediaWiki:' + message,
				action: 'render'
			}, function( data ) {
			$( '#mw-abusefilter-warn-preview' ).html( data )
		} );
	};

	/**
	 * Redirects browser to the warning message for editing
	 */
	this.editWarnMessage = function() {
		var message = getCurrentWarningMessage();

		window.location = mw.config.get( 'wgScript' ) + '?title=MediaWiki:' +  mw.util.wikiUrlencode( message ) + '&action=edit';
	};

	// From http://stackoverflow.com/questions/946534/insert-text-into-textarea-with-jquery/2819568#2819568
	$.fn.extend({
		/**
		 * Inserts a string in a textarea at the current caret position
		 * 
		 * @param {String} myValue String to insert
		 * @returns {jQuery}
		 */
		insertAtCaret: function(myValue){
			return this.each( function() {
				if ( document.selection ) {
					this.focus();
					sel = document.selection.createRange();
					sel.text = myValue;
					this.focus();
				} else if ( this.selectionStart || this.selectionStart == '0' ) {
					var startPos = this.selectionStart;
					var endPos = this.selectionEnd;
					var scrollTop = this.scrollTop;
					this.value = this.value.substring( 0, startPos )
						+ myValue
						+ this.value.substring( endPos, this.value.length );
					this.focus();
					this.selectionStart = startPos + myValue.length;
					this.selectionEnd = startPos + myValue.length;
					this.scrollTop = scrollTop;
				} else {
					this.value += myValue;
					this.focus();
				}
			} );
		}
	});

	/*
	 * On ready initialization
	 */
	$( function( $ ) {
		// Hide the syntax ok message when the text changes
		$filterBox.keyup( function() {
			var $el = $( '#mw-abusefilter-syntaxresult' );
			if ( $el.data( 'syntaxOk' ) ) {
				$el.hide();
			}
		} );

		$( '#mw-abusefilter-load' ).click( that.fetchFilter );
		$( '#mw-abusefilter-warn-preview-button' ).click( that.previewWarnMessage );
		$( '#mw-abusefilter-warn-edit-button' ).click( that.editWarnMessage );
		$( 'input.mw-abusefilter-action-checkbox' ).click( that.hideDeselectedActions );
		that.hideDeselectedActions();

		$( '#mw-abusefilter-syntaxcheck' ).click( that.doSyntaxCheck );
		$( '#wpFilterBuilder' ).change( that.addText );

		var $exportBox = $( '#mw-abusefilter-export' );
		$( '#mw-abusefilter-export-link' ).toggle( function() {
			$exportBox.show()
		}, function() {
			$exportBox.hide();
		} );
	} );
})( jQuery, mediaWiki );
