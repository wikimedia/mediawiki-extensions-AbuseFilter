<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\AFPUserVisibleException;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Parser\AFPUserVisibleException
 */
class AFPUserVisibleExceptionTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::getPosition
	 * @covers ::getMessageForLogs
	 * @covers ::getMessageObj
	 */
	public function testGetters() {
		$excID = 'abusefilter-foo';
		$position = 42;
		$params = [ 'foo' ];
		$exc = new AFPUserVisibleException( $excID, $position, $params );
		$this->assertSame( $position, $exc->getPosition(), 'position' );
		$this->assertStringContainsString( $excID, $exc->getMessageForLogs(), 'ID in log message' );
		$this->assertStringContainsString( $position, $exc->getMessageForLogs(), 'position in logs message' );
		$message = $exc->getMessageObj();
		$this->assertSame( 'abusefilter-exception-' . $excID, $message->getKey(), 'msg key' );
		$this->assertArrayEquals( array_merge( [ $position ], $params ), $message->getParams(), 'msg params' );
	}

	/**
	 * @covers ::toArray
	 * @covers ::fromArray
	 */
	public function testToArrayRoundTrip() {
		$exc = new AFPUserVisibleException( 'abusefilter-foo', 42, [ 'foo' ] );
		$newExc = AFPUserVisibleException::fromArray( $exc->toArray() );
		$this->assertSame( $exc->getPosition(), $newExc->getPosition() );
		$this->assertSame( $exc->getMessageObj()->getParams(), $newExc->getMessageObj()->getParams() );
	}
}
