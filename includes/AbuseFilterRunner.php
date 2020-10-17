<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use MediaWiki\User\UserIdentity;
use Wikimedia\IPUtils;
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
	 * @var string The action we're filtering
	 */
	protected $action;

	/**
	 * @var array Data from per-filter profiling. Shape:
	 *   [ filterName => [ 'time' => float, 'conds' => int, 'result' => bool ] ]
	 * @phan-var array<string,array{time:float,conds:int,result:bool}>
	 *
	 * Where 'timeTaken' is in seconds, 'result' is a boolean indicating whether the filter matched
	 * the action, and 'filterID' is "{prefix}-{ID}" ; Prefix should be empty for local
	 * filters. In stash mode this member is saved in cache, while in execute mode it's used to
	 * update profiling after checking all filters.
	 */
	protected $profilingData;

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

	/** @var AbuseFilterHookRunner */
	private $hookRunner;

	/** @var FilterProfiler */
	private $filterProfiler;

	/** @var ChangeTagger */
	private $changeTagger;

	/** @var UserIdentity */
	private $filterUser;

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
		if ( !$vars->varIsSet( 'action' ) ) {
			throw new InvalidArgumentException( "The 'action' variable is not set." );
		}
		$this->user = $user;
		$this->title = $title;
		$this->vars = $vars;
		$this->vars->setLogger( LoggerFactory::getInstance( 'AbuseFilter' ) );
		$this->group = $group;
		$this->action = $vars->getVar( 'action' )->toString();
		$this->hookRunner = AbuseFilterHookRunner::getRunner();
		$this->filterProfiler = AbuseFilterServices::getFilterProfiler();
		$this->changeTagger = AbuseFilterServices::getChangeTagger();
		$this->filterUser = AbuseFilterServices::getFilterUser()->getUser();
	}

	/**
	 * Inits variables and parser right before running
	 */
	private function init() {
		// Add vars from extensions
		$this->hookRunner->onAbuseFilterFilterAction(
			$this->vars,
			$this->title
		);
		$this->hookRunner->onAbuseFilterAlterVariables(
			$this->vars,
			$this->title,
			$this->user
		);
		$generator = new VariableGenerator( $this->vars );
		$this->vars = $generator->addGenericVars()->getVariableHolder();

		$this->vars->forFilter = true;
		$this->vars->setVar( 'timestamp', (int)wfTimestamp( TS_UNIX ) );
		$this->parser = $this->getParser();
		$this->parser->setStatsd( MediaWikiServices::getInstance()->getStatsdDataFactory() );
		$this->profilingData = [];
	}

	/**
	 * Shortcut method, so that it can be overridden in mocks.
	 * @return AbuseFilterParser
	 */
	protected function getParser() : AbuseFilterParser {
		return AbuseFilter::getDefaultParser( $this->vars );
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
		$this->init();

		$skipReasons = [];
		$shouldFilter = $this->hookRunner->onAbuseFilterShouldFilterAction(
			$this->vars, $this->title, $this->user, $skipReasons
		);
		if ( !$shouldFilter ) {
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->info(
				'Skipping action {action}. Reasons provided: {reasons}',
				[ 'action' => $this->action, 'reasons' => implode( ', ', $skipReasons ) ]
			);
			return Status::newGood();
		}

		$useStash = $allowStash && $this->action === 'edit';

		$fromCache = false;
		$result = [];
		if ( $useStash ) {
			$cacheData = $this->seekCache();
			if ( $cacheData !== false ) {
				// Use cached vars (T176291) and profiling data (T191430)
				$this->vars = AbuseFilterVariableHolder::newFromArray( $cacheData['vars'] );
				$result = [
					'hitCondLimit' => $cacheData['hitCondLimit'],
					'matches' => $cacheData['matches'],
					'runtime' => $cacheData['runtime'],
					'condCount' => $cacheData['condCount'],
					'profiling' => $cacheData['profiling']
				];
				$fromCache = true;
			}
		}

		if ( !$fromCache ) {
			$startTime = microtime( true );
			// Ensure there's no extra time leftover
			AFComputedVariable::$profilingExtraTime = 0;

			$hitCondLimit = false;
			// This also updates $this->profilingData and $this->parser->mCondCount used later
			$matches = $this->checkAllFilters( $hitCondLimit );
			$timeTaken = ( microtime( true ) - $startTime - AFComputedVariable::$profilingExtraTime ) * 1000;
			$result = [
				'hitCondLimit' => $hitCondLimit,
				'matches' => $matches,
				'runtime' => $timeTaken,
				'condCount' => $this->parser->getCondCount(),
				'profiling' => $this->profilingData
			];
		}
		'@phan-var array{hitCondLimit:bool,matches:array,runtime:int,condCount:int,profiling:array} $result';

		$matchedFilters = array_keys( array_filter( $result['matches'] ) );
		$allFilters = array_keys( $result['matches'] );

		$this->profileExecution( $result, $matchedFilters, $allFilters );

		if ( $result['hitCondLimit'] ) {
			$this->changeTagger->addConditionsLimitTag( $this->getSpecsForTagger() );
		}

		if ( count( $matchedFilters ) === 0 ) {
			return Status::newGood();
		}

		$status = $this->executeFilterActions( $matchedFilters );
		$actionsTaken = $status->getValue();

		$this->addLogEntries( $actionsTaken );

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
		if ( $this->action !== 'edit' ) {
			throw new InvalidArgumentException(
				__METHOD__ . " can only be called for edits, called for action {$this->action}."
			);
		}

		$this->init();

		$skipReasons = [];
		$shouldFilter = $this->hookRunner->onAbuseFilterShouldFilterAction(
			$this->vars, $this->title, $this->user, $skipReasons
		);
		if ( !$shouldFilter ) {
			// Don't log it yet
			return Status::newGood();
		}

		$cache = ObjectCache::getLocalClusterInstance();
		$stashKey = $this->getStashKey( $cache );

		$startTime = microtime( true );
		// Ensure there's no extra time leftover
		AFComputedVariable::$profilingExtraTime = 0;

		$hitCondLimit = false;
		$matchedFilters = $this->checkAllFilters( $hitCondLimit );
		// Save the filter stash result and do nothing further
		$cacheData = [
			'matches' => $matchedFilters,
			'hitCondLimit' => $hitCondLimit,
			'condCount' => $this->parser->getCondCount(),
			'runtime' => ( microtime( true ) - $startTime - AFComputedVariable::$profilingExtraTime ) * 1000,
			'vars' => $this->vars->dumpAllVars(),
			'profiling' => $this->profilingData
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
			'v2'
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
	 * @param bool|null &$hitCondLimit TEMPORARY
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public function checkAllFilters( &$hitCondLimit = false ) : array {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral, $wgAbuseFilterConditionLimit;

		// Ensure that we start fresh, see T193374
		$this->parser->resetCondCount();

		$matchedFilters = [];

		foreach ( $this->getLocalFilters() as $row ) {
			$matchedFilters[$row->af_id] = $this->checkFilter( $row );
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			foreach ( $this->getGlobalFilters() as $row ) {
				$matchedFilters[ AbuseFilter::buildGlobalName( $row->af_id ) ] =
					$this->checkFilter( $row, true );
			}
		}

		// Tag the action if the condition limit was hit
		$hitCondLimit = $this->parser->getCondCount() > $wgAbuseFilterConditionLimit;

		return $matchedFilters;
	}

	/**
	 * @return array abuse_filter DB rows
	 */
	protected function getLocalFilters() : array {
		return iterator_to_array( wfGetDB( DB_REPLICA )->select(
			'abuse_filter',
			AbuseFilter::ALL_ABUSE_FILTER_FIELDS,
			[
				'af_enabled' => 1,
				'af_deleted' => 0,
				'af_group' => $this->group,
			],
			__METHOD__
		) );
	}

	/**
	 * @return array abuse_filter rows from the foreign DB
	 */
	protected function getGlobalFilters() : array {
		$globalRulesKey = AbuseFilter::getGlobalRulesKey( $this->group );
		$fname = __METHOD__;

		return MediaWikiServices::getInstance()->getMainWANObjectCache()->getWithSetCallback(
			$globalRulesKey,
			WANObjectCache::TTL_WEEK,
			function () use ( $fname ) {
				$fdb = AbuseFilter::getCentralDB( DB_REPLICA );

				return iterator_to_array( $fdb->select(
					'abuse_filter',
					AbuseFilter::ALL_ABUSE_FILTER_FIELDS,
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
	}

	/**
	 * Check the conditions of a single filter, and profile it if $this->executeMode is true
	 *
	 * @param stdClass $row
	 * @param bool $global
	 * @return bool
	 */
	protected function checkFilter( $row, $global = false ) {
		$filterName = AbuseFilter::buildGlobalName( $row->af_id, $global );

		$startConds = $this->parser->getCondCount();
		$startTime = microtime( true );
		$origExtraTime = AFComputedVariable::$profilingExtraTime;

		// Store the row somewhere convenient
		AbuseFilter::cacheFilter( $filterName, $row );

		$pattern = trim( $row->af_pattern );
		$this->parser->setFilter( $filterName );
		$result = $this->parser->checkConditions( $pattern, true, $filterName );

		$actualExtra = AFComputedVariable::$profilingExtraTime - $origExtraTime;
		$timeTaken = 1000 * ( microtime( true ) - $startTime - $actualExtra );
		$condsUsed = $this->parser->getCondCount() - $startConds;

		$this->profilingData[$filterName] = [
			'time' => $timeTaken,
			'conds' => $condsUsed,
			'result' => $result
		];

		return $result;
	}

	/**
	 * @param array $result Result of the execution, as created in run()
	 * @param string[] $matchedFilters
	 * @param string[] $allFilters
	 */
	protected function profileExecution( array $result, array $matchedFilters, array $allFilters ) {
		$this->filterProfiler->checkResetProfiling( $this->group, $allFilters );
		$this->filterProfiler->recordRuntimeProfilingResult(
			count( $allFilters ),
			$result['condCount'],
			$result['runtime']
		);
		$this->filterProfiler->recordPerFilterProfiling( $this->title, $result['profiling'] );
		$this->filterProfiler->recordStats(
			$this->group,
			$result['condCount'],
			$result['runtime'],
			(bool)$matchedFilters
		);
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
		global $wgMainCacheType, $wgAbuseFilterLocallyDisabledGlobalActions,
			   $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;

		$actionsByFilter = AbuseFilter::getConsequencesForFilters( $filters, false );
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
			if ( isset( $actions['throttle'] ) && $wgMainCacheType !== CACHE_NONE ) {
				$parameters = $actions['throttle'];
				$throttleId = array_shift( $parameters );
				list( $rateCount, $ratePeriod ) = explode( ',', array_shift( $parameters ) );
				$rateCount = (int)$rateCount;
				$ratePeriod = (int)$ratePeriod;

				$hitThrottle = false;

				// The rest are throttle-types.
				foreach ( $parameters as $throttleType ) {
					$hitThrottle = $this->isThrottled( $throttleId, $throttleType, $rateCount, $isGlobalFilter )
						|| $hitThrottle;
					$this->setThrottled( $throttleId, $throttleType, $ratePeriod, $isGlobalFilter );
				}

				unset( $actions['throttle'] );
				if ( !$hitThrottle ) {
					$actionsTaken[$filter][] = 'throttle';
					continue;
				}
			}

			if ( $isGlobalFilter ) {
				$actions = array_diff_key(
					$actions,
					array_filter( $wgAbuseFilterLocallyDisabledGlobalActions )
				);
			}

			if ( isset( $actions['warn'] ) ) {
				$parameters = $actions['warn'];
				// Generate a unique key to determine whether the user has already been warned.
				// We'll warn again if one of these changes: session, page, triggered filter or action
				$warnKey = 'abusefilter-warned-' . md5( $this->title->getPrefixedText() ) .
					'-' . $filter . '-' . $this->action;

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

			// Don't show the disallow message if a blocking action is executed
			if ( count( array_intersect( array_keys( $actions ), AbuseFilter::getDangerousActions() ) ) > 0
				&& isset( $actions['disallow'] )
			) {
				unset( $actions['disallow'] );
			}

			// Find out the max expiry to issue the longest triggered block.
			// Need to check here since methods like user->getBlock() aren't available
			if ( isset( $actions['block'] ) ) {
				$parameters = $actions['block'];

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
				if ( $maxExpiry === -1 || $currentExpiry > SpecialBlock::parseExpiryInput( $maxExpiry ) ) {
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
					$info,
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
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->doBlock( $blockValues[0], $blockValues[1], $maxExpiry, $blockValues[2] );
			$message = [
				'abusefilter-blocked-display',
				$blockValues[0],
				$blockValues[1]
			];
			// Manually add the message. If we're here, there is one.
			$messages[] = $message;
			// @phan-suppress-next-line PhanTypeMismatchDimAssignment
			$actionsTaken[$blockValues[1]][] = 'block';
		}

		return $this->buildStatus( $actionsTaken, $messages );
	}

	/**
	 * Determines whether the throttle has been hit with the given parameters
	 *
	 * @param string $throttleId
	 * @param string $types
	 * @param int $rateCount
	 * @param bool $global
	 * @return bool
	 */
	protected function isThrottled( string $throttleId, string $types, int $rateCount, bool $global = false ) : bool {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		$key = $this->throttleKey( $throttleId, $types, $global );
		$newCount = (int)$stash->get( $key ) + 1;

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug(
			'New value is {newCount} for throttle key {key}. Maximum is {rateCount}.',
			[
				'newCount' => $newCount,
				'key' => $key,
				'rateCount' => $rateCount,
			]
		);

		return $newCount > $rateCount;
	}

	/**
	 * Updates the throttle status with the given parameters
	 *
	 * @param string $throttleId
	 * @param string $types
	 * @param int $ratePeriod
	 * @param bool $global
	 */
	protected function setThrottled( string $throttleId, string $types, int $ratePeriod, bool $global = false ) : void {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		$key = $this->throttleKey( $throttleId, $types, $global );
		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug(
			'Increasing throttle key {key}',
			[ 'key' => $key ]
		);
		$stash->incrWithInit( $key, $ratePeriod );
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
				$identifier = substr( IPUtils::toHex( $request->getIP() ), 0, 4 );
				break;
			case 'creationdate':
				$reg = (int)$this->user->getRegistration();
				$identifier = $reg - ( $reg % 86400 );
				break;
			case 'editcount':
				// Hack for detecting different single-purpose accounts.
				$identifier = (int)$this->user->getEditCount();
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
		global $wgAbuseFilterCustomActionsHandlers, $wgAbuseFilterBlockAutopromoteDuration;

		$message = null;

		switch ( $action ) {
			case 'disallow':
				$msg = $parameters[0] ?? 'abusefilter-disallowed';
				$message = [ $msg, $ruleDescription, $ruleNumber ];
				break;
			case 'rangeblock':
				$this->doRangeBlock( $ruleDescription, $ruleNumber, '1 week' );

				$message = [
					'abusefilter-blocked-display',
					$ruleDescription,
					$ruleNumber
				];
				break;
			case 'degroup':
				if ( !$this->user->isAnon() ) {
					$userGroupsManager = MediaWikiServices::getInstance()->getUserGroupManager();
					// Pull the groups from the VariableHolder, so that they will always be computed.
					// This allow us to pull the groups from the VariableHolder to undo the degroup
					// via Special:AbuseFilter/revert.
					$groups = $this->vars->getVar( 'user_groups', AbuseFilterVariableHolder::GET_LAX );
					if ( $groups->type !== AFPData::DARRAY ) {
						// Somehow, the variable wasn't set
						$groups = $userGroupsManager->getUserEffectiveGroups( $this->user );
						$this->vars->setVar( 'user_groups', $groups );
					} else {
						$groups = $groups->toNative();
					}
					$this->vars->setVar( 'user_groups', $groups );

					$implicitGroups = $userGroupsManager->listAllImplicitGroups();
					$removeGroups = array_diff( $groups, $implicitGroups );
					foreach ( $removeGroups as $group ) {
						$userGroupsManager->removeUserFromGroup( $this->user, $group );
					}

					$message = [
						'abusefilter-degrouped',
						$ruleDescription,
						$ruleNumber
					];

					// Don't log it if there aren't any groups being removed!
					if ( !count( $removeGroups ) ) {
						break;
					}

					// TODO Core should provide a logging method
					$logEntry = new ManualLogEntry( 'rights', 'rights' );
					$logEntry->setPerformer( $this->filterUser );
					$logEntry->setTarget( $this->user->getUserPage() );
					$logEntry->setComment(
						wfMessage(
							'abusefilter-degroupreason',
							$ruleDescription,
							$ruleNumber
						)->inContentLanguage()->text()
					);
					$logEntry->setParameters( [
						'4::oldgroups' => $removeGroups,
						'5::newgroups' => []
					] );
					$logEntry->publish( $logEntry->insert() );
				}

				break;
			case 'blockautopromote':
				if ( !$this->user->isAnon() ) {
					$duration = $wgAbuseFilterBlockAutopromoteDuration * 86400;
					$store = AbuseFilterServices::getBlockAutopromoteStore();
					$blocked = $store->blockAutoPromote(
						$this->user,
						wfMessage(
							'abusefilter-blockautopromotereason',
							$ruleDescription,
							$ruleNumber
						)->inContentLanguage()->text(),
						$duration
					);

					if ( $blocked ) {
						$message = [
							'abusefilter-autopromote-blocked',
							$ruleDescription,
							$ruleNumber,
							$duration
						];
					}
				}
				break;

			case 'block':
				// Do nothing, handled at the end of executeFilterActions. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				$this->changeTagger->addTags( $this->getSpecsForTagger(), $parameters );
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
	 * @param string $ruleDesc
	 * @param string|int $ruleNumber
	 * @param string $expiry
	 */
	private function doRangeBlock( $ruleDesc, $ruleNumber, $expiry ) {
		global $wgAbuseFilterRangeBlockSize, $wgBlockCIDRLimit;

		$ip = RequestContext::getMain()->getRequest()->getIP();
		$type = IPUtils::isIPv6( $ip ) ? 'IPv6' : 'IPv4';
		$CIDRsize = max( $wgAbuseFilterRangeBlockSize[$type], $wgBlockCIDRLimit[$type] );
		$blockCIDR = $ip . '/' . $CIDRsize;

		$target = IPUtils::sanitizeRange( $blockCIDR );
		$autoblock = false;
		$this->doBlockInternal( $ruleDesc, $ruleNumber, $target, $expiry, $autoblock, false );
	}

	/**
	 * @param string $ruleDesc
	 * @param string|int $ruleNumber
	 * @param string $expiry
	 * @param bool $preventsTalk
	 */
	private function doBlock( $ruleDesc, $ruleNumber, $expiry, $preventsTalk ) {
		$target = $this->user->getName();
		$autoblock = true;
		$this->doBlockInternal( $ruleDesc, $ruleNumber, $target, $expiry, $autoblock, $preventsTalk );
	}

	/**
	 * Perform a block by the AbuseFilter system user
	 * @param string $ruleDesc
	 * @param int|string $ruleNumber
	 * @param string $target
	 * @param string $expiry
	 * @param bool $isAutoBlock
	 * @param bool $preventEditOwnUserTalk
	 */
	private function doBlockInternal(
		$ruleDesc,
		$ruleNumber,
		$target,
		$expiry,
		$isAutoBlock,
		$preventEditOwnUserTalk
	) {
		$blockUserFactory = MediaWikiServices::getInstance()->getBlockUserFactory();
		$reason = wfMessage(
			'abusefilter-blockreason',
			$ruleDesc, $ruleNumber
		)->inContentLanguage()->text();

		$blockUserFactory->newBlockUser(
			$target,
			User::newFromIdentity( $this->filterUser ),
			$expiry,
			$reason,
			[
				'isHardBlock' => false,
				'isAutoblocking' => $isAutoBlock,
				'isCreateAccountBlocked' => true,
				'isUserTalkEditBlocked' => $preventEditOwnUserTalk
			]
		)->placeBlockUnsafe();
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
			'afl_action' => $this->action,
			'afl_ip' => $wgAbuseFilterLogIP ? $request->getIP() : ''
		];
		// Hack to avoid revealing IPs of people creating accounts
		if (
			!$user->getId() &&
			( $this->action === 'createaccount' || $this->action === 'autocreateaccount' )
		) {
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
			'afl_wiki' => WikiMap::getCurrentWikiDbDomain()->getId(),
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
		$varDump = "tt:$varDump";

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
				global $wgCheckUserLogAdditionalRights;
				$wgCheckUserLogAdditionalRights[] = 'abusefilter-view';
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
				},
				$method
			);
		}

		$globalLogIDs = [];

		// Global stuff
		if ( count( $loggedGlobalFilters ) ) {
			$this->vars->computeDBVars();
			$globalVarDump = AbuseFilter::storeVarDump( $this->vars, true );
			$globalVarDump = "tt:$globalVarDump";
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
				},
				$method
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
		// @ToDo this is an amount between 1 and AbuseFilterProfileActionsCap, which means that the
		// reliability of this number may strongly vary. We should instead use a fixed one.
		$groupProfile = $this->filterProfiler->getGroupProfile( $this->group );
		$totalActions = $groupProfile['total'];

		foreach ( $filters as $filter ) {
			$threshold = AbuseFilter::getEmergencyValue( 'threshold', $this->group );
			$hitCountLimit = AbuseFilter::getEmergencyValue( 'count', $this->group );
			$maxAge = AbuseFilter::getEmergencyValue( 'age', $this->group );

			$filterProfile = $this->filterProfiler->getFilterProfile( $filter );
			// xxx: should this increment be here?
			$matchCount = $filterProfile['matches'] + 1;

			// Figure out if the filter is subject to being throttled.
			$filterAge = (int)wfTimestamp( TS_UNIX, AbuseFilter::getFilter( $filter )->af_timestamp );
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
	 * @return array
	 */
	private function getSpecsForTagger() : array {
		return [
			'action' => $this->action,
			'username' => $this->user->getName(),
			'target' => $this->title,
			'accountname' => $this->vars->getVar(
				'accountname',
				AbuseFilterVariableHolder::GET_BC
			)->toNative()
		];
	}
}
