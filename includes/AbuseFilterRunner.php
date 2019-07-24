<?php

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use Wikimedia\Rdbms\IDatabase;

/**
 * This class contains the logic for executing abuse filters and their actions. The entry points are
 * run() and runForStash(). Note that run() can only be executed once on a given instance.
 * @todo In a perfect world, every time this class gets constructed we should have a context
 *   source at hand. Unfortunately, this currently isn't true, as the hooks used for filtering
 *   don't pass a full context. If they did, this class would just extend ContextSource and use
 *   that to retrieve user, title, globals etc.
 */
class AbuseFilterRunner {
	/**
	 * @var User The user who performed the action being filtered
	 */
	protected $user;
	/**
	 * @var Title The title where the action being filtered was performed
	 */
	protected $title;
	/**
	 * @var AbuseFilterVariableHolder The variables for the current action
	 */
	protected $vars;
	/**
	 * @var string The group of filters to check (as defined in $wgAbuseFilterValidGroups)
	 */
	protected $group;
	/**
	 * @var bool Whether we're running in 'execute' mode, i.e. the normal mode. The other possibility
	 *  is 'stash' mode, where we only cache filter results and do nothing further.
	 * @private DON'T USE THIS, IT WILL BE REMOVED. Public for back-compat only. Use self::run or
	 * self::runForStash to specify the mode.
	 */
	public $executeMode;
	/**
	 * @var AbuseFilterParser The parser instance to use to check all filters
	 * @protected Public for back-compat only, will be made protected. self::init already handles
	 *  building a parser object.
	 */
	public $parser;
	/**
	 * @var bool Whether a run() was already performed. Used to avoid multiple executions with the
	 *   same members.
	 */
	private $executed = false;

	/**
	 * @param User $user The user who performed the action being filtered
	 * @param Title $title The title where the action being filtered was performed
	 * @param AbuseFilterVariableHolder $vars The variables for the current action
	 * @param string $group The group of filters to check. It must be defined as so in
	 *   $wgAbuseFilterValidGroups, or this will throw.
	 * @throws InvalidArgumentException
	 */
	public function __construct( User $user, Title $title, AbuseFilterVariableHolder $vars, $group ) {
		global $wgAbuseFilterValidGroups;
		if ( !in_array( $group, $wgAbuseFilterValidGroups ) ) {
			throw new InvalidArgumentException( '$group must be defined in $wgAbuseFilterValidGroups' );
		}
		$this->user = $user;
		$this->title = $title;
		$this->vars = $vars;
		$this->group = $group;
	}

	/**
	 * Inits variables and parser right before running
	 */
	private function init() {
		// Add vars from extensions
		Hooks::run( 'AbuseFilter-filterAction', [ &$this->vars, $this->title ] );
		Hooks::run( 'AbuseFilterAlterVariables', [ &$this->vars, $this->title, $this->user ] );
		$this->vars->addHolders( AbuseFilter::generateStaticVars() );

		$this->vars->forFilter = true;
		$this->vars->setVar( 'timestamp', (int)wfTimestamp( TS_UNIX ) );
		$this->parser = $this->getParser();
	}

	/**
	 * @return AbuseFilterParser
	 */
	protected function getParser() : AbuseFilterParser {
		global $wgAbuseFilterParserClass;
		return new $wgAbuseFilterParserClass( $this->vars );
	}

	/**
	 * The main entry point of this class. This method runs all filters and takes their consequences.
	 *
	 * @param bool $allowStash Whether we are allowed to check the cache to see if there's a cached
	 *  result of a previous execution for the same edit.
	 * @throws BadMethodCallException If run() was already called on this instance
	 * @return Status Good if no action has been taken, a fatal otherwise.
	 */
	public function run( $allowStash = true ) : Status {
		if ( $this->executed ) {
			throw new BadMethodCallException( 'run() was already called on this instance.' );
		}
		$this->executed = true;
		$this->executeMode = true;
		$this->init();

		$useStash = $allowStash && $this->vars->getVar( 'action' )->toString() === 'edit';

		$startTime = microtime( true );

		$fromCache = false;
		$result = [];
		if ( $useStash ) {
			$cacheData = $this->seekCache();
			if ( $cacheData !== false ) {
				// Merge in any tags to apply to recent changes entries
				AbuseFilter::bufferTagsToSetByAction( $cacheData['tags'] );
				$result = [
					'matches' => $cacheData['matches'],
					'runtime' => $cacheData['runtime'],
					'condCount' => $cacheData['condCount'],
				];
				$fromCache = true;
			}
		}

		if ( !$fromCache ) {
			$result = [
				'matches' => $this->checkAllFilters(),
				'runtime' => ( microtime( true ) - $startTime ) * 1000,
				'condCount' => $this->parser->getCondCount()
			];
		}
		$matchedFilters = array_keys( array_filter( $result['matches'] ) );

		$this->recordRuntimeProfilingResult(
			count( $matchedFilters ),
			$result['condCount'],
			$result['runtime']
		);

		if ( count( $matchedFilters ) === 0 ) {
			return Status::newGood();
		}

		$status = $this->executeFilterActions( $matchedFilters );
		$actionsTaken = $status->getValue();

		// If we executed actions using the cached result, then asynchronously run
		// checkAllFilters again in order to populate lazy variables and
		// record profiling data, see T191430 and T176291. The reason why we need to run
		// this function again is that we want variables from executeMode (since stash mode
		// doesn't store lazy-loaded vars), and to record stats only once (otherwise a single
		// action may count as 2). However, we can't guess beforehand if we'll ever run in execute mode
		// or just end up using cache data, so this is to make sure that the last run will always be with
		// executeMode === true. Also, run the function as deferredupdate to avoid lags, that is the main
		// reason for which we use cache and stash mode.
		// @todo this is TEMPORARY. A proper solution would be to: 1-save vars in cache and reuse them;
		// 2-move self::recordStats from checkAllFilters to this method, after the call
		// to recordRuntimeProfilingResult (but outside the if); 3-save per-filter profiling data in
		// stash mode. 1 and 2 are easy, but 3 isn't because per-filter profiling is handled in
		// checkFilter, and we'd need either a new global (bad!), to change return values (worse!) or to
		// save data in cache directly in checkFilter (quite bad because other things are saved here).
		if ( $useStash && $fromCache ) {
			DeferredUpdates::addCallableUpdate( function () use ( $actionsTaken ) {
				$this->checkAllFilters();
				$this->addLogEntries( $actionsTaken );
			} );
		} else {
			$this->addLogEntries( $actionsTaken );
		}

		return $status;
	}

