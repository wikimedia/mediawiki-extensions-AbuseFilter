<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser;

use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
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
		$exc = new AFPException( 'Condition limit reached.' );
		$newExc = AFPException::fromArray( $exc->toArray() );
		$this->assertSame( $exc->getMessage(), $newExc->getMessage() );
	}

}
