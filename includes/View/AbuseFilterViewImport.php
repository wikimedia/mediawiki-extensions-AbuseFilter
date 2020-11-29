<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use HTMLForm;

class AbuseFilterViewImport extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		if ( !$this->afPermManager->canEdit( $this->getUser() ) ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}
		$url = $this->getTitle( 'new' )->getFullURL();

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