	/**
	 * Similar to run(), but runs in "stash" mode, which means filters are executed, no actions are
	 *  taken, and the result is saved in cache to be later reused. This can only be used for edits,
	 *  and not doing so will throw.
	 *
	 * @throws InvalidArgumentException
	 * @return Status Always a good status, since we're only saving data.
	 */
	public function runForStash() : Status {
		$action = $this->vars->getVar( 'action' )->toString();
		if ( $action !== 'edit' ) {
			throw new InvalidArgumentException(
				__METHOD__ . " can only be called for edits, called for action $action."
			);
		}

		$this->executeMode = false;
		$this->init();

		$cache = ObjectCache::getLocalClusterInstance();
		$stashKey = $this->getStashKey( $cache );

		$startTime = microtime( true );
		$matchedFilters = $this->checkAllFilters();
		// Save the filter stash result and do nothing further
		$cacheData = [
			'matches' => $matchedFilters,
			'tags' => AbuseFilter::$tagsToSet,
			'condCount' => $this->parser->getCondCount(),
			'runtime' => ( microtime( true ) - $startTime ) * 1000
		];

		$cache->set( $stashKey, $cacheData, $cache::TTL_MINUTE );
		$this->logCache( 'store', $stashKey );

		return Status::newGood();
	}

	/**
	 * Search the cache to find data for a previous execution done for the current edit.
	 *
	 * @return false|array False on failure, the array with data otherwise
	 */
	protected function seekCache() {
		$cache = ObjectCache::getLocalClusterInstance();
		$stashKey = $this->getStashKey( $cache );

		$ret = $cache->get( $stashKey );
		$status = $ret !== false ? 'hit' : 'miss';
		$this->logCache( $status, $stashKey );

		return $ret;
	}

	/**
	 * Get the stash key for the current variables
	 *
	 * @param BagOStuff $cache
	 * @return string
	 */
	protected function getStashKey( BagOStuff $cache ) {
		$inputVars = $this->vars->exportNonLazyVars();
		// Exclude noisy fields that have superficial changes
		$excludedVars = [
			'old_html' => true,
			'new_html' => true,
			'user_age' => true,
			'timestamp' => true,
			'page_age' => true,
			'moved_from_age' => true,
			'moved_to_age' => true
		];

		$inputVars = array_diff_key( $inputVars, $excludedVars );
		ksort( $inputVars );
		$hash = md5( serialize( $inputVars ) );

		return $cache->makeKey(
			'abusefilter',
			'check-stash',
			$this->group,
			$hash,
			'v1'
		);
	}

