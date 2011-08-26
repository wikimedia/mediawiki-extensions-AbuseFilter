<?php
if ( !defined( 'MEDIAWIKI' ) )
	die();

class AbuseFilterViewTools extends AbuseFilterView {
	function show() {
		global $wgOut, $wgUser;

		// Header
		$wgOut->addWikiMsg( 'abusefilter-tools-text' );

		// Expression evaluator
		$eval = '';
		$eval .= AbuseFilter::buildEditBox( '', 'wpTestExpr' );

		// Only let users with permission actually test it
		if ( $wgUser->isAllowed( 'abusefilter-modify' ) ) {
			$eval .= Xml::tags( 'p', null,
				Xml::element( 'input',
				array(
					'type' => 'button',
					'id' => 'mw-abusefilter-submitexpr',
					'value' => wfMsg( 'abusefilter-tools-submitexpr' ) )
				)
			);
			$eval .= Xml::element( 'p', array( 'id' => 'mw-abusefilter-expr-result' ), ' ' );
		}
		$eval = Xml::fieldset( wfMsg( 'abusefilter-tools-expr' ), $eval );
		$wgOut->addHTML( $eval );

		$wgOut->addModules( 'ext.abuseFilter.tools' );

		if ( $wgUser->isAllowed( 'abusefilter-modify' ) ) {
			// Hacky little box to re-enable autoconfirmed if it got disabled
			$rac = '';
			$rac .= Xml::inputLabel(
				wfMsg( 'abusefilter-tools-reautoconfirm-user' ),
				'wpReAutoconfirmUser',
				'reautoconfirm-user',
				45
			);
			$rac .= '&#160;';
			$rac .= Xml::element(
				'input',
				array(
					'type' => 'button',
					'id' => 'mw-abusefilter-reautoconfirmsubmit',
					'value' => wfMsg( 'abusefilter-tools-reautoconfirm-submit' )
				)
			);
			$rac = Xml::fieldset( wfMsg( 'abusefilter-tools-reautoconfirm' ), $rac );
			$wgOut->addHTML( $rac );
		}
	}
}
