<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Filter\ExistingFilter;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\TitleNotification;
use MediaWiki\Title\Title;

/**
 * Helper service for EmergencyWatcher to notify filter maintainers of throttled filters
 */
class EchoNotifier {
	public const SERVICE_NAME = ServiceNames::EchoNotifier;
	public const EVENT_TYPE = 'throttled-filter';

	public function __construct(
		private readonly FilterLookup $filterLookup,
		private readonly ConsequencesRegistry $consequencesRegistry,
		private readonly NotificationService $notifications,
	) {
	}

	private function getTitleForFilter( int $filter ): Title {
		return SpecialAbuseFilter::getTitleForSubpage( (string)$filter );
	}

	private function getFilterObject( int $filter ): ExistingFilter {
		return $this->filterLookup->getFilter( $filter, false );
	}

	private function getThrottledActionNames( ExistingFilter $filterObj ): array {
		return array_intersect(
			$filterObj->getActionsNames(),
			$this->consequencesRegistry->getDangerousActionNames()
		);
	}

	/**
	 * Send notification about a filter being throttled
	 */
	public function notifyForFilter( int $filter ): void {
		$filterObj = $this->getFilterObject( $filter );
		$this->notifications->notify(
			new TitleNotification(
				self::EVENT_TYPE,
				$this->getTitleForFilter( $filter ),
				[ 'throttled-actions' => $this->getThrottledActionNames( $filterObj ) ]
			),
			new RecipientSet( $filterObj->getUserIdentity() )
		);
	}

}
