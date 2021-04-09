<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use BagOStuff;
use Language;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Parser\ParserFactory
 */
class ParserFactoryTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::__construct
	 * @covers ::newParser
	 */
	public function testNewParser() {
		$factory = new ParserFactory(
			$this->createMock( Language::class ),
			$this->createMock( BagOStuff::class ),
			new NullLogger(),
			$this->createMock( KeywordsManager::class ),
			$this->createMock( VariablesManager::class ),
			1000
		);
		$this->assertInstanceOf( AbuseFilterCachingParser::class, $factory->newParser() );
	}
}
