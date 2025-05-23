<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\Exception\InternalException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleWarning;
use MediaWiki\Extension\AbuseFilter\Parser\RuleCheckerStatus;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\RuleCheckerStatus
 */
class RuleCheckerStatusTest extends MediaWikiUnitTestCase {

	public function testGetters() {
		$result = true;
		$warm = false;
		$exc = $this->createMock( UserVisibleException::class );
		$warnings = [ new UserVisibleWarning( 'foo', 1, [] ) ];
		$condsUsed = 42;
		$status = new RuleCheckerStatus( $result, $warm, $exc, $warnings, $condsUsed );
		$this->assertSame( $result, $status->getResult() );
		$this->assertSame( $warm, $status->getWarmCache() );
		$this->assertSame( $exc, $status->getException() );
		$this->assertSame( $warnings, $status->getWarnings() );
		$this->assertSame( $condsUsed, $status->getCondsUsed() );
	}

	public static function provideToArrayException() {
		yield 'exception instance' => [ InternalException::class ];
		yield 'null' => [ null ];
	}

	/**
	 * @dataProvider provideToArrayException
	 */
	public function testToArrayRoundTrip( ?string $exception ) {
		$status = new RuleCheckerStatus(
			true,
			false,
			$exception ? new $exception() : null,
			[ new UserVisibleWarning( 'foo', 1, [] ) ],
			42
		);
		$newStatus = RuleCheckerStatus::fromArray( $status->toArray() );
		$this->assertSame( $status->getResult(), $newStatus->getResult() );
		$this->assertSame( $status->getWarmCache(), $newStatus->getWarmCache() );
		if ( $exception !== null ) {
			$this->assertInstanceOf( $exception, $newStatus->getException() );
		} else {
			$this->assertNull( $newStatus->getException() );
		}
		$this->assertContainsOnlyInstancesOf( UserVisibleWarning::class, $newStatus->getWarnings() );
		$this->assertSame( $status->getCondsUsed(), $newStatus->getCondsUsed() );
	}
}