	/**
	 * Log cache operations related to stashed edits, i.e. store, hit and miss
	 *
	 * @param string $type Either 'store', 'hit' or 'miss'
	 * @param string $key The cache key used
	 * @throws InvalidArgumentException
	 */
	protected function logCache( $type, $key ) {
		if ( !in_array( $type, [ 'store', 'hit', 'miss' ] ) ) {
			throw new InvalidArgumentException( '$type must be either "store", "hit" or "miss"' );
		}
		$logger = LoggerFactory::getInstance( 'StashEdit' );
		// Bots do not use edit stashing, so avoid distorting the stats
		$statsd = $this->user->isBot()
			? new NullStatsdDataFactory()
			: MediaWikiServices::getInstance()->getStatsdDataFactory();

		$logger->debug( __METHOD__ . ": cache $type for '{$this->title}' (key $key)." );
		$statsd->increment( "abusefilter.check-stash.$type" );
	}

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @protected Public for back compat only; this will actually be made protected in the future.
	 *   You should either rely on $this->run() or subclass this class.
	 * @todo This method should simply return an array with IDs of matched filters as values,
	 *   since we always end up filtering it after calling this method.
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public function checkAllFilters() : array {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral, $wgAbuseFilterConditionLimit;

		// Ensure that we start fresh, see T193374
		$this->parser->resetCondCount();

		// Fetch filters to check from the database.
		$matchedFilters = [];

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'abuse_filter',
			AbuseFilter::$allAbuseFilterFields,
			[
				'af_enabled' => 1,
				'af_deleted' => 0,
				'af_group' => $this->group,
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$matchedFilters[$row->af_id] = $this->checkFilter( $row, '' );
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			// Global filters
			$globalRulesKey = AbuseFilter::getGlobalRulesKey( $this->group );

			$fname = __METHOD__;
			$res = MediaWikiServices::getInstance()->getMainWANObjectCache()->getWithSetCallback(
				$globalRulesKey,
				WANObjectCache::TTL_WEEK,
				function () use ( $fname ) {
					$fdb = AbuseFilter::getCentralDB( DB_REPLICA );

					return iterator_to_array( $fdb->select(
						'abuse_filter',
						AbuseFilter::$allAbuseFilterFields,
						[
							'af_enabled' => 1,
							'af_deleted' => 0,
							'af_global' => 1,
							'af_group' => $this->group,
						],
						$fname
					) );
				},
				[
					'checkKeys' => [ $globalRulesKey ],
					'lockTSE' => 300,
					'version' => 1
				]
			);

			foreach ( $res as $row ) {
				$matchedFilters[ AbuseFilter::buildGlobalName( $row->af_id ) ] =
					$this->checkFilter( $row, AbuseFilter::GLOBAL_FILTER_PREFIX );
			}
		}

		if ( $this->parser->getCondCount() > $wgAbuseFilterConditionLimit ) {
			$actionID = $this->getTaggingID();
			AbuseFilter::bufferTagsToSetByAction( [ $actionID => [ 'abusefilter-condition-limit' ] ] );
		}

		if ( $this->executeMode ) {
			// Update statistics, and throttle filters which are over-blocking.
			$this->recordStats( $matchedFilters );
		}

		return $matchedFilters;
	}

	/**
	 * Check the conditions of a single filter, and profile it if $this->executeMode is true
	 *
	 * @param stdClass $row
	 * @param string $prefix
	 * @return bool
	 */
	protected function checkFilter( $row, $prefix = '' ) {
		global $wgAbuseFilterSlowFilterRuntimeLimit;

		$filterID = $prefix . $row->af_id;

		// Record data to be used if executeMode is true
		$startConds = $this->parser->getCondCount();
		$startTime = microtime( true );

		// Store the row somewhere convenient
		AbuseFilter::cacheFilter( $filterID, $row );

		$pattern = trim( $row->af_pattern );
		$result = AbuseFilter::checkConditions( $pattern, $this->parser, true, $filterID );

		$timeTaken = microtime( true ) - $startTime;
		$condsUsed = $this->parser->getCondCount() - $startConds;

		if ( $this->executeMode ) {
			$this->recordProfilingResult( $row->af_id, $timeTaken, $condsUsed );

			$runtime = $timeTaken * 1000;
			if ( $runtime > $wgAbuseFilterSlowFilterRuntimeLimit ) {
				$this->recordSlowFilter( $filterID, $runtime, $condsUsed, $result );
			}
		}

		return $result;
	}

	/**
	 * Record per-filter profiling data
	 *
	 * @param int $filter
	 * @param float $time
	 * @param int $conds
	 */
	protected function recordProfilingResult( $filter, $time, $conds ) {
		// Defer updates to avoid massive (~1 second) edit time increases
		DeferredUpdates::addCallableUpdate( function () use ( $filter, $time, $conds ) {
			$stash = MediaWikiServices::getInstance()->getMainObjectStash();
			$countKey = $stash->makeKey( 'abusefilter', 'profile', $filter, 'count' );
			$totalKey = $stash->makeKey( 'abusefilter', 'profile', $filter, 'total' );
			$condsKey = $stash->makeKey( 'abusefilter', 'profile', $filter, 'conds' );

			$curCount = $stash->get( $countKey );
			$curTotal = $stash->get( $totalKey );
			$curConds = $stash->get( $condsKey );

			if ( $curCount ) {
				$stash->set( $condsKey, $curConds + $conds, 3600 );
				$stash->set( $totalKey, $curTotal + $time, 3600 );
				$stash->incr( $countKey );
			} else {
				$stash->set( $countKey, 1, 3600 );
				$stash->set( $totalKey, $time, 3600 );
				$stash->set( $condsKey, $conds, 3600 );
			}
		} );
	}

	/**
	 * Logs slow filter's runtime data for later analysis
	 *
	 * @param string $filterId
	 * @param float $runtime
	 * @param int $totalConditions
	 * @param bool $matched
	 */
	protected function recordSlowFilter( $filterId, $runtime, $totalConditions, $matched ) {
		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->info(
			'Edit filter {filter_id} on {wiki} is taking longer than expected',
			[
				'wiki' => wfWikiID(),
				'filter_id' => $filterId,
				'title' => $this->title->getPrefixedText(),
				'runtime' => $runtime,
				'matched' => $matched,
				'total_conditions' => $totalConditions
			]
		);
	}

