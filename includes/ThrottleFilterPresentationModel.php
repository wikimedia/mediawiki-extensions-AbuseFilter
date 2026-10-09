<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Language\Language;
use MediaWiki\Message\Message;
use MediaWiki\User\User;

class ThrottleFilterPresentationModel extends EchoEventPresentationModel {

	private readonly int $filter;
	/** @var string[] */
	private readonly array $disabledActions;

	/**
	 * @inheritDoc
	 */
	protected function __construct( Event $event, Language $language, User $user, $distributionType ) {
		parent::__construct( $event, $language, $user, $distributionType );

		// Extract the filter ID from Special:AbuseFilter/<filter>
		$afTitleText = $this->event->getTitle()->getText();
		$this->filter = (int)explode( '/', $afTitleText, 2 )[1];

		// BC: Default to an empty array for events created before Id989fb1
		$this->disabledActions = $this->event->getExtraParam( 'throttled-actions' ) ?? [];
	}

	/**
	 * @inheritDoc
	 */
	public function getIconType() {
		return $this->disabledActions !== [] ? 'alert' : 'placeholder';
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage() {
		if ( $this->disabledActions !== [] ) {
			$specsFormatter = AbuseFilterServices::getSpecsFormatter();
			$specsFormatter->setMessageLocalizer( $this );
			$disabledActionsLocalized = [];
			foreach ( $this->disabledActions as $action ) {
				$disabledActionsLocalized[] = $specsFormatter->getActionMessage( $action )->text();
			}
			return $this->msg( 'notification-header-throttle-filter-actions' )
				->params( $this->getViewingUserForGender() )
				->numParams( $this->filter )
				->params( Message::listParam( $disabledActionsLocalized ) )
				->params( count( $disabledActionsLocalized ) );
		}

		return $this->msg( 'notification-header-throttle-filter-no-actions' )
			->params( $this->getViewingUserForGender() )
			->numParams( $this->filter );
	}

	/**
	 * @inheritDoc
	 */
	public function getSubjectMessage() {
		$msgKey = $this->disabledActions !== []
			? 'notification-subject-throttle-filter-actions'
			: 'notification-subject-throttle-filter-no-actions';
		return $this->msg( $msgKey )->numParams( $this->filter );
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
