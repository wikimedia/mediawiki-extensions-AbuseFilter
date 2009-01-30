function doSyntaxCheck()
{
	var filter = document.getElementById(wgFilterBoxName).value;
	injectSpinner( document.getElementById( 'mw-abusefilter-syntaxcheck' ), 'abusefilter-syntaxcheck' );
	document.getElementById( 'mw-abusefilter-syntaxcheck' ).disabled = true;
	sajax_do_call( 'AbuseFilter::ajaxCheckSyntax', [filter], processSyntaxResult );
}

function processSyntaxResult( request ) {
	var response = request.responseText;
	
	removeSpinner( 'abusefilter-syntaxcheck' );
	document.getElementById( 'mw-abusefilter-syntaxcheck' ).disabled = false;

	var el = document.getElementById( 'mw-abusefilter-syntaxresult' );
	el.style.display = 'block';
	
	if (response.match( /OK/ )) {
		// Successful
		changeText( el, 'No syntax errors.' );
		el.syntaxOk = true;
		el.className = 'mw-abusefilter-syntaxresult-ok';
	} else {
		var errorData = eval(response.substr(4));
		changeText( el, 'Syntax error: '+errorData[0] );
		el.syntaxOk = false;
		el.className = 'mw-abusefilter-syntaxresult-error';

		var position = errorData[1];
		var textArea = document.getElementById( wgFilterBoxName );

		textArea.focus();
		if (document.selection) {
			var sel = document.selection.createRange();
			sel.moveStart( 'character', -textArea.value.length );
			sel.moveStart( 'character', position );
			sel.select();
		} else if (textArea.selectionStart && textArea.selectionEnd) {
			textArea.selectionStart = position;
			textArea.selectionEnd = position;
		}
	}
}

function addText() {
	if (document.getElementById('wpFilterBuilder').selectedIndex == 0) {
		return;
	}
	
	insertAtCursor(document.getElementById(wgFilterBoxName), document.getElementById('wpFilterBuilder').value + " ");
	document.getElementById('wpFilterBuilder').selectedIndex = 0;
}

function fetchFilter() {
	var filter = document.getElementById( 'mw-abusefilter-load-filter' ).value;

	sajax_do_call( 'AbuseFilter::ajaxGetFilter', [filter], function(request) {
		var filter = request.responseText;
		document.getElementById( wgFilterBoxName ).value = filter;
	} );
}

//From http://clipmarks.com/clipmark/CEFC94CB-94D6-4495-A7AA-791B7355E284/
function insertAtCursor(myField, myValue) {
	//IE support
	if (document.selection) {
		myField.focus();
		sel = document.selection.createRange();
		sel.text = myValue;
	}
	//MOZILLA/NETSCAPE support
	else if (myField.selectionStart || myField.selectionStart == '0') {
		var startPos = myField.selectionStart;
		var endPos = myField.selectionEnd;
		myField.value = myField.value.substring(0, startPos)
		+ myValue
		+ myField.value.substring(endPos, myField.value.length);
	} else {
		myField.value += myValue;
	}
}

function setupActions() {
	var checkboxen = getElementsByClassName( document, 'input', 'mw-abusefilter-action-checkbox' );

	for( var i=0; i<checkboxen.length; i++ ) {
		var checkbox = checkboxen[i];

		addHandler( checkbox, 'click', hideDeselectedActions );
	}
	// A second loop, so sue me.
	hideDeselectedActions();
}

function hideDeselectedActions() {
	var checkboxen = getElementsByClassName( document, 'input', 'mw-abusefilter-action-checkbox' );

	for( var i=0; i<checkboxen.length; i++ ) {
		// ID format is mw-abusefilter-action-checkbox-$action
		// We want substr(31)
		var checkbox = checkboxen[i];
		var action = checkbox.id.substr(31);
		var params = document.getElementById( "mw-abusefilter-"+action+"-parameters" );

		if (params && checkbox.checked) {
			params.style.display = 'block';
		} else if (params) {
			params.style.display = 'none';
		}
	}
}

addOnloadHook( function() {
	addHandler( document.getElementById( wgFilterBoxName ), 'keyup', function() {
		el = document.getElementById( 'mw-abusefilter-syntaxresult' );
		if (el.syntaxOk == true) {
			el.style.display = 'none';
		}
	} );

	var loader = document.getElementById( 'mw-abusefilter-load' );
	if (loader) {
		addHandler( loader, 'click', fetchFilter );
	}

	setupActions();
} );