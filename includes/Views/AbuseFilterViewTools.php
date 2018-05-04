<?php

class AbuseFilterViewTools extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$out->enableOOUI();
		$request = $this->getRequest();

		if ( !$this->canViewPrivate() ) {
			$out->addWikiMsg( 'abusefilter-mustviewprivateoredit' );
			return;
		}

		// Header
		$out->addWikiMsg( 'abusefilter-tools-text' );

		// Expression evaluator
		$eval = '';
		$eval .= $this->buildEditBox(
			$request->getText( 'wpTestExpr' ),
			'wpTestExpr',
			true,
			false,
			false
		);

		$eval .=
			Xml::tags( 'p', null,
				new OOUI\ButtonInputWidget( [
					'label' => $this->msg( 'abusefilter-tools-submitexpr' )->text(),
					'id' => 'mw-abusefilter-submitexpr'
				] )
			);
		$eval .= Xml::element( 'p', [ 'id' => 'mw-abusefilter-expr-result' ], ' ' );

		$eval = Xml::fieldset( $this->msg( 'abusefilter-tools-expr' )->text(), $eval );
		$out->addHTML( $eval );

		$out->addModules( 'ext.abuseFilter.tools' );

		// Hacky little box to re-enable autoconfirmed if it got disabled
		$formDescriptor = [
			'RestoreAutoconfirmed' => [
				'label-message' => 'abusefilter-tools-reautoconfirm-user',
				'type' => 'user',
				'name' => 'wpReAutoconfirmUser',
				'id' => 'reautoconfirm-user',
				'infusable' => true
			],
		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setWrapperLegendMsg( 'abusefilter-tools-reautoconfirm' )
			->setSubmitTextMsg( 'abusefilter-tools-reautoconfirm-submit' )
			->setSubmitName( 'wpReautoconfirmSubmit' )
			->setSubmitId( 'mw-abusefilter-reautoconfirmsubmit' )
			->prepareForm()
			->displayForm( false );
	}
}