	/**
	 * Update global statistics, and disable filters which are over-blocking.
	 *
	 * @param bool[] $filters
	 */
	protected function recordStats( array $filters ) {
		global $wgAbuseFilterConditionLimit, $wgAbuseFilterProfileActionsCap;

		$stash = MediaWikiServices::getInstance()->getMainObjectStash();

		$overflowKey = AbuseFilter::filterLimitReachedKey();
		$totalKey = AbuseFilter::filterUsedKey( $this->group );

		$total = $stash->get( $totalKey );

		$storagePeriod = AbuseFilter::$statsStoragePeriod;

		if ( !$total || $total > $wgAbuseFilterProfileActionsCap ) {
			// This is for if the total doesn't exist, or has gone past the limit.
			// Recreate all the keys at the same time, so they expire together.
			$stash->set( $totalKey, 0, $storagePeriod );
			$stash->set( $overflowKey, 0, $storagePeriod );

			foreach ( array_keys( $filters ) as $filter ) {
				$stash->set( AbuseFilter::filterMatchesKey( $filter ), 0, $storagePeriod );
			}
			$stash->set( AbuseFilter::filterMatchesKey(), 0, $storagePeriod );
		}

		$stash->incr( $totalKey );

		// Increment overflow counter, if our condition limit overflowed
		if ( $this->parser->getCondCount() > $wgAbuseFilterConditionLimit ) {
			$stash->incr( $overflowKey );
		}
	}

	/**
	 * Record runtime profiling data for all filters
	 *
	 * @param int $totalFilters
	 * @param int $totalConditions
	 * @param float $runtime
	 */
	protected function recordRuntimeProfilingResult( $totalFilters, $totalConditions, $runtime ) {
		$keyPrefix = 'abusefilter.runtime-profile.' . wfWikiID() . '.';

		$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$statsd->timing( $keyPrefix . 'runtime', $runtime );
		$statsd->timing( $keyPrefix . 'total_filters', $totalFilters );
		$statsd->timing( $keyPrefix . 'total_conditions', $totalConditions );
	}

	/**
	 * Executes a set of actions.
	 *
	 * @param string[] $filters
	 * @return Status returns the operation's status. $status->isOK() will return true if
	 *         there were no actions taken, false otherwise. $status->getValue() will return
	 *         an array listing the actions taken. $status->getErrors() etc. will provide
	 *         the errors and warnings to be shown to the user to explain the actions.
	 */
	protected function executeFilterActions( array $filters ) : Status {
		global $wgMainCacheType, $wgAbuseFilterDisallowGlobalLocalBlocks, $wgAbuseFilterRestrictions,
			   $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;

		$actionsByFilter = AbuseFilter::getConsequencesForFilters( $filters );
		$actionsTaken = array_fill_keys( $filters, [] );

		$messages = [];
		// Accumulator to track max block to issue
		$maxExpiry = -1;

		foreach ( $actionsByFilter as $filter => $actions ) {
			// Special-case handling for warnings.
			$filterPublicComments = AbuseFilter::getFilter( $filter )->af_public_comments;

			$isGlobalFilter = AbuseFilter::splitGlobalName( $filter )[1];

			// If the filter has "throttle" enabled and throttling is available via object
			// caching, check to see if the user has hit the throttle.
			if ( !empty( $actions['throttle'] ) && $wgMainCacheType !== CACHE_NONE ) {
				$parameters = $actions['throttle']['parameters'];
				$throttleId = array_shift( $parameters );
				list( $rateCount, $ratePeriod ) = explode( ',', array_shift( $parameters ) );

				$hitThrottle = false;

				// The rest are throttle-types.
				foreach ( $parameters as $throttleType ) {
					$hitThrottle = $hitThrottle || $this->isThrottled(
							$throttleId, $throttleType, $rateCount, $ratePeriod, $isGlobalFilter );
				}

				unset( $actions['throttle'] );
				if ( !$hitThrottle ) {
					$actionsTaken[$filter][] = 'throttle';
					continue;
				}
			}

			if ( $wgAbuseFilterDisallowGlobalLocalBlocks && $isGlobalFilter ) {
				$actions = array_diff_key( $actions, array_filter( $wgAbuseFilterRestrictions ) );
			}

			if ( !empty( $actions['warn'] ) ) {
				$parameters = $actions['warn']['parameters'];
				$action = $this->vars->getVar( 'action' )->toString();
				// Generate a unique key to determine whether the user has already been warned.
				// We'll warn again if one of these changes: session, page, triggered filter or action
				$warnKey = 'abusefilter-warned-' . md5( $this->title->getPrefixedText() ) .
					'-' . $filter . '-' . $action;

				// Make sure the session is started prior to using it
				$session = SessionManager::getGlobalSession();
				$session->persist();

				if ( !isset( $session[$warnKey] ) || !$session[$warnKey] ) {
					$session[$warnKey] = true;

					$msg = $parameters[0] ?? 'abusefilter-warning';
					$messages[] = [ $msg, $filterPublicComments, $filter ];

					$actionsTaken[$filter][] = 'warn';

					// Don't do anything else.
					continue;
				} else {
					// We already warned them
					$session[$warnKey] = false;
				}

				unset( $actions['warn'] );
			}

			// Prevent double warnings
			if ( count( array_intersect_key( $actions, array_filter( $wgAbuseFilterRestrictions ) ) ) > 0 &&
				!empty( $actions['disallow'] )
			) {
				unset( $actions['disallow'] );
			}

			// Find out the max expiry to issue the longest triggered block.
			// Need to check here since methods like user->getBlock() aren't available
			if ( !empty( $actions['block'] ) ) {
				$parameters = $actions['block']['parameters'];

				if ( count( $parameters ) === 3 ) {
					// New type of filters with custom block
					if ( $this->user->isAnon() ) {
						$expiry = $parameters[1];
					} else {
						$expiry = $parameters[2];
					}
				} else {
					// Old type with fixed expiry
					if ( $this->user->isAnon() && $wgAbuseFilterAnonBlockDuration !== null ) {
						// The user isn't logged in and the anon block duration
						// doesn't default to $wgAbuseFilterBlockDuration.
						$expiry = $wgAbuseFilterAnonBlockDuration;
					} else {
						$expiry = $wgAbuseFilterBlockDuration;
					}
				}

				$currentExpiry = SpecialBlock::parseExpiryInput( $expiry );
				if ( $currentExpiry > SpecialBlock::parseExpiryInput( $maxExpiry ) ) {
					// Save the parameters to issue the block with
					$maxExpiry = $expiry;
					$blockValues = [
						AbuseFilter::getFilter( $filter )->af_public_comments,
						$filter,
						is_array( $parameters ) && in_array( 'blocktalk', $parameters )
					];
				}
				unset( $actions['block'] );
			}

			// Do the rest of the actions
			foreach ( $actions as $action => $info ) {
				$newMsg = $this->takeConsequenceAction(
					$action,
					$info['parameters'],
					AbuseFilter::getFilter( $filter )->af_public_comments,
					$filter
				);

				if ( $newMsg !== null ) {
					$messages[] = $newMsg;
				}
				$actionsTaken[$filter][] = $action;
			}
		}

		// Since every filter has been analysed, we now know what the
		// longest block duration is, so we can issue the block if
		// maxExpiry has been changed.
		if ( $maxExpiry !== -1 ) {
			$this->doAbuseFilterBlock(
				[
					'desc' => $blockValues[0],
					'number' => $blockValues[1]
				],
				$this->user->getName(),
				$maxExpiry,
				true,
				$blockValues[2]
			);
			$message = [
				'abusefilter-blocked-display',
				$blockValues[0],
				$blockValues[1]
			];
			// Manually add the message. If we're here, there is one.
			$messages[] = $message;
			$actionsTaken[$blockValues[1]][] = 'block';
		}

		return $this->buildStatus( $actionsTaken, $messages );
	}

