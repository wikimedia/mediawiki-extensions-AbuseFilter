let $textarea,
	blockedExternalDomains,
	wikiEditorCtx;

/**
 * Fetch and cache the contents of the blocked external domains JSON page.
 *
 * @return {Promise}
 */
function loadBlockedExternalDomains() {
	if ( blockedExternalDomains !== undefined ) {
		return Promise.resolve( blockedExternalDomains );
	}

	const title = new mw.Title( 'BlockedExternalDomains.json', mw.config.get( 'wgNamespaceIds' ).mediawiki );
	return fetch( title.getUrl( { action: 'raw' } ) )
		.then( ( res ) => res.json() )
		.then( ( entries ) => {
			blockedExternalDomains = entries.map( ( entry ) => entry.domain );
			return blockedExternalDomains;
		} )
		.catch( () => {
			// Silently fail, say if MediaWiki:BlockedExternalDomains.json is missing or invalid JSON.
			blockedExternalDomains = [];
		} );
}

/**
 * Get a URL object for the given URL string.
 * As of September 2023, the static URL.canParse() is still not supported in most modern browsers.
 *
 * @param {string} urlStr
 * @return {URL|false} URL object or false if it could not be parsed.
 */
function parseUrl( urlStr ) {
	try {
		return new URL( urlStr );
	} catch ( e ) {
		return false;
	}
}

/**
 * Click handler for the 'Review link' link.
 *
 * @param {Event} e
 */
function reviewClickHandler( e ) {
	const start = e.data.cursorPosition - e.data.wikitext.length,
		end = e.data.cursorPosition,
		selectedContent = $textarea.textSelection( 'getContents' )
			.slice( start, end );

	if ( selectedContent !== e.data.wikitext ) {
		// Abort if wikitext has changed since the notification was shown.
		return;
	}

	e.preventDefault();
	$textarea.trigger( 'focus' );
	$textarea.textSelection( 'setSelection', { start, end } );

	// Open the WikiEditor link insertion dialog, double-checking that it still exists (T271457)
	if ( wikiEditorCtx && $.wikiEditor && $.wikiEditor.modules && $.wikiEditor.modules.dialogs ) {
		$.wikiEditor.modules.dialogs.api.openDialog( wikiEditorCtx, 'insert-link' );
		e.data.notification.close();
	}
}

/**
 * Issue a notification of type 'warn'.
 *
 * @param {string} wikitext
 * @param {URL} url
 * @param {number} cursorPosition
 */
function showWarning( wikitext, url, cursorPosition ) {
	const $reviewLink = $( '<a>' )
		.prop( 'href', url.href )
		.prop( 'target', '_blank' )
		.text( mw.msg( 'abusefilter-blocked-domains-notif-review-link' ) )
		.addClass( 'mw-abusefilter-blocked-domains-notif-review-link' );
	const $content = $( '<p>' ).append(
		mw.message( 'abusefilter-blocked-domains-notif-body', [ url.hostname ] ).parse()
	);
	const notification = mw.notification.notify( [ $content, $reviewLink ], {
		autoHideSeconds: 'long',
		type: 'warn',
		classes: 'mw-abusefilter-blocked-domains-notif',
		tag: 'mw-abusefilter-blocked-domains-notif'
	} );

	$reviewLink.on(
		'click',
		{ url, wikitext, cursorPosition, notification },
		reviewClickHandler
	);
}

/**
 * Query the blocked domains list and check the given URL against it.
 * If there's a match, a warning is displayed to the user.
 *
 * @param {string} wikitext
 * @param {string} urlStr
 * @param {number} cursorPosition
 */
function checkIfBlocked( wikitext, urlStr, cursorPosition ) {
	const url = parseUrl( urlStr );
	if ( !url ) {
		// Likely an invalid URL.
		return;
	}
	loadBlockedExternalDomains().then( () => {
		if ( blockedExternalDomains.includes( url.hostname ) ) {
			showWarning( wikitext, url, cursorPosition );
		}
	} );
}

/**
 * (Re-)add the keyup listener to the textarea.
 *
 * @param {jQuery|CodeMirror} [editor]
 * @param {string} [event]
 */
function addEditorListener( editor = $textarea, event = 'input.blockedexternaldomains' ) {
	editor.off( event );
	editor.on( event, () => {
		const cursorPosition = $textarea.textSelection( 'getCaretPosition' ),
			context = $textarea.textSelection( 'getContents' )
				.slice( 0, cursorPosition ),
			// TODO: somehow use the same regex as the MediaWiki parser
			matches = /.*\b\[?(https?:\/\/[-a-zA-Z0-9@:%._+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&/=]*))(?:.*?])?$/.exec( context );

		if ( matches ) {
			checkIfBlocked( matches[ 0 ], matches[ 1 ], cursorPosition );
		}
	} );
}

/**
 * Script entrypoint.
 *
 * @param {jQuery} $form
 */
function init( $form ) {
	$textarea = $form.find( '#wpTextbox1' );

	/**
	 * Skin doesn't support this clientside solution if the textarea is not present in page.
	 * We also want to use the JavaScript URL API, so IE is not supported.
	 */
	if ( !$textarea.length || !( 'URL' in window ) ) {
		return;
	}

	addEditorListener();

	// WikiEditor integration; causes the 'Review link' link to open the link insertion dialog.
	mw.hook( 'wikiEditor.toolbarReady' ).add( function ( $wikiEditorTextarea ) {
		wikiEditorCtx = $wikiEditorTextarea.data( 'wikiEditor-context' );
	} );

	// CodeMirror integration.
	mw.hook( 'ext.CodeMirror.switch' ).add( function ( _enabled, $editor ) {
		$textarea = $editor;
		addEditorListener( $editor[ 0 ].CodeMirror, 'change' );
	} );
}

mw.hook( 'wikipage.editform' ).add( init );
