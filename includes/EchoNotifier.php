<?php

namespace MediaWiki\Extension\AbuseFilter;

use EchoEvent;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use Title;

/**
 * Helper service for EmergencyWatcher to notify filter maintainers of throttled filters
 * @todo DI not possible due to Echo
 */
class EchoNotifier {
	public const SERVICE_NAME = 'AbuseFilterEchoNotifier';
	public const EVENT_TYPE = 'throttled-filter';

	/** @var FilterLookup */
	private $filterLookup;

	/** @var bool */
	private $isEchoLoaded;

	/**
	 * @param FilterLookup $filterLookup
	 * @param bool $isEchoLoaded
	 */
	public function __construct(
		FilterLookup $filterLookup,
		bool $isEchoLoaded
	) {
		$this->filterLookup = $filterLookup;
		$this->isEchoLoaded = $isEchoLoaded;
	}

	/**
	 * @param int $filter
	 * @return Title
	 */
	private function getTitleForFilter( int $filter ) : Title {
		return SpecialAbuseFilter::getTitleForSubpage( (string)$filter );
	}

	/**
	 * @param int $filter
	 * @return int
	 */
	private function getLastUserIDForFilter( int $filter ) : int {
		return $this->filterLookup->getFilter( $filter, false )->getUserID();
	}

	/**
	 * @internal
	 * @param int $filter
	 * @return array
	 */
	public function getDataForEvent( int $filter ) : array {
		return [
			'type' => self::EVENT_TYPE,
			'title' => $this->getTitleForFilter( $filter ),
			'extra' => [
				'user' => $this->getLastUserIDForFilter( $filter ),
			],
		];
	}

	/**
	 * Send notification about a filter being throttled
	 *
	 * @param int $filter
	 * @return EchoEvent|false
	 */
	public function notifyForFilter( int $filter ) {
		if ( $this->isEchoLoaded ) {
			return EchoEvent::create( $this->getDataForEvent( $filter ) );
		}
		return false;
	}

}
