<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilter;
use BagOStuff;
use DeferredUpdates;
use IBufferingStatsdDataFactory;
use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;
use Title;

/**
 * This class is used to create, store, and retrieve profiling information for single filters and
 * groups of filters.
 * @internal
 */
class FilterProfiler {
	public const SERVICE_NAME = 'AbuseFilterFilterProfiler';

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterProfileActionsCap',
		'AbuseFilterConditionLimit',
		'AbuseFilterSlowFilterRuntimeLimit',
	];

	/**
	 * @var int How long to keep profiling data in cache (in seconds)
	 */
	private const STATS_STORAGE_PERIOD = 86400;

	/** @var BagOStuff */
	private $objectStash;

	/** @var ServiceOptions */
	private $options;

	/** @var string */
	private $localWikiID;

	/** @var IBufferingStatsdDataFactory */
	private $statsd;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param BagOStuff $objectStash
	 * @param ServiceOptions $options
	 * @param string $localWikiID
	 * @param IBufferingStatsdDataFactory $statsd
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		BagOStuff $objectStash,
		ServiceOptions $options,
		string $localWikiID,
		IBufferingStatsdDataFactory $statsd,
		LoggerInterface $logger
	) {
		$this->objectStash = $objectStash;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->localWikiID = $localWikiID;
		$this->statsd = $statsd;
		$this->logger = $logger;
	}

	/**
	 * @param int|string $filter
	 */
	public function resetFilterProfile( $filter ) : void {
		$profileKey = $this->filterProfileKey( $filter );
		$this->objectStash->delete( $profileKey );
	}

	/**
	 * Retrieve per-filter statistics.
	 *
	 * @param string $filter
	 * @return array
	 */
	public function getFilterProfile( string $filter ) : array {
		$profile = $this->objectStash->get( $this->filterProfileKey( $filter ) );

		if ( $profile !== false ) {
			$curCount = $profile['count'];
			$curTotalTime = $profile['total-time'];
			$curTotalConds = $profile['total-cond'];
		} else {
			return [ 0, 0, 0, 0 ];
		}

		// Return in milliseconds, rounded to 2dp
		$avgTime = round( $curTotalTime / $curCount, 2 );
		$avgCond = round( $curTotalConds / $curCount, 1 );

		return [ $curCount, $profile['matches'], $avgTime, $avgCond ];
	}

	/**
	 * Retrieve per-group statistics
	 * @param string $group
	 * @return array|false
	 * @phan-return array{total:int,overflow:int,matches:int}|false
	 */
	public function getGroupProfile( string $group ) {
		return $this->objectStash->get( $this->filterProfileGroupKey( $group ) );
	}

	/**
	 * Record per-filter profiling data
	 *
	 * @param int $filter
	 * @param float $time Time taken, in milliseconds
	 * @param int $conds
	 * @param bool $matched
	 */
	private function recordProfilingResult( int $filter, float $time, int $conds, bool $matched ) : void {
		// Defer updates to avoid massive (~1 second) edit time increases
		DeferredUpdates::addCallableUpdate( function () use ( $filter, $time, $conds, $matched ) {
			$profileKey = $this->filterProfileKey( $filter );
			$profile = $this->objectStash->get( $profileKey );

			if ( $profile !== false ) {
				// Number of observed executions of this filter
				$profile['count']++;
				if ( $matched ) {
					// Number of observed matches of this filter
					$profile['matches']++;
				}
				// Total time spent on this filter from all observed executions
				$profile['total-time'] += $time;
				// Total number of conditions for this filter from all executions
				$profile['total-cond'] += $conds;
			} else {
				$profile = [
					'count' => 1,
					'matches' => (int)$matched,
					'total-time' => $time,
					'total-cond' => $conds
				];
			}
			// Note: It is important that all key information be stored together in a single
			// memcache entry to avoid race conditions where competing Apache instances
			// partially overwrite the stats.
			$this->objectStash->set( $profileKey, $profile, 3600 );
		} );
	}

	/**
	 * Check if profiling data for all filters is lesser than the limit. If not, delete it and
	 * also delete per-filter profiling for all filters. Note that we don't need to reset it for
	 * disabled filters too, as their profiling data will be reset upon re-enabling anyway.
	 *
	 * @param string $group
	 * @param array $allFilters
	 */
	public function checkResetProfiling( string $group, array $allFilters ) : void {
		$profileKey = $this->filterProfileGroupKey( $group );

		$profile = $this->objectStash->get( $profileKey );
		$total = $profile['total'] ?? 0;

		if ( $total > $this->options->get( 'AbuseFilterProfileActionsCap' ) ) {
			$this->objectStash->delete( $profileKey );
			foreach ( $allFilters as $filter ) {
				$this->resetFilterProfile( $filter );
			}
		}
	}

	/**
	 * Update global statistics
	 *
	 * @param string $group
	 * @param int $condsUsed The amount of used conditions
	 * @param float $totalTime Time taken, in milliseconds
	 * @param bool $anyMatch Whether at least one filter matched the action
	 */
	public function recordStats( string $group, int $condsUsed, float $totalTime, bool $anyMatch ) : void {
		$profileKey = $this->filterProfileGroupKey( $group );

		// Note: All related data is stored in a single memcache entry and updated via merge()
		// to avoid race conditions where partial updates on competing instances corrupt the data.
		$this->objectStash->merge(
			$profileKey,
			function ( $cache, $key, $profile ) use ( $condsUsed, $totalTime, $anyMatch ) {
				if ( $profile === false ) {
					$profile = [
						// Total number of actions observed
						'total' => 0,
						// Number of actions ending by exceeding condition limit
						'overflow' => 0,
						// Total time of execution of all observed actions
						'total-time' => 0,
						// Total number of conditions from all observed actions
						'total-cond' => 0,
						// Total number of filters matched
						'matches' => 0
					];
				}

				$profile['total']++;
				$profile['total-time'] += $totalTime;
				$profile['total-cond'] += $condsUsed;

				// Increment overflow counter, if our condition limit overflowed
				if ( $condsUsed > $this->options->get( 'AbuseFilterConditionLimit' ) ) {
					$profile['overflow']++;
				}

				// Increment counter by 1 if there was at least one match
				if ( $anyMatch ) {
					$profile['matches']++;
				}

				return $profile;
			},
			self::STATS_STORAGE_PERIOD
		);
	}

	/**
	 * Record runtime profiling data for all filters together
	 *
	 * @param int $totalFilters
	 * @param int $totalConditions
	 * @param float $runtime
	 */
	public function recordRuntimeProfilingResult( int $totalFilters, int $totalConditions, float $runtime ) : void {
		$keyPrefix = 'abusefilter.runtime-profile.' . $this->localWikiID . '.';

		$this->statsd->timing( $keyPrefix . 'runtime', $runtime );
		$this->statsd->timing( $keyPrefix . 'total_filters', $totalFilters );
		$this->statsd->timing( $keyPrefix . 'total_conditions', $totalConditions );
	}

	/**
	 * Record per-filter profiling, for all filters
	 *
	 * @param Title $title
	 * @param array $data Profiling data, as stored in $this->profilingData
	 * @phan-param array<string,array{time:float,conds:int,result:bool}> $data
	 */
	public function recordPerFilterProfiling( Title $title, array $data ) : void {
		foreach ( $data as $filterName => $params ) {
			list( $filterID, $global ) = AbuseFilter::splitGlobalName( $filterName );
			if ( !$global ) {
				// @todo Maybe add a parameter to recordProfilingResult to record global filters
				// data separately (in the foreign wiki)
				$this->recordProfilingResult(
					$filterID,
					$params['time'],
					$params['conds'],
					$params['result']
				);
			}

			if ( $params['time'] > $this->options->get( 'AbuseFilterSlowFilterRuntimeLimit' ) ) {
				$this->recordSlowFilter( $title, $filterName, $params['time'], $params['conds'], $params['result'] );
			}
		}
	}

	/**
	 * Logs slow filter's runtime data for later analysis
	 *
	 * @param Title $title
	 * @param string $filterId
	 * @param float $runtime
	 * @param int $totalConditions
	 * @param bool $matched
	 */
	private function recordSlowFilter(
		Title $title,
		string $filterId,
		float $runtime,
		int $totalConditions,
		bool $matched
	) : void {
		$this->logger->info(
			'Edit filter {filter_id} on {wiki} is taking longer than expected',
			[
				'wiki' => $this->localWikiID,
				'filter_id' => $filterId,
				'title' => $title->getPrefixedText(),
				'runtime' => $runtime,
				'matched' => $matched,
				'total_conditions' => $totalConditions
			]
		);
	}

	/**
	 * Get the memcache access key used to store per-filter profiling data.
	 *
	 * @param string|int $filter
	 * @return string
	 */
	private function filterProfileKey( $filter ) : string {
		return $this->objectStash->makeKey( 'abusefilter-profile', 'v3', $filter );
	}

	/**
	 * Memcache access key used to store overall profiling data for rule groups
	 *
	 * @param string $group
	 * @return string
	 */
	private function filterProfileGroupKey( string $group ) : string {
		return $this->objectStash->makeKey( 'abusefilter-profile', 'group', $group );
	}
}
