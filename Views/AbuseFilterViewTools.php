<?php

if (!defined( 'MEDIAWIKI' ))
	die();

class AbuseFilterViewTools extends AbuseFilterView {
	function show( ) {
		global $wgRequest,$wgOut;

		// Header
		$wgOut->setSubTitle( wfMsg( 'abusefilter-tools-subtitle' ) );
		$wgOut->addWikiMsg( 'abusefilter-tools-text' );

		// Expression evaluator
		$eval = '';
		$eval .= Xml::textarea( 'wpTestExpr', "" );
		$eval .= Xml::tags( 'p', null, Xml::element( 'input', array( 'type' => 'button', 'id' => 'mw-abusefilter-submitexpr', 'onclick' => 'doExprSubmit();', 'value' => wfMsg( 'abusefilter-tools-submitexpr' ) ) ) );
		$eval .= Xml::element( 'p', array( 'id' => 'mw-abusefilter-expr-result' ), ' ' );
		$eval = Xml::fieldset( wfMsg( 'abusefilter-tools-expr' ), $eval );
		$wgOut->addHTML( $eval );

		// Associated script
		$exprScript = "function doExprSubmit()
		{
			var expr = document.getElementById('wpTestExpr').value;
			injectSpinner( document.getElementById( 'mw-abusefilter-submitexpr' ), 'abusefilter-expr' );
			sajax_do_call( 'AbuseFilter::ajaxEvaluateExpression', [expr], processExprResult );
		}
		function processExprResult( request ) {
			var response = request.responseText;

			removeSpinner( 'abusefilter-expr' );

			var el = document.getElementById( 'mw-abusefilter-expr-result' );
			changeText( el, response );
		}
		function doReautoSubmit()
		{
			var name = document.getElementById('reautoconfirm-user').value;
			injectSpinner( document.getElementById( 'mw-abusefilter-reautoconfirmsubmit' ), 'abusefilter-reautoconfirm' );
			sajax_do_call( 'AbuseFilter::ajaxReAutoconfirm', [name], processReautoconfirm );
		}
		function processReautoconfirm( request ) {
			var response = request.responseText;

			if (strlen(response)) {
				jsMsg( response );
			}

			removeSpinner( 'abusefilter-reautoconfirm' );
		}
		";

		$wgOut->addInlineScript( $exprScript );

		global $wgUser;

		if ($wgUser->isAllowed( 'abusefilter-modify' )) {
			// Hacky little box to re-enable autoconfirmed if it got disabled
			$rac = '';
			$rac .= Xml::inputLabel( wfMsg( 'abusefilter-tools-reautoconfirm-user' ), 'wpReAutoconfirmUser', 'reautoconfirm-user', 45 );
			$rac .= Xml::element( 'input', array( 'type' => 'button', 'id' => 'mw-abusefilter-reautoconfirmsubmit', 'onclick' => 'doReautoSubmit();', 'value' => wfMsg( 'abusefilter-tools-reautoconfirm-submit' ) ) );
			$rac = Xml::fieldset( wfMsg( 'abusefilter-tools-reautoconfirm' ), $rac );
			$wgOut->addHTML( $rac );
		}
	}
}