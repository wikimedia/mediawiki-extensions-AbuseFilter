<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;
use MediaWiki\Extension\AbuseFilter\Parser\UserVisibleWarning;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Parser\ParserStatus
 */
class ParserStatusTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::getResult
	 * @covers ::getWarmCache
	 * @covers ::getException
	 * @covers ::getWarnings
	 */
	public function testGetters() {
		$result = true;
		$warm = false;
		$exc = new AFPException();
		$warnings = [ new UserVisibleWarning( 'foo', 1, [] ) ];
		$status = new ParserStatus( $result, $warm, $exc, $warnings );
		$this->assertSame( $result, $status->getResult() );
		$this->assertSame( $warm, $status->getWarmCache() );
		$this->assertSame( $exc, $status->getException() );
		$this->assertSame( $warnings, $status->getWarnings() );
	}
}
