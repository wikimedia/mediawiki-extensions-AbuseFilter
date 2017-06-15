<?php

class AbuseFilterViewImport extends AbuseFilterView {
	function show() {
		$out = $this->getOutput();
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}
		$url = SpecialPage::getTitleFor( 'AbuseFilter', 'new' )->getFullURL();

		$formDescriptor = [
			'ImportText' => [
				'type' => 'textarea',
				'cols' => 200,
			]
		];
		$htmlForm = new HTMLForm( $formDescriptor, $this->getContext() );
		$htmlForm->setHeaderText( $this->msg( 'abusefilter-import-intro' ) )
			->setSubmitTextMsg( 'abusefilter-import-submit' )
			->setAction( $url )
			->show();
	}
}
