<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Extension\AbuseFilter\Parser\AFPInternalException;
use MediaWiki\Extension\AbuseFilter\Parser\AFPUserVisibleException;
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
		$exc = $this->createMock( AFPUserVisibleException::class );
		$warnings = [ new UserVisibleWarning( 'foo', 1, [] ) ];
		$status = new ParserStatus( $result, $warm, $exc, $warnings );
		$this->assertSame( $result, $status->getResult() );
		$this->assertSame( $warm, $status->getWarmCache() );
		$this->assertSame( $exc, $status->getException() );
		$this->assertSame( $warnings, $status->getWarnings() );
	}

	public function provideToArrayException() {
		yield 'exception instance' => [ new AFPInternalException() ];
		yield 'null' => [ null ];
	}

	/**
	 * @dataProvider provideToArrayException
	 * @covers ::toArray
	 * @covers ::fromArray
	 */
	public function testToArrayRoundTrip( ?AFPException $exception ) {
		$status = new ParserStatus(
			true,
			false,
			$exception,
			[ new UserVisibleWarning( 'foo', 1, [] ) ]
		);
		$newStatus = ParserStatus::fromArray( $status->toArray() );
		$this->assertSame( $status->getResult(), $newStatus->getResult() );
		$this->assertSame( $status->getWarmCache(), $newStatus->getWarmCache() );
		if ( $exception !== null ) {
			$this->assertInstanceOf( get_class( $exception ), $newStatus->getException() );
		} else {
			$this->assertNull( $newStatus->getException() );
		}
		$this->assertContainsOnlyInstancesOf( UserVisibleWarning::class, $newStatus->getWarnings() );
	}
}