	/**
	 * @param string $throttleId
	 * @param string $types
	 * @param string $rateCount
	 * @param string $ratePeriod
	 * @param bool $global
	 * @return bool
	 */
	protected function isThrottled( $throttleId, $types, $rateCount, $ratePeriod, $global = false ) {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		$key = $this->throttleKey( $throttleId, $types, $global );
		$count = intval( $stash->get( $key ) );

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug( "Got value $count for throttle key $key" );

		if ( $count > 0 ) {
			$stash->incr( $key );
			$count++;
			$logger->debug( "Incremented throttle key $key" );
		} else {
			$logger->debug( "Added throttle key $key with value 1" );
			$stash->add( $key, 1, $ratePeriod );
			$count = 1;
		}

		if ( $count > $rateCount ) {
			$logger->debug( "Throttle $key hit value $count -- maximum is $rateCount." );

			// THROTTLED
			return true;
		}

		$logger->debug( "Throttle $key not hit!" );

		// NOT THROTTLED
		return false;
	}

	/**
	 * @param string $throttleId
	 * @param string $type
	 * @param bool $global
	 * @return string
	 */
	protected function throttleKey( $throttleId, $type, $global = false ) {
		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		$types = explode( ',', $type );

		$identifiers = [];

		foreach ( $types as $subtype ) {
			$identifiers[] = $this->throttleIdentifier( $subtype );
		}

		$identifier = sha1( implode( ':', $identifiers ) );

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( $global && !$wgAbuseFilterIsCentral ) {
			return $cache->makeGlobalKey(
				'abusefilter', 'throttle', $wgAbuseFilterCentralDB, $throttleId, $type, $identifier
			);
		}

		return $cache->makeKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}

	/**
	 * @param string $type
	 * @return int|string
	 */
	protected function throttleIdentifier( $type ) {
		$request = RequestContext::getMain()->getRequest();

		switch ( $type ) {
			case 'ip':
				$identifier = $request->getIP();
				break;
			case 'user':
				$identifier = $this->user->getId();
				break;
			case 'range':
				$identifier = substr( IP::toHex( $request->getIP() ), 0, 4 );
				break;
			case 'creationdate':
				$reg = $this->user->getRegistration();
				$identifier = $reg - ( $reg % 86400 );
				break;
			case 'editcount':
				// Hack for detecting different single-purpose accounts.
				$identifier = $this->user->getEditCount();
				break;
			case 'site':
				$identifier = 1;
				break;
			case 'page':
				$identifier = $this->title->getPrefixedText();
				break;
			default:
				// Should never happen
				// @codeCoverageIgnoreStart
				$identifier = 0;
				// @codeCoverageIgnoreEnd
		}

		return $identifier;
	}

