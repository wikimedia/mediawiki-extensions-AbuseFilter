<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Extension\AbuseFilter\EditRevUpdater;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

class PageSaveHandler implements PageSaveCompleteHook {
	/** @var EditRevUpdater */
	private $revUpdater;

	public function __construct( EditRevUpdater $revUpdater ) {
		$this->revUpdater = $revUpdater;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$this->revUpdater->updateRev( $wikiPage, $revisionRecord );
	}
}
