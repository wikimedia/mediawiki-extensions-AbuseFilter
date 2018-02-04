<?php

class AbuseFilterViewTools extends AbuseFilterView {
	function show() {
		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		if ( !$user->isAllowed( 'abusefilter-modify' ) ) {
			$out->addWikiMsg( 'abusefilter-mustbeeditor' );
			return;
		}

		// Header
		$out->addWikiMsg( 'abusefilter-tools-text' );

		// Expression evaluator
		$eval = '';
		$eval .= AbuseFilter::buildEditBox( $request->getText( 'wpTestExpr' ), 'wpTestExpr' );

		$eval .= Xml::tags( 'p', null,
			Xml::element( 'input',
			[
				'type' => 'button',
				'id' => 'mw-abusefilter-submitexpr',
				'value' => $this->msg( 'abusefilter-tools-submitexpr' )->text() ]
			)
		);
		$eval .= Xml::element( 'p', [ 'id' => 'mw-abusefilter-expr-result' ], ' ' );

		$eval = Xml::fieldset( $this->msg( 'abusefilter-tools-expr' )->text(), $eval );
		$out->addHTML( $eval );

		$out->addModules( 'ext.abuseFilter.tools' );

		// Hacky little box to re-enable autoconfirmed if it got disabled
		$rac = '';
		$rac .= Xml::inputLabel(
			$this->msg( 'abusefilter-tools-reautoconfirm-user' )->text(),
			'wpReAutoconfirmUser',
			'reautoconfirm-user',
			45
		);
		$rac .= '&#160;';
		$rac .= Xml::element(
			'input',
			[
				'type' => 'button',
				'id' => 'mw-abusefilter-reautoconfirmsubmit',
				'value' => $this->msg( 'abusefilter-tools-reautoconfirm-submit' )->text()
			]
		);
		$rac = Xml::fieldset( $this->msg( 'abusefilter-tools-reautoconfirm' )->text(), $rac );
		$out->addHTML( $rac );
	}
}
