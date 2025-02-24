<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Variables\AbuseFilterProtectedVariablesLookup
 */
class AbuseFilterProtectedVariablesLookupTest extends MediaWikiIntegrationTestCase {
	public function testGetAllProtectedVariables() {
		$this->overrideConfigValue( 'AbuseFilterProtectedVariables', [ 'user_unnamed_ip' ] );
		$objectUnderTest = AbuseFilterServices::getProtectedVariablesLookup( $this->getServiceContainer() );
		$this->assertArrayEquals( [ 'user_unnamed_ip' ], $objectUnderTest->getAllProtectedVariables() );
	}
}
