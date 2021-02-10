<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\AFPInternalException;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Parser\AFPException
 */
class AFPExceptionTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::toArray
	 * @covers ::fromArray
	 */
	public function testToArrayRoundTrip() {
		$exc = new AFPInternalException( 'Foo' );
		$newExc = AFPInternalException::fromArray( $exc->toArray() );
		$this->assertSame( $exc->getMessage(), $newExc->getMessage() );
	}

}
