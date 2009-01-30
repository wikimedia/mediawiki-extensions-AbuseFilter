/** Scripts for Examiner */

function examinerCheckFilter() {
	var filter = document.getElementById( 'wpTestFilter' ).value;

	sajax_do_call( 'AbuseFilter::ajaxCheckFilterWithVars', [filter, wgExamineVars], function(request) {
		var response = request.responseText;
		var el = document.getElementById( 'mw-abusefilter-syntaxresult' );

		el.style.display = 'block';

		if (response == 'MATCH') {
			changeText( el, wgMessageMatch );
			el.className = 'mw-abusefilter-examine-match';
		} else if (response == 'NOMATCH') {
			changeText( el, wgMessageNomatch );
			el.className = 'mw-abusefilter-examine-nomatch';
		} else if (response == 'SYNTAXERROR' ) {
			el.className = 'mw-abusefilter-examine-syntaxerror';
			changeText( el, wgMessageError );
		}
	} );
}

addOnloadHook( function() {
	var el = document.getElementById( 'mw-abusefilter-examine-test' );
	addHandler( el, 'click', examinerCheckFilter );
} );