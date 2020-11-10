<?php

namespace MediaWiki\Extension\AbuseFilter;

use EchoEventPresentationModel;

class ThrottleFilterPresentationModel extends EchoEventPresentationModel {

	/**
	 * @inheritDoc
	 */
	public function getIconType() {
		return 'placeholder';
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage() {
		$text = $this->event->getTitle()->getText();
		list( , $filter ) = explode( '/', $text, 2 );
		return $this->msg( 'notification-header-throttle-filter' )
			->params( $this->getViewingUserForGender() )
			->numParams( $filter );
	}

	/**
	 * @inheritDoc
	 */
	public function getSubjectMessage() {
		return $this->msg( 'notification-subject-throttle-filter' )
			->params( $this->getViewingUserForGender() );
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'notification-link-text-show-filter' )->text()
		];
	}
}
