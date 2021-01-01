<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

use AbuseFilterVariableHolder;
use BagOStuff;
use Language;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
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

	/** @var string */
	private $parserClass;

	/** @var int */
	private $conditionsLimit;

	/**
	 * @param Language $contLang
	 * @param BagOStuff $cache
	 * @param LoggerInterface $logger
	 * @param KeywordsManager $keywordsManager
	 * @param string $parserClass
	 * @param int $conditionsLimit
	 */
	public function __construct(
		Language $contLang,
		BagOStuff $cache,
		LoggerInterface $logger,
		KeywordsManager $keywordsManager,
		string $parserClass,
		int $conditionsLimit
	) {
		$this->contLang = $contLang;
		$this->cache = $cache;
		$this->logger = $logger;
		$this->keywordsManager = $keywordsManager;
		$this->parserClass = $parserClass;
		$this->conditionsLimit = $conditionsLimit;
	}

	/**
	 * @param AbuseFilterVariableHolder|null $vars
	 * @return AbuseFilterParser
	 */
	public function newParser( AbuseFilterVariableHolder $vars = null ) : AbuseFilterParser {
		$class = "\MediaWiki\Extension\AbuseFilter\Parser\\{$this->parserClass}";
		return new $class(
			$this->contLang,
			$this->cache,
			$this->logger,
			$this->keywordsManager,
			$this->conditionsLimit,
			$vars
		);
	}
}