	/**
	 * @param string $action
	 * @param array $parameters
	 * @param string $ruleDescription
	 * @param int|string $ruleNumber
	 *
	 * @return array|null a message describing the action that was taken,
	 *         or null if no action was taken. The message is given as an array
	 *         containing the message key followed by any message parameters.
	 */
	protected function takeConsequenceAction( $action, $parameters, $ruleDescription, $ruleNumber ) {
		global $wgAbuseFilterCustomActionsHandlers;

		$message = null;

		switch ( $action ) {
			case 'disallow':
				$msg = $parameters[0] ?? 'abusefilter-disallowed';
				$message = [ $msg, $ruleDescription, $ruleNumber ];
				break;
			case 'rangeblock':
				global $wgAbuseFilterRangeBlockSize, $wgBlockCIDRLimit;

				$ip = RequestContext::getMain()->getRequest()->getIP();
				$type = IP::isIPv6( $ip ) ? 'IPv6' : 'IPv4';
				$CIDRsize = max( $wgAbuseFilterRangeBlockSize[$type], $wgBlockCIDRLimit[$type] );
				$blockCIDR = $ip . '/' . $CIDRsize;

				$this->doAbuseFilterBlock(
					[
						'desc' => $ruleDescription,
						'number' => $ruleNumber
					],
					IP::sanitizeRange( $blockCIDR ),
					'1 week',
					false
				);

				$message = [
					'abusefilter-blocked-display',
					$ruleDescription,
					$ruleNumber
				];
				break;
			case 'degroup':
				if ( !$this->user->isAnon() ) {
					// Pull the groups from the VariableHolder, so that they will always be computed.
					// This allow us to pull the groups from the VariableHolder to undo the degroup
					// via Special:AbuseFilter/revert.
					$groups = $this->vars->getVar( 'user_groups' );
					if ( $groups->type !== AFPData::DARRAY ) {
						// Somehow, the variable wasn't set
						$groups = $this->user->getEffectiveGroups();
						$this->vars->setVar( 'user_groups', $groups );
					} else {
						$groups = $groups->toNative();
					}
					$this->vars->setVar( 'user_groups', $groups );

					foreach ( $groups as $group ) {
						$this->user->removeGroup( $group );
					}

					$message = [
						'abusefilter-degrouped',
						$ruleDescription,
						$ruleNumber
					];

					// Don't log it if there aren't any groups being removed!
					if ( !count( $groups ) ) {
						break;
					}

					$logEntry = new ManualLogEntry( 'rights', 'rights' );
					$logEntry->setPerformer( AbuseFilter::getFilterUser() );
					$logEntry->setTarget( $this->user->getUserPage() );
					$logEntry->setComment(
						wfMessage(
							'abusefilter-degroupreason',
							$ruleDescription,
							$ruleNumber
						)->inContentLanguage()->text()
					);
					$logEntry->setParameters( [
						'4::oldgroups' => $groups,
						'5::newgroups' => []
					] );
					$logEntry->publish( $logEntry->insert() );
				}

				break;
			case 'blockautopromote':
				if ( !$this->user->isAnon() ) {
					// Block for 3-7 days.
					$blockPeriod = (int)mt_rand( 3 * 86400, 7 * 86400 );
					MediaWikiServices::getInstance()->getMainObjectStash()->set(
						AbuseFilter::autoPromoteBlockKey( $this->user ), true, $blockPeriod
					);

					$message = [
						'abusefilter-autopromote-blocked',
						$ruleDescription,
						$ruleNumber
					];
				}
				break;

			case 'block':
				// Do nothing, handled at the end of executeFilterActions. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				$actionID = $this->getTaggingID();
				AbuseFilter::bufferTagsToSetByAction( [ $actionID => $parameters ] );
				break;
			default:
				if ( isset( $wgAbuseFilterCustomActionsHandlers[$action] ) ) {
					$customFunction = $wgAbuseFilterCustomActionsHandlers[$action];
					if ( is_callable( $customFunction ) ) {
						$msg = call_user_func(
							$customFunction,
							$action,
							$parameters,
							$this->title,
							$this->vars,
							$ruleDescription,
							$ruleNumber
						);
					}
					if ( isset( $msg ) ) {
						$message = [ $msg ];
					}
				} else {
					$logger = LoggerFactory::getInstance( 'AbuseFilter' );
					$logger->warning( "Unrecognised action $action" );
				}
		}

		return $message;
	}

