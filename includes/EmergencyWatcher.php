<?php

namespace MediaWiki\Extension\AbuseFilter;

use AutoCommitUpdate;
use DeferredUpdates;
use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Service for monitoring filters with restricted actions and preventing them
 * from executing destructive actions ("throttling")
 *
 * @todo We should log throttling somewhere
 */
class EmergencyWatcher {
	public const SERVICE_NAME = 'AbuseFilterEmergencyWatcher';

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterEmergencyDisableAge',
		'AbuseFilterEmergencyDisableCount',
		'AbuseFilterEmergencyDisableThreshold',
	];

	/** @var FilterProfiler */
	private $profiler;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var FilterLookup */
	private $filterLookup;

	/** @var ServiceOptions */
	private $options;

	/**
	 * @param FilterProfiler $profiler
	 * @param ILoadBalancer $loadBalancer
	 * @param FilterLookup $filterLookup
	 * @param ServiceOptions $options
	 */
	public function __construct(
		FilterProfiler $profiler,
		ILoadBalancer $loadBalancer,
		FilterLookup $filterLookup,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->profiler = $profiler;
		$this->loadBalancer = $loadBalancer;
		$this->filterLookup = $filterLookup;
		$this->options = $options;
	}

	/**
	 * Determine which filters must be throttled, i.e. their potentially dangerous
	 *  actions must be disabled.
	 *
	 * @param string[] $filters The filters to check
	 * @param string $group Group the filters belong to
	 * @return string[] Array of filters to be throttled
	 */
	public function getFiltersToThrottle( array $filters, string $group ) : array {
		$groupProfile = $this->profiler->getGroupProfile( $group );

		// @ToDo this is an amount between 1 and AbuseFilterProfileActionsCap, which means that the
		// reliability of this number may strongly vary. We should instead use a fixed one.
		$totalActions = $groupProfile['total'];
		if ( $totalActions === 0 ) {
			return [];
		}

		$threshold = $this->getEmergencyValue( 'threshold', $group );
		$hitCountLimit = $this->getEmergencyValue( 'count', $group );
		$maxAge = $this->getEmergencyValue( 'age', $group );

		$time = (int)wfTimestamp( TS_UNIX );

		$throttleFilters = [];
		foreach ( $filters as $filter ) {
			$filterObj = $this->filterLookup->getFilter( (int)$filter, false );
			if ( $filterObj->isThrottled() ) {
				continue;
			}

			// Figure out if the filter is subject to being throttled.
			$filterAge = (int)wfTimestamp( TS_UNIX, $filterObj->getTimestamp() );

			// TODO: this value might be stale, there is no guarantee the match
			// has actually been recorded now
			$matchCount = $this->profiler->getFilterProfile( $filter )['matches'];

			$exemptTime = $filterAge + $maxAge;

			if ( $exemptTime > $time
				&& $matchCount > $hitCountLimit
				&& ( $matchCount / $totalActions ) > $threshold
			) {
				// More than AbuseFilterEmergencyDisableCount matches, constituting more than
				// AbuseFilterEmergencyDisableThreshold (a fraction) of last few edits.
				// Disable it.
				$throttleFilters[] = $filter;
			}
		}

		return $throttleFilters;
	}

	/**
	 * Determine which a filters must be throttled and apply the throttling
	 *
	 * @param string[] $filters The filters to check
	 * @param string $group
	 * @todo Could this be a single update?
	 */
	public function checkFilters( array $filters, string $group ) : void {
		$throttleFilters = $this->getFiltersToThrottle( $filters, $group );
		foreach ( $throttleFilters as $filter ) {
			DeferredUpdates::addUpdate(
				new AutoCommitUpdate(
					$this->loadBalancer->getConnection( DB_MASTER ),
					__METHOD__,
					function ( IDatabase $dbw, $fname ) use ( $filter ) {
						$dbw->update(
							'abuse_filter',
							[ 'af_throttled' => 1 ],
							[ 'af_id' => $filter ],
							$fname
						);
					}
				)
			);
		}
	}

	/**
	 * @param string $type The value to get, either "threshold", "count" or "age"
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return mixed
	 */
	private function getEmergencyValue( string $type, string $group ) {
		switch ( $type ) {
			case 'threshold':
				$opt = 'AbuseFilterEmergencyDisableThreshold';
				break;
			case 'count':
				$opt = 'AbuseFilterEmergencyDisableCount';
				break;
			case 'age':
				$opt = 'AbuseFilterEmergencyDisableAge';
				break;
			default:
				// @codeCoverageIgnoreStart
				throw new InvalidArgumentException( '$type must be either "threshold", "count" or "age"' );
				// @codeCoverageIgnoreEnd
		}

		$value = $this->options->get( $opt );
		return $value[$group] ?? $value['default'];
	}
}
