<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Consequence\BCConsequence;
use MediaWiki\Extension\AbuseFilter\Consequence\Consequence;
use MediaWiki\Extension\AbuseFilter\Consequence\ConsequencesDisablerConsequence;
use MediaWiki\Extension\AbuseFilter\Consequence\HookAborterConsequence;
use MediaWiki\Extension\AbuseFilter\Consequence\Parameters;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Extension\AbuseFilter\Watcher\Watcher;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * This class contains the logic for executing abuse filters and their actions. The entry points are
 * run() and runForStash(). Note that run() can only be executed once on a given instance.
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

	/** @var FilterLookup */
	private $filterLookup;

	/** @var Watcher[] */
	private $watchers;

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
		$this->filterLookup = AbuseFilterServices::getFilterLookup();
		// TODO Inject, add a hook for custom watchers
		$this->watchers = [
			AbuseFilterServices::getUpdateHitCountWatcher(),
			AbuseFilterServices::getEmergencyWatcher()
		];
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
		return AbuseFilterServices::getParserFactory()->newParser( $this->vars );
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

		$abuseLogger = AbuseFilterServices::getAbuseLoggerFactory()->newLogger(
			$this->title,
			$this->user,
			$this->vars
		);
		[
			'local' => $loggedLocalFilters,
			'global' => $loggedGlobalFilters
		] = $abuseLogger->addLogEntries( $actionsTaken );

		foreach ( $this->watchers as $watcher ) {
			$watcher->run( $loggedLocalFilters, $loggedGlobalFilters, $this->group );
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
	 * @param bool|null &$hitCondLimit TEMPORARY
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public function checkAllFilters( &$hitCondLimit = false ) : array {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral, $wgAbuseFilterConditionLimit;

		// Ensure that we start fresh, see T193374
		$this->parser->resetCondCount();

		$matchedFilters = [];
		foreach ( $this->filterLookup->getAllActiveFiltersInGroup( $this->group, false ) as $filter ) {
			// @phan-suppress-next-line PhanTypeMismatchDimAssignment
			$matchedFilters[$filter->getID()] = $this->checkFilter( $filter );
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			foreach ( $this->filterLookup->getAllActiveFiltersInGroup( $this->group, true ) as $filter ) {
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
				$matchedFilters[ AbuseFilter::buildGlobalName( $filter->getID() ) ] =
					$this->checkFilter( $filter, true );
			}
		}

		// Tag the action if the condition limit was hit
		$hitCondLimit = $this->parser->getCondCount() > $wgAbuseFilterConditionLimit;

		return $matchedFilters;
	}

	/**
	 * Check the conditions of a single filter, and profile it if $this->executeMode is true
	 *
	 * @param Filter $filter
	 * @param bool $global
	 * @return bool
	 */
	protected function checkFilter( Filter $filter, $global = false ) {
		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$filterName = AbuseFilter::buildGlobalName( $filter->getID(), $global );

		$startConds = $this->parser->getCondCount();
		$startTime = microtime( true );
		$origExtraTime = AFComputedVariable::$profilingExtraTime;

		$this->parser->setFilter( $filterName );
		$result = $this->parser->checkConditions( $filter->getRules(), true, $filterName );

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
		$consLookup = AbuseFilterServices::getConsequencesLookup();
		$actionsByFilter = $consLookup->getConsequencesForFilters( $filters );
		$consequences = $this->replaceArraysWithConsequences( $actionsByFilter );
		$actionsToTake = $this->getFilteredConsequences( $consequences );
		$actionsTaken = array_fill_keys( $filters, [] );

		$messages = [];
		foreach ( $actionsToTake as $filter => $actions ) {
			foreach ( $actions as $action => $info ) {
				[ $executed, $newMsg ] = $this->takeConsequenceAction( $info );

				if ( $newMsg !== null ) {
					$messages[] = $newMsg;
				}
				if ( $executed ) {
					$actionsTaken[$filter][] = $action;
				}
			}
		}

		return $this->buildStatus( $actionsTaken, $messages );
	}

	/**
	 * Remove consequences that we already know won't be executed. This includes:
	 * - Only keep the longest block from all filters
	 * - For global filters, remove locally disabled actions
	 * - For every filter, remove "disallow" if a blocking action will be executed
	 * Then, convert the remaining ones to Consequence objects.
	 *
	 * @param array[] $actionsByFilter
	 * @return Consequence[][]
	 * @internal Temporarily public
	 */
	public function replaceArraysWithConsequences( array $actionsByFilter ) : array {
		global $wgAbuseFilterLocallyDisabledGlobalActions,
			   $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;

		// Keep track of the longest block
		$maxBlock = [ 'id' => null, 'expiry' => -1, 'blocktalk' => null ];
		$dangerousActions = AbuseFilterServices::getConsequencesRegistry()->getDangerousActionNames();

		foreach ( $actionsByFilter as $filter => &$actions ) {
			$isGlobalFilter = AbuseFilter::splitGlobalName( $filter )[1];

			if ( $isGlobalFilter ) {
				$actions = array_diff_key( $actions, array_filter( $wgAbuseFilterLocallyDisabledGlobalActions ) );
			}

			// Don't show the disallow message if a blocking action is executed
			if ( array_intersect( array_keys( $actions ), $dangerousActions )
				&& isset( $actions['disallow'] )
			) {
				unset( $actions['disallow'] );
			}

			foreach ( $actions as $name => $parameters ) {
				switch ( $name ) {
					case 'throttle':
					case 'warn':
					case 'disallow':
					case 'rangeblock':
					case 'degroup':
					case 'blockautopromote':
					case 'tag':
						$actions[$name] = $this->actionsParamsToConsequence( $name, $parameters, $filter );
						break;
					case 'block':
						// TODO Move to a dedicated method and/or create a generic interface
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

						$parsedExpiry = SpecialBlock::parseExpiryInput( $expiry );
						if (
							$maxBlock['expiry'] === -1 ||
							$parsedExpiry > SpecialBlock::parseExpiryInput( $maxBlock['expiry'] )
						) {
							// Save the parameters to issue the block with
							$maxBlock = [
								'id' => $filter,
								'expiry' => $expiry,
								'blocktalk' => is_array( $parameters ) && in_array( 'blocktalk', $parameters )
							];
						}
						// We'll re-add it later
						unset( $actions['block'] );
						break;
					default:
						$cons = $this->actionsParamsToConsequence( $name, $parameters, $filter );
						if ( $cons !== null ) {
							$actions[$name] = $cons;
						} else {
							unset( $actions[$name] );
						}
				}
			}
		}
		unset( $actions );

		if ( $maxBlock['id'] !== null ) {
			$id = $maxBlock['id'];
			unset( $maxBlock['id'] );
			$actionsByFilter[$id]['block'] = $this->actionsParamsToConsequence( 'block', $maxBlock, $id );
		}

		return $actionsByFilter;
	}

	/**
	 * @param string $actionName
	 * @param array $rawParams
	 * @param int|string $filter
	 * @return Consequence|null
	 */
	private function actionsParamsToConsequence( string $actionName, array $rawParams, $filter ) : ?Consequence {
		global $wgAbuseFilterBlockAutopromoteDuration, $wgAbuseFilterCustomActionsHandlers;
		[ $filterID, $isGlobalFilter ] = AbuseFilter::splitGlobalName( $filter );
		$filterObj = $this->filterLookup->getFilter( $filterID, $isGlobalFilter );
		$consFactory = AbuseFilterServices::getConsequencesFactory();

		$baseConsParams = new Parameters(
			$filterObj,
			$isGlobalFilter,
			$this->user,
			$this->title,
			$this->action
		);

		switch ( $actionName ) {
			case 'throttle':
				$throttleId = array_shift( $rawParams );
				list( $rateCount, $ratePeriod ) = explode( ',', array_shift( $rawParams ) );

				$throttleParams = [
					'id' => $throttleId,
					'count' => (int)$rateCount,
					'period' => (int)$ratePeriod,
					'groups' => $rawParams,
					'global' => $isGlobalFilter
				];
				return $consFactory->newThrottle( $baseConsParams, $throttleParams );
			case 'warn':
				return $consFactory->newWarn( $baseConsParams, $rawParams[0] ?? 'abusefilter-warning' );
			case 'disallow':
				return $consFactory->newDisallow( $baseConsParams, $rawParams[0] ?? 'abusefilter-disallowed' );
			case 'rangeblock':
				return $consFactory->newRangeBlock( $baseConsParams, '1 week' );
			case 'degroup':
				return $consFactory->newDegroup( $baseConsParams, $this->vars );
			case 'blockautopromote':
				$duration = $wgAbuseFilterBlockAutopromoteDuration * 86400;
				return $consFactory->newBlockAutopromote( $baseConsParams, $duration );
			case 'block':
				return $consFactory->newBlock( $baseConsParams, $rawParams['expiry'], $rawParams['blocktalk'] );
			case 'tag':
				$accountName = $this->vars->getVar( 'accountname', AbuseFilterVariableHolder::GET_BC )->toNative();
				return $consFactory->newTag( $baseConsParams, $accountName, $rawParams );
			default:
				$registry = AbuseFilterServices::getConsequencesRegistry();
				if ( array_key_exists( $actionName, $registry->getCustomActions() ) ) {
					$callback = $registry->getCustomActions()[$actionName];
					return $callback( $baseConsParams, $rawParams );
				} elseif ( isset( $wgAbuseFilterCustomActionsHandlers[$actionName] ) ) {
					wfDeprecated(
						'$wgAbuseFilterCustomActionsHandlers; use the AbuseFilterCustomActions hook instead',
						'1.36'
					);
					$customFunction = $wgAbuseFilterCustomActionsHandlers[$actionName];
					return new BCConsequence( $baseConsParams, $rawParams, $this->vars, $customFunction );
				} else {
					$logger = LoggerFactory::getInstance( 'AbuseFilter' );
					$logger->warning( "Unrecognised action $actionName" );
					return null;
				}
		}
	}

	/**
	 * Pre-check any "special" consequence and remove any further actions prevented by them. Specifically:
	 * should be actually executed. Normalizations done here:
	 * - For every filter with "throttle" enabled, remove other actions if the throttle counter hasn't been reached
	 * - For every filter with "warn" enabled, remove other actions if the warning hasn't been shown
	 *
	 * @param Consequence[][] $actionsByFilter
	 * @return Consequence[][]
	 * @internal Temporary method
	 */
	public function getFilteredConsequences( array $actionsByFilter ) : array {
		foreach ( $actionsByFilter as $filter => $actions ) {
			/** @var ConsequencesDisablerConsequence[] $consequenceDisablers */
			$consequenceDisablers = array_filter( $actions, function ( $el ) {
				return $el instanceof ConsequencesDisablerConsequence;
			} );
			'@phan-var ConsequencesDisablerConsequence[] $consequenceDisablers';
			uasort(
				$consequenceDisablers,
				function ( ConsequencesDisablerConsequence $x, ConsequencesDisablerConsequence $y ) {
					return $x->getSort() - $y->getSort();
				}
			);
			foreach ( $consequenceDisablers as $name => $consequence ) {
				if ( $consequence->shouldDisableOtherConsequences() ) {
					$actionsByFilter[$filter] = [ $name => $consequence ];
					continue 2;
				}
			}
		}

		return $actionsByFilter;
	}

	/**
	 * @param Consequence $consequence
	 * @return array [ Executed (bool), Message (?array) ] The message is given as an array
	 *   containing the message key followed by any message parameters.
	 * @todo Improve return value
	 */
	protected function takeConsequenceAction( Consequence $consequence ) : array {
		// Special case
		if ( $consequence instanceof BCConsequence ) {
			$consequence->execute();
			try {
				$message = $consequence->getMessage();
			} catch ( LogicException $_ ) {
				// Swallow. Sigh.
				$message = null;
			}
			return [ true, $message ];
		}

		$res = $consequence->execute();
		if ( $res && $consequence instanceof HookAborterConsequence ) {
			$message = $consequence->getMessage();
		}

		return [ $res, $message ?? null ];
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
