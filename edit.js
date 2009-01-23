function doSyntaxCheck()
{
	var filter = document.getElementById(wgFilterBoxName).value;
	injectSpinner( document.getElementById( 'mw-abusefilter-syntaxcheck' ), 'abusefilter-syntaxcheck' );
	sajax_do_call( 'AbuseFilter::ajaxCheckSyntax', [filter], processSyntaxResult );
}

function processSyntaxResult( request ) {
	var response = request.responseText;
	
	removeSpinner( 'abusefilter-syntaxcheck' );

	var el = document.getElementById( 'mw-abusefilter-syntaxresult' );
	el.style.display = 'block';
	
	if (response.match( /OK/ )) {
		// Successful
		changeText( el, 'No syntax errors.' );
		el.syntaxOk = true;
	} else {
		var error = response.substr(4);
		changeText( el, 'Syntax error: '+error );
		el.syntaxOk = false;

		var charRegex = /at char (\d+)/;
		if ( charRegex.test( error ) ) {
			var charArray = charRegex.exec( error );
			var position = charArray[1];
			var textArea = document.getElementById( wgFilterBoxName );

			if (textArea.selectionStart && textArea.selectionEnd) {
				textArea.selectionStart = position;
				textArea.selectionEnd = position;
				textArea.focus();
			}
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

addOnloadHook( function() {
	addHandler( document.getElementById( wgFilterBoxName ), 'keyup', function() {
		el = document.getElementById( 'mw-abusefilter-syntaxresult' );
		if (el.syntaxOk == true) {
			el.style.display = 'none';
		}
	} );
} );