	/**
	 * Perform a block by the AbuseFilter system user
	 * @param array $rule should have 'desc' and 'number'
	 * @param string $target
	 * @param string $expiry
	 * @param bool $isAutoBlock
	 * @param bool $preventEditOwnUserTalk
	 */
	private function doAbuseFilterBlock(
		array $rule,
		$target,
		$expiry,
		$isAutoBlock,
		$preventEditOwnUserTalk = false
	) {
		$filterUser = AbuseFilter::getFilterUser();
		$reason = wfMessage(
			'abusefilter-blockreason',
			$rule['desc'], $rule['number']
		)->inContentLanguage()->text();

		$block = new DatabaseBlock();
		$block->setTarget( $target );
		$block->setBlocker( $filterUser );
		$block->mReason = $reason;
		$block->isHardblock( false );
		$block->isAutoblocking( $isAutoBlock );
		$block->isCreateAccountBlocked( true );
		$block->isUsertalkEditAllowed( !$preventEditOwnUserTalk );
		$block->mExpiry = SpecialBlock::parseExpiryInput( $expiry );

		$success = $block->insert();

		if ( $success ) {
			// Log it only if the block was successful
			$logParams = [];
			$logParams['5::duration'] = ( $block->mExpiry === 'infinity' )
				? 'indefinite'
				: $expiry;
			$flags = [ 'nocreate' ];
			if ( !$block->isAutoblocking() && !IP::isIPAddress( $target ) ) {
				// Conditionally added same as SpecialBlock
				$flags[] = 'noautoblock';
			}
			if ( $preventEditOwnUserTalk === true ) {
				$flags[] = 'nousertalk';
			}
			$logParams['6::flags'] = implode( ',', $flags );

			$logEntry = new ManualLogEntry( 'block', 'block' );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $filterUser );
			$logEntry->setParameters( $logParams );
			$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
			$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
			$logEntry->publish( $logEntry->insert() );
		}
	}

	/**
	 * Constructs a Status object as returned by executeFilterActions() from the list of
	 * actions taken and the corresponding list of messages.
	 *
	 * @param array[] $actionsTaken associative array mapping each filter to the list if
	 *                actions taken because of that filter.
	 * @param array[] $messages a list of arrays, where each array contains a message key
	 *                followed by any message parameters.
	 *
	 * @return Status
	 */
	protected function buildStatus( array $actionsTaken, array $messages ) : Status {
		$status = Status::newGood( $actionsTaken );

		foreach ( $messages as $msg ) {
			$status->fatal( ...$msg );
		}

		return $status;
	}

	/**
	 * Creates a template to use for logging taken actions
	 *
	 * @return array
	 */
	protected function buildLogTemplate() : array {
		global $wgAbuseFilterLogIP;

		$request = RequestContext::getMain()->getRequest();
		$action = $this->vars->getVar( 'action' )->toString();
		// If $this->user isn't safe to load (e.g. a failure during
		// AbortAutoAccount), create a dummy anonymous user instead.
		$user = $this->user->isSafeToLoad() ? $this->user : new User;
		// Create a template
		$logTemplate = [
			'afl_user' => $user->getId(),
			'afl_user_text' => $user->getName(),
			'afl_timestamp' => wfGetDB( DB_REPLICA )->timestamp(),
			'afl_namespace' => $this->title->getNamespace(),
			'afl_title' => $this->title->getDBkey(),
			'afl_action' => $action,
			'afl_ip' => $wgAbuseFilterLogIP ? $request->getIP() : ''
		];
		// Hack to avoid revealing IPs of people creating accounts
		if ( !$user->getId() && ( $action === 'createaccount' || $action === 'autocreateaccount' ) ) {
			$logTemplate['afl_user_text'] = $this->vars->getVar( 'accountname' )->toString();
		}
		return $logTemplate;
	}

	/**
	 * Create and publish log entries for taken actions
	 *
	 * @param array[] $actionsTaken
	 * @todo Split this method
	 */
	protected function addLogEntries( array $actionsTaken ) {
		$dbw = wfGetDB( DB_MASTER );
		$logTemplate = $this->buildLogTemplate();
		$centralLogTemplate = [
			'afl_wiki' => wfWikiID(),
		];

		$logRows = [];
		$centralLogRows = [];
		$loggedLocalFilters = [];
		$loggedGlobalFilters = [];

		foreach ( $actionsTaken as $filter => $actions ) {
			list( $filterID, $global ) = AbuseFilter::splitGlobalName( $filter );
			$thisLog = $logTemplate;
			$thisLog['afl_filter'] = $filter;
			$thisLog['afl_actions'] = implode( ',', $actions );

			// Don't log if we were only throttling.
			if ( $thisLog['afl_actions'] !== 'throttle' ) {
				$logRows[] = $thisLog;
				// Global logging
				if ( $global ) {
					$centralLog = $thisLog + $centralLogTemplate;
					$centralLog['afl_filter'] = $filterID;
					$centralLog['afl_title'] = $this->title->getPrefixedText();
					$centralLog['afl_namespace'] = 0;

					$centralLogRows[] = $centralLog;
					$loggedGlobalFilters[] = $filterID;
				} else {
					$loggedLocalFilters[] = $filter;
				}
			}
		}

		if ( !count( $logRows ) ) {
			return;
		}

		// Only store the var dump if we're actually going to add log rows.
		$varDump = AbuseFilter::storeVarDump( $this->vars );
		// To distinguish from stuff stored directly
		$varDump = "stored-text:$varDump";

		$stash = MediaWikiServices::getInstance()->getMainObjectStash();

		// Increment trigger counter
		$stash->incr( AbuseFilter::filterMatchesKey() );

		$localLogIDs = [];
		global $wgAbuseFilterNotifications, $wgAbuseFilterNotificationsPrivate;
		foreach ( $logRows as $data ) {
			$data['afl_var_dump'] = $varDump;
			$dbw->insert( 'abuse_filter_log', $data, __METHOD__ );
			$localLogIDs[] = $data['afl_id'] = $dbw->insertId();
			// Give grep a chance to find the usages:
			// logentry-abusefilter-hit
			$entry = new ManualLogEntry( 'abusefilter', 'hit' );
			// Construct a user object
			$user = User::newFromId( $data['afl_user'] );
			$user->setName( $data['afl_user_text'] );
			$entry->setPerformer( $user );
			$entry->setTarget( $this->title );
			// Additional info
			$entry->setParameters( [
				'action' => $data['afl_action'],
				'filter' => $data['afl_filter'],
				'actions' => $data['afl_actions'],
				'log' => $data['afl_id'],
			] );

			// Send data to CheckUser if installed and we
			// aren't already sending a notification to recentchanges
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )
				&& strpos( $wgAbuseFilterNotifications, 'rc' ) === false
			) {
				$rc = $entry->getRecentChange();
				CheckUserHooks::updateCheckUserData( $rc );
			}

			if ( $wgAbuseFilterNotifications !== false ) {
				list( $filterID, $global ) = AbuseFilter::splitGlobalName( $data['afl_filter'] );
				if ( AbuseFilter::filterHidden( $filterID, $global ) && !$wgAbuseFilterNotificationsPrivate ) {
					continue;
				}
				$this->publishEntry( $dbw, $entry, $wgAbuseFilterNotifications );
			}
		}

		$method = __METHOD__;

		if ( count( $loggedLocalFilters ) ) {
			// Update hit-counter.
			$dbw->onTransactionPreCommitOrIdle(
				function () use ( $dbw, $loggedLocalFilters, $method ) {
					$dbw->update( 'abuse_filter',
						[ 'af_hit_count=af_hit_count+1' ],
						[ 'af_id' => $loggedLocalFilters ],
						$method
					);
				}
			);
		}

		$globalLogIDs = [];

		// Global stuff
		if ( count( $loggedGlobalFilters ) ) {
			$this->vars->computeDBVars();
			$globalVarDump = AbuseFilter::storeVarDump( $this->vars, true );
			$globalVarDump = "stored-text:$globalVarDump";
			foreach ( $centralLogRows as $index => $data ) {
				$centralLogRows[$index]['afl_var_dump'] = $globalVarDump;
			}

			$fdb = AbuseFilter::getCentralDB( DB_MASTER );

			foreach ( $centralLogRows as $row ) {
				$fdb->insert( 'abuse_filter_log', $row, __METHOD__ );
				$globalLogIDs[] = $fdb->insertId();
			}

			$fdb->onTransactionPreCommitOrIdle(
				function () use ( $fdb, $loggedGlobalFilters, $method ) {
					$fdb->update( 'abuse_filter',
						[ 'af_hit_count=af_hit_count+1' ],
						[ 'af_id' => $loggedGlobalFilters ],
						$method
					);
				}
			);
		}

		AbuseFilter::$logIds[ $this->title->getPrefixedText() ] = [
			'local' => $localLogIDs,
			'global' => $globalLogIDs
		];

		$this->checkEmergencyDisable( $loggedLocalFilters );
	}

	/**
	 * Like LogEntry::publish, but doesn't require an ID (which we don't have) and skips the
	 * tagging part
	 *
	 * @param IDatabase $dbw To cancel the callback if the log insertion fails
	 * @param ManualLogEntry $entry
	 * @param string $to One of 'udp', 'rc' and 'rcandudp'
	 */
	private function publishEntry( IDatabase $dbw, ManualLogEntry $entry, $to ) {
		DeferredUpdates::addCallableUpdate(
			function () use ( $entry, $to ) {
				$rc = $entry->getRecentChange();

				if ( $to === 'rc' || $to === 'rcandudp' ) {
					$rc->save( $rc::SEND_NONE );
				}
				if ( $to === 'udp' || $to === 'rcandudp' ) {
					$rc->notifyRCFeeds();
				}
			},
			DeferredUpdates::POSTSEND,
			$dbw
		);
	}

	/**
	 * Determine whether a filter must be throttled, i.e. its potentially dangerous
	 *  actions must be disabled.
	 *
	 * @param string[] $filters The filters to check
	 */
	protected function checkEmergencyDisable( array $filters ) {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		// @ToDo this is an amount between 1 and AbuseFilterProfileActionsCap, which means that the
		// reliability of this number may strongly vary. We should instead use a fixed one.
		$totalActions = $stash->get( AbuseFilter::filterUsedKey( $this->group ) );

		foreach ( $filters as $filter ) {
			$threshold = AbuseFilter::getEmergencyValue( 'threshold', $this->group );
			$hitCountLimit = AbuseFilter::getEmergencyValue( 'count', $this->group );
			$maxAge = AbuseFilter::getEmergencyValue( 'age', $this->group );

			$matchCount = $stash->get( AbuseFilter::filterMatchesKey( $filter ) );

			// Handle missing keys...
			if ( !$matchCount ) {
				$stash->set( AbuseFilter::filterMatchesKey( $filter ), 1, AbuseFilter::$statsStoragePeriod );
			} else {
				$stash->incr( AbuseFilter::filterMatchesKey( $filter ) );
			}
			$matchCount++;

			// Figure out if the filter is subject to being throttled.
			$filterAge = wfTimestamp( TS_UNIX, AbuseFilter::getFilter( $filter )->af_timestamp );
			$exemptTime = $filterAge + $maxAge;

			if ( $totalActions && $exemptTime > time() && $matchCount > $hitCountLimit &&
				( $matchCount / $totalActions ) > $threshold
			) {
				// More than $wgAbuseFilterEmergencyDisableCount matches, constituting more than
				// $threshold (a fraction) of last few edits. Disable it.
				DeferredUpdates::addUpdate(
					new AutoCommitUpdate(
						wfGetDB( DB_MASTER ),
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
	}

	/**
	 * Helper function to get the ID used to identify an action for later tagging it.
	 * @return string
	 */
	protected function getTaggingID() {
		$action = $this->vars->getVar( 'action' )->toString();
		if ( strpos( $action, 'createaccount' ) === false ) {
			$username = $this->user->getName();
			$actionTitle = $this->title;
		} else {
			$username = $this->vars->getVar( 'accountname' )->toString();
			$actionTitle = Title::makeTitleSafe( NS_USER, $username );
		}

		return AbuseFilter::getTaggingActionId( $action, $actionTitle, $username );
	}
}
