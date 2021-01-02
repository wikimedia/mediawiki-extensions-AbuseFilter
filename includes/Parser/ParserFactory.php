<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

use BagOStuff;
use Language;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use Psr\Log\LoggerInterface;

class ParserFactory {
	public const SERVICE_NAME = 'AbuseFilterParserFactory';

	/** @var Language */
	private $contLang;

	/** @var BagOStuff */
	private $cache;

	/** @var LoggerInterface */
	private $logger;

	/** @var KeywordsManager */
	private $keywordsManager;

	/** @var VariablesManager */
	private $varManager;

	/** @var string */
	private $parserClass;

	/** @var int */
	private $conditionsLimit;

	/**
	 * @param Language $contLang
	 * @param BagOStuff $cache
	 * @param LoggerInterface $logger
	 * @param KeywordsManager $keywordsManager
	 * @param VariablesManager $varManager
	 * @param string $parserClass
	 * @param int $conditionsLimit
	 */
	public function __construct(
		Language $contLang,
		BagOStuff $cache,
		LoggerInterface $logger,
		KeywordsManager $keywordsManager,
		VariablesManager $varManager,
		string $parserClass,
		int $conditionsLimit
	) {
		$this->contLang = $contLang;
		$this->cache = $cache;
		$this->logger = $logger;
		$this->keywordsManager = $keywordsManager;
		$this->varManager = $varManager;
		$this->parserClass = $parserClass;
		$this->conditionsLimit = $conditionsLimit;
	}

	/**
	 * @param VariableHolder|null $vars
	 * @return AbuseFilterParser
	 */
	public function newParser( VariableHolder $vars = null ) : AbuseFilterParser {
		$class = "\MediaWiki\Extension\AbuseFilter\Parser\\{$this->parserClass}";
		return new $class(
			$this->contLang,
			$this->cache,
			$this->logger,
			$this->keywordsManager,
			$this->varManager,
			$this->conditionsLimit,
			$vars
		);
	}
}
