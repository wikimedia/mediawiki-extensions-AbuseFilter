<?php

class AbuseFilterViewImport extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		if ( !AbuseFilter::canEdit( $this->getUser() ) ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}
		$url = SpecialPage::getTitleFor( 'AbuseFilter', 'new' )->getFullURL();

		$out->addWikiMsg( 'abusefilter-import-intro' );

		$formDescriptor = [
			'ImportText' => [
				'type' => 'textarea',
				'required' => true
			]
		];
		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setSubmitTextMsg( 'abusefilter-import-submit' )
			->setAction( $url )
			->show();
	}
}
