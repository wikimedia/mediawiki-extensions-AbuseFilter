<?php

namespace MediaWiki\Extension\AbuseFilter;

use BadMethodCallException;
use BagOStuff;
use IBufferingStatsdDataFactory;
use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory;
use MediaWiki\Extension\AbuseFilter\Filter\ExistingFilter;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Extension\AbuseFilter\Watcher\Watcher;
use MediaWiki\Logger\LoggerFactory;
use NullStatsdDataFactory;
use ObjectCache;
use Psr\Log\LoggerInterface;
use Status;
use Title;
use User;

/**
 * This class contains the logic for executing abuse filters and their actions. The entry points are
 * run() and runForStash(). Note that run() can only be executed once on a given instance.
 * @internal Not stable yet
 */
class FilterRunner {
	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterValidGroups',
		'AbuseFilterCentralDB',
		'AbuseFilterIsCentral',
		'AbuseFilterConditionLimit',
	];

	/** @var AbuseFilterHookRunner */
	private $hookRunner;
	/** @var FilterProfiler */
	private $filterProfiler;
	/** @var ChangeTagger */
	private $changeTagger;
	/** @var FilterLookup */
	private $filterLookup;
	/** @var ParserFactory */
	private $parserFactory;
	/** @var ConsequencesExecutorFactory */
	private $consExecutorFactory;
	/** @var AbuseLoggerFactory */
	private $abuseLoggerFactory;
	/** @var Watcher[] */
	private $watchers;
	/** @var LoggerInterface */
	private $logger;
	/** @var IBufferingStatsdDataFactory */
	private $statsdDataFactory;
	/** @var VariablesManager */
	private $varManager;
	/** @var VariableGeneratorFactory */
	private $varGeneratorFactory;
	/** @var ServiceOptions */
	private $options;

	/**
	 * @var AbuseFilterParser
	 * @private Temporarily public for BC
	 */
	public $parser;

	/**
	 * @var User The user who performed the action being filtered
	 */
	protected $user;
	/**
	 * @var Title The title where the action being filtered was performed
	 */
	protected $title;
	/**
	 * @var VariableHolder The variables for the current action
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
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param FilterProfiler $filterProfiler
	 * @param ChangeTagger $changeTagger
	 * @param FilterLookup $filterLookup
	 * @param ParserFactory $parserFactory
	 * @param ConsequencesExecutorFactory $consExecutorFactory
	 * @param AbuseLoggerFactory $abuseLoggerFactory
	 * @param VariablesManager $varManager
	 * @param VariableGeneratorFactory $varGeneratorFactory
	 * @param Watcher[] $watchers
	 * @param LoggerInterface $logger
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param ServiceOptions $options
	 * @param User $user
	 * @param Title $title
	 * @param VariableHolder $vars
	 * @param string $group
	 * @throws InvalidArgumentException If $group is invalid or the 'action' variable is unset
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		FilterProfiler $filterProfiler,
		ChangeTagger $changeTagger,
		FilterLookup $filterLookup,
		ParserFactory $parserFactory,
		ConsequencesExecutorFactory $consExecutorFactory,
		AbuseLoggerFactory $abuseLoggerFactory,
		VariablesManager $varManager,
		VariableGeneratorFactory $varGeneratorFactory,
		array $watchers,
		LoggerInterface $logger,
		IBufferingStatsdDataFactory $statsdDataFactory,
		ServiceOptions $options,
		User $user,
		Title $title,
		VariableHolder $vars,
		string $group
	) {
		$this->hookRunner = $hookRunner;
		$this->filterProfiler = $filterProfiler;
		$this->changeTagger = $changeTagger;
		$this->filterLookup = $filterLookup;
		$this->parserFactory = $parserFactory;
		$this->consExecutorFactory = $consExecutorFactory;
		$this->abuseLoggerFactory = $abuseLoggerFactory;
		$this->varManager = $varManager;
		$this->varGeneratorFactory = $varGeneratorFactory;
		$this->watchers = $watchers;
		$this->logger = $logger;
		$this->statsdDataFactory = $statsdDataFactory;

		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		if ( !in_array( $group, $options->get( 'AbuseFilterValidGroups' ), true ) ) {
			throw new InvalidArgumentException( "Group $group is not a valid group" );
		}
		$this->options = $options;
		if ( !$vars->varIsSet( 'action' ) ) {
			throw new InvalidArgumentException( "The 'action' variable is not set." );
		}
		$this->user = $user;
		$this->title = $title;
		$this->vars = $vars;
		$this->group = $group;
		$this->action = $vars->getComputedVariable( 'action' )->toString();
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
		$generator = $this->varGeneratorFactory->newGenerator( $this->vars );
		$this->vars = $generator->addGenericVars()->getVariableHolder();

		$this->vars->forFilter = true;
		$this->vars->setVar( 'timestamp', (int)wfTimestamp( TS_UNIX ) );
		$this->parser = $this->parserFactory->newParser( $this->vars );
		$this->parser->setStatsd( $this->statsdDataFactory );
		$this->profilingData = [];
	}

	/**
	 * The main entry point of this class. This method runs all filters and takes their consequences.
	 *
	 * @param bool $allowStash Whether we are allowed to check the cache to see if there's a cached
	 *  result of a previous execution for the same edit.
	 * @throws BadMethodCallException If run() was already called on this instance
	 * @return Status Good if no action has been taken, a fatal otherwise.
	 */
	public function run( $allowStash = true ): Status {
		$this->init();

		$skipReasons = [];
		$shouldFilter = $this->hookRunner->onAbuseFilterShouldFilterAction(
			$this->vars, $this->title, $this->user, $skipReasons
		);
		if ( !$shouldFilter ) {
			$this->logger->info(
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
				$this->vars = VariableHolder::newFromArray( $cacheData['vars'] );
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
			LazyVariableComputer::$profilingExtraTime = 0;

			$hitCondLimit = false;
			// This also updates $this->profilingData and $this->parser->mCondCount used later
			$matches = $this->checkAllFilters( $hitCondLimit );
			$timeTaken = ( microtime( true ) - $startTime - LazyVariableComputer::$profilingExtraTime ) * 1000;
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

		$executor = $this->consExecutorFactory->newExecutor(
			$this->user,
			$this->title,
			$this->vars
		);
		$status = $executor->executeFilterActions( $matchedFilters );
		$actionsTaken = $status->getValue();

		// Note, it's important that we create an AbuseLogger now, after all lazy-loaded variables
		// requested by active filters have been computed
		$abuseLogger = $this->abuseLoggerFactory->newLogger( $this->title, $this->user, $this->vars );
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
		LazyVariableComputer::$profilingExtraTime = 0;

		$hitCondLimit = false;
		$matchedFilters = $this->checkAllFilters( $hitCondLimit );
		// Save the filter stash result and do nothing further
		$cacheData = [
			'matches' => $matchedFilters,
			'hitCondLimit' => $hitCondLimit,
			'condCount' => $this->parser->getCondCount(),
			'runtime' => ( microtime( true ) - $startTime - LazyVariableComputer::$profilingExtraTime ) * 1000,
			'vars' => $this->varManager->dumpAllVars( $this->vars ),
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
		$inputVars = $this->varManager->exportNonLazyVars( $this->vars );
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
			: $this->statsdDataFactory;

		$logger->debug( __METHOD__ . ": cache $type for '{$this->title}' (key $key)." );
		$statsd->increment( "abusefilter.check-stash.$type" );
	}

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @protected Public for back compat only; this will actually be made protected in the future.
	 * @param bool|null &$hitCondLimit TEMPORARY
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public function checkAllFilters( &$hitCondLimit = false ) : array {
		// Ensure that we start fresh, see T193374
		$this->parser->resetCondCount();

		$matchedFilters = [];
		foreach ( $this->filterLookup->getAllActiveFiltersInGroup( $this->group, false ) as $filter ) {
			$matchedFilters[$filter->getID()] = $this->checkFilter( $filter );
		}

		if ( $this->options->get( 'AbuseFilterCentralDB' ) && !$this->options->get( 'AbuseFilterIsCentral' ) ) {
			foreach ( $this->filterLookup->getAllActiveFiltersInGroup( $this->group, true ) as $filter ) {
				$matchedFilters[GlobalNameUtils::buildGlobalName( $filter->getID() )] =
					$this->checkFilter( $filter, true );
			}
		}

		// Tag the action if the condition limit was hit
		// TODO: Check can be moved to callers
		$hitCondLimit = $this->parser->getCondCount() > $this->options->get( 'AbuseFilterConditionLimit' );

		return $matchedFilters;
	}

	/**
	 * Check the conditions of a single filter, and profile it if $this->executeMode is true
	 *
	 * @param ExistingFilter $filter
	 * @param bool $global
	 * @return bool
	 */
	protected function checkFilter( ExistingFilter $filter, $global = false ) {
		$filterName = GlobalNameUtils::buildGlobalName( $filter->getID(), $global );

		$startConds = $this->parser->getCondCount();
		$startTime = microtime( true );
		$origExtraTime = LazyVariableComputer::$profilingExtraTime;

		$this->parser->setFilter( $filterName );
		$result = $this->parser->checkConditions( $filter->getRules(), $filterName )->getResult();

		$actualExtra = LazyVariableComputer::$profilingExtraTime - $origExtraTime;
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
	 * @return array
	 */
	private function getSpecsForTagger() : array {
		return [
			'action' => $this->action,
			'username' => $this->user->getName(),
			'target' => $this->title,
			'accountname' => $this->varManager->getVar(
				$this->vars,
				'accountname',
				VariablesManager::GET_BC
			)->toNative()
		];
	}
}
