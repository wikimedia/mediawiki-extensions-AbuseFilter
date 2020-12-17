<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilterVariableHolder;
use IBufferingStatsdDataFactory;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use Psr\Log\LoggerInterface;
use Title;
use User;

class FilterRunnerFactory {
	public const SERVICE_NAME = 'AbuseFilterRunnerFactory';

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
	/** @var UpdateHitCountWatcher */
	private $updateHitCountWatcher;
	/** @var EmergencyWatcher */
	private $emergencyWatcher;
	/** @var LoggerInterface */
	private $logger;
	/** @var IBufferingStatsdDataFactory */
	private $statsdDataFactory;
	/** @var string[] */
	private $validGroups;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param FilterProfiler $filterProfiler
	 * @param ChangeTagger $changeTagger
	 * @param FilterLookup $filterLookup
	 * @param ParserFactory $parserFactory
	 * @param ConsequencesExecutorFactory $consExecutorFactory
	 * @param AbuseLoggerFactory $abuseLoggerFactory
	 * @param UpdateHitCountWatcher $updateHitCountWatcher
	 * @param EmergencyWatcher $emergencyWatcher
	 * @param LoggerInterface $logger
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param array $validFilterGroups
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		FilterProfiler $filterProfiler,
		ChangeTagger $changeTagger,
		FilterLookup $filterLookup,
		ParserFactory $parserFactory,
		ConsequencesExecutorFactory $consExecutorFactory,
		AbuseLoggerFactory $abuseLoggerFactory,
		UpdateHitCountWatcher $updateHitCountWatcher,
		EmergencyWatcher $emergencyWatcher,
		LoggerInterface $logger,
		IBufferingStatsdDataFactory $statsdDataFactory,
		array $validFilterGroups
	) {
		$this->hookRunner = $hookRunner;
		$this->filterProfiler = $filterProfiler;
		$this->changeTagger = $changeTagger;
		$this->filterLookup = $filterLookup;
		$this->parserFactory = $parserFactory;
		$this->consExecutorFactory = $consExecutorFactory;
		$this->abuseLoggerFactory = $abuseLoggerFactory;
		$this->updateHitCountWatcher = $updateHitCountWatcher;
		$this->emergencyWatcher = $emergencyWatcher;
		$this->logger = $logger;
		$this->statsdDataFactory = $statsdDataFactory;
		$this->validGroups = $validFilterGroups;
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $group
	 * @return FilterRunner
	 */
	public function newRunner(
		User $user,
		Title $title,
		AbuseFilterVariableHolder $vars,
		string $group
	) : FilterRunner {
		// TODO Add a method to this class taking these as params? Add a hook for custom watchers
		$watchers = [ $this->updateHitCountWatcher, $this->emergencyWatcher ];
		return new FilterRunner(
			$this->hookRunner,
			$this->filterProfiler,
			$this->changeTagger,
			$this->filterLookup,
			$this->parserFactory,
			$this->consExecutorFactory,
			$this->abuseLoggerFactory,
			$watchers,
			$this->logger,
			$this->statsdDataFactory,
			$this->validGroups,
			$user,
			$title,
			$vars,
			$group
		);
	}
}
