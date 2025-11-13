<?php

namespace MediaWiki\Extension\AbuseFilter\MediaWikiEventIngress;

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\AbuseFilter\EditRevUpdater;
use MediaWiki\Page\Event\PageRevisionUpdatedEvent;
use MediaWiki\Page\Event\PageRevisionUpdatedListener;
use MediaWiki\Page\WikiPageFactory;

class PageEventIngress extends DomainEventIngress implements PageRevisionUpdatedListener {

	public function __construct(
		private readonly EditRevUpdater $revUpdater,
		private readonly WikiPageFactory $wikiPageFactory
	) {
	}

	/** @inheritDoc */
	public function handlePageRevisionUpdatedEvent( PageRevisionUpdatedEvent $event ): void {
		$latestRevisionRecord = $event->getLatestRevisionAfter();
		$wikiPage = $this->wikiPageFactory->newFromTitle(
			$latestRevisionRecord->getPage()
		);
		$this->revUpdater->updateRev( $wikiPage, $latestRevisionRecord );
	}
}
