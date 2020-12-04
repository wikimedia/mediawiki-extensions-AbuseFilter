<?php

use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\ConsequencesLookup;
use MediaWiki\Extension\AbuseFilter\ConsequencesRegistry;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\ConsequencesLookup
 * @todo Write unit tests (non-trivial because the class is tied to a DB)
 */
class AbuseFilterConsequencesLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::__construct
	 */
	public function testConstructor() {
		$this->assertInstanceOf(
			ConsequencesLookup::class,
			new ConsequencesLookup(
				$this->createMock( ILoadBalancer::class ),
				$this->createMock( CentralDBManager::class ),
				$this->createMock( ConsequencesRegistry::class ),
				new NullLogger()
			)
		);
	}
}
