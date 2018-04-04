<?php

class AbuseFilterViewImport extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}
		$url = SpecialPage::getTitleFor( 'AbuseFilter', 'new' )->getFullURL();

		$out->addWikiMsg( 'abusefilter-import-intro' );

		$formDescriptor = [
			'ImportText' => [
				'type' => 'textarea',
			]
		];
		$htmlform = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setSubmitTextMsg( 'abusefilter-import-submit' )
			->setAction( $url )
			->show();
	}
}
